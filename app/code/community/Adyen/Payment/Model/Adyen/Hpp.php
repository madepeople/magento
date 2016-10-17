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
 * @category     Adyen
 * @package      Adyen_Payment
 * @copyright    Copyright (c) 2011 Adyen (http://www.adyen.com)
 * @license      http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */



/**
 * @category   Payment Gateway
 * @package    Adyen_Payment
 * @author     Adyen
 * @property   Adyen B.V
 * @copyright  Copyright (c) 2014 Adyen BV (http://www.adyen.com)
 */
class Adyen_Payment_Model_Adyen_Hpp extends Adyen_Payment_Model_Adyen_Abstract
{
    /**
     * @var GUEST_ID , used when order is placed by guests
     */
    const GUEST_ID = 'customer_';

    protected $_canUseInternal = false;
    protected $_code = 'adyen_hpp';
    protected $_formBlockType = 'adyen/form_hpp';
    protected $_infoBlockType = 'adyen/info_hpp';
    protected $_paymentMethod = 'hpp';
    protected $_isInitializeNeeded = true;

    protected $_paymentMethodType = 'hpp';

    public function getPaymentMethodType() {
        return $this->_paymentMethodType;
    }

    /**
     * Ability to set the code, for dynamic payment methods.
     * @param $code
     * @return $this
     */
    public function setCode($code)
    {
        $this->_code = $code;
        return $this;
    }

    /**
     * @desc Get checkout session namespace
     *
     * @return Mage_Checkout_Model_Session
     */
    public function getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }
        $info    = $this->getInfoInstance();

        if(!$this->getHppOptionsDisabled()) {
            $hppType = str_replace('adyen_hpp_', '', $info->getData('method'));
            $hppType = str_replace('adyen_ideal', 'ideal', $hppType);
        } else {
            $hppType = null;
        }

        // set hpp type
        $info->setCcType($hppType);

        $hppTypeLabel =  Mage::getStoreConfig('payment/'.$info->getData('method').'/title');
        $info->setAdditionalInformation('hpp_type_label', $hppTypeLabel);

        // set bankId and label
        $selectedBankId = $data->getData('adyen_ideal_type');
        if($selectedBankId) {
            $issuers = $this->getInfoInstance()->getMethodInstance()->getIssuers();
            if(!empty($issuers)) {
                $info->setAdditionalInformation('hpp_type_bank_label', $issuers[$selectedBankId]['label']);
            }
            $info->setPoNumber($selectedBankId);
        }

        /* @note misused field */
        $config = Mage::getStoreConfig("payment/adyen_hpp/disable_hpptypes");
        if (empty($hppType) && empty($config)) {
            Mage::throwException(
                Mage::helper('adyen')->__('Payment Method is compulsory in order to process your payment')
            );
        }
        return $this;
    }


    public function validate()
    {
        parent::validate();
    }


    /**
     * @desc Called just after asssign data
     */
    public function prepareSave()
    {
        parent::prepareSave();
    }


    /**
     * @desc Get current quote
     *
     * @return Mage_Sales_Model_Quote
     */
    public function getQuote()
    {
        return $this->getCheckout()->getQuote();
    }


    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('adyen/process/redirect');
    }


    /**
     * @desc prepare params array to send it to gateway page via POST
     * @return array
     */
    public function getFormFields()
    {
        $this->_initOrder();
        $order             = $this->_order;
        $realOrderId       = trim($order->getRealOrderId());
        $orderCurrencyCode = trim($order->getOrderCurrencyCode());
        $skinCode          = trim($this->_getConfigData('skinCode', 'adyen_hpp'));
        $amount            = trim(Mage::helper('adyen')->formatAmount($order->getGrandTotal(), $orderCurrencyCode));
        $merchantAccount   = trim($this->_getConfigData('merchantAccount'));
        $shopperEmail      = trim($order->getCustomerEmail());
        $customerId        = trim($order->getCustomerId());
        $shopperIP         = trim($order->getRemoteIp());
        $browserInfo       = trim($_SERVER['HTTP_USER_AGENT']);
        $shopperLocale     = trim($this->_getConfigData('shopperlocale'));
        $shopperLocale     = (!empty($shopperLocale)) ? $shopperLocale : trim(Mage::app()->getLocale()->getLocaleCode());
        $countryCode       = trim($this->_getConfigData('countryCode'));
        $countryCode       = (!empty($countryCode)) ? $countryCode : false;
        // if directory lookup is enabled use the billingadress as countrycode
        if ($countryCode == false) {
            if (is_object($order->getBillingAddress()) && $order->getBillingAddress()->getCountry() != "") {
                $countryCode = trim($order->getBillingAddress()->getCountry());
            }
        }
        $adyFields                      = array();
        $deliveryDays                   = (int)$this->_getConfigData('delivery_days', 'adyen_hpp');
        $deliveryDays                   = (!empty($deliveryDays)) ? $deliveryDays : 5;
        $adyFields['merchantAccount']   = $merchantAccount;
        $adyFields['merchantReference'] = $realOrderId;
        $adyFields['paymentAmount']     = (int)$amount;
        $adyFields['currencyCode']      = $orderCurrencyCode;
        $adyFields['shipBeforeDate']    = date(
            "Y-m-d",
            mktime(date("H"), date("i"), date("s"), date("m"), date("j") + $deliveryDays, date("Y"))
        );
        $adyFields['skinCode']          = $skinCode;
        $adyFields['shopperLocale']     = $shopperLocale;
        $adyFields['countryCode']       = $countryCode;
        $adyFields['shopperIP']         = $shopperIP;
        $adyFields['browserInfo']       = $browserInfo;
        //order data
        $items          = $order->getAllItems();
        $shipmentAmount = number_format($order->getShippingAmount() + $order->getShippingTaxAmount(), 2, ',', ' ');
        $prodDetails    = Mage::helper('adyen')->__('Shipment cost: %s %s <br />', $shipmentAmount, $orderCurrencyCode);
        $prodDetails .= Mage::helper('adyen')->__('Order rows: <br />');
        foreach ($items as $item) {
            if ($item->getParentItem()) {
                continue;
            }
            $name       = $item->getName();
            $qtyOrdered = $this->_numberFormat($item->getQtyOrdered(), '0');
            $rowTotal   = number_format($item->getRowTotalInclTax(), 2, ',', ' ');
            $prodDetails .= Mage::helper('adyen')->__(
                '%s ( Qty: %s ) (Price: %s %s ) <br />',
                $name,
                $qtyOrdered,
                $rowTotal,
                $orderCurrencyCode
            );
        }
        $adyFields['orderData']       = base64_encode(gzencode(trim($prodDetails))); //depreacated by Adyen
        $adyFields['sessionValidity'] = trim(date(
            DATE_ATOM,
            mktime(date("H") + 1, date("i"), date("s"), date("m"), date("j"), date("Y"))
        ));
        $adyFields['shopperEmail']    = $shopperEmail;
        // recurring
        $recurringType                  = trim($this->_getConfigData('recurringtypes', 'adyen_abstract'));

        // Paypal does not allow ONECLICK,RECURRING will be fixed on adyen platform but this is the quickfix for now
        if($this->getInfoInstance()->getMethod() == "adyen_hpp_paypal" && $recurringType == 'ONECLICK,RECURRING') {
            $recurringType = "RECURRING";
        }

        if ($customerId) {
            $customer = Mage::getModel('customer/customer')->load($order->getCustomerId());
            $customerId = $customer->getData('adyen_customer_ref')
                ?: $customer->getData('increment_id')
                ?: $customerId;
        }

        $adyFields['recurringContract'] = $recurringType;
        $adyFields['shopperReference']  = (!empty($customerId)) ? trim($customerId) : self::GUEST_ID . $realOrderId;
        //blocked methods
        $adyFields['blockedMethods'] = "";

        /*
         * This feld will be appended as-is to the return URL when the shopper completes, or abandons, the payment and
         * returns to your shop; it is typically used to transmit a session ID. This feld has a maximum of 128 characters
         * This is an optional field and not necessary by default
         */
        $adyFields['merchantReturnData'] = "";

        $openinvoiceType = $this->_getConfigData('openinvoicetypes', 'adyen_openinvoice');
        if ($this->_code == "adyen_openinvoice" || $this->getInfoInstance()->getCcType() == "klarna"
            || $this->getInfoInstance()->getCcType() == "afterpay_default"
        ) {
            $adyFields['billingAddressType']  = "1";
            $adyFields['deliveryAddressType'] = "1";

            // get shopperType setting
            $shopperType = $this->_getConfigData("shoppertype", "adyen_openinvoice");
            if($shopperType == '1') {
                $adyFields['shopperType'] = "";
            } else {
                $adyFields['shopperType'] = "1";
            }

        } else {
            // for other payment methods like creditcard don't show avs address field in skin
            $adyFields['billingAddressType'] = "2";

            // Only set DeliveryAddressType to hidden and in request if there is a shipping address otherwise keep it empty
            $deliveryAddress = $order->getShippingAddress();
            if($deliveryAddress != null)
            {
                $adyFields['deliveryAddressType'] = "2";
            } else {
                $adyFields['deliveryAddressType'] = "";
            }

            $adyFields['shopperType'] = "";
        }

        // get extra fields
        $adyFields = Mage::getModel('adyen/adyen_openinvoice')->getOptionalFormFields($adyFields, $this->_order, false);

        // For IDEAL add isuerId into request so bank selection is skipped
        if (strpos($this->getInfoInstance()->getCcType(), "ideal") !== false) {
            $adyFields['issuerId'] = trim($this->getInfoInstance()->getPoNumber());
        }

        // if option to put Return Url in request from magento is enabled add this in the request
        $returnUrlInRequest = $this->_getConfigData('return_url_in_request', 'adyen_hpp');
        if ($returnUrlInRequest) {
            $url = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK, true) . "adyen/process/success";
            $adyFields['resURL'] = trim($url);
        }

        $secretWord               = $this->_getSecretWord();

        if ($this->_code == "adyen_openinvoice") {
            $brandCode = $this->_getConfigData('openinvoicetypes', 'adyen_openinvoice');
            $adyFields['brandCode'] = trim($brandCode);
        } else {
            $brandCode        = $this->getInfoInstance()->getCcType();
            if($brandCode) {
                $adyFields['brandCode'] = trim($brandCode);
            }
        }

        // set offset to 0
        $adyFields['offset'] = "0";

        // eventHandler to overwrite the adyFields without changing module code
        $adyFields = new Varien_Object($adyFields);
        Mage::dispatchEvent('adyen_payment_hpp_fields', array('order' => $order, 'fields' => $adyFields));
        $adyFields = $adyFields->getData();

        // Sort the array by key using SORT_STRING order
        ksort($adyFields, SORT_STRING);

        // Generate the signing data string
        $signData = implode(":",array_map(array($this, 'escapeString'),array_merge(array_keys($adyFields), array_values($adyFields))));

        $signMac = Zend_Crypt_Hmac::compute(pack("H*" , $secretWord), 'sha256', $signData);
        $adyFields['merchantSig'] = base64_encode(pack('H*', $signMac));

        // pos over hpp
//         disable this because no one using this and it will always show POS payment method
//         $terminalcode = 'redirect';
//         $adyFields['pos.serial_number'] = $terminalcode;
//         // calculate signatature pos
//         $strsign = "merchantSig:pos.serial_number|" . $adyFields['merchantSig'] . ":" . $terminalcode;
//         $signPOS = Zend_Crypt_Hmac::compute($secretWord, 'sha1', $strsign);
//         $adyFields['pos.sig'] = base64_encode(pack('H*', $signPOS));
        Mage::log($adyFields, self::DEBUG_LEVEL, 'adyen_http-request.log', true);

//        print_r($adyFields);die();
        return $adyFields;
    }

    /*
     * @desc The character escape function is called from the array_map function in _signRequestParams
     * $param $val
     * return string
     */
    protected function escapeString($val)
    {
        return str_replace(':','\\:',str_replace('\\','\\\\',$val));
    }

    protected function _getSecretWord($options = null)
    {
        switch ($this->getConfigDataDemoMode()) {
            case true:
                $secretWord = trim($this->_getConfigData('secret_wordt', 'adyen_hpp'));
                break;
            default:
                $secretWord = trim($this->_getConfigData('secret_wordp', 'adyen_hpp'));
                break;
        }
        return $secretWord;
    }


    /**
     * @desc Get url of Adyen payment
     * @return string
     */
    public function getFormUrl()
    {
        $paymentRoutine   = $this->_getConfigData('payment_routines', 'adyen_hpp');
        $isConfigDemoMode = $this->getConfigDataDemoMode();
        switch ($isConfigDemoMode) {
            case true:
                if ($paymentRoutine == 'single' && $this->getHppOptionsDisabled()) {
                    $url = 'https://test.adyen.com/hpp/pay.shtml';
                } else {
                    $url = ($this->getHppOptionsDisabled())
                        ? 'https://test.adyen.com/hpp/select.shtml'
                        : "https://test.adyen.com/hpp/details.shtml";
                }
                break;
            default:
                if ($paymentRoutine == 'single' && $this->getHppOptionsDisabled()) {
                    $url = 'https://live.adyen.com/hpp/pay.shtml';
                } else {
                    $url = ($this->getHppOptionsDisabled())
                        ? 'https://live.adyen.com/hpp/select.shtml'
                        : "https://live.adyen.com/hpp/details.shtml";
                }
                break;
        }
        return $url;
    }


    public function getFormName()
    {
        return "Adyen HPP";
    }


    /**
     * Return redirect block type
     *
     * @return string
     */
    public function getRedirectBlockType()
    {
        return $this->_redirectBlockType;
    }


    public function initialize($paymentAction, $stateObject)
    {
        $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        $stateObject->setState($state);
        $stateObject->setStatus($this->_getConfigData('order_status'));
    }

    public function getHppOptionsDisabled() {
        return Mage::getStoreConfig("payment/adyen_hpp/disable_hpptypes");
    }

    public function getShowIdealLogos() {
        return $this->_getConfigData('show_ideal_logos', 'adyen_hpp');
    }

    public function canCreateAdyenSubscription() {

        // validate if recurringType is correctly configured
        $recurringType = $this->_getConfigData('recurringtypes', 'adyen_abstract');
        if($recurringType == "RECURRING" || $recurringType == "ONECLICK,RECURRING") {
            return true;
        }
        return false;
    }

    /**
     * @param Mage_Sales_Model_Quote|null $quote
     * @return bool
     */
    public function isAvailable($quote = null)
    {
        $isAvailable = parent::isAvailable();
        
        if (!is_null($quote)) {
            $disableZeroTotal = Mage::getStoreConfig('payment/adyen_hpp/disable_zero_total', $quote->getStoreId());
        } else {
            $disableZeroTotal = Mage::getStoreConfig('payment/adyen_hpp/disable_zero_total');
        }
        
        if (!is_null($quote) && $quote->getGrandTotal() <= 0 && $disableZeroTotal) {
            return false;
        }

        return $isAvailable;
    }
}
