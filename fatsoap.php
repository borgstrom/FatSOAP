<?php
/*
 * FatSOAP
 *
 * I hate SOAP, it should be COAP.
 *
 * These classes are the result of working with a very strict, broken
 * WSDL, web service.
 *
 * This is just a light shell, it does NOT support WSDL in any way and
 * assumes that you'll create all of your objects with their correct
 * target name spaces. In the future WSDL support may be added, but only
 * if this gets more use.
 *
 * Requires: PHP >= 5.2.1, curl, XMLWriter
 */

class SOAP_Client {
	private $curl;

	/*
	 * this needs to be public so the XMLObject's can get it
	 */
	public $namespaces = array('soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/');
	public $writer;

	/*
	 * constructor sets url & optionally the namespaces
	 */
	public function __construct($url, $namespaces = null) {
		if (($namespaces != null) and (is_array($namespaces))) {
			$this->namespaces = $namespaces;
		}
		$this->curl = curl_init();
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_POST, 1);
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
	}

	/*
	 * call invokes the SOAP function
	 *
	 * params:
	 * $body - an XMLObject that will be used as the body
	 * $header - if null no headers are added, else should be an object that extends XMLObject
	 */
	public function call($function, $body, $header = null) {
		$this->create_xml($body, $header);
		$data = $this->writer->outputMemory();

		// we need to strip off the xml declaration
		$lines = explode("\n", $data);
		array_shift($lines);
		$data = implode("\n", $lines);

		$headers = array(
			"SOAPAction: \"$function\"",
			"Content-Type: text/xml",
			"Content-Length: " . strlen($data),
		);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, $data);

		$response = curl_exec($this->curl);
		return $response;
	}

	/*
	 * show displays the XML that would be built for $function
	 *
	 * params:
	 * $body - an XMLObject that will be used as the body
	 * $header - if null no headers are added, else should be an object that extends XMLObject
	 */
	public function show($function, $body, $header = null) {
		$this->create_xml($body, $header);
		return $this->writer->outputMemory();
	}

	/*
	 * create_xml - builds the XML object for the request
	 *
	 * params:
	 * $body - an XMLObject that will be used as the body
	 * $header - if null no headers are added, else should be an object that extends XMLObject
	 */
	public function create_xml($body, $header = null) {
		$soapns = $this->namespace_by_url('http://schemas.xmlsoap.org/soap/envelope/');
		if (!isset($soapns)) {
			throw new Exception("You need a namespace for the SOAP envelope");
		}

		$this->writer = new XMLWriter();
		$this->writer->openMemory();
		$this->writer->startDocument('1.0', 'UTF-8');
		$this->writer->setIndent(4);

		$this->writer->startElement("$soapns:Envelope");
		foreach ($this->namespaces as $key => $url) {
			$this->writer->writeAttribute("xmlns:$key", $url);
		}

		if ($header !== null) {
			$this->writer->startElement("$soapns:Header");
			$header->set_client(&$this);
			$header->create_xml();
			$this->writer->endElement(); // Header
		}

		$this->writer->startElement("$soapns:Body");
		$body->set_client(&$this);
		$body->create_xml();
		$this->writer->endElement(); // Body

		$this->writer->endElement(); // Envelope
	}

	public function namespace_by_url($target) {
		foreach ($this->namespaces as $key => $url) {
			if ($url == $target) {
				return $key;
			}
		}
	}

}

/*
 * XMLObject is a class that should be inherited by any object
 * that will be used with SOAP_Client. It's job is to provide a
 * consistent serialization with maximum flexibility
 */
class XMLObject {
	/*
	 * namespace sets the namespace for the properties of this object
	 */
	public $namespace = null;

	/*
	 * own_namespace sets the namespace for the object itself, if its
	 * null then it defaults to self->namespace
	 */
	public $own_namespace = null;

	/*
	 * soap_client & writer will hold references to the client & writer
	 */
	private $soap_client = null;
	private $writer = null;

	/*
	 * set_client associates the client & writer with this object
	 */
	public function set_client(&$client) {
		$client_cls = get_class($client);
		if ($client_cls == 'SOAP_Client') {
			$this->soap_client =& $client;
			$this->writer =& $client->writer;
			return true;
		}
		return false;
	}

	/*
	 * with_namespace is a utility function to prefix the namespace onto
	 * a value, if it's not null
	 *
	 * params:
	 * $name is the name that might be returned with the name space prefix
	 * $namespace is optional and allows you to override $this->namespace
	 */
	private function with_namespace($name, $namespace = null) {
		if ($namespace === null) {
			$namespace = $this->namespace;
		}

		if ($namespace !== null) {
			/*
			 * allow name spaces to be specified by URL too
			 */
			$ns = $this->soap_client->namespace_by_url($namespace);
			if (isset($ns)) {
				$namespace = $ns;
			}

			if (array_key_exists($namespace, $this->soap_client->namespaces)) {
				$name = $namespace . ":" . $name;
			} else {
				throw new Exception("You have specified an invalid name space ($namespace) for the " . get_class($this) . " class");
			}
		}
		return $name;
	}

	/*
	 * serialize_writer is the new version of serialize that uses the
	 * XMLWriter class
	 */
	private function serialize_writer($name, $val) {
		if (is_array($val)) {
			$this->writer->startElement($this->with_namespace($name));
			foreach ($val as $sub_key => $sub_val) {
				$this->serialize_writer($sub_key, $sub_val);
			}
			$this->writer->endElement();
		} elseif (is_object($val)) {
			$cls = get_class($val);
			$ref = new ReflectionClass($cls);
			$parent = $ref->getParentClass();
			if ($parent->name == "XMLObject") {
				if (preg_match("#^[0-9]$#", $name)) {
					/* if the name is all numeric, ie not an explicitly associatiave
					 * array, then we use the class name in its place. this is probably
					 * a terrible hack, but it works...
					 */
					$name = $cls;
				}
				$val->set_client(&$this->soap_client);
				$val->create_xml($this->namespace);
			} else {
				throw new Exception("serialize_writer got an object I can't serialize: $cls");
			}
		} else {
			if (is_bool($val)) {
				if ($val === true) {
					$val = "true";
				} else {
					$val = "false";
				}
			}
			$this->writer->writeElement($this->with_namespace($name), $val);
		}
	}

	/*
	 * create_xml is the new version of the object serializtion logic
	 * that now uses XMLWriter instead of simplexml.
	 * it's job is to inspect itself through reflection and then
	 * serialize any properties recursively using serialize_writer
	 */
	public function create_xml() {
		$cls = get_class($this);

		$this->writer->startElement($this->with_namespace($cls, $this->own_namespace));

		$ref = new ReflectionClass($cls);
		$props = $ref->getProperties();
		foreach ($props as $prop) {
			$name = $prop->getName();
			switch ($name) {
			case 'namespace':
			case 'own_namespace':
				continue 2; break; // jump to the next iteration in the foreach
			}

			if (isset($this->{$name})) {
				$val = $this->{$name};
				$this->serialize_writer($name, $val);
			}
		}
		$this->writer->endElement();
	}
}
