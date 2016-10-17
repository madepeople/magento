<?php

/**
 * Adyen Payment Module
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category	Adyen
 * @package	Adyen_Payment
 * @copyright	Copyright (c) 2011 Adyen (http://www.adyen.com)
 * @license	http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
/**
 * @category   Payment Gateway
 * @package    Adyen_Payment
 * @author     Adyen
 * @property   Adyen B.V
 * @copyright  Copyright (c) 2014 Adyen BV (http://www.adyen.com)
 */
class Adyen_Payment_Helper_Payment extends Adyen_Payment_Helper_Data
{

    /**
     * @var GUEST_ID , used when order is placed by guests
     */
    const GUEST_ID = 'customer_';


    /**
     * @param array $fields
     * @param bool $isConfigDemoMode
     * @param string $paymentRoutine
     * @param bool $hppOptionsDisabled
     * @return string
     */
    public function getFormUrl($fields, $isConfigDemoMode = false, $paymentRoutine='single', $hppOptionsDisabled = true)
    {
        switch ($isConfigDemoMode) {
            case true:
                if ($paymentRoutine == 'single' && $hppOptionsDisabled) {
                    $url = 'https://test.adyen.com/hpp/pay.shtml';
                } else {
                    $url = ($hppOptionsDisabled)
                        ? 'https://test.adyen.com/hpp/select.shtml'
                        : "https://test.adyen.com/hpp/details.shtml";
                }
                break;
            default:
                if ($paymentRoutine == 'single' && $hppOptionsDisabled) {
                    $url = 'https://live.adyen.com/hpp/pay.shtml';
                } else {
                    $url = ($hppOptionsDisabled)
                        ? 'https://live.adyen.com/hpp/select.shtml'
                        : "https://live.adyen.com/hpp/details.shtml";
                }
                break;
        }
        return $url;
    }

    public function prepareFieldsforUrl($fields, $isConfigDemoMode = false)
    {
        $url = $this->getFormUrl($fields, $isConfigDemoMode);

        if (count($fields)) {
            $url = $url . '?' . http_build_query($fields, '', '&');
        }

        return $url;
    }

    /**
     * @desc prepares an array with order detail values to call the Adyen HPP page.
     *
     * @param $orderCurrencyCode
     * @param $realOrderId
     * @param $orderGrandTotal
     * @param $shopperEmail
     * @param $customerId
     * @param $merchantReturnData
     * @param $orderStoreId
     * @param $storeLocaleCode
     * @param $billingCountryCode
     * @param $shopperIP
     * @param $infoInstanceCCType
     * @param $infoInstanceMethod
     * @param $infoInstancePoNumber
     * @param $paymentMethodCode
     * @param $hasDeliveryAddress
     * @param $extraData
     * @param $order
     *
     * @return array
     */
    public function prepareFields(
        $orderCurrencyCode,
        $incrementId,
        $realOrderId,
        $orderGrandTotal,
        $shopperEmail,
        $customerId,
        $merchantReturnData,
        $orderStoreId,
        $storeLocaleCode,
        $billingCountryCode,
        $shopperIP,
        $infoInstanceCCType,
        $infoInstanceMethod,
        $infoInstancePoNumber,
        $paymentMethodCode,
        $hasDeliveryAddress,
        $order = null
    )
    {
        // check if Pay By Mail has a skincode, otherwise use HPP
        $skinCode = trim($this->getConfigData('skin_code', $paymentMethodCode, $orderStoreId));
        if ($skinCode == "") {
            $skinCode = trim($this->getConfigData('skinCode', 'adyen_hpp', $orderStoreId));
        }

        $merchantAccount = trim($this->getConfigData('merchantAccount', null, $orderStoreId));
        $amount = Mage::helper('adyen')->formatAmount($orderGrandTotal, $orderCurrencyCode);

        $shopperLocale = trim($this->getConfigData('shopperlocale', null, $orderStoreId));
        $shopperLocale = (!empty($shopperLocale)) ? $shopperLocale : $storeLocaleCode;

        $countryCode = trim($this->getConfigData('countryCode', null, $orderStoreId));
        $countryCode = (!empty($countryCode)) ? $countryCode : $billingCountryCode;

        // shipBeforeDate is a required field by certain payment methods
        $deliveryDays = (int)$this->getConfigData('delivery_days', 'adyen_hpp', $orderStoreId);
        $deliveryDays = (!empty($deliveryDays)) ? $deliveryDays : 5 ;

        $shipBeforeDate = new DateTime("now");
        $shipBeforeDate->add(new DateInterval("P{$deliveryDays}D"));

        // number of days link is valid to use
        $sessionValidity = (int)trim($this->getConfigData('session_validity', 'adyen_pay_by_mail', $orderStoreId));
        $sessionValidity = ($sessionValidity == "") ? 3 : $sessionValidity ;

        $sessionValidityDate = new DateTime("now");
        $sessionValidityDate->add(new DateInterval("P{$sessionValidity}D"));

        // is recurring?
        $recurringType = trim($this->getConfigData('recurringtypes', 'adyen_abstract', $orderStoreId));

        // @todo Paypal does not allow ONECLICK,RECURRING will be fixed on adyen platform but this is the quickfix for now
        if($infoInstanceMethod == "adyen_hpp_paypal" && $recurringType == 'ONECLICK,RECURRING') {
            $recurringType = "RECURRING";
        }

        // For IDEAL add isuerId into request so bank selection is skipped
        $issuerId = (strstr($infoInstanceCCType, "ideal")) ?
            $infoInstancePoNumber :
            null;

        $customerId = $this->getShopperReference($customerId, $realOrderId);

        // should billing and shipping address and customer info be shown, hidden or editable on the HPP page.
        // this is heavily influenced by payment method requirements and best be left alone
        $viewDetails = $this->getHppViewDetails($infoInstanceCCType, $paymentMethodCode, $hasDeliveryAddress);
        $billingAddressType = $viewDetails['billing_address_type'];
        $deliveryAddressType = $viewDetails['shipping_address_type'];
        $shopperType = $viewDetails['customer_info'];
        
        // set Shopper, Billing and DeliveryAddress
        $shopperInfo =  $this->getHppShopperDetails($order->getBillingAddress(), $order->getCustomerGender(), $order->getCustomerDob());
        $billingAddress = $this->getHppBillingAdressDetails($order->getBillingAddress());
        $deliveryAddress = $this->getHppDeliveryAdressDetails($order->getShippingAddress());
        

        // if option to put Return Url in request from magento is enabled add this in the request
        if($paymentMethodCode != Adyen_Payment_Model_Adyen_PayByMail::METHODCODE) {
            $returnUrlInRequest = $this->getConfigData('return_url_in_request', 'adyen_hpp', $orderStoreId);
            $returnUrl = ($returnUrlInRequest) ?
                trim(Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK, true) . "adyen/process/success") :
                "" ;
        } else {
            $returnUrl = null;
        }



        // type of payment method (card)
        $brandCode = $paymentMethodCode == "adyen_openinvoice" ?
            trim($this->getConfigData('openinvoicetypes', 'adyen_openinvoice', $orderStoreId)) :
            trim($infoInstanceCCType) ;

        // Risk offset, 0 to 100 points
        $adyFields['offset'] = "0";


        $browserInfo = trim($_SERVER['HTTP_USER_AGENT']);

        /*
         * This field will be appended as-is to the return URL when the shopper completes, or abandons, the payment and
         * returns to your shop; it is typically used to transmit a session ID. This field has a maximum of 128 characters
         * This is an optional field and not necessary by default
         */
        $dataString = (is_array($merchantReturnData)) ? serialize($merchantReturnData) : $merchantReturnData;

        $adyFields = $this->adyenValueArray(
            $orderCurrencyCode,
            $shopperEmail,
            $customerId,
            $merchantAccount,
            $incrementId,
            $amount,
            $shipBeforeDate,
            $skinCode,
            $shopperLocale,
            $countryCode,
            $recurringType,
            $dataString,
            $browserInfo,
            $shopperIP,
            $billingAddressType,
            $deliveryAddressType,
            $shopperType,
            $issuerId,
            $returnUrl,
            $brandCode,
            $shopperInfo,
            $billingAddress,
            $deliveryAddress
        );

        // eventHandler to overwrite the adyFields without changing module code
        $adyFields = new Varien_Object($adyFields);
        Mage::dispatchEvent('adyen_payment_prepare_fields', [
            'fields' => $adyFields
        ]);

        // @deprecated in favor of above event, this one is left in for backwards compatibility
        Mage::dispatchEvent('adyen_payment_hpp_fields', [
            'order' => $order,
            'fields' => $adyFields
        ]);
        $adyFields = $adyFields->getData();

        // remove keys that has empty or null value
        $adyFields = array_filter($adyFields);


        return $adyFields;
    }

    /**
     * @descr format the data in a specific array
     *
     * @param $orderCurrencyCode
     * @param $shopperEmail
     * @param $customerId
     * @param $merchantAccount
     * @param $amount
     * @param $shipBeforeDate
     * @param $skinCode
     * @param $shopperLocale
     * @param $countryCode
     * @param $recurringType
     * @param $dataString
     * @param $browserInfo
     * @param $shopperIP
     * @param $billingAddressType
     * @param $deliveryAddressType
     * @param $shopperType
     * @param $issuerId
     * @param $returnUrl
     * @param $brandCode
     * @param $shopperInfo
     * @param $billingAddress
     * @param $deliveryAddress
     *
     * @return array
     */
    public function adyenValueArray(
        $orderCurrencyCode,
        $shopperEmail,
        $customerId,
        $merchantAccount,
        $merchantReference,
        $amount,
        $shipBeforeDate,
        $skinCode,
        $shopperLocale,
        $countryCode,
        $recurringType,
        $dataString,
        $browserInfo,
        $shopperIP,
        $billingAddressType,
        $deliveryAddressType,
        $shopperType,
        $issuerId,
        $returnUrl,
        $brandCode,
        $shopperInfo,
        $billingAddress,
        $deliveryAddress
    )
    {
        $adyFields = [
            'merchantAccount' => $merchantAccount,
            'merchantReference' => $merchantReference,
            'paymentAmount' => (int)$amount,
            'currencyCode' => $orderCurrencyCode,
            'shipBeforeDate' => $shipBeforeDate->format('Y-m-d'),
            'skinCode' => $skinCode,
            'shopperLocale' => $shopperLocale,
            'countryCode' => $countryCode,
            'sessionValidity' => $shipBeforeDate->format("c"),
            'shopperEmail' => $shopperEmail,
            'recurringContract' => $recurringType,
            'shopperReference' => $customerId,
            'shopperIP' => $shopperIP,
            'browserInfo' => $browserInfo,
            'issuerId' => $issuerId,
            'resURL' => $returnUrl,
            'merchantReturnData' => substr(urlencode($dataString), 0, 128),
            // @todo remove this and add allowed methods via a config xml node
            'blockedMethods' => "",
            // Will only work if billingAddress, deliveryAddress and shopperInfo is in request
            'billingAddressType' => $billingAddressType,
            'deliveryAddressType' => $deliveryAddressType,
            'shopperType' => $shopperType
        ];

        // explode details for request
        $adyFields = $this->explodeArrayToRequestFields($adyFields, 'shopper', $shopperInfo);
        $adyFields = $this->explodeArrayToRequestFields($adyFields, 'billingAddress', $billingAddress);
        $adyFields = $this->explodeArrayToRequestFields($adyFields, 'deliveryAddress', $deliveryAddress);

        // Add brandCode if payment selection is done
        if($brandCode) {
            $adyFields['brandCode'] = $brandCode;
        }

        return $adyFields;
    }

    public function explodeArrayToRequestFields($adyFields, $name, $items)
    {
        if (is_array($items)) {
            foreach ($items as $field => $value) {
                $adyFields[$name . '.' . $field] = $value;
            }
        }
        return $adyFields;
    }

    /**
     * @param null $storeId
     * @param $paymentMethodCode
     * @return string
     */
    public function _getSecretWord($storeId=null, $paymentMethodCode)
    {
        $skinCode = trim($this->getConfigData('skin_code', $paymentMethodCode, $storeId));
        if ($skinCode=="") { // fallback if no skincode is available for the specific method
            $paymentMethodCode = 'adyen_hpp';
        }

        switch ($this->getConfigDataDemoMode()) {
            case true:
                $secretWord = trim($this->getConfigData('secret_wordt', $paymentMethodCode, $storeId));
                break;
            default:
                $secretWord = trim($this->getConfigData('secret_wordp', $paymentMethodCode ,$storeId));
                break;
        }
        return $secretWord;
    }

    /**
     * @desc The character escape function is called from the array_map function in _signRequestParams
     * @param $val
     * @return string
     */
    public function escapeString($val)
    {
        return str_replace(':','\\:',str_replace('\\','\\\\',$val));
    }

    /**
     * @descr Hmac key signing is standardised by Adyen
     * - first we order the array by string
     * - then we create a column seperated array with first all the keys, then all the values
     * - finally generating the SHA256 HMAC encrypted merchant signature
     * @param $adyFields
     * @param $secretWord
     * @return string
     */
    public function createHmacSignature($adyFields, $secretWord)
    {
        ksort($adyFields, SORT_STRING);

        $signData = implode(":", array_map([$this, 'escapeString'], array_merge(
            array_keys($adyFields),
            array_values($adyFields)
        )));

        $signMac = Zend_Crypt_Hmac::compute(pack("H*", $secretWord), 'sha256', $signData);

        return base64_encode(pack('H*', $signMac));
    }

    /**
     * @param $customerId
     * @param $realOrderId
     * @return string
     */
    public function getShopperReference($customerId, $realOrderId)
    {
        if ($customerId) { // there is a logged in customer for this order
            // the following allows to send the 'pretty' customer ID or increment ID to Adyen instead of the entity id
            // used collection here, it's about half the resources of using the load method on the customer opject
            /* var $customer Mage_Customer_Model_Resource_Customer_Collection */
            $customer = Mage::getResourceModel('customer/customer_collection')
                ->addAttributeToSelect('adyen_customer_ref')
                ->addAttributeToSelect('increment_id')
                ->addAttributeToFilter('entity_id', $customerId)
                ->getFirstItem();

            $customerId = $customer->getId() && $customer->getData('adyen_customer_ref') ?
                $customer->getData('increment_id') :
                $customerId;
            return $customerId;
        } else { // it was a guest order
            $customerId = self::GUEST_ID . $realOrderId;
            return $customerId;
        }
    }

    /**
     * @param $infoInstanceCCType
     * @param $paymentMethodCode
     * @param $hasDeliveryAddress
     * @return array
     */
    public function getHppViewDetails($infoInstanceCCType, $paymentMethodCode, $hasDeliveryAddress)
    {
        // should the HPP page show address and delivery type details
        if ($paymentMethodCode == "adyen_openinvoice" || $infoInstanceCCType == "klarna" || $infoInstanceCCType == "afterpay_default") {
            $billingAddressType = "1"; // yes, but not editable
            $deliveryAddressType = "1"; // yes, but not editable

            // get shopperType setting
            $shopperType = $this->getConfigData("shoppertype", "adyen_openinvoice") == '1' ? "" : "1"; // only for openinvoice show this
        } else {
            $shopperType = "";
            // for other payment methods like creditcard don't show the address field on the HPP page
            $billingAddressType = "2";
            // Only show DeliveryAddressType to hidden in request if there is a shipping address otherwise keep it empty
            $deliveryAddressType = $hasDeliveryAddress ? "2" : "";
        }

        return [
            'billing_address_type' => $billingAddressType,
            'shipping_address_type' => $deliveryAddressType,
            'customer_info' => $shopperType
        ];
    }


    /**
     * @param $billingAddress
     * @param $gender
     * @param $dob
     * @return array
     */
    public function getHppShopperDetails($billingAddress, $gender, $dob)
    {
        $shopperInfo = [];

        $shopperInfo['firstName'] = trim($billingAddress->getFirstname());

        $middleName = trim($billingAddress->getMiddlename());
        if($middleName != "") {
            $shopperInfo['infix'] = trim($middleName);
        }

        $shopperInfo['lastName'] = trim($billingAddress->getLastname());

        $shopperInfo['gender'] = $this->getGenderText($gender);

        if (!empty($dob)) {
            $shopperInfo['dateOfBirthDayOfMonth'] = trim($this->getDate($dob, 'd'));
            $shopperInfo['dateOfBirthMonth'] = trim($this->getDate($dob, 'm'));
            $shopperInfo['dateOfBirthYear'] = trim($this->getDate($dob, 'Y'));
        }

        $shopperInfo['telephoneNumber'] = trim($billingAddress->getTelephone());

        return $shopperInfo;
    }

    /**
     * Date Manipulation
     *
     * @param null $date
     * @param string $format
     * @return string
     */
    public function getDate($date = null, $format = 'Y-m-d H:i:s') {
        if (strlen($date) < 0) {
            $date = date('d-m-Y H:i:s');
        }
        $timeStamp = new DateTime($date);
        return $timeStamp->format($format);
    }

    /**
     * @param $genderId
     * @return string
     */
    public function getGenderText($genderId)
    {
        $result = "";
        if($genderId == '1') {
            $result = 'MALE';
        } elseif($genderId == '2') {
            $result = 'FEMALE';
        }
        return $result;
    }

    /**
     * @param $billingAddress
     * @return array
     */
    public function getHppBillingAdressDetails($billingAddress)
    {
        $billingAddressRequest = [];
        $helper = Mage::helper('adyen');

        $billingAddressRequest['street'] = trim($helper->getStreet($billingAddress,true)->getName());
        if(trim($helper->getStreet($billingAddress,true)->getHouseNumber()) == "") {
            $billingAddressRequest['houseNumberOrName'] = "NA";
        } else {
            $billingAddressRequest['houseNumberOrName'] = trim($helper->getStreet($billingAddress,true)->getHouseNumber());
        }

        if (trim($billingAddress->getCity()) == "") {
            $billingAddressRequest['city'] = "NA";
        } else {
            $billingAddressRequest['city'] = trim($billingAddress->getCity());
        }

        if (trim($billingAddress->getPostcode()) == "") {
            $billingAddressRequest['postalCode'] = "NA";
        } else {
            $billingAddressRequest['postalCode'] = trim($billingAddress->getPostcode());
        }

        if (trim($billingAddress->getRegionCode()) == "") {
            $billingAddressRequest['stateOrProvince'] = "NA";
        } else {
            $billingAddressRequest['stateOrProvince'] = trim($billingAddress->getRegionCode());
        }

        if (trim($billingAddress->getCountryId()) == "") {
            $billingAddressRequest['country'] = "NA";
        } else {
            $billingAddressRequest['country'] = trim($billingAddress->getCountryId());
        }
        return $billingAddressRequest;
    }


    /**
     * @param $deliveryAddress
     * @return array
     */
    public function getHppDeliveryAdressDetails($deliveryAddress)
    {
        $deliveryAddressRequest = [];
        $helper = Mage::helper('adyen');

        $deliveryAddressRequest['street'] = trim($helper->getStreet($deliveryAddress,true)->getName());
        if (trim($helper->getStreet($deliveryAddress,true)->getHouseNumber()) == "") {
            $deliveryAddressRequest['houseNumberOrName'] = "NA";
        } else {
            $deliveryAddressRequest['houseNumberOrName'] = trim($helper->getStreet($deliveryAddress,true)->getHouseNumber());
        }

        if (trim($deliveryAddress->getCity()) == "") {
            $deliveryAddressRequest['city'] = "NA";
        } else {
            $deliveryAddressRequest['city'] = trim($deliveryAddress->getCity());
        }

        if (trim($deliveryAddress->getPostcode()) == "") {
            $deliveryAddressRequest['postalCode'] = "NA";
        } else {
            $deliveryAddressRequest['postalCode'] = trim($deliveryAddress->getPostcode());
        }

        if (trim($deliveryAddress->getRegionCode()) == "") {
            $deliveryAddressRequest['stateOrProvince'] = "NA";
        } else {
            $deliveryAddressRequest['stateOrProvince'] = trim($deliveryAddress->getRegionCode());
        }

        if (trim($deliveryAddress->getCountryId()) == "") {
            $deliveryAddressRequest['country'] = "NA";
        } else {
            $deliveryAddressRequest['country'] = trim($deliveryAddress->getCountryId());
        }

        return $deliveryAddressRequest;
    }
}
