<?php
/**
 * Interprets WSDL documents for the purposes of PHP 5 object creation
 *
 * The WSDLInterpreter package is used for the interpretation of a WSDL
 * document into PHP classes that represent the messages using inheritance
 * and typing as defined by the WSDL rather than SoapClient's limited
 * interpretation.  PHP classes are also created for each service that
 * represent the methods with any appropriate overloading and strict
 * variable type checking as defined by the WSDL.
 *
 * PHP version 5
 *
 * LICENSE: This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category    WebServices
 * @package     WSDLInterpreter
 * @author      Kevin Vaughan kevin@kevinvaughan.com
 * @copyright   2007 Kevin Vaughan
 * @license     http://www.gnu.org/copyleft/lesser.html  LGPL License 2.1
 *
 * This is a modified version.
 * Modifications made by Ceeram, c33ram@gmail.com
 */

/**
 * A lightweight wrapper of Exception to provide basic package specific
 * unrecoverable program states.
 *
 * @category WebServices
 * @package WSDLInterpreter
 */
class WSDLInterpreterException extends Exception { }

/**
 * The main class for handling WSDL interpretation
 *
 * The WSDLInterpreter is utilized for the parsing of a WSDL document for rapid
 * and flexible use within the context of PHP 5 scripts.
 *
 * @category WebServices
 * @package WSDLInterpreter
 */
class WSDLInterpreter
{
    /**
     * The WSDL document's URI
     * @var string
     * @access private
     */
    private $_wsdl = null;

    /**
     * A SoapClient for loading the WSDL
     * @var SoapClient
     * @access private
     */
    private $_client = null;

    /**
     * DOM document representation of the wsdl and its translation
     * @var DOMDocument
     * @access private
     */
    private $_dom = null;

    /**
     * Array of classes and members representing the WSDL message types
     * @var array
     * @access private
     */
    private $_classmap = array();

    /**
     * Array of sources for WSDL message classes
     * @var array
     * @access private
     */
    private $_classPHPSources = array();

    /**
     * Array of sources for WSDL services
     * @var array
     * @access private
     */
    private $_servicePHPSources = array();

	/**
     * name of the base class to extend
     * @var string
     * @access private
     */
    private $_baseClass;

	/**
     * array of parameters for the baseClass
     * @var array
     * @access private
     */
    private $_baseParams = array();

    /**
     * Parses the target wsdl and loads the interpretation into object members
     *
     * @param string $wsdl  the URI of the wsdl to interpret
     * @throws WSDLInterpreterException Container for all WSDL interpretation problems
     * @todo Create plug in model to handle extendability of WSDL files
     */
    public function __construct($wsdl) {
        try {
            $this->_wsdl = $wsdl;
            $this->_client = new SoapClient($wsdl);

            $this->_dom = new DOMDocument();
            $this->_dom->load($wsdl, LIBXML_DTDLOAD|LIBXML_DTDATTR|LIBXML_NOENT|LIBXML_XINCLUDE);

            $xpath = new DOMXPath($this->_dom);

            /**
             * wsdl:import
             */
            $query = "//*[local-name()='import' and namespace-uri()='http://schemas.xmlsoap.org/wsdl/']";
            $entries = $xpath->query($query);
            foreach ($entries as $entry) {
                $parent = $entry->parentNode;
                $wsdl = new DOMDocument();
                $wsdl->load($entry->getAttribute("location"), LIBXML_DTDLOAD|LIBXML_DTDATTR|LIBXML_NOENT|LIBXML_XINCLUDE);
                foreach ($wsdl->documentElement->childNodes as $node) {
                    $newNode = $this->_dom->importNode($node, true);
                    $parent->insertBefore($newNode, $entry);
                }
                $parent->removeChild($entry);
            }

            /**
             * xsd:import
             */
            $query = "//*[local-name()='import' and namespace-uri()='http://www.w3.org/2001/XMLSchema']";
            $entries = $xpath->query($query);
            foreach ($entries as $entry) {
                $parent = $entry->parentNode;
                $xsd = new DOMDocument();
                $result = @$xsd->load(dirname($this->_wsdl) . "/" . $entry->getAttribute("schemaLocation"),
                    LIBXML_DTDLOAD|LIBXML_DTDATTR|LIBXML_NOENT|LIBXML_XINCLUDE);
                if ($result) {
                    foreach ($xsd->documentElement->childNodes as $node) {
                        $newNode = $this->_dom->importNode($node, true);
                        $parent->insertBefore($newNode, $entry);
                    }
                    $parent->removeChild($entry);
                }
            }


            $this->_dom->formatOutput = true;
        } catch (Exception $e) {
            throw new WSDLInterpreterException("Error loading WSDL document (".$e->getMessage().")");
        }

        try {
            $xsl = new XSLTProcessor();
            $xslDom = new DOMDocument();
            $xslDom->load(dirname(__FILE__)."/wsdl2php.xsl");
            $xsl->registerPHPFunctions();
            $xsl->importStyleSheet($xslDom);
            $this->_dom = $xsl->transformToDoc($this->_dom);
            $this->_dom->formatOutput = true;
        } catch (Exception $e) {
            throw new WSDLInterpreterException("Error interpreting WSDL document (".$e->getMessage().")");
        }
		$this->_loadBaseClasses();
        $this->_loadClasses();
        $this->_loadClassMaps();
    }

    /**
     * Validates a name against standard PHP naming conventions
     *
     * @param string $name the name to validate
     *
     * @return string the validated version of the submitted name
     *
     * @access private
     */
    private function _validateNamingConvention($name) {
		$pos = strpos($name, ':');
		if ($pos !== false) {
			$name = substr($name, $pos);
		}
        return preg_replace('#[^a-zA-Z0-9_\x7f-\xff]*#', '',
            preg_replace('#^[^a-zA-Z_\x7f-\xff]*#', '', $name));
    }

    /**
     * Validates a class name against PHP naming conventions and already defined
     * classes, and optionally stores the class as a member of the interpreted classmap.
     *
     * @param string $className the name of the class to test
     * @param boolean $addToClassMap whether to add this class name to the classmap
     *
     * @return string the validated version of the submitted class name
     *
     * @access private
     * @todo Add reserved keyword checks
     */
    private function _validateClassName($className, $addToClassMap = true) {
        $validClassName = $this->_validateNamingConvention($className);

        if (class_exists($validClassName)) {
            throw new Exception("Class ".$validClassName." already defined.".
                " Cannot redefine class with class loaded.");
        }

        if ($addToClassMap) {
            $this->_classmap[$className] = $validClassName;
        }

        return $validClassName;
    }


    /**
     * Validates a wsdl type against known PHP primitive types, or otherwise
     * validates the namespace of the type to PHP naming conventions
     *
     * @param string $type the type to test
     *
     * @return string the validated version of the submitted type
     *
     * @access private
     * @todo Extend type handling to gracefully manage extendability of wsdl definitions, add reserved keyword checking
     */
    private function _validateType($type) {
        $array = false;
        if (substr($type, -2) == "[]") {
            $array = true;
            $type = substr($type, 0, -2);
        }
		$type = $this->_validateNamingConvention($type);

        switch ($type) {
        case "int": case "integer": case "long": case "byte": case "short":
        case "negativeInteger": case "nonNegativeInteger":
        case "nonPositiveInteger": case "positiveInteger":
        case "unsignedByte": case "unsignedInt": case "unsignedLong": case "unsignedShort":
            $validType = "integer";
            break;

        case "float": case "long": case "double": case "decimal":
            $validType = "double";
            break;

        case "string": case "token": case "normalizedString": case "hexBinary": case "string":
            $validType = "string";
            break;

        default:
            $validType = $type;
            break;
        }
        if ($array) {
            $validType .= "[]";
        }
        return $validType;
    }

	/**
     * Loads base classes to extend
     *
     * @access private
     */
	private function _loadBaseClasses() {
		$functionNames = $baseParams = array();
		$services = $this->_dom->getElementsByTagName("service");
		foreach($services as $service) {
			$baseClass = Inflector::camelize($this->_validateClassName($service->getAttribute("name"), false) . "BaseClass");
			$functions = $service->getElementsByTagName("function");
            foreach ($functions as $function) {
				$functionName = $this->_validateNamingConvention($function->getAttribute("name"));
                $this->_baseClass[$functionName] = $baseClass;
			}
		}
		$this->_setBaseParams();
		$this->_classPHPSources[$baseClass] = $this->_generateBaseClassPHP($baseClass);
	}

	private function _setBaseParams() {
		$classes = $this->_dom->getElementsByTagName("class");
        foreach ($classes as $class) {
            $validatedClassName = $this->_validateClassName($class->getAttribute("name"));
			if (array_key_exists($validatedClassName, $this->_baseClass)) {
				$properties = $class->getElementsByTagName("entry");
				foreach ($properties as $property) {
					$params[$validatedClassName][$this->_validateNamingConvention($property->getAttribute("name"))] = $this->_validateType($property->getAttribute("type"));
					$baseParams[$this->_validateNamingConvention($property->getAttribute("name"))] = $this->_validateType($property->getAttribute("type"));
				}
			}
        }
		foreach ($params as $class => $classParams) {
			$baseParams = array_intersect_key($baseParams, $classParams);
		}
		$this->_baseParams = $baseParams;
	}
    /**
     * Loads classes from the translated wsdl document's message types
     *
     * @access private
     */
    private function _loadClasses() {
        $classes = $this->_dom->getElementsByTagName("class");
        foreach ($classes as $class) {
            $class->setAttribute("validatedName",
                $this->_validateClassName($class->getAttribute("name")));
            $extends = $class->getElementsByTagName("extends");
            if ($extends->length > 0) {
                $extends->item(0)->nodeValue =
                    $this->_validateClassName($extends->item(0)->nodeValue);
                $classExtension = $extends->item(0)->nodeValue;
            } else {
                $classExtension = false;
            }
            $properties = $class->getElementsByTagName("entry");
            foreach ($properties as $property) {
                $property->setAttribute("validatedName",
                    $this->_validateNamingConvention($property->getAttribute("name")));
                $property->setAttribute("type",
                    $this->_validateType($property->getAttribute("type")));
            }

            $sources[$class->getAttribute("validatedName")] = array(
                "extends" => $classExtension,
                "source" => $this->_generateClassPHP($class)
            );
        }

        while (sizeof($sources) > 0)
        {
            $classesLoaded = 0;
            foreach ($sources as $className => $classInfo) {
                if (!$classInfo["extends"] || (isset($this->_classPHPSources[$classInfo["extends"]]))) {
                    $this->_classPHPSources[$className] = $classInfo["source"];
                    unset($sources[$className]);
                    $classesLoaded++;
                }
            }
            if (($classesLoaded == 0) && (sizeof($sources) > 0)) {
                throw new WSDLInterpreterException("Error loading PHP classes: ".join(", ", array_keys($sources)));
            }
        }
    }

	protected function _generateBaseClassPHP($class) {
		$return = "";
        $return .= 'if (!class_exists("'.$class.'")) {'."\n";
        $return .= '/**'."\n";
        $return .= ' * '.$class."\n";
        $return .= ' */'."\n";
        $return .= "class ".$class;
		$return .= " {\n";
		$properties = $this->_baseParams;
        foreach ($properties as $name => $type) {
			$return .= "\t/**\n"
					 . "\t * @access public\n"
					 . "\t * @var ".$type."\n"
					 . "\t */\n"
					 . "\t".'public $'.$name. " = '".$type."';\n"
					 . "\n";
        }
		$return .= "\t".'/**'."\n";
		$return .= "\t".' * constructor'."\n";
        $return .= "\t".' * @param $args'."\n";
        $return .= "\t".' * @return void'."\n";
        $return .= "\t".' */'."\n";
        $return .= "\t".'public function __construct($args) {'."\n";
		$return .= "\t\t".'foreach ((array) $args as $name => $arg) {'."\n";
		$return .= "\t\t\t".'if (property_exists($this, $name)) {'."\n";
		$return .= "\t\t\t\t".'$this->{$name} = $arg;'."\n";
		$return .= "\t\t\t".'}'."\n";
		$return .= "\t\t".'}'."\n";
		$return .= "\t".'}'."\n";
		$return .= "}}";
		return $return;
	}
    /**
     * Generates the PHP code for a WSDL message type class representation
     *
     * This gets a little bit fancy as the magic methods __get and __set in
     * the generated classes are used for properties that are not named
     * according to PHP naming conventions (e.g., "MY-VARIABLE").  These
     * variables are set directly by SoapClient within the target class,
     * and could normally be retrieved by $myClass->{"MY-VARIABLE"}.  For
     * convenience, however, this will be available as $myClass->MYVARIABLE.
     *
     * @param DOMElement $class the interpreted WSDL message type node
     * @return string the php source code for the message type class
     *
     * @access private
     * @todo Include any applicable annotation from WSDL
     */
    private function _generateClassPHP($class) {
		$extends = false;
        $return = "";
        $return .= 'if (!class_exists("'.$class->getAttribute("validatedName").'")) {'."\n";
        $return .= '/**'."\n";
        $return .= ' * '.$class->getAttribute("validatedName")."\n";
        $return .= ' */'."\n";
        $return .= "class ".$class->getAttribute("validatedName");
		if (!empty($this->_baseClass[$class->getAttribute("validatedName")])) {
			$extends = true;
			$return .= " extends ".$this->_baseClass[$class->getAttribute("validatedName")];
		}
        $return .= " {\n";

        $properties = $class->getElementsByTagName("entry");
        foreach ($properties as $property) {
			if (!$extends || !array_key_exists($property->getAttribute("validatedName"), $this->_baseParams)) {
				$return .= "\t/**\n"
						 . "\t * @access public\n"
						 . "\t * @var ".$property->getAttribute("type")."\n"
						 . "\t */\n"
						 . "\t".'public $'.$property->getAttribute("validatedName"). " = '".$property->getAttribute("type")."';\n"
						 . "\n";
			}
        }

        $extraParams = false;
        $paramMapReturn = "\t".'private $_parameterMap = array ('."\n";
        $properties = $class->getElementsByTagName("entry");
        foreach ($properties as $property) {
            if ($property->getAttribute("name") != $property->getAttribute("validatedName")) {
                $extraParams = true;
                $paramMapReturn .= "\t\t".'"'.$property->getAttribute("name").
                    '" => "'.$property->getAttribute("validatedName").'",'."\n";
            }
        }
        $paramMapReturn .= "\t".');'."\n";
        $paramMapReturn .= "\t".'/**'."\n";
        $paramMapReturn .= "\t".' * Provided for setting non-php-standard named variables'."\n";
        $paramMapReturn .= "\t".' * @param $var Variable name to set'."\n";
        $paramMapReturn .= "\t".' * @param $value Value to set'."\n";
        $paramMapReturn .= "\t".' */'."\n";
        $paramMapReturn .= "\t".'public function __set($var, $value) '.
            '{ $this->{$this->_parameterMap[$var]} = $value; }'."\n";
        $paramMapReturn .= "\t".'/**'."\n";
        $paramMapReturn .= "\t".' * Provided for getting non-php-standard named variables'."\n";
        $paramMapReturn .= "\t".' * @param $var Variable name to get'."\n";
        $paramMapReturn .= "\t".' * @return mixed Variable value'."\n";
        $paramMapReturn .= "\t".' */'."\n";
        $paramMapReturn .= "\t".'public function __get($var) '.
            '{ return $this->{$this->_parameterMap[$var]}; }'."\n";

        if ($extraParams) {
            $return .= $paramMapReturn;
        }

        $return .= "}}";
        return $return;
    }

    /**
     * Loads Class maps from the translated wsdl document
     *
     * @access private
     */
    private function _loadClassMaps() {
        $services = $this->_dom->getElementsByTagName("service");
        foreach ($services as $service) {
            $service->setAttribute("validatedName",
                Inflector::camelize($this->_validateClassName($service->getAttribute("name"), false) . "ClassMap"));
            $this->_servicePHPSources[$service->getAttribute("validatedName")] =
                $this->_generateServicePHP($service);
        }
    }

    /**
     * Generates the PHP code for a WSDL service class representation
     *
     * This method, in combination with generateServiceFunctionPHP, create a PHP class
     * representation capable of handling overloaded methods with strict parameter
     * type checking.
     *
     * @param DOMElement $service the interpreted WSDL service node
     * @return string the php source code for the service class
     *
     * @access private
     * @todo Include any applicable annotation from WSDL
     */
    private function _generateServicePHP($service) {
        $return = "";
        $return .= 'if (!class_exists("'.$service->getAttribute("validatedName").'")) {'."\n";
        $return .= '/**'."\n";
        $return .= ' * '.$service->getAttribute("validatedName")."\n";
        $return .= ' * @author WSDLInterpreter'."\n";
        $return .= ' */'."\n";
        $return .= "class ".$service->getAttribute("validatedName")." {\n";

        if (sizeof($this->_classmap) > 0) {
            $return .= "\t".'/**'."\n";
            $return .= "\t".' * Default class map for wsdl=>php'."\n";
            $return .= "\t".' * @access p'."\n";
            $return .= "\t".' * @var array'."\n";
            $return .= "\t".' */'."\n";
            $return .= "\t".'public static $classmap = array('."\n";
            foreach ($this->_classmap as $className => $validClassName)    {
                $return .= "\t\t".'"'.$className.'" => "'.$validClassName.'",'."\n";
            }
            $return .= "\t);\n\n";
        }
        $return .= "}}";
        return $return;
    }


    /**
     * Saves the PHP source code that has been loaded to a target directory.
     *
     * Services will be saved by their validated name, and classes will be included
     * with each service file so that they can be utilized independently.
     *
     * @param string $outputDirectory the destination directory for the source code
     * @return array array of source code files that were written out
     * @throws WSDLInterpreterException problem in writing out service sources
     * @access public
     * @todo Add split file options for more efficient output
     */
    public function savePHP($outputDirectory) {
        if (sizeof($this->_servicePHPSources) == 0) {
            throw new WSDLInterpreterException("No services loaded");
        }
        $classSource = join("\n\n", $this->_classPHPSources);
        $outputFiles = array();
        foreach ($this->_servicePHPSources as $serviceName => $serviceCode) {
            $filename = $outputDirectory."/".$serviceName.".php";
            if (file_put_contents($filename,
                    "<?php\n\n".$classSource."\n\n".$serviceCode."\n\n")) {
                $outputFiles[] = $filename;
            }
        }
        if (sizeof($outputFiles) == 0) {
            throw new WSDLInterpreterException("Error writing PHP source files.");
        }
        return $outputFiles;
    }
}