<?php
/**
 *               _
 *              | |
 *     __ _   _ | | _  _   ___  _ __
 *    / _` | / || || || | / _ \| '  \
 *   | (_| ||  || || || ||  __/| || |
 *    \__,_| \__,_|\__, | \___||_||_|
 *                 |___/
 *
 * Adyen Subscription module (https://www.adyen.com/)
 *
 * Copyright (c) 2015 H&O (http://www.h-o.nl/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>, H&O <info@h-o.nl>
 */

class Adyen_Fee_Block_Adminhtml_System_Config_HppPaymentMethodFee
    extends Mage_Adminhtml_Block_System_Config_Form_Field_Array_Abstract
{
    public function __construct()
    {
        $this->addColumn('code', array(
            'label' => Mage::helper('adyen')->__('Payment Method Code'),
            'style' => 'width:250px',
        ));
        $this->addColumn('amount', array(
            'label' => Mage::helper('core')->__('Fixed costs'),
            'style' => 'width:100px',
        ));

        $this->addColumn('percentage', array(
            'label' => Mage::helper('core')->__('Variable costs (%)'),
            'style' => 'width:100px',
        ));

        $this->_addAfter = false;
        $this->_addButtonLabel = Mage::helper('core')->__('Add Fee');

        parent::__construct();
    }
}
