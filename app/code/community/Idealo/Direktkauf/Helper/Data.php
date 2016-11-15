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
 * idealo Direktkauf helper class
 */
class Idealo_Direktkauf_Helper_Data extends Mage_Core_Helper_Abstract
{

    /**
     * idealo SDK client
     *
     * @var object
     */
    protected $_oClient = null;
    
    /**
     * Current store scope
     * 
     * @var Mage_Core_Model_Store
     */
    protected $_oStore = null;
    
    /**
     * Set the current store scope
     * 
     * @param  Mage_Core_Model_Store $oStore
     * @return void
     */
    public function setStore(Mage_Core_Model_Store $oStore)
    {
        $this->_oStore = $oStore;
        $this->_oClient = null;
    }
    
    /**
     * Return the current store
     * 
     * @return Mage_Core_Model_Store
     */
    public function getStore()
    {
        if ($this->_oStore === null) {
            return Mage::app()->getStore();
        }
        
        return $this->_oStore;
    }

    /**
     * Return idealo SDK client
     *
     * @return object
     */
    public function getClient()
    {
        if ($this->_oClient === null) {
            require_once(Mage::getBaseDir('lib').'/idealo/Direktkauf/REST/Client.php');

            $sToken = $this->getAuthToken();
            $blIsLive = $this->getMode() == 'live' ? true : false;

            $this->_oClient = new idealo\Direktkauf\REST\Client($sToken, $blIsLive);
        }
        
        return $this->_oClient;
    }

    /**
     * Return a parameter from config
     *
     * @param string $sKey
     * @param string $sGroup
     * @return string
     */
    protected function _getConfigParam($sKey, $sGroup = 'basic')
    {
        $sConfigPath = "idealo_direktkauf/{$sGroup}/{$sKey}";
        $sValue = Mage::getStoreConfig($sConfigPath, $this->getStore());
        return $sValue;
    }

    /**
     * Return if module is active
     * 
     * @return bool
     */
    public function isActive()
    {
        return (bool)$this->_getConfigParam('active');
    }
    
    /**
     * Return operation mode from config
     * live or test
     *
     * @return string
     */
    public function getMode()
    {
        return $this->_getConfigParam('mode');
    }

    /**
     * Return idealo API auth token from config
     *
     * @return string
     */
    public function getAuthToken()
    {
        return $this->_getConfigParam('auth_token');
    }

    /**
     * Return error email address from config
     *
     * @return string
     */
    public function getErrorEmailAddress()
    {
        return $this->_getConfigParam('error_email');
    }

    /**
     * Return cancellation reason from config
     *
     * @return string
     */
    public function getCancellationReason()
    {
        return $this->_getConfigParam('cancellation_reason');
    }

    /**
     * Return logging active status from config
     *
     * @return bool
     */
    public function isLoggingActive()
    {
        return (bool)$this->_getConfigParam('logging_active');
    }
    
    /**
     * Return if the multistore mode is enabled
     *
     * @return bool
     */
    public function isMultistoreActive()
    {
        return (bool)$this->_getConfigParam('multistore');
    }

    /**
     * Get new order status from shop config
     *
     * @return array
     */
    public function getNewOrderStatus()
    {
        $sNewOrderStatus = $this->_getConfigParam('order_status', 'mappings');
        if (strpos($sNewOrderStatus, '|') !== false) {
            $aSplit = explode('|', $sNewOrderStatus);
            return array(
                'state' => $aSplit[0],
                'status' => $aSplit[1],
            );
        }
        
        return array(
            'state' => 'new',
            'status' => 'pending',
        );
    }

    /**
     * Get payment mapping from shop config
     *
     * @return array
     */
    public function getPaymentMapping()
    {
        $sPaymentMapping = $this->_getConfigParam('payment_mapping', 'mappings');
        $aPaymentMapping = unserialize($sPaymentMapping);
        return $aPaymentMapping;
    }

    /**
     * Get delivery mapping from shop config
     *
     * @return array
     */
    public function getDeliveryMapping()
    {
        $sDeliveryMapping = $this->_getConfigParam('delivery_mapping', 'mappings');
        $aDeliveryMapping = unserialize($sDeliveryMapping);
        return $aDeliveryMapping;
    }

    /**
     * Get shop payment types
     *
     * @return array
     */
    public function getShopPaymentTypes()
    {
        $aPayments = Mage::getSingleton('payment/config')->getActiveMethods();
        $aMethods = array();
        foreach ($aPayments as $sPaymentCode => $oPaymentModel) {
            $aMethods[$sPaymentCode] = Mage::getStoreConfig('payment/'.$sPaymentCode.'/title');
        }
        
        return $aMethods;
    }

    /**
     * Get shop delivery types
     *
     * @return array
     */
    public function getShopDeliveryTypes()
    {
        $aMethods = Mage::getSingleton('shipping/config')->getActiveCarriers();

        $aDeliveryTypes = array();
        foreach ($aMethods as $sCarrierCode => $oCarrier) {
            if (!$sTitle = Mage::getStoreConfig("carriers/$sCarrierCode/title")) {
                $sTitle = $sCarrierCode;
            }
            
            if ($aMethods = $oCarrier->getAllowedMethods()) {
                foreach ($aMethods as $sMethodCode => $sMethod) {
                    $sCode = $sCarrierCode . '_' . $sMethodCode;
                    $aDeliveryTypes[$sCode] = $sTitle.' - '.$sMethod;
                }
            }
        }
        
        return $aDeliveryTypes;
    }

}