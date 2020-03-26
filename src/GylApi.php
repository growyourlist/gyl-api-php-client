<?php

/**
 * PHP Client for the growyourlist API.
 */
class GylApi
{

	/**
	 * The API key to use when making requests to the API.
	 */
	private $apiKey = '';

	/**
	 * The base URL of the admin part of the API.
	 */
	private $apiUrl = '';

	/**
	 * Constructs a new instance of the GylApi, setting the given api key
	 * and url.
	 *
	 * @param string $apiKey
	 * @param string $apiUrl
	 */
	function __construct($apiKey = '', $apiUrl = '')
	{
		$this->apiKey = $apiKey;
		$this->apiUrl = $apiUrl . ((substr($apiUrl, -1) !== '/') ? '/' : '');
	}

	/**
	 * Sends a single email to a single recipient.
	 * 
	 * The $toEmailAddress and $fromEmailAddress can be basic, single email
	 * addresses like "test@1example.org" or they can include a name and be in
	 * the format: "Test Person <test@1example.org>".
	 * 
	 * The body is an array with two optional keys: 'text' and 'html'. It may also
	 * be an object with two properties of the same names. The value of the 'text'
	 * property should be a basic text version of the email. The value of the
	 * 'html' property should be a html version of the email.
	 * 
	 * Options (all optional) include:
	 *   - fromEmailAddress: the source email address the email should be sent
	 *     from. If setting a $fromEmailAddress, it must be validated in SES.
	 *   - waitInSeconds: number of seconds to wait between queuing the email and
	 *     sending the email.
	 *   - tagReason: an array of 1 or more tags that make up the reason why the
	 *     email was sent (so if a subscriber unsubscribes from a tag later, all
	 *     queued items containing this tag in tagReason can be removed).
	 * 
	 * Example call:
	 * ```
	 * try {
	 *   sendSingleEmail(
	 *     'test@test.localhost',
	 *     'Test',
	 *     [
	 *       'text' => 'Test',
	 *       'html' => '<strong>Test</strong>',
	 * 	   ],
	 *     [
	 *       'fromEmailAddress' => 'me@test.localhost',
	 *       'waitInSeconds' => 60,
	 *       'tagReason' => 'list-default',
	 *     ]
	 *   ]);
	 * }
	 * catch (Exception $ex) {
	 *   // Handle exceptions here (all failures: including validation and server)
	 * }
	 * ```
	 * 
	 * @param string $toEmailAddress The email address to send the email to.
	 * @param string $subject The subject line of the email.
	 * @param mixed $body The content of the email. See notes for options.
	 * @param string $opts Optional. The email address to send from.
	 */
	function sendSingleEmail($toEmailAddress, $subject, $body, $opts = [])
	{
		// Force the send settings into standard shape.
		$bodyObject = is_array($body) ? ((object) $body) : $body;
		$optsObject = is_array($opts) ? ((object) $opts) : $opts;
		if (isset($opts['tagReason']) && is_string($opts['tagReason'])) {
			$opts['tagReason'] = [$opts['tagReason']];
		}

		// Validate the values of the send (basically a type check)
		$this->validateSendSingleEmail(
			$toEmailAddress, $subject, $bodyObject, $opts
		);

		// Post the single email send to the GYL API.
		return $this->_postRequest("send-single-email", (object) [
			'toEmailAddress' => $toEmailAddress,
			'subject' => $subject,
			'body' => $bodyObject,
			'opts' => $optsObject
		]);
	} 

	/**
	 * Creates a new subscriber with the given data. Options can include:
	 * ['trigger' => ['type' => '<type>','id' => '<id>']]
	 * @throws Exception
	 */
	function postSubscriber($subscriberData, $options = null)
	{
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
		return $this->_postRequest("subscriber$params", (object) $subscriberFull);
	}

	/**
	 * Triggers an autoresponder for the given subscriber.
	 */
	function triggerAutoresponder($subscriberData, $options)
	{
		$trigger = $options['trigger'];
		$triggerType = $trigger['type'];
		$params = '?triggerType=' . urlencode($triggerType) . '&triggerId='
			. urlencode($trigger['id']);
		$url = "subscriber/trigger-autoresponder$params";
		return $this->_postRequest(
			"subscriber/trigger-autoresponder$params",
			(object) $subscriberData
		);
	}

	/**
	 * Gets a subscriber's status by email. The status is in the shape:
	 * {
	 *   subscriberId: "string",
	 *   email: "string", // (lowercase, used for comparisons)
	 *   displayEmail: "string", // (original casing, used when sending)
	 *   tag: ["string1", "string2", "string3"],
	 *   confirmed: boolean,
	 *   unsubscribed: boolean,
	 * }
	 * @throws Exception
	 */
	function getSubscriberStatus($email, $opts = [])
	{
		if (empty($email) || !(strlen($email) > 256)) {
			throw new Exception('Valid email required for subscriber retrieval');
		}
		$encodedEmail = urlencode($email);
		try {
			$response = $this->_getRequest("subscriber/status?email=$encodedEmail");
			return json_decode($response);
		} catch (Exception $ex) {
			if (
				$ex->getCode() === 404 && array_key_exists('onNotFound', $opts) &&
				$opts['onNotFound'] !== 'error'
			) {
				return $opts['onNotFound'];
			}
			throw $ex;
		}
	}

	/**
	 * Gets a full subscriber or returns the $fallback if no subscriber is found.
	 * @throws Exception
	 */
	function getSubscriber($email, $fallback = null)
	{
		$encodedEmail = urlencode($email);
		$subscriber = null;
		try {
			$response = $this->_getRequest("subscriber?email=$encodedEmail");
			$subscriber = json_decode($response);
		} catch (Exception $ex) {
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
	 *   $gylStatus = $gylApi->getExistsAndHasTag('person@test.com', 'a-tag');
	 *   if ($gylStatus) {
	 *     // Perform further actions in GYL system.
	 *   }
	 *   else {
	 *     // Fall back to old mail system.
	 *   }
	 *
	 */
	function getExistsAndHasTag($email, $tag)
	{
		try {
			$status = $this->getSubscriberStatus($email);
			if (isset($status->tags) && in_array($tag, $status->tags)) {
				return $status;
			}
			return false;
		} catch (Exception $ex) {
			return false;
		}
	}

	/**
	 * Deletes a subscriber by subscriberId.
	 * @throws Exception
	 */
	function deleteSubscriber($subscriberId)
	{
		return $this->_deleteRequest("subscriber?subscriberId=$subscriberId");
	}

	/**
	 * Unsubscribes a subscriber. Requires an array with a key 'email'.
	 * @throws Exception
	 */
	function unsubscribe($subscriberData)
	{
		$this->validateUnsubscribe($subscriberData);
		return $this->_postRequest(
			'subscriber/unsubscribe',
			(object) $subscriberData
		);
	}

	/**
	 * Tags a subscriber. Requires an array with keys 'email' and 'tag'.
	 */
	function tag($subscriberData)
	{
		$this->validateTag($subscriberData);
		return $this->_postRequest('subscriber/tag', (object) $subscriberData);
	}

	/**
	 * Untags a subscriber. Requires an array with keys 'email' and 'tag'.
	 * @throws Exception
	 */
	function untag($subscriberData)
	{
		$this->validateUntag($subscriberData);
		return $this->_postRequest('subscriber/untag', (object) $subscriberData);
	}

	/**
	 * Validates the arguments sent to the send single email function.
	 * 
	 * @param string $toEmailAddress
	 * @param string $subject
	 * @param object $body
	 * @param object $opts
	 * @return void
	 * @throws Exception
	 */
	protected function validateSendSingleEmail(
		$toEmailAddress, $subject, $body, $opts
	) {
		if (!is_string($toEmailAddress)) {
			throw new Exception("\$toEmailAddress must be a string");
		}
		if (!is_string($subject)) {
			throw new Exception("\$subject must be a string");
		}
		if (!is_object($body)) {
			throw new Exception("\$body must be an array or object");
		}
		foreach (get_object_vars($bodyObject) as $key => $value) {
			if (!(($key === 'html') || ($key === 'text'))) {
				throw new Exception("Only 'html' and 'text' keys allowed");
			}
			else if (!is_string($value)) {
				throw new Exception("Value of $key must be a string");
			}
		}
		if (!is_object($opts)) {
			throw new Exception("\$opts must be an array or object");
		}
		if (isset($opts['fromEmailAddress']) && !is_string($opts['fromEmailAddress'])) {
			throw new Exception("\$opts['fromEmailAddress'] must be a string");
		}
		if (isset($opts['waitInSeconds']) && !is_int($opts['waitInSeconds'])) {
			throw new Exception("\$opts['waitInSeconds'] must be an integer");
		}
		if (isset($opts['tagReason'])) {
			if (!is_array($opts['tagReason'])) {
				throw new Exception("\$opts['tagReason'] must be a string or array");
			}
			foreach ($opts['tagReason'] as $tag) {
				if (!is_string($tag)) {
					throw new Exception("All tags in \$opts['tagReason'] array must be strings");
				}
			}
		}
	}

	/**
	 * Validates subscriber data.
	 * @throws Exception
	 */
	protected function validateSubscriber($subscriberData)
	{
		if (
			empty($subscriberData['email'])
			|| !filter_var($subscriberData['email'], FILTER_VALIDATE_EMAIL)
		) {
			throw new Exception('Subscriber must have a valid email');
		}
		if (isset($subscriberData['tags']) && !is_array($subscriberData['tags'])) {
			throw new Exception('If tags is provided, it must be an array');
		}
	}

	/**
	 * Validates data required for unsubscribe.
	 */
	protected function validateUnsubscribe($subscriberData)
	{
		if (
			empty($subscriberData['email'])
			|| !filter_var($subscriberData['email'], FILTER_VALIDATE_EMAIL)
		) {
			throw new Exception('Subscriber must have a valid email');
		}
	}

	/**
	 * Validates data required for tag.
	 */
	protected function validateTag($subscriberData)
	{
		if (
			empty($subscriberData['email'])
			|| !filter_var($subscriberData['email'], FILTER_VALIDATE_EMAIL)
		) {
			throw new Exception('Subscriber must have a valid email');
		}
		if (empty($subscriberData['tag'])) {
			throw new Exception('A tag name must be provided');
		}
	}

	/**
	 * Validates data required for untag.
	 */
	protected function validateUntag($subscriberData)
	{
		if (
			empty($subscriberData['email'])
			|| !filter_var($subscriberData['email'], FILTER_VALIDATE_EMAIL)
		) {
			throw new Exception('Subscriber must have a valid email');
		}
		if (
			empty($subscriberData['tag'])
			|| !ctype_alnum($subscriberData['tag'])
			|| (strlen($subscriberData['tag']) > 26)
		) {
			throw new Exception('A valid tag name must be provided');
		}
	}

	/**
	 * Generates a delivery time preference for the subscriber based on the
	 * timezone of the subscriber and the current time.
	 */
	protected function generateDtp($subscriberData)
	{
		$now = new DateTime();
		try {
			if (!empty($subscriberData['timezone'])) {
				$now->setTimezone(new DateTimeZone($subscriberData['timezone']));
			} else {
				$now->setTimezone(new DateTimeZone('America/Vancouver'));
			}
		} catch (Exception $ex) {
			error_log('GYL: error setting timezone');
		}

		return (object) [
			'hour' => (int) $now->format('G'),
			'minute' => (int) $now->format('i')
		];
	}

	/**
	 * Performs a HTTP post request to the API.
	 */
	private function _postRequest($endpoint, $bodyObject)
	{
		return $this->_doRequest($endpoint, 'POST', $bodyObject);
	}

	/**
	 * Performs a HTTP put request to the API.
	 */
	private function _putRequest($endpoint, $bodyObject)
	{
		return $this->_doRequest($endpoint, 'PUT', $bodyObject);
	}

	/**
	 * Performs a HTTP get request to the API.
	 */
	private function _getRequest($endpoint)
	{
		return $this->_doRequest($endpoint);
	}

	/**
	 * Performs a HTTP delete request to the API.
	 */
	private function _deleteRequest($endpoint)
	{
		return $this->_doRequest($endpoint, 'DELETE');
	}

	/**
	 * Performs a HTTP request to the API.
	 */
	private function _doRequest($endpoint, $method = 'GET', $bodyObject = null)
	{
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
		$responseCode = (int) $matches[0];
		if ($responseCode < 200 || $responseCode >= 400) {
			throw new Exception($response, $responseCode);
		}
		return $response;
	}

	/**
	 * Generates the header for requests to the API.
	 */
	private function _generateHeader($contentType = '')
	{
		$header = '';
		if ($contentType) {
			$header .= "Content-type: $contentType\r\n";
		}
		$header .= 'x-api-key: ' . $this->apiKey . "\r\n";
		return $header;
	}
}
