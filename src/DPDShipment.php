<?php
namespace BernhardK\Dpd;

use BernhardK\Dpd\DPDException;
use Illuminate\Support\Facades\Log;
use SOAPHeader;
use SoapFault;
use Soapclient;

class DPDShipment{

    public $client;

    protected $environment;

    protected $authorisation;

    protected $predictCountries = [
        'BE', 'NL', 'DE', 'AT',
        'PL', 'FR', 'PT', 'GB',
        'LU', 'EE', 'CH', 'IE',
        'SK', 'LV', 'SI', 'LT',
        'CZ', 'HU', 'HR', 'ES',
        'IT', 'DK', 'FI', 'SE',
        'NO'
    ];

    protected $storeOrderMessage = [];

    protected $trackingLanguage = null;
    protected $label = null;
    protected $airWayBills = [];

    const TEST_SHIP_WSDL = 'https://shipperadmintest.dpd.nl/PublicAPI/WSDL/ShipmentServiceV33.wsdl';
    const SHIP_WSDL = 'https://wsshipper.dpd.nl/soap/WSDL/ShipmentServiceV33.wsdl';
    const SOAPHEADER_URL = 'http://dpd.com/common/service/types/Authentication/2.0';
    const TRACKING_URL = 'https://tracking.dpd.de/parcelstatus?locale=:lang&query=:awb';

    /**
     * @param object  DPDAuthorisation    $authorisationObject
     * @param boolean [$wsdlCache         = true]
     */
    public function __construct(DPDAuthorisation $authorisationObject, $wsdlCache = true)
    {
        $this->authorisation = $authorisationObject->authorisation;
        $this->environment = [
            'wsdlCache' => $wsdlCache,
            'shipWsdl'  => ($this->authorisation['staging'] ? self::TEST_SHIP_WSDL : self::SHIP_WSDL),
        ];
        $this->startNewOrder();
    }

    /**
     * Clear the parcels array to start a new transport
     */
    public function startNewOrder()
    {
        $this->airWayBills = [];
        $this->storeOrderMessage = [
            'printOptions' => [
                'paperFormat' => config('dpd.paperFormat'),
                'printerLanguage' => config('dpd.printerLanguage'),
                'startPosition' => config('dpd.startPosition')
            ],
            'order' => [
                'generalShipmentData' => [
                    'sendingDepot' => $this->authorisation['token']->depot,
                    'product' => null,
                    'mpsCustomerReferenceNumber1' => null,
                    'mpsCustomerReferenceNumber2' => null,
                    'sender' => [
                        'name1' => null,
                        'name2' => null,
                        'street' => null,
                        'houseNo' => null,
                        'state' => null,
                        'country' => null,
                        'zipCode' => null,
                        'city' => null,
                        'email' => null,
                        'phone' => null,
                        'gln' => null,
                        'contact' => null,
                        'fax' => null,
                        'customerNumber' => null,
                    ],
                    'recipient' => [
                        'name1' => null,
                        'name2' => null,
                        'street' => null,
                        'houseNo' => null,
                        'state' => null,
                        'country' => null,
                        'gln' => null,
                        'zipCode' => null,
                        'customerNumber' => null,
                        'contact' => null,
                        'phone' => null,
                        'fax' => null,
                        'email' => null,
                        'city' => null,
                        'comment' => null
                    ]
                ],
                'parcels' => [
                ],
                'productAndServiceData' => [
                    'saturdayDelivery' => config('dpd.saturdayDelivery'),
                    'orderType' => 'consignment',
                    'guarantee' => false
                ]
            ]
        ];
    }


    /**
     * Add a parcel to the shipment
     * @param array $array
     * @throws \BernhardK\Dpd\DPDException
     */
    public function addParcel($array)
    {
        if (!isset($array['weight']) or !isset($array['height']) or !isset($array['length']) or !isset($array['width'])){
            Log::emergency('DPD: Parcel array not complete');
            throw new DPDException('DPD: Parcel array not complete');
        }

        if ((int) $array['length'] < 1) {
            Log::emergency('DPD: Minimum value for "length" is 100.');
            throw new DPDException('DPD: Minimum value for "length" is 100.');
        }

        if ((int) $array['width'] < 1) {
            Log::emergency('DPD: Minimum value for "width" is 100.');
            throw new DPDException('DPD: Minimum value for "width" is 100.');
        }

        if ((int) $array['height'] < 1) {
            Log::emergency('DPD: Minimum value for "height" is 1.');
            throw new DPDException('DPD: Minimum value for "height" is 1.');
        }

        $volume = str_pad((string) ceil($array['length']), 3, '0', STR_PAD_LEFT);
        $volume .= str_pad((string) ceil($array['width']), 3, '0', STR_PAD_LEFT);
        $volume .= str_pad((string) ceil($array['height']), 3, '0', STR_PAD_LEFT);

        $this->storeOrderMessage['order']['parcels'][] = [
            'volume' => $volume,
            'weight' => (int) ceil($array['weight'] / 10)
        ];

        //set the flag for return package. DPD will flip sender and receiver on their server
        if(isset($array['return']) && $array['return'] === true){
            $this->storeOrderMessage['order']['parcels']['returns'] = true;
        }


    }

    /**
     * Submit the parcel to the DPD webservice
     */
    public function submit()
    {

        if (isset($this->storeOrderMessage['order']['productAndServiceData']['predict'])){
            if (!in_array(strtoupper($this->storeOrderMessage['order']['generalShipmentData']['recipient']['country']), $this->predictCountries)){
                Log::emergency('DPD: Predict service not available for this destination');
                throw new DPDException('DPD: Predict service not available for this destination');
            }
        }
        if (count($this->storeOrderMessage['order']['parcels']) === 0){
            Log::emergency('DPD: Create at least 1 parcel');
            throw new DPDException('DPD: Create at least 1 parcel');
        }

        if ($this->environment['wsdlCache']){
            $soapParams = [
                'cache_wsdl' => WSDL_CACHE_BOTH,
                'trace' => config('dpd.tracing')
            ];
        }
        else{
            $soapParams = [
                'cache_wsdl' => WSDL_CACHE_NONE,
                'exceptions' => true,
                'trace' => config('dpd.tracing')
            ];
        }

        try{

            $this->client = new Soapclient($this->environment['shipWsdl'], $soapParams);
            $header = new SOAPHeader(self::SOAPHEADER_URL, 'authentication', $this->authorisation['token']);
            $this->client->__setSoapHeaders($header);
            $response = $original_response = $this->client->storeOrders($this->storeOrderMessage);

            if(config('dpd.tracing')) {
                Log::debug('DPD: SOAP-Request Shipment: ' . $this->client->__getLastRequest());
                Log::debug('DPD: SOAP-Response Shipment: ' . $this->client->__getLastResponse());
            }

            if (isset($response->orderResult->shipmentResponses->faults)){
                Log::debug('DPD: '.$response->orderResult->shipmentResponses->faults->message);
                throw new DPDException('SOAP Fehler ' . $response->orderResult->shipmentResponses->faults->message);
            }

            $this->label = $response->orderResult->parcellabelsPDF;
            unset($response->orderResult->parcellabelsPDF);

            if (is_array($response->orderResult->shipmentResponses->parcelInformation)){
                foreach($response->orderResult->shipmentResponses->parcelInformation as $parcelResponse){
                    $this->airWayBills[] = [
                        'airWayBill' => $parcelResponse->parcelLabelNumber,
                        'trackingLink' => strtr(self::TRACKING_URL, [
                            ':awb' => $parcelResponse->parcelLabelNumber,
                            ':lang' => $this->trackingLanguage
                        ])
                    ];
                }
            }
            else{
                $this->airWayBills[] = [
                    'airWayBill' => $response->orderResult->shipmentResponses->parcelInformation->parcelLabelNumber,
                    'trackingLink' => strtr(self::TRACKING_URL, [
                        ':awb' => $response->orderResult->shipmentResponses->parcelInformation->parcelLabelNumber,
                        ':lang' => $this->trackingLanguage
                    ])
                ];
            }
        }
        catch (SoapFault $e)
        {
            $message = $e->detail != null ? $e->detail->faults->message : $e->faultstring;
            Log::emergency('DPD Shipment: '.$message);
            if ($this->client) {
                Log::debug('DPD: SOAP-Request Shipment: ' . $this->client->__getLastRequest());
            }
            throw new DPDException($message);
        }

    }

    /**
     * Enable DPD's B2C service. Only allowed for countries in protected $predictCountries
     * @param array $array
     *  'channel' => email|telephone|sms,
     *  'value' => emailaddress or phone number,
     *  'language' => EN
     */
    public function setPredict($array)
    {

        if (!isset($array['channel']) or !isset($array['value']) or !isset($array['language'])){
            Log::emergency('DPD: Predict array not complete');
            throw new DPDException('DPD: Parcel array not complete');
        }

        switch (strtolower($array['channel'])) {
            case 'email':
                $array['channel'] = 1;
                if (!filter_var($array['value'], FILTER_VALIDATE_EMAIL)) {
                    Log::emergency('DPD: Predict email address not valid');
                    throw new DPDException('DPD: Predict email address not valid');
                }
                break;
            case 'telephone':
                $array['channel'] = 2;
                if (empty($array['value'])){
                    Log::emergency('DPD: Predict value (telephone) empty');
                    throw new DPDException('DPD: Predict value (telephone) empty');
                }
                break;
            case 'sms':
                $array['channel'] = 3;
                if (empty($array['value'])){
                    Log::emergency('DPD: Predict value (sms) empty');
                    throw new DPDException('DPD: Predict value (sms) empty');
                }
                break;
            default:
                Log::emergency('DPD: Predict channel not allowed');
                throw new DPDException('DPD: Predict channel not allowed');
        }

        if (ctype_alpha($array['language']) && strlen($array['language']) === 2){
            $array['language'] = strtoupper($array['language']);
        }
        $this->storeOrderMessage['order']['productAndServiceData']['predict'] = $array;
    }

    /**
     * Get an array with parcelnumber and trackinglink for each package
     * @return array
     */
    public function getParcelResponses()
    {
     return $this->airWayBills;
    }

    /**
     * Set the general shipmentdata
     * @param array $array see protected $storeOrderMessage
     */
    public function setGeneralShipmentData($array)
    {
     $this->storeOrderMessage['order']['generalShipmentData'] = array_merge($this->storeOrderMessage['order']['generalShipmentData'], $array);
    }

    /**
     * Enable saturday delivery
     * @param boolean $bool default false
     */
    public function setSaturdayDelivery($bool)
    {
     $this->storeOrderMessage['order']['productAndServiceData']['saturdayDelivery'] = $bool;
    }

    /**
     * Enable guarantee delivery
     * @param boolean $bool default false
     */
    public function setGuarantee($bool)
    {
     $this->storeOrderMessage['order']['productAndServiceData']['guarantee'] = $bool;
    }

    /**
     * Set the shipment's sender
     * @param array $array see protected $storeOrderMessage
     */
    public function setSender($array)
    {
     $array['customerNumber'] = $this->authorisation['customerNumber'];
     $array['city'] = strtoupper($array['city']);
     $this->storeOrderMessage['order']['generalShipmentData']['sender'] = array_merge($this->storeOrderMessage['order']['generalShipmentData']['sender'], $array);
    }

    /**
     * Set the shipment's receiver
     * @param array $array see protected $storeOrderMessage
     */
    public function setReceiver($array)
    {
     $this->storeOrderMessage['order']['generalShipmentData']['recipient'] = array_merge($this->storeOrderMessage['order']['generalShipmentData']['recipient'], $array);
    }

    /**
     * Set the printoptions
     * @param array $array see protected $storeOrderMessage
     */
    public function setPrintOptions($printoptions)
    {
     $this->storeOrderMessage['printOptions'] = array_merge($this->storeOrderMessage['printOptions'], $printoptions);
    }

    /**
     * Set the language for the track & trace link
     * @param string $language format: en_EN
     */
    public function setTrackingLanguage($language)
    {
     $this->trackingLanguage = $language;
    }

    /**
     * Get's the shipment label pdf as a string
     * @return string
     */
    public function getLabels()
    {
     return $this->label;
    }

}
