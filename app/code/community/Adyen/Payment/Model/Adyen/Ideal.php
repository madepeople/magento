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
 
class Adyen_Payment_Model_Adyen_Ideal
    extends Adyen_Payment_Model_Adyen_Hpp
{
    protected $_code = 'adyen_ideal';
    protected $_formBlockType = 'adyen/form_ideal';

    /**
     * @return mixed
     */
    public function getShowIdealLogos()
    {
        return $this->_getConfigData('show_ideal_logos', 'adyen_ideal');
    }

    public function getIssuers()
    {
        $issuerData = json_decode($this->getConfigData('issuers'), true);
        $issuers = array();
        if (! $issuerData) {
            return $issuers;
        }
        foreach ($issuerData as $issuer) {
            $issuers[$issuer['issuerId']] = array(
                'label' => $issuer['name']
            );
        }

        // check if auto select is turned on in the settings
        if ($this->_getConfigData('autoselect_stored_ideal_bank', 'adyen_ideal')) {
            if (isset($issuers[$this->getInfoInstance()->getPoNumber()])) {
                $issuers[$this->getInfoInstance()->getPoNumber()]['selected'] = true;
            }
        }

        ksort($issuers);
        return $issuers;
    }

    public function validate()
    {
        parent::validate();
        $info    = $this->getInfoInstance();
        $hppType = $info->getCcType();
        // validate if the ideal bank is chosen
        if ($hppType == "ideal") {
            if ($info->getPoNumber() == "") {
                // hpp type is empty throw error
                Mage::throwException(Mage::helper('adyen')->__('You chose an invalid bank'));
            }
        }
    }
}
