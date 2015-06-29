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
class Adyen_Payment_Block_Redirect extends Mage_Core_Block_Abstract {

    /**
     * Collected debug information
     *
     * @var array
     */
    protected $_debugData = array();

    protected function _getCheckout() {
        return Mage::getSingleton('checkout/session');
    }

    protected function _getOrder() {
        if ($this->getOrder()) {
            return $this->getOrder();
        } else {
            // log the exception
            Mage::log("Redirect exception could not load the order:", Zend_Log::DEBUG, "adyen_notification.log", true);
            return null;
        }
    }

    protected function _toHtml() {

        $order = $this->_getOrder();
        $paymentObject = $order->getPayment();
        $payment = $order->getPayment()->getMethodInstance();

        $html = '<html><head><link rel="stylesheet" type="text/css" href="'.Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_SKIN).'/frontend/base/default/css/adyenstyle.css"><script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js" ></script>';

        // for cash add epson libary to open the cash drawer
        $cashDrawer = $this->_getConfigData("cash_drawer", "adyen_pos", null);
        if($payment->getCode() == "adyen_hpp_c_cash" && $cashDrawer) {
            $jsPath = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_JS);
            $html .= '<script src="'.$jsPath.'adyen/payment/epos-device-2.6.0.js"></script>';
        }
        $html .= '</head><body class="redirect-body-adyen">';


        // if pos payment redirect to app
        if($payment->getCode() == "adyen_pos") {

            $adyFields = $payment->getFormFields();
            // use the secure url (if not secure this will be filled in with http://
            $url = urlencode(Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK, true)."adyen/process/successPos");

            // detect ios or android
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
            $iPod    = stripos($userAgent,"iPod");
            $iPhone  = stripos($userAgent,"iPhone");
            $iPad    = stripos($userAgent,"iPad");
            $Android = stripos($userAgent,"Android");
            $webOS   = stripos($userAgent,"webOS");

            // extra parameters so that you alway's return these paramters from the application
            $extra_paramaters = urlencode("/?originalCustomCurrency=".$adyFields['currencyCode']."&originalCustomAmount=".$adyFields['paymentAmount']. "&originalCustomMerchantReference=".$adyFields['merchantReference'] . "&originalCustomSessionId=".session_id());

            // add recurring before the callback url
            if(empty($adyFields['recurringContract'])) {
                $recurring_parameters = "";
            } else {
                $recurring_parameters = "&recurringContract=".urlencode($adyFields['recurringContract'])."&shopperReference=".urlencode($adyFields['shopperReference']). "&shopperEmail=".urlencode($adyFields['shopperEmail']);
            }



            $addReceiptOrderLines = $this->_getConfigData("add_receipt_order_lines", "adyen_pos", null);

            $receiptOrderLines = "";
            if($addReceiptOrderLines) {
                $orderLines = base64_encode($this->getReceiptOrderLines($this->_getOrder()));
                $receiptOrderLines = "&receiptOrderLines=" . urlencode($orderLines);
            }

            // important url must be the latest parameter before extra parameters! otherwise extra parameters won't return in return url
            $launchlink = "adyen://payment?sessionId=".session_id()."&amount=".$adyFields['paymentAmount']."&currency=".$adyFields['currencyCode']."&merchantReference=".$adyFields['merchantReference']. $recurring_parameters . $receiptOrderLines .  "&callback=".$url . $extra_paramaters;

            // log the launchlink
            $this->_debugData['LaunchLink'] = $launchlink;
            $storeId = $order->getStoreId();
            $this->_debug($storeId);

            // call app directly without HPP
            $html .= "<div id=\"pos-redirect-page\">
    					<div class=\"logo\"></div>
    					<div class=\"grey-header\">
    						<h1>POS Payment</h1>
    					</div>
    					<div class=\"amount-box\">".
                $adyFields['paymentAmountGrandTotal'] .
                "<a id=\"launchlink\" href=\"".$launchlink ."\" >Payment</a> ".
                "<span id=\"adyen-redirect-text\">If you stuck on this page please press the payment button</span></div>";

            $html .= '<script type="text/javascript">
    				//<![CDATA[
    				function checkStatus() {
	    				$.ajax({
						    url: "'. $this->getUrl('adyen/process/getOrderStatus', array('_secure'=>true)) . '",
						    type: "POST",
						    data: "merchantReference='.$adyFields['merchantReference'] .'",
						    asynchronous: false,
						    success: function(data) {
						    	if(data == "true") {
						    		// redirect to success page
						    		window.location.href = "'. Mage::getBaseUrl()."adyen/process/successPosRedirect" . '";
						    	} else {
						    		window.location.href = "'. Mage::getBaseUrl()."adyen/process/cancel" . '";			
						    	}
						    }
						});
					}';

            $expressCheckoutRedirectConnect = $this->_getConfigData("express_checkout_redirect_connect", "adyen_pos", null);

            if($expressCheckoutRedirectConnect) {

                if($iPod || $iPhone || $iPad) {
                    $html .= 'document.getElementById(\'launchlink\').click();';
                    $html .= 'setTimeout("checkStatus()", 5000);';
                } else {
                    // android
                    $html .= 'var isActive;
                    window.onfocus = function () {
                      isActive = true;
                    };

                    window.onblur = function () {
                      isActive = false;
                    };

                    // test
                    setInterval(function () {
                        checkStatus();
                    }, 1000);';
                    $html .= 'url = document.getElementById(\'launchlink\').href;';
                    $html .= 'window.location = url;';
                }
            } else {

                $html .= '  var eventName = "visibilitychange";
                            document.addEventListener(eventName,visibilityChanged,false);
                            function visibilityChanged() {
                                if (document.hidden || document.mozHidden || document.msHidden || document.webkitHidden)
                                {
                                    //Page got hidden; Adyen App called and transaction on terminal triggered
                                } else {
                                    //The page is showing again; Cash Register regained control from Adyen App
                                    checkStatus();
                                }
                            }';
            }

            $html .= '
                        //]]>
                        </script>
                    </div>';
        } else {
            $form = new Varien_Data_Form();
            $form->setAction($payment->getFormUrl())
                ->setId($payment->getCode())
                ->setName($payment->getFormName())
                ->setMethod('POST')
                ->setUseContainer(true);
            foreach ($payment->getFormFields() as $field => $value) {
                $form->addField($field, 'hidden', array('name' => $field, 'value' => $value));
            }

            $html.= $this->__(' ');
            $html.= $form->toHtml();

            if($payment->getCode() == "adyen_hpp_c_cash" && $cashDrawer) {

                $cashDrawerIp = $this->_getConfigData("cash_drawer_printer_ip", "adyen_pos", null);
                $cashDrawerPort = $this->_getConfigData("cash_drawer_printer_port", "adyen_pos", null);
                $cashDrawerDeviceId = $this->_getConfigData("cash_drawer_printer_device_id", "adyen_pos", null);

                if($cashDrawerIp != '' && $cashDrawerPort != '' && $cashDrawerDeviceId != '') {
                    $html.= '
                            <script type="text/javascript">
                                var ipAddress = "'.$cashDrawerIp.'";
                                var port = "'.$cashDrawerPort.'";
                                var deviceID = "'.$cashDrawerDeviceId.'";
                                var ePosDev = new epson.ePOSDevice();
                                ePosDev.connect(ipAddress, port, Callback_connect);

                                function Callback_connect(data) {
                                    if (data == "OK" || data == "SSL_CONNECT_OK") {
                                        var options = "{}";
                                        ePosDev.createDevice(deviceID, ePosDev.DEVICE_TYPE_PRINTER, options, callbackCreateDevice_printer);
                                    } else {
                                        alert("connected to ePOS Device Service Interface is failed. [" + data + "]");
                                    }
                                }

                                function callbackCreateDevice_printer(data, code) {
                                    var print = data;
                                    var drawer = "{}";
                                    var time = print.PULSE_100
                                    print.addPulse();
                                    print.send();
                                    document.getElementById("'.$payment->getCode().'").submit();
                                }
                            </script>
                    ';
                } else {
                    Mage::log("You did not fill in all the fields (ip,port,device id) to use Cash Drawer support:", Zend_Log::DEBUG, "adyen_notification.log", true);
                }
            } else {
                $html.= '<script type="text/javascript">document.getElementById("'.$payment->getCode().'").submit();</script>';
            }
        }
        $html.= '</body></html>';
        return $html;
    }


    /**
     * Log debug data to file
     *
     * @param mixed $debugData
     */
    protected function _debug($storeId)
    {
        if ($this->_getConfigData('debug', 'adyen_abstract', $storeId)) {
            $file = 'adyen_request_pos.log';
            Mage::getModel('core/log_adapter', $file)->log($this->_debugData);
        }
    }

    private function getReceiptOrderLines($order) {

        $myReceiptOrderLines = "";

        // temp
        $currency = $order->getOrderCurrencyCode();
        $formattedAmountValue = Mage::helper('core')->formatPrice($order->getGrandTotal(), false);

        $formattedAmountValue = Mage::getModel('directory/currency')->format(
            $order->getGrandTotal(),
            array('display'=>Zend_Currency::NO_SYMBOL),
            false
        );

        $taxAmount = Mage::helper('checkout')->getQuote()->getShippingAddress()->getData('tax_amount');
        $formattedTaxAmount = Mage::getModel('directory/currency')->format(
            $taxAmount,
            array('display'=>Zend_Currency::NO_SYMBOL),
            false
        );

        $paymentAmount = "1000";

        $myReceiptOrderLines .= "---||C\n".
            "====== YOUR ORDER DETAILS ======||CB\n".
            "---||C\n".
            " No. Description |Piece  Subtotal|\n";

        foreach ($order->getItemsCollection() as $item) {
            //skip dummies
            if ($item->isDummy()) continue;
            $singlePriceFormat = Mage::getModel('directory/currency')->format(
                $item->getPriceInclTax(),
                array('display'=>Zend_Currency::NO_SYMBOL),
                false
            );

            $itemAmount = $item->getPriceInclTax() * (int) $item->getQtyOrdered();
            $itemAmountFormat = Mage::getModel('directory/currency')->format(
                $itemAmount,
                array('display'=>Zend_Currency::NO_SYMBOL),
                false
            );
            $myReceiptOrderLines .= "  " . (int) $item->getQtyOrdered() . "  " . trim(substr($item->getName(),0, 25)) . "| " . $currency . " " . $singlePriceFormat . "  " . $currency . " " . $itemAmountFormat . "|\n";
        }

        //discount cost
        if($order->getDiscountAmount() > 0 || $order->getDiscountAmount() < 0)
        {
            $discountAmountFormat = Mage::getModel('directory/currency')->format(
                $order->getDiscountAmount(),
                array('display'=>Zend_Currency::NO_SYMBOL),
                false
            );
            $myReceiptOrderLines .= "  " . 1 . " " . $this->__('Total Discount') . "| " . $currency . " " . $discountAmountFormat ."|\n";
        }

        //shipping cost
        if($order->getShippingAmount() > 0 || $order->getShippingTaxAmount() > 0)
        {
            $shippingAmountFormat = Mage::getModel('directory/currency')->format(
                $order->getShippingAmount(),
                array('display'=>Zend_Currency::NO_SYMBOL),
                false
            );
            $myReceiptOrderLines .= "  " . 1 . " " . $order->getShippingDescription() . "| " . $currency . " " . $shippingAmountFormat ."|\n";

        }

        if($order->getPaymentFeeAmount() > 0) {
            $paymentFeeAmount =  Mage::getModel('directory/currency')->format(
                $order->getPaymentFeeAmount(),
                array('display'=>Zend_Currency::NO_SYMBOL),
                false
            );
            $myReceiptOrderLines .= "  " . 1 . " " . $this->__('Payment Fee') . "| " . $currency . " " . $paymentFeeAmount ."|\n";

        }

        $myReceiptOrderLines .=    "|--------|\n".
            "|Order Total:  ".$currency." ".$formattedAmountValue."|B\n".
            "|Tax:  ".$currency." ".$formattedTaxAmount."|B\n".
            "||C\n";

        //Cool new header for card details section! Default location is After Header so simply add to Order Details as separator
        $myReceiptOrderLines .= "---||C\n".
            "====== YOUR PAYMENT DETAILS ======||CB\n".
            "---||C\n";


        return $myReceiptOrderLines;

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

}
