<?php
/*
   Copyright 2016 idealo internet GmbH

   Licensed under the Apache License, Version 2.0 (the "License");
   you may not use this file except in compliance with the License.
   You may obtain a copy of the License at

       http://www.apache.org/licenses/LICENSE-2.0

   Unless required by applicable law or agreed to in writing, software
   distributed under the License is distributed on an "AS IS" BASIS,
   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
   See the License for the specific language governing permissions and
   limitations under the License.
*/

/**
 * Payment mapping block class for admin config
 */
class Idealo_Direktkauf_Block_Adminhtml_Config_PaymentMapping 
    extends Mage_Adminhtml_Block_System_Config_Form_Field_Array_Abstract
{

    /**
     * Array of available idealo payment types
     * 
     * @var array
     */
    protected $_aDefaultIdealoPaymentTypes = array(
        'PAYPAL' => 'PayPal Payment Method',
        'CREDITCARD' => 'Credit Card Payment Method (Heidelpay)',
        'SOFORT' => 'SOFORT &Uuml;berweisung Payment Method',
    );
    
    /**
     * Constructor
     * 
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('idealo/direktkauf/config/payment_mapping.phtml');
    }
    
    /**
     * Needed to render the page correctly
     * 
     * @return void
     */
    protected function _prepareToRender()
    {
        $this->addColumn(
            'not needed', 
            array(
                'label' =>'',
                'style' => '',
            )
        );
        parent::_prepareToRender();
    }
    
    /**
     * Return idealo payment types
     * 
     * @return array
     */
    public function getIdealoPaymentTypes()
    {   
        $oClient = Mage::helper('idealo_direktkauf')->getClient();
        $aTypes = $oClient->getSupportedPaymentTypes();
        if (!is_array($aTypes) || count($aTypes) == 0) {
            $aTypes = $this->_aDefaultIdealoPaymentTypes;
        }
        
        return $aTypes;
    }
    
    /**
     * Return all active payment types
     * 
     * @return array
     */
    public function getShopPaymentTypes()
    {
        return Mage::helper('idealo_direktkauf')->getShopPaymentTypes();
    }
    
    /**
     * Return configured value for given key
     * 
     * @param string $sKey
     * @return string|bool
     */
    public function getValueByKey($sKey)
    {
        $aValue = $this->getElement()->getValue();
        if (isset($aValue[$sKey])) {
            return $aValue[$sKey];
        }
        
        return false;
    }

}