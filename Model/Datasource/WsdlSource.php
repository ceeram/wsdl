<?php
class WsdlSource extends DataSource {

	public $description = 'Wsdl Soap Datasource';

	protected $_SoapClient = null;

	public $connected = false;

	public $classmap = array();

	public function __construct($config) {
		parent::__construct($config);
		$this->loadService();
		$this->connect();
	}

	public function connect() {
		$options = array(
			'trace' => Configure::read('debug') > 0,// for SoapClient::__getLast...() methods
			//'soap_version' => SOAP_1_2,
			'classmap' => $this->classmap,
			'exceptions' => true,
			'features' => SOAP_SINGLE_ELEMENT_ARRAYS
		);
		try {
			$this->_SoapClient = new SoapClient($this->config['wsdl'], $options);
		} catch(SoapFault $SoapFault) {
			throw new CakeException($SoapFault->getMessage());
		}
		if (!empty($this->_SoapClient)) {
			$this->connected = true;
		}
		return $this->connected;
	}

	public function loadService() {
		if (empty($this->config['lib'])) {
			throw new CakeException('Define lib config key in database.php');
		}
		list($plugin, $className) = pluginSplit($this->config['lib'], true);
		App::uses($className, $plugin . 'Lib');
		$class = new ReflectionClass($className);
		$this->classmap = $class->getStaticPropertyValue('classmap');
	}

	public function close() {
		$this->_SoapClient = null;
		$this->connected = false;
		return true;
	}

	public function listSources() {
		return $this->_SoapClient->__getFunctions();
	}

	public function query($method, $query = null, $object = null) {
		if(!$this->connected) {
			throw new CakeException(__('Not connected'));
		}

		if($method == 'describe') {
			return $this->listSources();
		}

		if ($query) {
			$query = $query[0];
		}
		$query = new $method($query);
		$this->startTimer('soapQuery_' . $method, 'Soap::' . $method);
		try {
			$response = $this->_SoapClient->{$method}($query);
		} catch (SoapFault $SoapFault) {
			$this->stopTimer('soapQuery_' . $method);
			throw new CakeException($SoapFault->faultstring);
		}
		$this->stopTimer('soapQuery_' . $method);
		return $this->resultSet($response, $method);
	}

	public function getLastResponse() {
	   return $this->_SoapClient->__getLastResponse();
	}

	public function getLastRequest() {
		return $this->_SoapClient->__getLastRequest();
	}

	public function startTimer($name = null, $message = null) {
		if (Configure::read('debug') == 0){
			return false;
		}
		App::uses('DebugKitDebugger', 'DebugKit.Lib');
		return DebugKitDebugger::startTimer($name, $message);
	}

	public function stopTimer($name = null) {
		if (Configure::read('debug') == 0){
			return false;
		}
		return DebugKitDebugger::stopTimer($name);
	}

	public function resultSet($response, $method) {
		$resultName = ucfirst($method . 'Result');
		$response = $response->{$resultName};
		return Set::reverse($response);
	}

}