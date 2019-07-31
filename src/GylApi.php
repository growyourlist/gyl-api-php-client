<?php

/**
 * PHP Client for the growyourlist API.
 */
class GylApi {

  /**
   * The API key to use when making requests to the API.
   */
	private $apiKey = '';

  /**
   * The base URL of the admin part of the API.
   */
	private $apiUrl = '';

	function __construct($apiKey, $apiUrl) {
    $this->apiKey = $apiKey;
		$this->apiUrl = $apiUrl . ((substr($apiUrl, -1) !== '/') ? '/' : '');
	}

	/**
	 * Creates a new subscriber with the given data. Options can include:
   * ['trigger' => ['type' => '<type>','id' => '<id>']]
	 * @throws Exception
	 */
	function postSubscriber($subscriberData, $options = null) {
		$this->validateSubscriber($subscriberData);
		$subscriberFull = array_merge([
			'deliveryTimePreference' => $this->generateDtp($subscriberData),
			'tags' => [],
		], $subscriberData);

		// Pass on trigger options to the API if they exist.
		$params = '';
		if ($options && isset($options['trigger'], $options['trigger']['type'])) {
			$trigger = $options['trigger'];
			$triggerType = $trigger['type'];
			if (!empty($trigger['id'])) {
				$params = '?triggerType=' . urlencode($triggerType) . '&triggerId='
				. urlencode($trigger['id']);
			}
		}
		return $this->_postRequest("subscriber$params", (object)$subscriberFull);
	}

	/**
	 * Triggers an autoresponder for the given subscriber.
	 */
	function triggerAutoresponder($subscriberData, $options) {
		$trigger = $options['trigger'];
		$triggerType = $trigger['type'];
		$params = '?triggerType=' . urlencode($triggerType) . '&triggerId='
		. urlencode($trigger['id']);
		$url = "subscriber/trigger-autoresponder$params";
		return $this->_postRequest(
			"subscriber/trigger-autoresponder$params",
			(object)$subscriberData
		);
	}

	/**
	 * Updates an existing subscriber.
	 * @throws Exception
	 */
	function updateSubscriber($subscriberData) {
		$this->validateSubscriber($subscriberData);
		$this->_putRequest("subscriber", (object)$subscriberData);
	}

	/**
	 * Gets a subscriber's status by email.
	 * @throws Exception
	 */
	function getSubscriberStatus($email, $opts = []) {
		if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new Error('Valid email required for subscriber retrieval');
		}
		$encodedEmail = urlencode($email);
		try {
			$response = $this->_getRequest("subscriber/status?email=$encodedEmail");
			return json_decode($response);
		}
		catch (Exception $ex) {
			if ($ex->getCode() === 404 && array_key_exists('onNotFound', $opts) &&
					$opts['onNotFound'] !== 'error') {
				return $opts['onNotFound'];
			}
			throw $ex;
		}
	}

	/**
	 * Changes an email address for a subscriber. Returns FALSE if the subscriber
	 * could not be found.
	 * @throws Exception
	 */
	function changeEmail($oldEmail, $newEmail) {
		$gylStatus = $this->getSubscriberStatus($oldEmail, ['onNotFound' => null]);
		if (!$gylStatus) {
			return FALSE;
		}
		$encodedEmail = urlencode($email);
		return $this->_postRequest("subscriber/change-email", [
			'oldEmail' => $oldEmail,
			'newEmail' => $newEmail
		]);
	}

	/**
	 * Gets a full subscriber or returns the $fallback if no subscriber is found.
	 * @throws Exception
	 */
	function getSubscriber($email, $fallback = null) {
		$encodedEmail = urlencode($email);
		$subscriber = null;
		try {
			$response = $this->_getRequest("subscriber?email=$encodedEmail");
			$subscriber = json_decode($response);
		}
		catch (Exception $ex) {
			if ($ex->getCode() === 404) {
				return $fallback;
			}
			throw $ex;
		}
	}

	/**
	 * Returns truthy value (i.e. subscriber status) if and only if the email
   * exists as a subscriber and that subscriber has the given tag. Returns false
   * in all other cases, including errors! Probably don't use in production.
	 * For example, as part of the following logic:
	 *
	 *   $gylStatus = $gylApi->getExistsAndHasTag('person@example.com', 'a-tag');
	 *   if ($gylStatus) {
	 *     // Perform further actions in GYL system.
	 *   }
	 *   else {
	 *     // Fall back to old mail system.
	 *   }
	 *
	 */
	function getExistsAndHasTag($email, $tag) {
		try {
			$status = $this->getSubscriberStatus($email);
			if (isset($status->tags) && in_array($tag, $status->tags)) {
				return $status;
			}
			return false;
		}
		catch (Exception $ex) {
			return false;
		}
	}

	/**
	 * Deletes a subscriber by subscriberId.
	 * @throws Exception
	 */
	function deleteSubscriber($subscriberId) {
		return $this->_deleteRequest("subscriber?subscriberId=$subscriberId");
	}

	/**
	 * Unsubscribes a subscriber. Requires an array with a key 'email'.
	 * @throws Exception
	 */
	function unsubscribe($subscriberData) {
		$this->validateUnsubscribe($subscriberData);
		return $this->_postRequest(
			'subscriber/unsubscribe',
			(object)$subscriberData
		);
	}

	/**
	 * Tags a subscriber. Requires an array with keys 'email' and 'tag'.
	 */
	function tag($subscriberData) {
		$this->validateTag($subscriberData);
		return $this->_postRequest('subscriber/tag', (object)$subscriberData);
	}

	/**
	 * Untags a subscriber. Requires an array with keys 'email' and 'tag'.
	 * @throws Exception
	 */
	function untag($subscriberData) {
		$this->validateUntag($subscriberData);
		return $this->_postRequest('subscriber/untag', (object)$subscriberData);
	}

	/**
	 * Validates subscriber data.
	 * @throws Exception
	 */
	protected function validateSubscriber($subscriberData) {
		if (empty($subscriberData['email'])
			|| !filter_var($subscriberData['email'], FILTER_VALIDATE_EMAIL)) {
			throw new Exception('Subscriber must have a valid email');
		}
		if (isset($subscriberData['tags']) && !is_array($subscriberData['tags'])) {
			throw new Exception('If tags is provided, it must be an array');
		}
	}
	
	/**
	 * Validates data required for unsubscribe.
	 */
	protected function validateUnsubscribe($subscriberData) {
		if (empty($subscriberData['email'])
			|| !filter_var($subscriberData['email'], FILTER_VALIDATE_EMAIL)) {
			throw new Exception('Subscriber must have a valid email');
		}
	}

	/**
	 * Validates data required for tag.
	 */
	protected function validateTag($subscriberData) {
		if (empty($subscriberData['email'])
			|| !filter_var($subscriberData['email'], FILTER_VALIDATE_EMAIL)) {
			throw new Exception('Subscriber must have a valid email');
		}
		if (empty($subscriberData['tag'])) {
			throw new Exception('A tag name must be provided');
		}
	}

	/**
	 * Validates data required for untag.
	 */
	protected function validateUntag($subscriberData) {
		if (empty($subscriberData['email'])
			|| !filter_var($subscriberData['email'], FILTER_VALIDATE_EMAIL)) {
			throw new Exception('Subscriber must have a valid email');
		}
		if (empty($subscriberData['tag'])
			|| !ctype_alnum($subscriberData['tag'])
			|| (strlen($subscriberData['tag']) > 26)) {
			throw new Exception('A valid tag name must be provided');
		}
	}

	/**
	 * Generates a delivery time preference for the subscriber based on the
	 * timezone of the subscriber and the current time.
	 */
	protected function generateDtp($subscriberData) {
		$now = new DateTime();
		try {
			if (!empty($subscriberData['timezone'])) {
				$now->setTimezone(new DateTimeZone($subscriberData['timezone']));
			}
			else {
				$now->setTimezone(new DateTimeZone('America/Vancouver'));
			}
		}
		catch (Exception $ex) {
			error_log('GYL: error setting timezone ' . $timezone);
		}

		return (object)[
			'hour' => (int)$now->format('G'),
			'minute' => (int)$now->format('i')
		];
	}

	/**
	 * Performs a HTTP post request to the API.
	 */
	private function _postRequest($endpoint, $bodyObject) {
		return $this->_doRequest($endpoint, 'POST', $bodyObject);
	}

	/**
	 * Performs a HTTP put request to the API.
	 */
	private function _putRequest($endpoint, $bodyObject) {
		return $this->_doRequest($endpoint, 'PUT', $bodyObject);
	}

	/**
	 * Performs a HTTP get request to the API.
	 */
	private function _getRequest($endpoint) {
		return $this->_doRequest($endpoint);
	}
	
	/**
	 * Performs a HTTP delete request to the API.
	 */
	private function _deleteRequest($endpoint) {
		return $this->_doRequest($endpoint, 'DELETE');
	}

	/**
	 * Performs a HTTP request to the API.
	 */
	private function _doRequest($endpoint, $method = 'GET', $bodyObject = null) {
		$url = $this->apiUrl . $endpoint;
		$contentType = '';
		if ($method === 'PUT' || $method === 'POST') {
			$contentType = 'application/json';
		}
		$params = [
			'http' => [
        'header'  => $this->_generateHeader($contentType),
        'method'  => $method,
				'timeout' => 2,
				'ignore_errors' => true,
			]
		];
		if (($method === 'POST' || $method === 'PUT') && $bodyObject !== null) {
			$params['http']['content'] = json_encode($bodyObject);
		}
		$context = stream_context_create($params);
		$response = file_get_contents($url, false, $context);
		$responseStatus = (isset($http_response_header, $http_response_header[0])) ?
			$http_response_header[0] : null;
		if (!$responseStatus) {
			throw new Exception('Failed to interact with API: could not connect.');
		}
		if (!preg_match('/\b\d\d\d\b/', $responseStatus, $matches)) {
			throw new Exception('Failed to interact with API: no response code.');
		}
		$responseCode = (int)$matches[0];
		if ($responseCode < 200 || $responseCode >= 400) {
			throw new Exception($response, $responseCode);
		}
		return $response;
	}

	/**
	 * Generates the header for requests to the API.
	 */
	private function _generateHeader($contentType = '') {
		$header = '';
		if ($contentType) {
			$header .= "Content-type: $contentType\r\n";
		}
		$header .= 'x-api-key: ' . $this->apiKey . "\r\n";
		return $header;
	}
}
