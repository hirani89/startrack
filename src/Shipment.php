<?php
	
	namespace Zoet\Startrack;
	
	/**
	 * A shipment, made up of one or more parcels
	 *
	 * @package Zoet\Startrack
	 * @author Josh Marshall <josh@jmarshall.com.au>
	 *
	 * @property Startrack $_startrack
	 * @property string $shipment_reference
	 * @property string $customer_reference_1
	 * @property string $customer_reference_2
	 * @property bool $email_tracking_enabled
	 * @property Address $to
	 * @property Address $from
	 * @property Parcel[] $parcels
	 * @property string $product_id The AusPost product to use for this shipment
	 * @property string $shipment_id The AusPost generated id when lodged
	 * @property \DateTime $shipment_lodged_at The time the shipment was lodged
	 */
	class Shipment
	{
		
		private $_startrack;
		public $shipment_reference;
		public $customer_reference_1 = '';
		public $customer_reference_2 = '';
		public $email_tracking_enabled = false;
		public $movement_type = 'DESPATCH';
		public $from;
		public $to;
		public $parcels = [];
		public $delivery_instructions = '';
		
		public $product_id;
		public $shipment_id;
		public $shipment_lodged_at;
		
		public function __construct($api)
		{
			$this->_startrack = $api;
		}
		
		/**
		 * Add the To address
		 * @param Address $data The address to deliver to
		 * @return $this
		 */
		public function setTo($data)
		{
			$this->to = $data;
			return $this;
		}
		
		/**
		 * Add the From address
		 * @param Address $data The address to send from
		 * @return $this
		 */
		public function setFrom($data)
		{
			$this->from = $data;
			return $this;
		}
		
		/**
		 * Set the movement type
		 * @param string $data 'DESPATCH' or 'RETURN' or 'TRANSFER'
		 * @return $this
		 */
		public function setMovementType($data)
		{
			$this->movement_type = $data;
			return $this;
		}
		
		public function addParcel($data)
		{
			$this->parcels[] = $data;
			return $this;
		}
		
		/**
		 *
		 * @return \Zoet\Startrack\Quote[]
		 * @throws \Exception
		 */
		public function getQuotes($urgent = false)
		{
			$request = [
				'from' => [
					'suburb' => $this->from->suburb,
					'postcode' => $this->from->postcode,
					'state' => $this->from->state,
				],
				'to' => [
					'suburb' => $this->to->suburb,
					'postcode' => $this->to->postcode,
					'state' => $this->to->state,
				],
				'items' => [],
			];
			$max_dimension = 0;
			foreach ($this->parcels as $parcel) {
				if(max($parcel->length,$parcel->height,$parcel->width)>$max_dimension){
					$max_dimension = max($parcel->length,$parcel->height,$parcel->width);
				}
				$item = [
					'packaging_type' => 'ITM',
					'length' => $parcel->length,
					'height' => $parcel->height,
					'width' => $parcel->width,
					'weight' => $parcel->weight,
				];
				if ($parcel->value) {
					$item['features'] = [
						'TRANSIT_COVER' => [
							'attributes' => [
								'cover_amount' => $parcel->value,
							]
						]
					];
				}
				$request['items'][] = $item;
			}
			return $this->_startrack->getQuotes(['shipments' => [$request]], $urgent, (int)$max_dimension);
		}
		
		public function lodgeShipment()
		{
			// Determine if Domestic or International
			$domestic_shipping = $this->to->country == 'AU';
			
			if ($domestic_shipping) {
				// Lodge domestic shipment
			} else {
				// Lodge international shipment
			}
			$request = [
				'shipment_reference' => $this->shipment_reference,
				'customer_reference_1' => $this->customer_reference_1,
				'customer_reference_2' => $this->customer_reference_2,
				'email_tracking_enabled' => $this->email_tracking_enabled,
				'movement_type' => $this->movement_type,
				'from' => [
					'name' => $this->from->name,
					'business_name' => $this->from->business_name,
					'lines' => $this->from->lines,
					'suburb' => $this->from->suburb,
					'state' => $this->from->state,
					'postcode' => $this->from->postcode,
					'country' => $this->from->country,
					'phone' => $this->from->phone,
					'email' => $this->from->email,
				],
				'to' => [
					'name' => $this->to->name,
					'business_name' => $this->to->business_name,
					'lines' => $this->to->lines,
					'suburb' => $this->to->suburb,
					'state' => $this->to->state,
					'postcode' => $this->to->postcode,
					'country' => $this->to->country,
					'phone' => $this->to->phone,
					'email' => $this->to->email,
					'delivery_instructions' => $this->delivery_instructions,
				],
				'items' => [],
			];
			foreach ($this->parcels as $parcel) {
				$parcel->product_id = $this->product_id;
				$item = [
					'item_reference' => $parcel->item_reference,
					'product_id' => $this->product_id,
					'length' => $parcel->length,
					'height' => $parcel->height,
					'width' => $parcel->width,
					'weight' => $parcel->weight,
					'contains_dangerous_goods' => $parcel->contains_dangerous_goods,
					'authority_to_leave' => $parcel->authority_to_leave,
					'safe_drop_enabled' => $parcel->safe_drop_enabled,
					'allow_partial_delivery' => $parcel->allow_partial_delivery,
					'packaging_type' => $parcel->packaging_type,
				];
				if ($parcel->value) {
					$item['features'] = [
						'TRANSIT_COVER' => [
							'attributes' => [
								'cover_amount' => $parcel->value,
							]
						]
					];
				}
				$request['items'][] = $item;
			}
			
			$response = $this->_startrack->shipments(['shipments' => $request]);
			
			foreach ($response['shipments'] as $shipment) {
				$this->shipment_id = $shipment['shipment_id'];
				$this->shipment_lodged_at = new \DateTime($shipment['shipment_creation_date']);
				foreach ($shipment['items'] as $item) {
					foreach ($this->parcels as $key => $parcel) {
//						if ($parcel->item_reference != $item['item_reference']) {
//							continue;
//						}
						$this->parcels[$key]->item_id = $item['item_id'];
						$this->parcels[$key]->tracking_article_id = $item['tracking_details']['article_id'];
						$this->parcels[$key]->tracking_consignment_id = $item['tracking_details']['consignment_id'];
					}
				}
			}
		}
		
		/**
		 * Get the labels for this shipment
		 * @param LabelType $labelType
		 * @return string url to label
		 * @throws \Exception
		 */
		public function getLabel($labelType)
		{
			return $this->_startrack->getLabels([$this->shipment_id], $labelType);
		}
		
		/**
		 * Delete this shipment
		 * @throws \Exception
		 */
		public function deleteShipment()
		{
			return $this->_startrack->deleteShipment($this->shipment_id);
		}
		public function deleteShipmentById($shipment_id)
		{
			return $this->_startrack->deleteShipment($shipment_id);
		}
		
	}
