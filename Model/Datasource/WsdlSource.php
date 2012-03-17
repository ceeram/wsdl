<?php
App::uses('DebugKitDebugger', 'DebugKit.Lib');

/**
 * SOAP datasource based on the service's WSDL.
 */
class WsdlSource extends DataSource {

	/**
	 * Description of the datasource.
	 *
	 * @var string
	 */
	public $description = 'Wsdl Soap Datasource';

	/**
	 * Connection status.
	 *
	 * @var boolean
	 */
	public $connected = false;

	/**
	 * Map of callable classes.
	 *
	 * @var array
	 */
	public $classmap = array();

	/**
	 * The soap client connection object.
	 *
	 * @var SoapClient
	 */
	protected $_SoapClient = null;

	/**
	 * Whether the WsdlSource is using DebugKit for timings.
	 *
	 * @var boolean
	 */
	protected $_useDebugKit = false;

	/**
	 * Whether the WsdlSource should log the queries.
	 *
	 * @var boolean
	 */
	protected $_useLogging = false;

	/**
	 * The query log.
	 *
	 * @var array
	 */
	protected $_log = array();

	/**
	 * Constructor.
	 *
	 * @param array $config Configuration options.
	 */
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

	/**
	 * Connect the datasource to the API.
	 *
	 * @return boolean True when the datasource could connect else false.
	 * @throws CakeException
	 */
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

	/**
	 * Loads the service classes.
	 *
	 * @throws CakeException
	 */
	public function loadService() {
		if (empty($this->config['lib'])) {
			throw new CakeException('Define lib config key in database.php');
		}
		list($plugin, $className) = pluginSplit($this->config['lib'], true);
		App::uses($className, $plugin . 'Lib');
		$class = new ReflectionClass($className);
		$this->classmap = $class->getStaticPropertyValue('classmap');
	}

	/**
	 * Close and destroy the connection.
	 *
	 * @return boolean Success.
	 */
	public function close() {
		$this->_SoapClient = null;
		$this->connected = false;
		return true;
	}

	/**
	 * Gets a list of SOAP methods.
	 *
	 * @return array The list with the methods.
	 */
	public function listSources() {
		return $this->_SoapClient->__getFunctions();
	}

	/**
	 * Query the SOAP webservice.
	 *
	 * @param string $method Name of the method to call.
	 * @param array $query Parameters for the query.
	 * @return array Results of the query.
	 * @throws CakeException
	 */
	public function query($method, $query = null) {
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

	/**
	 * Gets the last webservice response.
	 *
	 * @return object The response.
	 */
	public function getLastResponse() {
	   return $this->_SoapClient->__getLastResponse();
	}

	/**
	 * Gets the last webservice request.
	 *
	 * @return object
	 */
	public function getLastRequest() {
		return $this->_SoapClient->__getLastRequest();
	}

	/**
	 * Start the DebugKitTimer.
	 *
	 * @param string $method The method that is being called.
	 * @return boolean Whether the DebugKitTimer is started.
	 */
	public function startTimer($method) {
		if (Configure::read('debug') == 0){
			return false;
		}
		if ($this->_useDebugKit) {
			return DebugKitDebugger::startTimer("soapQuery_$method", "Soap::$method");
		}
		return true;

	}

	/**
	 * Stop the DebugKitTimer.
	 *
	 * @param string $method Name of the method that has been called.
	 * @return boolean Whether the DebugKitTimer has been stopped.
	 */
	public function stopTimer($method) {
		if (Configure::read('debug') == 0){
			return false;
		}
		if ($this->_useDebugKit) {
			return DebugKitDebugger::stopTimer("soapQuery_$method");
		}
	}

	/**
	 * Converts the result set to an array.
	 *
	 * @param object $response The response from the webservice.
	 * @param string $method The name of the called method.
	 * @return array The formatted array.
	 */
	public function resultSet($response, $method) {
		$resultName = ucfirst($method . 'Result');
		$response = $response->{$resultName};
		return Set::reverse($response);
	}

	/**
	 * Add a query to the log.
	 *
	 * @param string $method Name of the called method.
	 * @param array $query Arguments for the query.
	 * @param string $error A possible error message.
	 * @param string $affected The number of affected records.
	 * @param int $numRows The number of returned rows.
	 * @param int $took The time it took to process the query.
	 */
	public function log($method, $query, $error, $affected, $numRows, $took) {
		if ($this->_useLogging) {
			$query = $method . ' ' . json_encode($query);
			$this->_log[] = compact('query', 'error', 'affected', 'numRows', 'took');
		}
	}

	/**
	 * Gets the log array for CAkePHP's logging.
	 *
	 * @return array The formatted array.
	 */
	public function getLog() {
		$log = $this->_log;
		$count = count($log);
		$time = array_sum(Set::extract('/took', $log));
		return compact('log', 'count', 'time');
	}


}