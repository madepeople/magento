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
class Adyen_Payment_Model_ProcessNotification extends Mage_Core_Model_Abstract {


    /**
     * Collected debug information
     *
     * @var array
     */
    protected $_debugData = array();

    /**
     * Process the notification that is received by the Adyen platform
     * @param $response
     * @return string
     */
    public function processResponse($response)
    {
        // SOAP, JSON, HTTP POST
        $storeId = null;

        $this->_debugData['processResponse begin'] = 'Begin to process Notification';

        if (empty($response)) {
            $this->_debugData['error'] = 'Response is empty, please check your webserver that the result url accepts parameters';
            $this->_debug($storeId);
            return "401";
        }

        // Log the results in log file and adyen_debug table
        $this->_debugData['response'] = $response;
        Mage::getResourceModel('adyen/adyen_debug')->assignData($response);

//      $params = new Varien_Object($response);
        // Create Varien_Object from response (soap compatible)
        $params = new Varien_Object();
        foreach ($response as $code => $value) {
            $params->setData($code, $value);
        }
        $actionName = $this->_getRequest()->getActionName();

        // authenticate result url
        $authStatus = Mage::getModel('adyen/authenticate')->authenticate($actionName, $params);
        if (!$authStatus) {
            $this->_debugData['error'] = 'Autentication failure please check your notification username and password. This must be the same in Magento as in the Adyen platform';
            $this->_debug($storeId);
            return "401";
        }

        // skip notification if notification is REPORT_AVAILABLE
        $eventCode = trim($params->getData('eventCode'));
        if($eventCode == Adyen_Payment_Model_Event::ADYEN_EVENT_REPORT_AVAILABLE) {
            $this->_debugData['processResponse info'] = 'Skip notification REPORT_AVAILABLE';
            $this->_debug($storeId);
            return;
        }

        // check if notification is not duplicate
        if(!$this->_isDuplicate($params)) {

            $incrementId = $params->getData('merchantReference');

            if($incrementId) {
                $order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
                if ($order->getId()) {
                    // set StoreId for retrieving debug log setting
                    $storeId = $order->getStoreId();

                    $this->_updateOrder($order, $params);
                } else {
                    $this->_debugData['error'] = 'Order does not exists with increment_id: ' . $incrementId;
                    $this->_addNotificationToQueue($params);
                }
            } else {
                $this->_debugData['error'] = 'Empty merchantReference';
            }
        } else {
            $this->_debugData['processResponse info'] = 'Skipping duplicate notification';
        }

        // update the queue if it is not processed by cronjob
        if(!$this->_getConfigData('update_notification_cronjob')) {
            $this->_updateNotProcessedNotifications();
        }

        $this->_debug($storeId);
    }

    /**
     * Check if notification is already received
     * If this is the case ignore the notification
     * @param $params
     * @return bool
     */
    protected function _isDuplicate($params)
    {
        $pspReference = trim($params->getData('pspReference'));
        $success = trim($params->getData('success'));
        $eventCode = trim($params->getData('eventCode'));

        // if notification is already processed ignore it
        $isDuplicate = Mage::getModel('adyen/event')
            ->isDuplicate($pspReference, $eventCode, $success);
        if ($isDuplicate && $eventCode != Adyen_Payment_Model_Event::ADYEN_EVENT_RECURRING_CONTRACT) {
            return true;
        }
        return false;
    }

    // notification attributes
    protected $_pspReference;
    protected $_merchantReference;
    protected $_eventCode;
    protected $_success;
    protected $_paymentMethod;
    protected $_reason;
    protected $_value;
    protected $_boletoOriginalAmount;
    protected $_boletoPaidAmount;
    protected $_modificationResult;
    protected $_klarnaReservationNumber;
    protected $_fraudManualReview;

    /**
     * @param $order
     * @param $params
     */
    protected function _updateOrder($order, $params)
    {
        $this->_debugData['_updateOrder'] = 'Updating the order';

        Mage::dispatchEvent('adyen_payment_process_notifications_before', array('order' => $order, 'adyen_response' => $params));
        if ($params->getData('handled')) {
            $this->_debug($order->getStoreId());
            return;
        }

        $this->_declareVariables($order, $params);

        $previousAdyenEventCode = $order->getAdyenEventCode();

        // add notification to comment history status is current status
        $this->_addStatusHistoryComment($order);

        // update order details
        $this->_updateAdyenAttributes($order, $params);

        // check if success is true of false
        if (strcmp($this->_success, 'false') == 0 || strcmp($this->_success, '0') == 0) {
            // Only cancel the order when it is in state pending, payment review or if the ORDER_CLOSED is failed (means split payment has not be successful)
            if($order->getState() === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT || $order->getState() === Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW || $this->_eventCode == Adyen_Payment_Model_Event::ADYEN_EVENT_ORDER_CLOSED) {
                $this->_debugData['_updateOrder info'] = 'Going to cancel the order';

                // if payment is API check, check if API result pspreference is the same as reference
                if($this->_eventCode == Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISATION && $this->_getPaymentMethodType($order) == 'api') {
                    if($this->_pspReference == $order->getPayment()->getAdyenPspReference()) {
                        // don't cancel the order if previous state is authorisation with success=true
                        if($previousAdyenEventCode != "AUTHORISATION : TRUE") {
                            $this->_holdCancelOrder($order, false);
                        } else {
                            $order->setAdyenEventCode($previousAdyenEventCode); // do not update the adyenEventCode
                            $this->_debugData['_updateOrder warning'] = 'order is not cancelled because previous notification was a authorisation that succeeded';
                        }
                    } else {
                        $this->_debugData['_updateOrder warning'] = 'order is not cancelled because pspReference does not match with the order';
                    }
                } else {
                    // don't cancel the order if previous state is authorisation with success=true
                    if($previousAdyenEventCode != "AUTHORISATION : TRUE") {
                        $this->_holdCancelOrder($order, false);
                    } else {
                        $order->setAdyenEventCode($previousAdyenEventCode); // do not update the adyenEventCode
                        $this->_debugData['_updateOrder warning'] = 'order is not cancelled because previous notification was a authorisation that succeeded';
                    }
                }
            } else {
                $this->_debugData['_updateOrder info'] = 'Order is already processed so ignore this notification state is:' . $order->getState();
            }
        } else {
            // Notification is successful
            $this->_processNotification($order);
        }

        // save event for duplication
        $this->_storeNotification();

        // update the order with status/adyen event and comment history
        $order->save();

        Mage::dispatchEvent('adyen_payment_process_notifications_after', array('order' => $order, 'adyen_response' => $params));
    }

    protected function _declareVariables($order, $params)
    {
        //  declare the common parameters
        $this->_pspReference = trim($params->getData('pspReference'));
        $this->_merchantReference = trim($params->getData('merchantReference'));
        $this->_eventCode = trim($params->getData('eventCode'));
        $this->_success = trim($params->getData('success'));
        $this->_paymentMethod = trim($params->getData('paymentMethod'));
        $this->_reason = trim($params->getData('reason'));

        $valueArray = $params->getData('amount');
        if($valueArray && is_array($valueArray)) {
            $this->_value = isset($valueArray['value']) ? $valueArray['value'] : "";
        }

        $additionalData = $params->getData('additionalData');

        // boleto data
        if($this->_paymentMethodCode($order) == "adyen_boleto") {
            if($additionalData && is_array($additionalData)) {
                $boletobancario = isset($additionalData['boletobancario']) ? $additionalData['boletobancario'] : null;
                if($boletobancario && is_array($boletobancario)) {
                    $this->_boletoOriginalAmount = isset($boletobancario['originalAmount']) ? trim($boletobancario['originalAmount']) : "";
                    $this->_boletoPaidAmount = isset($boletobancario['paidAmount']) ? trim($boletobancario['paidAmount']) : "";
                }
            }
        }

        if($additionalData && is_array($additionalData)) {

            // check if the payment is in status manual review
            $fraudManualReview = isset($additionalData['fraudManualReview']) ? $additionalData['fraudManualReview'] : "";
            if($fraudManualReview == "true") {
                $this->_fraudManualReview = true;
            } else {
                $this->_fraudManualReview = false;
            }

            $modification = isset($additionalData['modification']) ? $additionalData['modification'] : null;
            if($modification && is_array($modification)) {
                $this->_modificationResult = isset($valueArray['action']) ? trim($modification['action']) : "";
            }
            $additionalData2 = isset($additionalData['additionalData']) ? $additionalData['additionalData'] : null;
            if($additionalData2 && is_array($additionalData2)) {
                $this->_klarnaReservationNumber = isset($additionalData2['acquirerReference']) ? trim($additionalData2['acquirerReference']) : "";
            }
        }
    }

    /**
     * @param $order
     * @param $params
     */
    protected function _updateAdyenAttributes($order, $params)
    {
        $this->_debugData['_updateAdyenAttributes'] = 'Updating the Adyen attributes of the order';

        $additionalData = $params->getData('additionalData');
        if($additionalData && is_array($additionalData)) {
            $avsResult = (isset($additionalData['avsResult'])) ? $additionalData['avsResult'] : "";
            $cvcResult = (isset($additionalData['cvcResult'])) ? $additionalData['cvcResult'] : "";
            $totalFraudScore = (isset($additionalData['totalFraudScore'])) ? $additionalData['totalFraudScore'] : "";
            $ccLast4 = (isset($additionalData['cardSummary'])) ? $additionalData['cardSummary'] : "";
            $refusalReasonRaw = (isset($additionalData['refusalReasonRaw'])) ? $additionalData['refusalReasonRaw'] : "";
            $acquirerReference = (isset($additionalData['acquirerReference'])) ? $additionalData['acquirerReference'] : "";
            $authCode = (isset($additionalData['authCode'])) ? $additionalData['authCode'] : "";
        }

        $paymentObj = $order->getPayment();
        $_paymentCode = $this->_paymentMethodCode($order);

        // if there is no server communication setup try to get last4 digits from reason field
        if(!isset($ccLast4) || $ccLast4 == "") {
            $ccLast4 = $this->_retrieveLast4DigitsFromReason($this->_reason);
        }
        $paymentObj->setLastTransId($this->_merchantReference)
                   ->setCcType($this->_paymentMethod);

        if ($this->_eventCode == Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISATION
            || $this->_eventCode == Adyen_Payment_Model_Event::ADYEN_EVENT_HANDLED_EXTERNALLY
            || ($this->_eventCode == Adyen_Payment_Model_Event::ADYEN_EVENT_CAPTURE && $_paymentCode == "adyen_pos"))
        {
            $paymentObj->setAdyenPspReference($this->_pspReference);
            if($this->_klarnaReservationNumber != "") {
                $paymentObj->setAdyenKlarnaNumber($this->_klarnaReservationNumber);
            }
            if(isset($ccLast4) && $ccLast4 != "") {
                $paymentObj->setccLast4($ccLast4);
            }
            if(isset($avsResult) && $avsResult != "") {
                $paymentObj->setAdyenAvsResult($avsResult);
            }
            if(isset($cvcResult) && $cvcResult != "") {
                $paymentObj->setAdyenCvcResult($cvcResult);
            }
            if($this->_boletoPaidAmount != "") {
                $paymentObj->setAdyenBoletoPaidAmount($this->_boletoPaidAmount);
            }
            if(isset($totalFraudScore) && $totalFraudScore != "") {
                $paymentObj->setAdyenTotalFraudScore($totalFraudScore);
            }
            if(isset($refusalReasonRaw) && $refusalReasonRaw != "") {
                $paymentObj->setAdyenRefusalReasonRaw($refusalReasonRaw);
            }
            if(isset($acquirerReference) && $acquirerReference != "") {
                $paymentObj->setAdyenAcquirerReference($acquirerReference);
            }
            if(isset($authCode) && $authCode != "") {
                $paymentObj->setAdyenAuthCode($authCode);
            }
        }

    }

    /**
     * retrieve last 4 digits of card from the reason field
     * @param $reason
     * @return string
     */
    protected function _retrieveLast4DigitsFromReason($reason)
    {
        $result = "";

        if($reason != "") {
            $reasonArray = explode(":", $reason);
            if($reasonArray != null && is_array($reasonArray)) {
                if(isset($reasonArray[1])) {
                    $result = $reasonArray[1];
                }
            }
        }
        return $result;
    }

    /**
     * @param $order
     * @param $params
     */
    protected function _storeNotification()
    {
        $success = ($this->_success == "true" || $this->_success == "1") ? true : false;

        try {
            //save all response data for a pure duplicate detection
            Mage::getModel('adyen/event')
                ->setPspReference($this->_pspReference)
                ->setAdyenEventCode($this->_eventCode)
                ->setAdyenEventResult($this->_eventCode)
                ->setIncrementId($this->_merchantReference)
                ->setPaymentMethod($this->_paymentMethod)
                ->setCreatedAt(now())
                ->setSuccess($success)
                ->saveData();

            $this->_debugData['_storeNotification'] = 'Notification is saved in adyen_event_data table';
        } catch (Exception $e) {
            $this->_debugData['_storeNotification error'] = 'Notification could not be saved in adyen_event_data table error message is: ' . $e->getMessage() ;
            Mage::logException($e);
        }
    }

    /**
     * @param $order
     * @param $params
     */
    protected function _processNotification($order)
    {
        $this->_debugData['_processNotification'] = 'Processing the notification';
        $_paymentCode = $this->_paymentMethodCode($order);

        switch ($this->_eventCode) {
            case Adyen_Payment_Model_Event::ADYEN_EVENT_REFUND_FAILED:
                // do nothing only inform the merchant with order comment history
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_REFUND:
                $ignoreRefundNotification = $this->_getConfigData('ignore_refund_notification', 'adyen_abstract', $order->getStoreId());
                if($ignoreRefundNotification != true) {
                    $this->_refundOrder($order);
                    //refund completed
                    $this->_setRefundAuthorized($order);
                } else {
                    $this->_debugData['_processNotification info'] = 'Setting to ignore refund notification is enabled so ignore this notification';
                }
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_PENDING:
                if($this->_getConfigData('send_email_bank_sepa_on_pending', 'adyen_abstract', $order->getStoreId())) {
                    // Check if payment is banktransfer or sepa if true then send out order confirmation email
                    $isBankTransfer = $this->_isBankTransfer($this->_paymentMethod);
                    if($isBankTransfer || $this->_paymentMethod == 'sepadirectdebit') {
                        $order->sendNewOrderEmail(); // send order email
                        $this->_debugData['_processNotification send email'] = 'Send orderconfirmation email to shopper';
                    }
                }
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_HANDLED_EXTERNALLY:
            case Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISATION:
                // for POS don't do anything on the AUTHORIZATION
                if($_paymentCode != "adyen_pos") {
                    $this->_authorizePayment($order, $this->_paymentMethod);
                }
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_MANUAL_REVIEW_REJECT:
                // don't do anything it will send a CANCEL_OR_REFUND notification when this payment is captured
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_MANUAL_REVIEW_ACCEPT:
                // only process this if you are on auto capture. On manual capture you will always get Capture or CancelOrRefund notification
                if ($this->_isAutoCapture($order)) {
                    $this->_setPaymentAuthorized($order, false);
                }
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_CAPTURE:
                if($_paymentCode != "adyen_pos") {
                    // ignore capture if you are on auto capture (this could be called if manual review is enabled and you have a capture delay)
                    if (!$this->_isAutoCapture($order)) {
                        $this->_setPaymentAuthorized($order, false, true);
                    }
                } else {

                    // uncancel the order just to be sure that order is going trough
                    $this->_uncancelOrder($order);

                    // FOR POS authorize the payment on the CAPTURE notification
                    $this->_authorizePayment($order, $this->_paymentMethod);
                }
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_CAPTURE_FAILED:
            case Adyen_Payment_Model_Event::ADYEN_EVENT_CANCELLATION:
            case Adyen_Payment_Model_Event::ADYEN_EVENT_CANCELLED:
                $this->_holdCancelOrder($order, true);
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_CANCEL_OR_REFUND:
                if(isset($this->_modificationResult) && $this->_modificationResult != "") {
                    if($this->_modificationResult == "cancel") {
                        $this->_holdCancelOrder($order, true);
                    } elseif($this->_modificationResult == "refund") {
                        $this->_refundOrder($order);
                        //refund completed
                        $this->_setRefundAuthorized($order);
                    }
                } else {
                    $orderStatus = $this->_getConfigData('order_status', 'adyen_abstract', $order->getStoreId());
                    if(($orderStatus != Mage_Sales_Model_Order::STATE_HOLDED && $order->canCancel()) || ($orderStatus == Mage_Sales_Model_Order::STATE_HOLDED && $order->canHold())) {
                        // cancel order
                        $this->_debugData['_processNotification'] = 'try to cancel the order';
                        $this->_holdCancelOrder($order, true);
                    } else {
                        $this->_debugData['_processNotification'] = 'try to refund the order';
                        // refund
                        $this->_refundOrder($order);
                        //refund completed
                        $this->_setRefundAuthorized($order);
                    }
                }
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_RECURRING_CONTRACT:

                // get payment object
                $payment = $order->getPayment();

                // save recurring contract (not for oneclicks because billing agreement does already exists
                if($_paymentCode != "adyen_oneclick") {

                    // storedReferenceCode
                    $recurringDetailReference = $this->_pspReference;

                    // check if there is already a BillingAgreement
                    $agreement = Mage::getModel('sales/billing_agreement')->load($recurringDetailReference, 'reference_id');

                    if ($agreement && $agreement->getAgreementId() > 0 && $agreement->isValid()) {

                        $agreement->addOrderRelation($order);
                        $agreement->setIsObjectChanged(true);
                        $order->addRelatedObject($agreement);
                        $message = Mage::helper('adyen')->__('Used existing billing agreement #%s.', $agreement->getReferenceId());

                    } else {
                        // set billing agreement data
                        $payment->setBillingAgreementData(array(
                            'billing_agreement_id'  => $recurringDetailReference,
                            'method_code'           => $payment->getMethodCode()
                        ));

                        // create billing agreement for this order
                        $agreement = Mage::getModel('sales/billing_agreement')->importOrderPayment($payment);
                        $agreement->setAgreementLabel($payment->getMethodInstance()->getTitle());

                        if ($agreement->isValid()) {
                            $message = Mage::helper('adyen')->__('Created billing agreement #%s.', $agreement->getReferenceId());

                            // save into sales_billing_agreement_order
                            $agreement->addOrderRelation($order);

                            // add to order to save agreement
                            $order->addRelatedObject($agreement);
                        } else {
                            $message = Mage::helper('adyen')->__('Failed to create billing agreement for this order.');
                        }
                    }
                    $comment = $order->addStatusHistoryComment($message);
                    $order->addRelatedObject($comment);

                    /*
                     * clear the cache for recurring payments so new card will be added
                     */
                    $merchantAccount = $this->_getConfigData('merchantAccount','adyen_abstract', $order->getStoreId());
                    $recurringType = $this->_getConfigData('recurringtypes', 'adyen_abstract', $order->getStoreId());

                    $cacheKey = $merchantAccount . "|" . $order->getCustomerId() . "|" . $recurringType;
                    Mage::app()->getCache()->remove($cacheKey);
                }
                break;
            default:
                $order->getPayment()->getMethodInstance()->writeLog('notification event not supported!');
                break;
        }
    }

    /**
     * @desc Revert back to NEW status if previous notification has cancelled the order
     * @param $order
     */
    protected function _uncancelOrder($order) {

        if($order->isCanceled()) {

            $this->_debugData['_uncancelOrder'] = 'Uncancel the order because could be that it is cancelled in a previous notification';

            $orderStatus = $this->_getConfigData('order_status', 'adyen_abstract', $order->getStoreId());

            $order->setState(Mage_Sales_Model_Order::STATE_NEW);
            $order->setStatus($orderStatus);
            $order->setBaseDiscountCanceled(0);
            $order->setBaseShippingCanceled(0);
            $order->setBaseSubtotalCanceled(0);
            $order->setBaseTaxCanceled(0);
            $order->setBaseTotalCanceled(0);
            $order->setDiscountCanceled(0);
            $order->setShippingCanceled(0);
            $order->setSubtotalCanceled(0);
            $order->setTaxCanceled(0);
            $order->setTotalCanceled(0);
            $order->save();

            try {
                foreach ($order->getAllItems() as $item) {
                    $item->setQtyCanceled(0);
                    $item->setTaxCanceled(0);
                    $item->setHiddenTaxCanceled(0);
                    $item->save();
                }
            } catch(Excpetion $e) {
                $this->_debugData['_uncancelOrder'] = 'Failed to cancel orderlines exception: ' . $e->getMessage();

            }
        }
    }
    /**
     * @param $order
     * @return bool
     */
    protected function _refundOrder($order)
    {
        $this->_debugData['_refundOrder'] = 'Refunding the order';

        // Don't create a credit memo if refund is initialize in Magento because in this case the credit memo already exits
        $result = Mage::getModel('adyen/event')
            ->getEvent($this->_pspReference, '[refund-received]');
        if (!empty($result)) {
            $this->_debugData['_refundOrder ignore'] = 'Skip refund process because credit memo is already created';
            return false;
        }

        $_mail = (bool) $this->_getConfigData('send_update_mail', 'adyen_abstract', $order->getStoreId());
        $amount = $this->_value;

        if ($order->canCreditmemo()) {
            $service = Mage::getModel('sales/service_order', $order);
            $creditmemo = $service->prepareCreditmemo();
            $creditmemo->getOrder()->setIsInProcess(true);

            //set refund data on the order
            $creditmemo->setGrandTotal($amount);
            $creditmemo->setBaseGrandTotal($amount);
            $creditmemo->save();

            try {
                Mage::getModel('core/resource_transaction')
                    ->addObject($creditmemo)
                    ->addObject($creditmemo->getOrder())
                    ->save();
                //refund
                $creditmemo->refund();
                $transactionSave = Mage::getModel('core/resource_transaction')
                    ->addObject($creditmemo)
                    ->addObject($creditmemo->getOrder());
                if ($creditmemo->getInvoice()) {
                    $transactionSave->addObject($creditmemo->getInvoice());
                }
                $transactionSave->save();
                if ($_mail) {
                    $creditmemo->getOrder()->setCustomerNoteNotify(true);
                    $creditmemo->sendEmail();
                }
                $this->_debugData['_refundOrder done'] = 'Credit memo is created';
            } catch (Exception $e) {
                $this->_debugData['_refundOrder error'] = 'Error creating credit memo error message is: ' . $e->getMessage();
                Mage::logException($e);
            }
        } else {
            $this->_debugData['_refundOrder error'] = 'Order can not be refunded';
        }
    }

    /**
     * @param $order
     */
    protected function _setRefundAuthorized($order)
    {
        $this->_debugData['_setRefundAuthorized'] = 'Status update to default status or refund_authorized status if this is set';
        $status = $this->_getConfigData('refund_authorized', 'adyen_abstract', $order->getStoreId());
        $status = (!empty($status)) ? $status : $order->getStatus();
        $order->addStatusHistoryComment(Mage::helper('adyen')->__('Adyen Refund Successfully completed'), $status);
        $order->sendOrderUpdateEmail((bool) $this->_getConfigData('send_update_mail', 'adyen_abstract', $order->getStoreId()));
    }

    /**
     * @param $order
     * @param $payment_method
     */
    protected function _authorizePayment($order, $payment_method)
    {
        $this->_debugData['_authorizePayment'] = 'Authorisation of the order';
        //pre-authorise if success
        $order->sendNewOrderEmail(); // send order email

        $this->_uncancelOrder($order);

        $this->_setPrePaymentAuthorized($order);

        $this->_prepareInvoice($order);

        $_paymentCode = $this->_paymentMethodCode($order);
        if($payment_method == "c_cash" || ($this->_getConfigData('create_shipment', 'adyen_pos', $order->getStoreId()) && $_paymentCode == "adyen_pos"))
        {
            $this->_createShipment($order);
        }
    }

    /**
     * @param $order
     */
    private function _setPrePaymentAuthorized($order)
    {
        $status = $this->_getConfigData('payment_pre_authorized', 'adyen_abstract', $order->getStoreId());

        // only do this if status in configuration is set
        if(!empty($status)) {
            $order->addStatusHistoryComment(Mage::helper('adyen')->__('Payment is pre authorised waiting for capture'), $status);
            $order->sendOrderUpdateEmail((bool) $this->_getConfigData('send_update_mail', 'adyen_abstract', $order->getStoreId()));
            $this->_debugData['_setPrePaymentAuthorized'] = 'Order status is changed to Pre-authorised status, status is ' . $status;
        } else {
            $this->_debugData['_setPrePaymentAuthorized'] = 'No pre-authorised status is used so ignore';
        }
    }

    /**
     * @param $order
     */
    protected function _prepareInvoice($order)
    {
        $this->_debugData['_prepareInvoice'] = 'Prepare invoice for order';
        $payment = $order->getPayment()->getMethodInstance();

        $_mail = (bool) $this->_getConfigData('send_update_mail', 'adyen_abstract', $order->getStoreId());

        //Set order state to new because with order state payment_review it is not possible to create an invoice
        if (strcmp($order->getState(), Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW) == 0) {
            $order->setState(Mage_Sales_Model_Order::STATE_NEW);
        }

        //capture mode
        if (!$this->_isAutoCapture($order)) {
            $order->addStatusHistoryComment(Mage::helper('adyen')->__('Capture Mode set to Manual'));
            $order->sendOrderUpdateEmail($_mail);
            $this->_debugData['_prepareInvoice done'] = 'Capture mode is set to Manual so don\'t create an invoice wait for the capture notification';

            // show message if order is in manual review
            if($this->_fraudManualReview) {
                // check if different status is selected
                $fraudManualReviewStatus = $this->_getConfigData('fraud_manual_review_status', 'adyen_abstract', $order->getStoreId());
                if($fraudManualReviewStatus != "") {
                    $status = $fraudManualReviewStatus;
                    $comment = "Adyen Payment is in Manual Review check the Adyen platform";
                    $order->addStatusHistoryComment(Mage::helper('adyen')->__($comment), $status);
                }
            }
            return;
        }

        // validate if amount is total amount
        $orderCurrencyCode = $order->getOrderCurrencyCode();
        if ($this->_value == Mage::helper('adyen')->formatAmount($order->getGrandTotal(), $orderCurrencyCode)) {

        }

        $orderCurrencyCode = $order->getOrderCurrencyCode();
        $orderAmount = (int) Mage::helper('adyen')->formatAmount($order->getGrandTotal(), $orderCurrencyCode);

        if($this->_isTotalAmount($orderAmount)) {
            $this->_createInvoice($order);
        } else {
            $this->_debugData['_prepareInvoice partial authorisation step1'] = 'This is a partial AUTHORISATION';

            // Check if this is the first partial authorisation or if there is already been an authorisation
            $paymentObj = $order->getPayment();
            $authorisationAmount = $paymentObj->getAdyenAuthorisationAmount();
            if($authorisationAmount != "") {
                $this->_debugData['_prepareInvoice partial authorisation step2'] = 'There is already a partial AUTHORISATION received check if this combined with the previous amounts match the total amount of the order';
                $authorisationAmount = (int) $authorisationAmount;
                $currentValue = (int) $this->_value;
                $totalAuthorisationAmount = $authorisationAmount + $currentValue;

                // update amount in column
                $paymentObj->setAdyenAuthorisationAmount($totalAuthorisationAmount);

                if($totalAuthorisationAmount == $orderAmount) {
                    $this->_debugData['_prepareInvoice partial authorisation step3'] = 'The full amount is paid. This is the latest AUTHORISATION notification. Create the invoice';
                    $this->_createInvoice($order);
                } else {
                    // this can be multiple times so use envenData as unique key
                    $this->_debugData['_prepareInvoice partial authorisation step3'] = 'The full amount is not reached. Wait for the next AUTHORISATION notification. The current amount that is authorized is:' . $totalAuthorisationAmount;
                }
            } else {
                $this->_debugData['_prepareInvoice partial authorisation step2'] = 'This is the first partial AUTHORISATION save this into the adyen_authorisation_amount field';
                $paymentObj->setAdyenAuthorisationAmount($this->_value);
            }
        }

        $order->sendOrderUpdateEmail($_mail);
    }

    protected function _isTotalAmount($orderAmount) {

        $this->_debugData['_isTotalAmount'] = 'Validate if AUTHORISATION notification has the total amount of the order';
        $value = (int)$this->_value;

        if($value == $orderAmount) {
            $this->_debugData['_isTotalAmount result'] = 'AUTHORISATION has the full amount';
            return true;
        } else {
            $this->_debugData['_isTotalAmount result'] = 'This is a partial AUTHORISATION, the amount is ' . $this->_value;
            return false;
        }

    }

    protected function _createInvoice($order)
    {
        $this->_debugData['_createInvoice'] = 'Creating invoice for order';

        if ($order->canInvoice()) {
            $invoice = $order->prepareInvoice();
            $invoice->getOrder()->setIsInProcess(true);
            // set transaction id so you can do a online refund this is used instead of online capture
            // because it is already auto capture in Adyen Backoffice
            $invoice->setTransactionId(1);
            $invoice->register()->pay();
            try {
                Mage::getModel('core/resource_transaction')
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder())
                    ->save();
                $this->_debugData['_createInvoice done'] = 'Created invoice';
            } catch (Exception $e) {
                $this->_debugData['_createInvoice error'] = 'Error saving invoice. The error message is: ' . $e->getMessage();
                Mage::logException($e);
            }

            //selected adyen status
            $this->_setPaymentAuthorized($order);

            $invoiceAutoMail = (bool) $this->_getConfigData('send_invoice_update_mail', 'adyen_abstract', $order->getStoreId());
            if ($invoiceAutoMail) {
                $invoice->sendEmail();
            }
        } else {
            $this->_debugData['_createInvoice error'] = 'It is not possible to create invoice for this order';
        }
    }

    /**
     * @param $order
     * @return bool
     */
    protected function _isAutoCapture($order)
    {
        $captureMode = trim($this->_getConfigData('capture_mode', 'adyen_abstract', $order->getStoreId()));
        $sepaFlow = trim($this->_getConfigData('capture_mode', 'adyen_sepa', $order->getStoreId()));
        $_paymentCode = $this->_paymentMethodCode($order);
        $captureModeOpenInvoice = $this->_getConfigData('auto_capture_openinvoice', 'adyen_abstract', $order->getStoreId());
        $captureModePayPal = trim($this->_getConfigData('paypal_capture_mode', 'adyen_abstract', $order->getStoreId()));

        //check if it is a banktransfer. Banktransfer only a Authorize notification is send.
        $isBankTransfer = $this->_isBankTransfer($this->_paymentMethod);

        // payment method ideal, cash adyen_boleto or adyen_pos has direct capture
        if (strcmp($this->_paymentMethod, 'ideal') === 0 || strcmp($this->_paymentMethod, 'c_cash' ) === 0 || $_paymentCode == "adyen_pos" || $isBankTransfer == true || ($_paymentCode == "adyen_sepa" && $sepaFlow != "authcap") || $_paymentCode == "adyen_boleto") {
            return true;
        }
        // if auto capture mode for openinvoice is turned on then use auto capture
        if ($captureModeOpenInvoice == true && (strcmp($this->_paymentMethod, 'openinvoice') === 0 || strcmp($this->_paymentMethod, 'afterpay_default') === 0 || strcmp($this->_paymentMethod, 'klarna') === 0)) {
            return true;
        }
        // if PayPal capture modues is different from the default use this one
        if(strcmp($this->_paymentMethod, 'paypal' ) === 0 && $captureModePayPal != "") {
            if(strcmp($captureModePayPal, 'auto') === 0 ) {
                return true;
            } elseif(strcmp($captureModePayPal, 'manual') === 0 ) {
                return false;
            }
        }
        if (strcmp($captureMode, 'manual') === 0) {
            return false;
        }
        //online capture after delivery, use Magento backend to online invoice (if the option auto capture mode for openinvoice is not set)
        if (strcmp($this->_paymentMethod, 'openinvoice') === 0 || strcmp($this->_paymentMethod, 'afterpay_default') === 0 || strcmp($this->_paymentMethod, 'klarna') === 0) {
            return false;
        }
        return true;
    }

    /**
     * @param $order
     * @return mixed
     */
    protected function _paymentMethodCode($order)
    {
        return $order->getPayment()->getMethod();
    }

    protected function _getPaymentMethodType($order) {
        return $order->getPayment()->getPaymentMethodType();
    }

    /**
     * @param $paymentMethod
     * @return bool
     */
    protected function _isBankTransfer($paymentMethod) {
        if(strlen($paymentMethod) >= 12 &&  substr($paymentMethod, 0, 12) == "bankTransfer") {
            $isBankTransfer = true;
        } else {
            $isBankTransfer = false;
        }
        return $isBankTransfer;
    }

    /**
     * @param $order
     */
    protected function _setPaymentAuthorized($order, $manualReviewComment = true, $createInvoice = false)
    {
        $this->_debugData['_setPaymentAuthorized start'] = 'Set order to authorised';

        // if full amount is captured create invoice
        $currency = $order->getOrderCurrencyCode();
        $amount = $this->_value;
        $orderAmount = (int) Mage::helper('adyen')->formatAmount($order->getGrandTotal(), $currency);

        // create invoice for the capture notification if you are on manual capture
        if($createInvoice == true && $amount == $orderAmount) {
            $this->_debugData['_setPaymentAuthorized amount'] = 'amount notification:'.$amount . ' amount order:'.$orderAmount;
            $this->_createInvoice($order);
        }

        $status = $this->_getConfigData('payment_authorized', 'adyen_abstract', $order->getStoreId());
        // virtual order can have different status
        if($order->getIsVirtual()) {
            $virtual_status = $this->_getConfigData('payment_authorized_virtual');
            if($virtual_status != "") {
                $status = $virtual_status;
            }
        }

        // check for boleto if payment is totally paid
        if($this->_paymentMethodCode($order) == "adyen_boleto") {

            // check if paid amount is the same as orginal amount
            $orginalAmount = $this->_boletoOriginalAmount;
            $paidAmount = $this->_boletoPaidAmount;

            if($orginalAmount != $paidAmount) {

                // not the full amount is paid. Check if it is underpaid or overpaid
                // strip the  BRL of the string
                $orginalAmount = str_replace("BRL", "",  $orginalAmount);
                $orginalAmount = floatval(trim($orginalAmount));

                $paidAmount = str_replace("BRL", "",  $paidAmount);
                $paidAmount = floatval(trim($paidAmount));

                if($paidAmount > $orginalAmount) {
                    $overpaidStatus =  $this->_getConfigData('order_overpaid_status', 'adyen_boleto');
                    // check if there is selected a status if not fall back to the default
                    $status = (!empty($overpaidStatus)) ? $overpaidStatus : $status;
                } else {
                    $underpaidStatus = $this->_getConfigData('order_underpaid_status', 'adyen_boleto');
                    // check if there is selected a status if not fall back to the default
                    $status = (!empty($underpaidStatus)) ? $underpaidStatus : $status;
                }
            }
        }

        $comment = "Adyen Payment Successfully completed";

        // if manual review is true use the manual review status if this is set
        if($manualReviewComment == true && $this->_fraudManualReview) {
            // check if different status is selected
            $fraudManualReviewStatus = $this->_getConfigData('fraud_manual_review_status', 'adyen_abstract', $order->getStoreId());
            if($fraudManualReviewStatus != "") {
                $status = $fraudManualReviewStatus;
                $comment = "Adyen Payment is in Manual Review check the Adyen platform";
            }
        }

        $status = (!empty($status)) ? $status : $order->getStatus();
        $order->addStatusHistoryComment(Mage::helper('adyen')->__($comment), $status);
        $order->sendOrderUpdateEmail((bool) $this->_getConfigData('send_update_mail', 'adyen_abstract', $order->getStoreId()));
        $this->_debugData['_setPaymentAuthorized end'] = 'Order status is changed to authorised status, status is ' . $status;
    }

    /**
     * @param $order
     */
    protected function _createShipment($order) {
        $this->_debugData['_createShipment'] = 'Creating shipment for order';
        // create shipment for cash payment
        $payment = $order->getPayment()->getMethodInstance();
        if($order->canShip())
        {
            $itemQty = array();
            $shipment = $order->prepareShipment($itemQty);
            if($shipment) {
                $shipment->register();
                $shipment->getOrder()->setIsInProcess(true);
                $comment = Mage::helper('adyen')->__('Shipment created by Adyen');
                $shipment->addComment($comment);
                Mage::getModel('core/resource_transaction')
                    ->addObject($shipment)
                    ->addObject($shipment->getOrder())
                    ->save();
                $this->_debugData['_createShipment done'] = 'Order is shipped';
            }
        } else {
            $this->_debugData['_createShipment error'] = 'Order can\'t be shipped';
        }
    }

    /**
     * @desc order comments or history
     * @param type $order
     */
    protected function _addStatusHistoryComment($order)
    {
        $success_result = (strcmp($this->_success, 'true') == 0 || strcmp($this->_success, '1') == 0) ? 'true' : 'false';
        $success = (!empty($this->_reason)) ? "$success_result <br />reason:$this->_reason" : $success_result;

        if($this->_eventCode == Adyen_Payment_Model_Event::ADYEN_EVENT_REFUND || $this->_eventCode == Adyen_Payment_Model_Event::ADYEN_EVENT_CAPTURE) {

            $currency = $order->getOrderCurrencyCode();

            // check if it is a full or partial refund
            $amount = $this->_value;
            $orderAmount = (int) Mage::helper('adyen')->formatAmount($order->getGrandTotal(), $currency);

            $this->_debugData['_addStatusHistoryComment amount'] = 'amount notification:'.$amount . ' amount order:'.$orderAmount;

            if($amount == $orderAmount) {
                $order->setAdyenEventCode($this->_eventCode . " : " . strtoupper($success_result));
            } else {
                $order->setAdyenEventCode("(PARTIAL) " . $this->_eventCode . " : " . strtoupper($success_result));
            }
        } else {
            $order->setAdyenEventCode($this->_eventCode . " : " . strtoupper($success_result));
        }

        // if payment method is klarna or openinvoice/afterpay show the reservartion number
        if(($this->_paymentMethod == "klarna" || $this->_paymentMethod == "afterpay_default" || $this->_paymentMethod == "openinvoice") && ($this->_klarnaReservationNumber != null && $this->_klarnaReservationNumber != "")) {
            $klarnaReservationNumberText = "<br /> reservationNumber: " . $this->_klarnaReservationNumber;
        } else {
            $klarnaReservationNumberText = "";
        }

        if($this->_boletoPaidAmount != null && $this->_boletoPaidAmount != "") {
            $boletoPaidAmountText = "<br /> Paid amount: " . $this->_boletoPaidAmount;
        } else {
            $boletoPaidAmountText = "";
        }

        $type = 'Adyen HTTP Notification(s):';
        $comment = Mage::helper('adyen')
            ->__('%s <br /> eventCode: %s <br /> pspReference: %s <br /> paymentMethod: %s <br /> success: %s %s %s', $type, $this->_eventCode, $this->_pspReference, $this->_paymentMethod, $success, $klarnaReservationNumberText, $boletoPaidAmountText);

        // If notification is pending status and pending status is set add the status change to the comment history
        if($this->_eventCode == Adyen_Payment_Model_Event::ADYEN_EVENT_PENDING)
        {
            $pendingStatus = $this->_getConfigData('pending_status', 'adyen_abstract', $order->getStoreId());
            if($pendingStatus != "") {
                $order->addStatusHistoryComment($comment, $pendingStatus);
                $this->_debugData['_addStatusHistoryComment'] = 'Created comment history for this notification with status change to: ' . $pendingStatus;
                return;
            }
        }

        $order->addStatusHistoryComment($comment);
        $this->_debugData['_addStatusHistoryComment'] = 'Created comment history for this notification';
    }
    /**
     * @param $order
     * @return bool
     * @deprecate not needed already cancelled in ProcessController
     */
    protected function _holdCancelOrder($order, $ignoreHasInvoice)
    {
        $orderStatus = $this->_getConfigData('payment_cancelled', 'adyen_abstract', $order->getStoreId());

        $_mail = (bool) $this->_getConfigData('send_update_mail', 'adyen_abstract', $order->getStoreId());
        $helper = Mage::helper('adyen');

        // check if order has in invoice only cancel/hold if this is not the case
        if ($ignoreHasInvoice || !$order->hasInvoices()) {
            $order->setActionFlag($orderStatus, true);

            if($orderStatus == Mage_Sales_Model_Order::STATE_HOLDED) {
                if ($order->canHold()) {
                    $order->hold();
                } else {
                    $this->_debugData['warning'] = 'Order can not hold or is already on Hold';
                    return;
                }
            } else {
                if ($order->canCancel()) {
                    $order->cancel();
                } else {
                    $this->_debugData['warning'] = 'Order can not be canceled';
                    return;
                }
            }
            $order->sendOrderUpdateEmail($_mail);
        } else {
            $this->_debugData['warning'] = 'Order has already an invoice so cannot be canceled';
        }
    }

    /*
     * Add AUTHORISATION notification where order does not exists to the queue
     */
    /**
     * @param $params
     */
    protected function _addNotificationToQueue($params) {

        $eventCode = trim($params->getData('eventCode'));
        $success = (trim($params->getData('success')) == 'true' || trim($params->getData('success')) == '1') ? true : false;
        // only log the AUTHORISATION with Sucess true because with false the order will never be created in magento
        if($eventCode == Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISATION && $success == true) {
            // pspreference is always numeric otherwise it is a test notification
            $pspReference = $params->getData('pspReference');
            if(is_numeric($pspReference)) {
                $this->_debugData['AddNotificationToQueue Step1'] = 'Going to add notification to queue';

                $incrementId = $params->getData('merchantReference');
                $pspReference = $params->getData('pspReference');
                $eventCode = $params->getData('eventCode');

                // check if already exists in the queue (sometimes Adyen Platform can send the same notification twice)
                $eventResults = Mage::getModel('adyen/event_queue')->getCollection()
                    ->addFieldToFilter('increment_id', $incrementId);
                $eventResults->getSelect()->limit(1);

                $eventQueue = null;
                if($eventResults->getSize() > 0) {
                    $eventQueue = current($eventResults->getItems());
                }

                if($eventQueue) {
                    $this->_debugData['AddNotificationToQueue Step2'] = 'Notification already in the queue';
                    $attempt = (int)$eventQueue->getAttempt();
                    try{
                        $eventQueue->setAttempt(++$attempt);
                        $eventQueue->save();
                        $this->_debugData['AddNotificationToQueue Step3'] = 'Updated the attempt of the Queue to ' . $eventQueue->getAttempt();
                    } catch(Exception $e) {
                        $this->_debugData['AddNotificationToQueue error'] = 'Could not update the notification to queue, reason: ' . $e->getMessage();
                        Mage::logException($e);
                    }
                } else {
                    try {
                        // add current request to the queue
                        $eventQueue = Mage::getModel('adyen/event_queue');
                        $eventQueue->setPspReference($pspReference);
                        $eventQueue->setAdyenEventCode($eventCode);
                        $eventQueue->setIncrementId($incrementId);
                        $eventQueue->setAttempt(1);
                        $eventQueue->setResponse(serialize($params));
                        $eventQueue->setCreatedAt(now());
                        $eventQueue->save();
                        $this->_debugData['AddNotificationToQueue Step2'] = 'Notification is added to the queue';
                    } catch(Exception $e) {
                        $this->_debugData['AddNotificationToQueue error'] = 'Could not save the notification to queue, reason: ' . $e->getMessage();
                        Mage::logException($e);
                    }
                }
            } else {
                $this->_debugData['AddNotificationToQueue'] = 'Notification is a TEST Notification so do not add to queue';
            }
        } else {
            $this->_debugData['AddNotificationToQueue'] = 'Notification is not a AUTHORISATION Notification so do not add to queue';
        }
    }


    /*
     * This function is called from the cronjob
     */
    public function updateNotProcessedNotifications() {

        $this->_debugData = array();

        $this->_debugData['processPosResponse begin'] = 'Begin to process cronjob for updating notifications from the queue';

        $this->_updateNotProcessedNotifications();

        $this->_debugData['processPosResponse end'] = 'Cronjob ends';

        return $this->_debugData;
    }

    /**
     *
     */
    protected function _updateNotProcessedNotifications() {

        $this->_debugData['UpdateNotProcessedEvents Step1'] = 'Going to update Notifications from the queue';
        // try to update old notifications that did not processed yet
        $collection = Mage::getModel('adyen/event_queue')->getCollection()
            ->addFieldToFilter('attempt', array('lteq' => '4'));

        if($collection->getSize() > 0) {
            foreach($collection as $event){
                if($event->getAdyenEventCode() == Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISATION) {

                    $incrementId = $event->getIncrementId();

                    $this->_debugData['UpdateNotProcessedEvents Step2'] = 'Going to update notification with incrementId: ' . $incrementId;

                    $order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
                    if ($order->getId()) {

                        $this->_debugData['UpdateNotProcessedEvents Step3'] = 'Order exists going to update it';
                        // try to process it now
                        $params = unserialize($event->getResponse());

                        $this->_debugData['UpdateNotProcessedEvents params'] = $params->debug();

                        $this->_updateOrder($order, $params);

                        // update event that it is processed
                        try{
                            // @codingStandardsIgnoreStart
                            $event->delete();
                            // @codingStandardsIgnoreEnd
                            $this->_debugData['UpdateNotProcessedEvents Step4'] = 'Notification is processed and removed from the queue';
                        } catch(Exception $e) {
                            Mage::logException($e);
                        }
                    } else {
                        // order still not exists save this attempt
                        $currentAttempt = $event->getAttempt();
                        $event->setAttempt(++$currentAttempt);
                        // @codingStandardsIgnoreStart
                        $event->save();
                        // @codingStandardsIgnoreEnd
                        $this->_debugData['UpdateNotProcessedEvents Step3'] = 'The Notification still does not exists updated attempt to ' . $event->getAttempt();
                    }
                }
            }
        } else {
            $this->_debugData['UpdateNotProcessedEvents Step2'] = 'The queue is empty';
        }
    }

    /**
     * Log debug data to file
     *
     * @param $storeId
     * @param mixed $debugData
     */
    protected function _debug($storeId)
    {
        if ($this->_getConfigData('debug', 'adyen_abstract', $storeId)) {
            $file = 'adyen_process_notification.log';
            Mage::getModel('core/log_adapter', $file)->log($this->_debugData);
        }
    }

    /**
     * @param $code
     * @param null $paymentMethodCode
     * @param null $storeId
     * @return mixed
     */
    protected function _getConfigData($code, $paymentMethodCode = null, $storeId = null)
    {
        return Mage::helper('adyen')->getConfigData($code, $paymentMethodCode, $storeId);
    }

    /**
     * @return mixed
     */
    protected function _getRequest()
    {
        return Mage::app()->getRequest();
    }
}