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
	 * Default configuration options.
	 */
	public $_baseConfig = array(
		'login' => false,
		'password' => false,
		'wsdl' => null,
		'cacheWsdl' => WSDL_CACHE_MEMORY,
		'headerNamespacesMap' => array()
	);

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
	 * List of SOAP headers.
	 */
	protected $_soapHeaders = array();

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

		$this->_useDebugKit = CakePlugin::loaded('DebugKit');

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
		try {
			$this->_SoapClient = new SoapClient($this->_getWsdl(), $this->_getOptions());
		} catch (SoapFault $SoapFault) {
			throw new CakeException($SoapFault->getMessage());
		}
		if (!empty($this->_SoapClient)) {
			$this->connected = true;
		}
		return $this->connected;
	}

/**
 * Creates correct wsdl location
 *
 * @return string
 */
	protected function _getWsdl() {
		$wsdl = $this->config['wsdl'];
		$hasCredentials = !empty($this->config['login']) && !empty($this->config['password']);
		if (strpos($wsdl, 'http') === 0 && $hasCredentials) {
			$auth = urlencode($this->config['login']) . ':' .  urlencode($this->config['password']) . '@';
			$wsdl = preg_replace('/:\/\//', '://' . $auth, $wsdl);
		}
		return $wsdl;
	}

	/**
	 * Formats the configuration to return options for the SoapClient.
	 *
	 * @return array The formatted options.
	 */
	protected function _getOptions() {
		$options = array(
			'trace' => Configure::read('debug') > 0,
			'classmap' => $this->classmap,
			'exceptions' => true,
			'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
			'cache_wsdl' => $this->config['cacheWsdl']
		);
		if ($this->config['login']) {
			$options += array('login' => $this->config['login']);
		}
		if ($this->config['password']) {
			$options += array('password' => $this->config['password']);
		}
		return $options;
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
	public function listSources($data = null) {
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
			$this->_SoapClient->__setSoapHeaders($this->_soapHeaders);
			$response = $this->_SoapClient->{$method}($Class);
		} catch (SoapFault $SoapFault) {
			$response = $SoapFault;
			$error = $SoapFault->faultstring;
		}

		$took = round((microtime(true) - $start) * 1000, 0);
		$this->stopTimer($method);
		$affected = '-';

		if ($response instanceof SoapFault) {
			$this->_log($method, $query, $error, $affected, 0, $took);
			throw new CakeException($SoapFault->faultstring);
		}

		$result = $this->resultSet($response, $method);
		$count = $result ? count(Set::flatten((array)$result)) : 0;
		$this->_log($method, $query, $error, $affected, $count, $took);
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
			return DebugTimer::start("soapQuery_$method", "Soap::$method");
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
			return DebugTimer::stop("soapQuery_$method");
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
	 * Adds a SOAP header to the next requests.
	 *
	 * @param string $headerNamespace The name of namespace to use (as described in the headerNamespaceMap config option).
	 * @param string $name The name of the header.
	 * @param mixed $data The data for the header.
	 * @param boolean $mustUnderstand Value of the mustUnderstand attribute of the SOAP header element.
	 * @param string $actor Value of the actor attribute of the SOAP header element.
	 */
	public function addSoapHeader($headerNamespace, $name, $data = array(), $mustUnderstand = false, $actor = false) {
		if ($actor) {
			$this->_soapHeaders[] = new SoapHeader($this->config['headerNamespacesMap'][$headerNamespace], $name, $data, $mustUnderstand, $actor);
		} else {
			$this->_soapHeaders[] = new SoapHeader($this->config['headerNamespacesMap'][$headerNamespace], $name, $data, $mustUnderstand);
		}
	}

	/**
	 * Resets the SOAP headers.
	 */
	public function resetSoapHeaders() {
		$this->_soapHeaders = array();
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
	protected function _log($method, $query, $error, $affected, $numRows, $took) {
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