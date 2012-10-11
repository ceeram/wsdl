This plugin iterates over a SOAP API and generates a (cake)php file that you can include in your projects
that contains all the API's methods & objects as PHP classes.
These classes can then be used by this plugin's datasource to talk with the service.

## Prerequisites

You'll need the XSLTProcessor. On Ubuntu you'd install that as such:

```shell
aptitude install php5-xsl
```

## Install

```shell
cd app/Plugin
wget https://github.com/ceeram/wsdl/zipball/master -o wsdl.zip
unzip wsdl.zip
mv ceeram-wsdl-* Wsdl && rm -f wsdl.zip
```
or git clone, from your app dir:

```shell
git clone git://github.com/ceeram/wsdl.git Plugin/Wsdl
```

## Usage

Command line:

```shell
cake Wsdl.Wsdl
```

and follow the instructions

create a config in `database.php` like the example:

```php
public $soapservice = array(
	'datasource' => 'Wsdl.WsdlSource',
	'wsdl' => 'http://domain.com/service.asmx?WSDL',
	'lib' => 'ServiceClassMap',
);
```

Set wsdl and lib to the values you entered and got back in the shell.

Add `public $useTable = false;` and `public $useDbConfig = 'soapservice';` to your model.


### Authentication

If the web service is protected via Basic authentication, you could supply
the credentials as follows:

```php
public $soapservice = array(
	'datasource' => 'Wsdl.WsdlSource',
	'wsdl' => 'http://domain.com/service.asmx?WSDL',
	'lib' => 'ServiceClassMap',
	'login' => 'phally',
	'password' => 'awesome',
);
```
