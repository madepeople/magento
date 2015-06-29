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
class Adyen_Payment_Model_Process extends Mage_Core_Model_Abstract {

    public function processCashResponse()
    {
        $response = $_REQUEST;

        $varienObj = new Varien_Object();
        foreach ($response as $code => $value) {
            if ($code == 'amount') {
                if (is_object($value))
                    $value = $value->value;
                $code = 'value';
            }
            $varienObj->setData($code, $value);
        }

        $pspReference = $varienObj->getData('pspReference');
        $merchantReference = $varienObj->getData('merchantReference');
        $skinCode =  $varienObj->getData('skinCode');
        $paymentAmount = $varienObj->getData('paymentAmount');
        $currencyCode = $varienObj->getData('currencyCode');
        $customPaymentMethod = $varienObj->getData('c_cash');
        $paymentMethod = $varienObj->getData('paymentMethod');
        $merchantSig = $varienObj->getData('merchantSig');

        $sign = $pspReference .
            $merchantReference .
            $skinCode .
            $paymentAmount .
            $currencyCode .
            $customPaymentMethod . $paymentMethod;

        $secretWord = $this->_getSecretWord();
        $signMac = Zend_Crypt_Hmac::compute($secretWord, 'sha1', $sign);
        $calMerchantSig = base64_encode(pack('H*', $signMac));

        // check if signatures are the same
        if($calMerchantSig == $merchantSig) {

            //get order && payment objects
            $order = Mage::getModel('sales/order');

            //error
            $orderExist = $this->_incrementIdExist($varienObj, $merchantReference);

            if (empty($orderExist)) {
                $this->_writeLog("unknown order : $merchantReference");
            } else {
                $order->loadByIncrementId($merchantReference);

                $comment = Mage::helper('adyen')
                    ->__('Adyen Cash Result URL Notification: <br /> pspReference: %s <br /> paymentMethod: %s', $pspReference, $paymentMethod);

                $status = true;

                $history = Mage::getModel('sales/order_status_history')
                    ->setStatus($status)
                    ->setComment($comment)
                    ->setEntityName("order")
                    ->setOrder($order);
                $history->save();
                return $status;
            }
        }
        return false;
    }

    protected function _getSecretWord() {
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
     * Used via Payment method.Notice via configuration ofcourse Y or N
     * @return boolean true on demo, else false
     */
    public function getConfigDataDemoMode() {
        if ($this->_getConfigData('demoMode') == 'Y') {
            return true;
        }
        return false;
    }

    /**
     * @desc check order existance
     * @param type $incrementId
     * @return type
     */
    protected function _incrementIdExist($varienObj, $incrementId) {

        $orderExist = Mage::getResourceModel('adyen/order')->orderExist($incrementId);
        return $orderExist;
    }

    protected function _writeLog($str, $order = null) {
        if (!empty($order)) {
            $order->getPayment()->getMethodInstance()->writeLog($str);
        }
    }

    /**
     * @desc Give Default settings
     * @example $this->_getConfigData('demoMode','adyen_abstract')
     * @since 0.0.2
     * @param string $code
     */
    protected function _getConfigData($code, $paymentMethodCode = null, $storeId = null) {
        return Mage::helper('adyen')->_getConfigData($code, $paymentMethodCode, $storeId);
    }

    public function getRequest() {
        return Mage::app()->getRequest();
    }

}