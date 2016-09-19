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
 * Delivery mapping block for admin config
 */
class Idealo_Direktkauf_Block_Adminhtml_Config_DeliveryMapping extends Mage_Adminhtml_Block_System_Config_Form_Field_Array_Abstract
{

    /**
     * Mapping for available idealo delivery types
     * 
     * @var array
     */
    protected $_aDefaultIdealoDeliveryTypes = array(
        'POSTAL' => 'Post-Versand',
        'FORWARDING' => 'Spedition',
    );
    
    /**
     * Mapping for available idealo delivery carriers
     * 
     * @var array
     */
    protected $_aDefaultDeliveryCarriers = array(
        'Cargo' => 'Cargo',
        'DHL' => 'DHL',
        'DPD' => 'DPD',
        'Der Courier' => 'Der Courier',
        'Deutsche Post' => 'Deutsche Post',
        'FedEx' => 'FedEx',
        'GLS' => 'GLS',
        'GO!' => 'GO!',
        'GdSK' => 'GdSK',
        'Hermes' => 'Hermes',
        'Midway' => 'Midway',
        'Noxxs Logistic' => 'Noxxs Logistic',
        'TOMBA' => 'TOMBA',
        'UPS' => 'UPS',
        'eparcel' => 'eparcel',
        'iloxx' => 'iloxx',
        'paket.ag' => 'paket.ag',
        'primeMail' => 'primeMail',
        'other' => 'Anderer',
    );
    
    /**
     * Constructor
     * 
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('idealo/direktkauf/config/delivery_mapping.phtml');
    }
    
    /**
     * Needed to render the page correctly
     * 
     * @return void
     */
    protected function _prepareToRender()
    {
        $this->addColumn('not needed', array(
            'label' =>'A',
            'style' => '',
        ));
        parent::_prepareToRender();
    }
    
    /**
     * Return idealo delivery carriers
     * 
     * @return array
     */
    public function getIdealoDeliveryCarriers() 
    {
        $aCarriers = $this->_aDefaultDeliveryCarriers;
        $aCarriers['other'] = Mage::helper('idealo_direktkauf')->__('Other');
        return $this->_aDefaultDeliveryCarriers;
    }
    
    /**
     * Return idealo delivery types
     * 
     * @return array
     */
    public function getIdealoDeliveryTypes() 
    {
        return $this->_aDefaultIdealoDeliveryTypes;
    }
    
    /**
     * Return all active shop delivery types
     * 
     * @return array
     */
    public function getShopDeliveryTypes() 
    {
        return Mage::helper('idealo_direktkauf')->getShopDeliveryTypes();
    }

    /**
     * Return configured type info for given key
     * 
     * @param string $sKey
     * @return string|bool
     */
    public function getValueByTypeKey($sKey)
    {
        $aValue = $this->getElement()->getValue();
        if (isset($aValue[$sKey]['type'])) {
            return $aValue[$sKey]['type'];
        }
        return false;
    }
    
    /**
     * Return configured carrier info for given key
     * 
     * @param string $sKey
     * @return bool
     */
    public function getValueByCarrierKey($sKey)
    {
        $aValue = $this->getElement()->getValue();
        if (isset($aValue[$sKey]['carrier'])) {
            return $aValue[$sKey]['carrier'];
        }
        return false;
    }

}