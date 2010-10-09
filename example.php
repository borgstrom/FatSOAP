<?php
require 'fatsoap.php';

class Credentials extends XMLObject {
	public $Username;
	public $Password;

	public function __construct($user, $pass) {
		$this->Username = $user;
		$this->Password = $pass;
	}
}

class RequestQuote extends XMLObject {
	public $symbol;

	public $namespace = 'val';
	public $own_namespace = 'quote';
}

$c = new SOAP_Client('http://somefinancialsite.com/quotes/', array(
	'soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/',
	'val' => 'http://somefinancialsite.com/schema/val/',
	'quote' => 'http://somefinancialsite.com/schema/quote/',
));

$creds = new Credentials("thisismyusername", "superrrrrrsecret");

$req = new RequestQuote();
$req->symbol = "AAPL";

header('Content-Type: text/xml');
echo $c->show('RequestQuote', $req, $creds);
