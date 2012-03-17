<?php
App::uses('DebugKitDebugger', 'DebugKit.Lib');
class WsdlSource extends DataSource {

	public $description = 'Wsdl Soap Datasource';

	protected $_SoapClient = null;

	public $connected = false;

	public $classmap = array();

	protected $_useDebugKit = false;

	protected $_useLogging = false;

	protected $_log = array();

	public function __construct($config) {
		parent::__construct($config);

		if (Configure::read('debug') > 0) {
			$this->_useLogging = true;
		}

		try {
			$this->_useDebugKit = class_exists('DebugKitDebugger');
		} catch (MissingPluginException $Exception) {
			$this->_useDebugKit = false;
		}

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
		} catch (SoapFault $SoapFault) {
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
		if (!$this->connected) {
			throw new CakeException(__('Not connected'));
		}

		if ($method == 'describe') {
			return $this->listSources();
		}

		if ($query) {
			$query = $query[0];
		}

		if (!class_exists($method)) {
			throw new CakeException(sprintf(__('Method %s does not exist in this API. Try rebuilding the classes from the WSDL.'), $method));
		}
		$Class = new $method($query);

		$error = '-';
		$this->startTimer($method);
		$start = microtime(true);

		try {
			$response = $this->_SoapClient->{$method}($Class);
		} catch (SoapFault $SoapFault) {
			$response = $SoapFault;
			$error = $SoapFault->faultstring;
		}

		$took = round((microtime(true) - $start) * 1000, 0);
		$this->stopTimer($method);
		$affected = '-';

		if ($response instanceof SoapFault) {
			$this->log($method, $query, $error, $affected, 0, $took);
			throw new CakeException($SoapFault->faultstring);
		}

		$result = $this->resultSet($response, $method);
		$count = $result ? count(Set::flatten($result)) : 0;
		$this->log($method, $query, $error, $affected, $count, $took);
		return $result;
	}

	public function getLastResponse() {
	   return $this->_SoapClient->__getLastResponse();
	}

	public function getLastRequest() {
		return $this->_SoapClient->__getLastRequest();
	}

	public function startTimer($method) {
		if (Configure::read('debug') == 0){
			return false;
		}
		if ($this->_useDebugKit) {
			return DebugKitDebugger::startTimer("soapQuery_$method", "Soap::$method");
		}
		return true;

	}

	public function stopTimer($method) {
		if (Configure::read('debug') == 0){
			return false;
		}
		if ($this->_useDebugKit) {
			return DebugKitDebugger::stopTimer("soapQuery_$method");
		}
	}

	public function resultSet($response, $method) {
		$resultName = ucfirst($method . 'Result');
		$response = $response->{$resultName};
		return Set::reverse($response);
	}

	public function log($method, $query, $error, $affected, $numRows, $took) {
		if ($this->_useLogging) {
			$query = $method . ' ' . json_encode($query);
			$this->_log[] = compact('query', 'error', 'affected', 'numRows', 'took');
		}
	}

	public function getLog() {
		$log = $this->_log;
		$count = count($log);
		$time = array_sum(Set::extract('/took', $log));
		return compact('log', 'count', 'time');
	}


}