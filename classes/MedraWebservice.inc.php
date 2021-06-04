<?php

/**
 * @file plugins/importexport/medra/classes/MedraWebservice.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MedraWebservice
 * @ingroup plugins_importexport_medra_classes
 *
 * @brief A wrapper for the mEDRA web service 2.0.
 *
 * NB: We do not use PHP's SoapClient because it is not PHP4 compatible and
 * it doesn't support multipart SOAP messages.
 */

import('lib.pkp.classes.xml.XMLNode');

define('MEDRA_WS_ENDPOINT_DEV', 'https://www-medra-dev.medra.org/servlet/ws/medraWS');
define('MEDRA2CR_WS_ENDPOINT_DEV', 'https://www-medra-dev.medra.org/servlet/ws/CRProxy');
define('MEDRA_WS_ENDPOINT', 'https://www.medra.org/servlet/ws/medraWS');
define('MEDRA2CR_WS_ENDPOINT', 'https://www.medra.org/servlet/ws/CRProxy');
define('MEDRA_WS_RESPONSE_OK', 200);

class MedraWebservice {

	/** @var array HTTP authentication credentials. */
	var $_auth;

	/** @var string The mEDRA web service endpoint. */
	var $_endpoint;


	/**
	 * Constructor
	 * @param $endpoint string The mEDRA web service endpoint.
	 * @param $login string
	 * @param $password string
	 */
	function __construct($endpoint, $login, $password) {
		$this->_endpoint = $endpoint;
		$this->_auth = [$login, $password];
	}


	//
	// Public Web Service Actions
	//
	/**
	 * mEDRA upload operation.
	 * @param $xml String
	 * @return boolean|string True for success, an error message otherwise.
	*/
	function upload($xml) {
		$attachmentId = $this->_getContentId('metadata');
		$attachment = array($attachmentId => $xml);
		$arg = "<med:contentID href=\"$attachmentId\" />";
		return $this->_doRequest('upload', $arg, $attachment);
	}

	/**
	 * mEDRA deposit operation, includes the deposit to Crossref.
	 * @param $xml String
	 * @param $lang String
	 * @param $accessMode String
	 * @return boolean|string True for success, an error message otherwise.
	 */
	function deposit($xml, $lang = 'eng', $accessMode = '01') {
		$attachmentId = $this->_getContentId('metadata');
		$attachment = array($attachmentId => $xml);
		$arg = "<med:accessMode>" . $accessMode . "</med:accessMode>" .
			"<med:language>" .$lang . "</med:language>" .
			"<med:contentID>" . $attachmentId . "</med:contentID>";
		return $this->_doRequest('deposit', $arg, $attachment);
	}

	/**
	 * mEDRA viewMetadata operation
	 */
	function viewMetadata($doi) {
		$doi = $this->_escapeXmlEntities($doi);
		$arg = "<med:doi>$doi</med:doi>";
		return $this->_doRequest('viewMetadata', $arg);
	}


	//
	// Internal helper methods.
	//
	/**
	 * Do the actual web service request.
	 * @param $action string
	 * @param $arg string
	 * @param $attachment array
	 * @return boolean|string True for success, an error message otherwise.
	 */
	function _doRequest($action, $arg, $attachment = null) {
		// Build the multipart SOAP message from scratch.
		$soapMessage =
			'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" ' .
					'xmlns:med="http://www.medra.org">' .
				'<SOAP-ENV:Header/>' .
				'<SOAP-ENV:Body>' .
					"<med:$action>$arg</med:$action>" .
				'</SOAP-ENV:Body>' .
			'</SOAP-ENV:Envelope>';

		$soapMessageId = $this->_getContentId($action);
		if ($attachment) {
			assert(count($attachment) == 1);
			$request =
				"--MIME_boundary\r\n" .
				$this->_getMimePart($soapMessageId, $soapMessage) .
				"--MIME_boundary\r\n" .
				$this->_getMimePart(key($attachment), current($attachment)) .
				"--MIME_boundary--\r\n";
			$contentType = 'multipart/related; type="text/xml"; boundary="MIME_boundary"';
		} else {
			$request = $soapMessage;
			$contentType = 'text/xml';
		}

		$httpClient = Application::get()->getHttpClient();
		$result = true;
		$document = new DOMDocument();
		// Make SOAP request.
		try {
			$response = $httpClient->request('POST', $this->_endpoint, [
				'auth' => $this->_auth,
				'headers' => [
					'SOAPAction' => $action,
					'Content-Type' => $contentType,
					'UserAgent' => 'OJS-mEDRA',
				],
				'body' => $request,
			]);
		} catch (GuzzleHttp\Exception\RequestException $e) {
			$result = $e->getMessage();
			if ($e->hasResponse()) {
				$exceptionResponseContent = $e->getResponse()->getBody()->getContents();
				$result = $exceptionResponseContent;
				$document->loadXml($exceptionResponseContent);
				$faultstring = $document->getElementsByTagName('faultstring');
				if ($faultstring->length > 0) {
					$result = 'mEDRA: ' . $e->getResponse()->getStatusCode() . ' - ' . $faultstring->item(0)->textContent;
				}
			}
			return $result;
		}

		if ($result === true && ($status = $response->getStatusCode()) != MEDRA_WS_RESPONSE_OK) {
			$result = 'OJS-mEDRA: Expected ' . MEDRA_WS_RESPONSE_OK . ' response code, got ' . $status . ' instead.';
		} elseif ($result === true) {
			$responseContent = $response->getBody()->getContents();
			if (!$attachment && $action == 'viewMetadata') {
				$parts = explode("\r\n\r\n", $responseContent);
				$result = array_pop($parts);
				$result = PKPString::regexp_replace('/>[^>]*$/', '>', $result);
			} else {
				$document->loadXml($responseContent);
				$returnCode = $document->getElementsByTagName('returnCode');
				$statusCode = $document->getElementsByTagName('statusCode');
				if (($returnCode->length > 0 && $returnCode->item(0)->textContent != 'success') ||
					($statusCode->length > 0 && $statusCode->item(0)->textContent != 'SUCCESS')) {
						$result = $responseContent;
				}
			}
		}
		return $result;
	}

	/**
	 * Create a mime part with the given content.
	 * @param $contentId string
	 * @param $content string
	 * @return string
	 */
	function _getMimePart($contentId, $content) {
		return
			"Content-Type: text/xml; charset=utf-8\r\n" .
			"Content-ID: <${contentId}>\r\n" .
			"\r\n" .
			$content . "\r\n";
	}

	/**
	 * Create a globally unique MIME content ID.
	 * @param $prefix string
	 * @return string
	 */
	function _getContentId($prefix) {
		return $prefix . md5(uniqid()) . '@medra.org';
	}

	/**
	 * Escape XML entities.
	 * @param $string string
	 */
	function _escapeXmlEntities($string) {
		return XMLNode::xmlentities($string);
	}
}
