<?php
	
	namespace Zoet\Startrack;
	
	use Exception;
	use GuzzleHttp\Client;
	
	/**
	 * Interact with the Australian Post API
	 *
	 * @package Cognito
	 * @author Josh Marshall <josh@jmarshall.com.au>
	 */
	class Startrack
	{
		
		private $api_key = null;
		private $api_password = null;
		private $account_number = null;
		private $test_mode = null;
		private $client = null;
		
		const API_SCHEME = 'https://';
		const API_HOST = 'digitalapi.auspost.com.au';
		const API_PORT = 443;
		const HEADER_EOL = "\r\n";
		
		private $socket; // socket for communicating to the API
		/**
		 * @var \stdClass
		 */
		private $response;
		
		/**
		 *
		 * @param string $api_key The AusPost API Key
		 * @param string $api_password The AusPost API Password
		 * @param string $account_number The AusPost Account number
		 * @param bool $test_mode Whether to use test mode or not
		 */
		public function __construct($api_key, $api_password, $account_number, $test_mode = false)
		{
			$this->api_key = $api_key;
			$this->api_password = $api_password;
			$this->account_number = $account_number;
			$this->test_mode = $test_mode;
			$this->client = new Client([
				'base_uri' => self::API_SCHEME . self::API_HOST . $this->baseUrl()
			]);
		}
		
		/**
		 * Perform a GetAccounts API call.
		 *
		 * @return Account the account data
		 * @throws Exception
		 */
		public function getAccountDetails(): Account
		{
			$headers = $this->buildHttpHeaders('GET', 'accounts/');
			$data = $this->client->request('GET', 'accounts/'. $this->account_number,['headers'=>$headers]);
			
//			$this->sendGetRequest('accounts/' . $this->account_number, [], false);
//			$data = $this->convertResponse($this->getResponse()->data);
//			$this->closeSocket();
			
			return new Account($data);
		}
		
		/**
		 * Get my address from the account details
		 *
		 * @return Address|false
		 * @throws Exception
		 */
		public function getMerchantAddress()
		{
			$account = $this->getAccountDetails();
			foreach ($account->addresses as $address) {
				if ($address->type == 'MERCHANT_LOCATION') {
					return $address;
				}
			}
			return false;
		}
		
		/**
		 * Perform a Prices/Items API call
		 *
		 * @param mixed $data
		 * @return Quote[]
		 * @throws Exception
		 */
		public function getQuotes($input, $urgent): array
		{
			$this->sendPostRequest('prices/shipments', $input);
			$data = $this->convertResponse($this->getResponse()->data);
			$this->closeSocket();
			
			if (array_key_exists('errors', $data)) {
				foreach ($data['errors'] as $error) {
					throw new Exception($error['message']);
				}
			}
			$quotes = [];
			$is_subsequent_item = false;
			foreach ($data['shipments'] as $shipment) {
				if (array_key_exists('errors', $shipment)) {
					foreach ($shipment['errors'] as $error) {
						throw new Exception($error['message']);
					}
				}
				if ($urgent && in_array($shipment['items'][0]['product_id'], ['FPP', 'PRM'])) {
					$quotes[$shipment['items'][0]['product_id']] = $shipment['shipment_summary']['total_cost'];
				} else if (!$urgent) {
					$quotes[$shipment['items'][0]['product_id']] = $shipment['shipment_summary']['total_cost'];
				}
				
				$is_subsequent_item = true;
			}
			asort($quotes, 1);
			return $quotes;
		}
		
		/**
		 * Start a new shipment for lodging or quoting
		 * @return Shipment
		 */
		public function newShipment(): Shipment
		{
			return new Shipment($this);
		}
		
		/**
		 * Perform a Shipments API call
		 *
		 * @param mixed $data
		 * @return array
		 * @throws Exception
		 */
		public function shipments($input)
		{
			$this->sendPostRequest('shipments', $input);
			$data = $this->convertResponse($this->getResponse()->data);
			$this->closeSocket();
			
			if (array_key_exists('errors', $data)) {
				foreach ($data['errors'] as $error) {
					throw new Exception($error['message']);
				}
			}
			
			return $data;
		}
		
		/**
		 * Get all labels for the shipments referenced by id
		 * @param string[] $shipment_ids
		 * @param LabelType $label_type
		 * @return string url to label file
		 * @throws Exception
		 */
		public function getLabels($shipment_ids, $label_type)
		{
			$group_template = [
				'layout' => $label_type->layout_type,
				'branded' => $label_type->branded,
				'left_offset' => $label_type->left_offset,
				'top_offset' => $label_type->top_offset,
			];
			$groups = [];
			foreach ([
				         'Parcel Post',
				         'Express Post',
				         'StarTrack',
				         'Startrack Courier',
				         'On Demand',
				         'International',
				         'Commercial',
			         ] as $group) {
				$groups[] = array_merge($group_template, [
					'group' => $group,
				]);
			}
			
			$shipments = [];
			foreach ($shipment_ids as $shipment_id) {
				$shipments[] = [
					'shipment_id' => $shipment_id,
				];
			}
			
			$request = [
				'wait_for_label_url' => true,
				'preferences' => [
					'type' => 'PRINT',
					'format' => $label_type->format,
					'groups' => $groups,
				],
				'shipments' => $shipments,
			];
			
			$this->sendPostRequest('labels', $request);
			$data = $this->convertResponse($this->getResponse()->data);
			$this->closeSocket();
			
			if (array_key_exists('errors', $data)) {
				foreach ($data['errors'] as $error) {
					throw new Exception($error['message']);
				}
			}
			foreach ($data['labels'] as $label) {
				return $label['url'];
			}
			
			return '';
		}
		
		/**
		 * Create an order and return a manifest
		 * @param string[] $shipment_ids
		 * @return Order
		 */
		public function createOrder($shipment_ids)
		{
			$request = [
				'shipments' => [],
			];
			foreach ($shipment_ids as $shipment_id) {
				$request['shipments'][] = [
					'shipment_id' => $shipment_id,
				];
			}
			
			$this->sendPutRequest('orders', $request);
			$data = $this->convertResponse($this->getResponse()->data);
			$this->closeSocket();
			if (!is_array($data)) {
				return new Order([
					'order_id' => 'None',
					'creation_date' => new \DateTime(),
					'manifest_pdf' => $data,
				]);
			}
			
			if (array_key_exists('errors', $data)) {
				foreach ($data['errors'] as $error) {
					throw new Exception($error['message']);
				}
			}
			
			// Get the url to the manifest pdf
			$this->sendGetRequest('accounts/' . $this->account_number . '/orders/' . $data['order']['order_id'] . '/summary');
			$data['order']['manifest_pdf'] = $this->getResponse()->data;
			$summarydata = $this->convertResponse($data['order']['manifest_pdf']);
			$this->closeSocket();
			
			if (is_array($summarydata) && array_key_exists('errors', $summarydata)) {
				foreach ($summarydata['errors'] as $error) {
					throw new Exception($error['message']);
				}
			}
			
			return new Order($data['order']);
		}
		
		/**
		 * Delete a shipment by id
		 * @param string $shipment_id
		 * @return bool
		 */
		public function deleteShipment($shipment_id)
		{
			$this->sendDeleteRequest('shipments/' . $shipment_id, null);
			$data = $this->convertResponse($this->getResponse()->data);
			$this->closeSocket();
			
			if (is_array($data) && array_key_exists('errors', $data)) {
				foreach ($data['errors'] as $error) {
					throw new Exception($error['message']);
				}
			}
			
			return true;
		}
		
		/**
		 * Get the base URL for the api connection
		 *
		 * @return string
		 */
		private function baseUrl()
		{
			if ($this->test_mode) {
				return '/test/shipping/v1/';
			}
			return '/shipping/v1/';
		}
		
		/**
		 * Creates a socket connection to the API.
		 *
		 * @throws Exception if the socket cannot be opened
		 */
		private function createSocket()
		{
			$this->socket = fsockopen(
				self::API_SCHEME . self::API_HOST,
				self::API_PORT,
				$errno,
				$errstr,
				15
			);
			if ($this->socket === false) {
				throw new Exception('Could not connect to Australia Post API: ' . $errstr, $errno);
			}
		}
		
		/**
		 * Builds the HTTP request headers.
		 *
		 * @param string $request_type GET/POST/HEAD/DELETE/PUT
		 * @param string $action the API action component of the URI
		 * @param int $content_length if true, content is included in the request
		 * @param bool $include_account if true, include the account number in the header
		 *
		 * @return array each element is a header line
		 */
		private function buildHttpHeaders($request_type, $action, $content_length = 0, $include_account = false)
		{
			$a_headers = array();
			$a_headers[] = $request_type . ' ' . $this->baseUrl() . $action . ' HTTP/1.1';
			$a_headers[] = 'Authorization: ' . 'Basic ' . base64_encode($this->api_key . ':' . $this->api_password);
			$a_headers[] = 'Host: ' . self::API_HOST;
			if ($content_length) {
				$a_headers[] = 'Content-Type: application/json';
				$a_headers[] = 'Content-Length: ' . $content_length;
			}
			$a_headers[] = 'Accept: */*';
			if ($include_account) {
				$a_headers[] = 'Account-Number: ' . $this->account_number;
			}
			$a_headers[] = 'Cache-Control: no-cache';
			$a_headers[] = 'Connection: close';
			return $a_headers;
		}
		
		/**
		 * Sends an HTTP GET request to the API.
		 *
		 * @param string $action the API action component of the URI
		 *
		 * @throws Exception on error
		 */
		private function sendGetRequest($action, $data = [], $include_account = true)
		{
			return $this->sendRequest($action, $data, 'GET', $include_account);
		}
		
		/**
		 * Sends an HTTP POST request to the API.
		 *
		 * @param string $action the API action component of the URI
		 * @param array $data assoc array containing the data to send
		 *
		 * @throws Exception on error
		 */
		private function sendPostRequest($action, $data)
		{
			return $this->sendRequest($action, $data, 'POST');
		}
		
		/**
		 * Sends an HTTP PUT request to the API.
		 *
		 * @param string $action the API action component of the URI
		 * @param array $data assoc array containing the data to send
		 *
		 * @throws Exception on error
		 */
		private function sendPutRequest($action, $data)
		{
			return $this->sendRequest($action, $data, 'PUT');
		}
		
		/**
		 * Sends an HTTP DELETE request to the API
		 *
		 * @param string $action
		 * @param mixed $data
		 * @return void
		 * @throws Exception
		 */
		private function sendDeleteRequest($action, $data)
		{
			return $this->sendRequest($action, $data, 'DELETE');
		}
		
		/**
		 * Sends an HTTP POST request to the API.
		 *
		 * @param string $action the API action component of the URI
		 * @param array $data assoc array containing the data to send
		 * @param string $type GET, POST, PUT, DELETE
		 *
		 * @throws Exception on error
		 */
		private function sendRequest($action, $data, $type, $include_account = true)
		{
			$encoded_data = $data ? json_encode($data) : '';
			
			$this->createSocket();
			$headers = $this->buildHttpHeaders($type, $action, strlen($encoded_data), $include_account);
			$data = $this->client->request($type, $action. $this->account_number,['json'=>$encoded_data,'headers'=>$headers]);
			
			$response = new \stdClass;
			$response->headers = $data->getHeaders();
			$response->data = $data->getBody();
			
			$this->response = $response;
		}
		
		/**
		 * Gets the response from the API.
		 *
		 * @return \stdClass
		 */
		private function getResponse()
		{
			return $this->response;
		}
		
		/**
		 * Closes the socket.
		 */
		private function closeSocket()
		{
			fclose($this->socket);
			$this->socket = false;
		}
		
		/**
		 * Convert the lines of response data into an associative array.
		 *
		 * @param string $data lines of response data
		 * @return array|string associative array
		 */
		private function convertResponse($data)
		{
			$response = json_decode($data, true);
			if ($data && is_null($response)) {
				return $data; // Could be an inline pdf
			}
			return $response;
		}
	}
