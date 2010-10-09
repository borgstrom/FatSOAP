<?php
/*
 * I hate SOAP, it should be COAP.
 *
 * These classes are the result of working with a very strict, broken
 * WSDL, web service.
 */

class SOAP_Client {
	public $namespaces = array('soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/');
	public $xml;
	public $writer;

	/*
	 * create_xml - builds the XML object for the request
	 *
	 * params:
	 * $header - if null no headers are added, else should be an object that extends XMLObject
	 */
	public function create_xml($header = null) {
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
			$header->create_xml(&$this->writer);
			$this->writer->endElement(); // Header
		}
		$this->writer->endElement(); // Envelope
	}

	public function namespace_by_url($target) {
		foreach ($this->namespaces as $key => $url) {
			if ($url == $target) {
				return $key;
			}
		}
	}

	public function namespace_by_key($target) {
		foreach ($this->namespaces as $key => $url) {
			if ($key == $target) {
				return $url;
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
	public $namespace = null;

	/*
	 * with_namespace is a utility function to prefix the namespace onto
	 * a value, if it's not null
	 */
	private function with_namespace($name) {
		if ($this->namespace !== null) {
			return $this->namespace . ":" . $name;
		}
		return $name;
	}

	/*
	 * serialize_writer is the new version of serialize that uses the
	 * XMLWriter class
	 */
	private function serialize_writer($name, $val, &$writer) {
		if (is_array($val)) {
			$writer->startElement($this->with_namespace($name));
			foreach ($val as $sub_key => $sub_val) {
				$this->serialize($sub_key, $sub_val, &$writer);
			}
			$writer->endElement();
		} elseif (is_object($val)) {
			$writer->startElement($this->with_namespace($name));
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
				$this->create_xml(&$writer);
			} else {
				print "Warning: got an object I can't serialize: $cls\n";
			}
			$writer->endElement();
		} else {
			$writer->writeElement($this->with_namespace($name), $val);
		}
	}

	public function serialize($name, $val, &$xml) {
		if (is_array($val)) {
			// array('this', 'is', 'a', 'test');
			// array('this' => 'test');
			$sub = $xml->addChild($name);
			foreach ($val as $sub_key => $sub_val) {
				$this->serialize($sub_key, $sub_val, &$sub);
			}
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
				if ($val->namespace !== null) {
					$sub = $xml->addChild($name, null, $val->namespace);
				} else {
					$sub = $xml->addChild($name);
				}
				$val->asXML(&$sub, false);
			} else {
				print "Warning: got an object I can't serialize: $cls\n";
			}
		} else {
			$xml->addChild($name, $val);
		}
	}

	/*
	 * create_xml is the new version of the object serializtion logic
	 * that now uses XMLWriter instead of simplexml.
	 * it's job is to inspect itself through reflection and then
	 * serialize any properties recursively
	 */
	public function create_xml(&$writer) {
		$cls = get_class($this);

		$writer->startElement($this->with_namespace($cls));

		$ref = new ReflectionClass($cls);
		$props = $ref->getProperties();
		foreach ($props as $prop) {
			$name = $prop->getName();
			if ($name == 'namespace') { 
				continue;
			}
			if (isset($this->{$name})) {
				$val = $this->{$name};
				$this->serialize_writer($name, $val, &$writer);
			}
		}
		$writer->endElement();
	}


	/*
	 * asXML will return the object into an XML string via reflection
	 * it is used recursively by passing in an existing $xml object
	 */
	public function asXML(&$xml = null, $return_xml = true) {
		$cls = get_class($this);
		if ($xml === null) {
			if ($this->namespace !== null) {
				$xml = new SimpleXMLElement("<$cls xmlns=\"" . $this->namespace . "\"/>");
			} else {
				$xml = new SimpleXMLElement("<$cls/>");
			}
		}
		$ref = new ReflectionClass($cls);
		$props = $ref->getProperties();
		foreach ($props as $prop) {
			$name = $prop->getName();
			if ($name == 'namespace') { 
				continue;
			}
			if (isset($this->{$name})) {
				$val = $this->{$name};
				$this->serialize($name, $val, &$xml);
			}
		}
		if ($return_xml === true) {
			/* we have to strip off the first line as the soap client will take
			 * of adding the xml header: <?xml version="1.0"?>
			 * then we also have to strip off our wrapper that lets name spaces work
			 */
			$lines = explode("\n", $xml->asXML());
			return preg_replace("#^<wrapper [^>]+>(.*)</wrapper>$#i", "$1", $lines[1]);
		}
	}
}
