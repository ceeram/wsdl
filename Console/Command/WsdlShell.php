<?php
App::uses('WSDLInterpreter', 'Wsdl.Vendor');
class WsdlShell extends Shell {


	public function main() {
		$wsdl = $path = $plugin = null;
		while (!$wsdl) {
			$wsdl = $this->in('Enter the url of the wsdl');
		}
		while (!$path) {
			$path = $this->in('Save to App or a plugin?', array('app', 'plugin'));
		}
		if ($path == 'plugin') {
			$loaded = CakePlugin::loaded();
			while (!$plugin) {
				$plugin = $this->in('Select plugin', $loaded);
				if (!in_array($plugin, $loaded)) {
					$plugin = null;
				}
			}
			$path = CakePlugin::path($plugin) . 'Lib';
			$plugin .= '.';
		} else {
			$path = APP . 'Lib';
		}
		$wsdlInterpreter = new WSDLInterpreter($wsdl);
		$return = $wsdlInterpreter->savePHP($path);
		$path .= DS;
		$file = str_replace($path, '', $return[0]);
		$class = substr($file, 0, -4);
		$this->hr();
		$this->out('Lib saved to:' . $path . $file);
		$text = "'lib' => '" . $plugin . $class . "'";
		$this->out("Add 'lib' key to the config in database.php: " . $text);
		$this->hr();
	}
}