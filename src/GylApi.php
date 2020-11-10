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
	public function __construct($apiKey = '', $apiUrl = '')
	{
		$this->apiKey = $apiKey;
		$this->apiUrl = ((substr($apiUrl, -1) === '/') ? substr($apiUrl, 0, strlen($apiUrl) - 1) : $apiUrl);
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
	 * Example call (template email):
	 * ```
	 * try {
	 *   $gylApi->sendSingleEmail(
	 *     [
	 *       'toEmailAddress' => 'test@e.example.com',
	 *       'templateId' => 'templateId'
	 *     ],
	 *     [
	 *       'fromEmailAddress' => 'me@test.localhost', // optional source email address. Default: source email address of list.
	 *       'waitInSeconds' => 60, // optional delay. Default: no delay.
	 *       'tagReason' => 'list-default', // optional tag. Default: no tag.
	 *       'autoSaveUnknownSubscriber' => false // optional flag indicating if the subscirber should be saved. Defaults to false.
	 *     ]
	 *   );
	 * }
	 * catch (Exception $ex) {
	 *   // Handle exceptions here (all failures: including validation and server)
	 * }
	 * ```
	 * Example call (text/html email):
	 * ```
	 * try {
	 *   $gylApi->sendSingleEmail(
	 *     [
	 *       'toEmailAddress' => 'test@e.example.com',
	 *       'subject' => 'Test email',
	 *       'body' => [
	 *         'text' => 'Test',
	 *         'html' => '<strong>Test</strong>'
	 *       ]
	 *     ],
	 *     [
	 *       'fromEmailAddress' => 'me@test.localhost',
	 *       'waitInSeconds' => 60,
	 *       'tagReason' => 'list-default',
	 *       'autoSaveUnknownSubscriber' => false,
	 *     ]
	 *   );
	 * }
	 * catch (Exception $ex) {
	 *   // Handle exceptions here (all failures: including validation and server)
	 * }
	 * ```
	 *
	 * @param object|array $emailData The email data in the form (array or object): [
	 *   'toEmailAddress' => 'test@e.example.com',
	 *   'templateId' => 'ExampleTemplate', // If body are subject are not given.
	 *   'subject' => 'Example email', // If templateId is not given.
	 *   'body' => [ // If templateId is not given.
	 *      'html' => '<div>Email html...</div>',
	 *      'text' => 'Text version of email'
	 *   ]
	 * ]
	 * @param object|array $opts The options (all optional) to use when sending the email in the form (array or object): [
	 *    'fromEmailAddress' => '', // Source email address in form "My Email <my@e.example.com>". Defaults to GYL default source email.
	 *    'waitInSeconds' => 0, // Time in seconds to delay the email. Defaults to 0.
	 *    'tagOnClick' => '', // Tag to assign to subscirber if they click the email. Defaults to undefined.
	 *    'tagReason' => '', // Tag identifying the reason this email is in the queue (i.e. will be removed from the queue if the subscriber unsubscribers from that tag).
	 * ]
	 * @param string $subject The subject line of the email.
	 * @param mixed $body The content of the email. See notes for options.
	 * @param string $opts Optional. The email address to send from.
	 */
	public function sendSingleEmail($emailData, $opts = [])
	{
		// Force the send settings into standard shape.
		$emailDataObj = is_object($emailData) ? $emailData : ((object) $emailData);
		$optsObj = is_object($opts) ? $opts : ((object) $opts);
		if (isset($emailDataObj->body)) {
			$emailDataObj->body = is_object($emailDataObj->body) ? $emailDataObj->body : ((object) $emailDataObj->body);
		}

		// Validate the values of the send (basically a type check)
		$this->validateSendSingleEmail($emailDataObj, $optsObj);

		// Post the single email send to the GYL API.
		$emailDataObj->opts = $optsObj;
		return $this->_postRequest("/single-email-send", $emailDataObj);
	}

	/**
	 * Creates a new subscriber with the given data. Options can include:
	 * ['trigger' => ['type' => '<type>','id' => '<id>']]
	 * @throws Exception
	 */
	public function postSubscriber($subscriberData, $options = null)
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
		return $this->_postRequest("/subscriber$params", (object) $subscriberFull);
	}

	/**
	 * Triggers an autoresponder for the given subscriber.
	 */
	public function triggerAutoresponder($subscriberData, $options)
	{
		$trigger = $options['trigger'];
		$triggerType = $trigger['type'];
		$params = '?triggerType=' . urlencode($triggerType) . '&triggerId='
		. urlencode($trigger['id']);
		$url = "subscriber/trigger-autoresponder$params";
		return $this->_postRequest(
			"/subscriber/trigger-autoresponder$params",
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
	public function getSubscriberStatus($email, $opts = [])
	{
		if (empty($email) || (strlen($email) > 256)) {
			throw new Exception('Valid email required for subscriber retrieval');
		}
		$encodedEmail = urlencode($email);
		try {
			$response = $this->_getRequest("/subscriber/status?email=$encodedEmail");
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
	public function getSubscriber($email, $fallback = null)
	{
		$encodedEmail = urlencode($email);
		$subscriber = null;
		try {
			$response = $this->_getRequest("/subscriber?email=$encodedEmail");
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
	public function getExistsAndHasTag($email, $tag)
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
	public function deleteSubscriber($subscriberId)
	{
		return $this->_deleteRequest("/subscriber?subscriberId=$subscriberId");
	}

	/**
	 * Unsubscribes a subscriber. Requires an array with a key 'email'.
	 * @throws Exception
	 */
	public function unsubscribe($subscriberData)
	{
		$this->validateUnsubscribe($subscriberData);
		return $this->_postRequest(
			'/subscriber/unsubscribe',
			(object) $subscriberData
		);
	}

	/**
	 * Tags a subscriber. Requires an array with keys 'email' and 'tag'.
	 * Optional $opts must be an array with values:
	 * [ 'ignoreSubscriberNotFound' => boolean ]
	 * If ignoreSubscriberNotFound is truthy, then no error will be thrown if
	 * the subscriber is not found.
	 */
	public function tag($subscriberData, $opts = [])
	{
		$fullOpts = array_merge(
			[
				'ignoreSubscriberNotFound' => false
			],
			$opts
		);
		$ignoreNotFound = !!$fullOpts['ignoreSubscriberNotFound'];
		$this->validateTag($subscriberData);
		$params = '';
		if ($opts && isset($opts['trigger'], $opts['trigger']['type'])) {
			$trigger = $opts['trigger'];
			$triggerType = $trigger['type'];
			if (!empty($trigger['id'])) {
				$params = '?triggerType=' . urlencode($triggerType) . '&triggerId='
				. urlencode($trigger['id']);
			}
		}
		try {
			return $this->_postRequest("/subscriber/tag$params", (object) $subscriberData);
		} catch (Exception $ex) {
			if ($ex->getCode() === 404 && array_key_exists('onNotFound', $fullOpts) &&
				$opts['onNotFound'] !== 'error') {
				return $opts['onNotFound'];
			}
			throw $ex;
		}
	}

	/**
	 * Untags a subscriber. Requires an array with keys 'email' and 'tag'.
	 * @throws Exception
	 */
	public function untag($subscriberData)
	{
		$this->validateUntag($subscriberData);
		return $this->_postRequest('/subscriber/untag', (object) $subscriberData);
	}

	/**
	 * Validates the arguments sent to the send single email function.
	 *
	 * @param object $emailDataObj
	 * @param object $optsObj
	 * @return void
	 * @throws Exception
	 */
	protected function validateSendSingleEmail($emailDataObj, $optsObj) {
		if (!isset($emailDataObj->toEmailAddress) || !is_string($emailDataObj->toEmailAddress)) {
			throw new Exception('$emailData->toEmailAddress must be a string');
		}
		if (empty($emailDataObj->templateId)) {
			// Validate the sending of a text/html email
			if (!is_string($emailDataObj->subject)) {
				throw new Exception('$emailData->subject must be a string when templateId is not given');
			}
			if (!is_object($emailDataObj->body)) {
				throw new Exception('$emailData->body must be an array or object when templateId is not given');
			}
			foreach (get_object_vars($emailDataObj->body) as $key => $value) {
				if (!(($key === 'html') || ($key === 'text'))) {
					throw new Exception('Only "html" and "text" allow on $emailData->body');
				} else if (!is_string($value)) {
					throw new Exception("Value of $key must be a string");
				}
			}
		} else {
			// Validate the sending of a template email
			if (!is_string($emailDataObj->templateId)) {
				throw new Exception('$opts->templateId must be a string if it is given');
			}
		}
		if (!is_object($optsObj)) {
			throw new Exception('$opts must be an array or object');
		}
		if (isset($optsObj->fromEmailAddress) && !is_string($optsObj->fromEmailAddress)) {
			throw new Exception('$opts->fromEmailAddress must be a string if it is given');
		}
		if (isset($optsObj->waitInSeconds) && !is_int($optsObj->waitInSeconds)) {
			throw new Exception('$opts->waitInSeconds must be an integer if it is given');
		}
		if (isset($optsObj->tagOnClick) && !is_string($optsObj->tagOnClick)) {
			throw new Exception('$opts->tagOnClick must be a tag string if it is given.');
		}
		if (isset($optsObj->tagReason)) {
			if (!(is_array($optsObj->tagReason) || is_string($optsObj->tagReason))) {
				throw new Exception('$opts->tagReason must be a string or array');
			}
			if (is_array($optsObj->tagReason)) {
				foreach ($optsObj->tagReason as $tag) {
					if (!is_string($tag)) {
						throw new Exception('All tags in $opts->tagReason array must be strings');
					}
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
			|| !ctype_alnum(str_replace(["-", "_"], "", $subscriberData['tag']))
			|| (strlen($subscriberData['tag']) > 64)
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
				'header' => $this->_generateHeader($contentType),
				'method' => $method,
				'timeout' => 5,
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
		$header .= 'X-Gyl-Auth-Key: ' . $this->apiKey . "\r\n";
		return $header;
	}
}
