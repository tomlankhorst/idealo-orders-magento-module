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
     * Return currenctly selected scope store id in the admin area
     *
     * @return int
     */
    protected function _getConfigScopeStoreId()
    {
        $iStoreId = Mage_Core_Model_App::ADMIN_STORE_ID;
        $sStoreCode = (string)Mage::app()->getRequest()->getParam('store');
        $sWebsiteCode = (string)Mage::app()->getRequest()->getParam('website');
        if ('' !== $sStoreCode) { // store level
            $iStoreId = Mage::getModel('core/store')->load( $sStoreCode )->getId();
        } elseif ('' !== $sWebsiteCode) { // website level
            $iStoreId = Mage::getModel('core/website')->load( $sWebsiteCode )->getDefaultStore()->getId();
        }
        return $iStoreId;
    }

    public function setAdminStoreId()
    {
        $iStoreId = $this->_getConfigScopeStoreId();
        $oStore = Mage::getModel('core/store')->load($iStoreId);
        $this->setStore($oStore);
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

            $oClient = new idealo\Direktkauf\REST\Client($sToken, $blIsLive);
            $oClient->setERPShopSystem('Magento');
            $oClient->setERPShopSystemVersion(Mage::getVersion());
            $oClient->setIntegrationPartner('FATCHIP');
            $oClient->setInterfaceVersion(Mage::getConfig()->getNode()->modules->Idealo_Direktkauf->version);
            $this->_oClient = $oClient;
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
     * Get active payment type codes
     *
     * @param int $iStoreId
     * @return array
     */
    protected function _getActivePaymentMethods($iStoreId)
    {
        // inspired by Mage::getSingleton('payment/config')->getActiveMethods($iStoreId) but this has a bug with paypal
        $aMethods = array();
        $aConfig = Mage::getStoreConfig('payment', $iStoreId);
        foreach ($aConfig as $sCode => $aMethodConfig) {
            if (Mage::getStoreConfigFlag('payment/' . $sCode . '/active', $iStoreId)) {
                if (array_key_exists('model', $aMethodConfig)) {
                    $oMethodModel = Mage::getModel($aMethodConfig['model']);
                    if (stripos($sCode, 'paypal') !== false || ($oMethodModel && $oMethodModel->getConfigData('active', $iStoreId))) {
                        $aMethods[] = $sCode;
                    }
                }
            }
        }
        return $aMethods;
    }

    /**
     * Get shop payment types
     *
     * @return array
     */
    public function getShopPaymentTypes()
    {
        $aMethods = array();
        $iStoreId = $this->getStore()->getId();
        $aPayments = $this->_getActivePaymentMethods($iStoreId);
        foreach ($aPayments as $sPaymentCode) {
            $aMethods[$sPaymentCode] = Mage::getStoreConfig('payment/'.$sPaymentCode.'/title', $iStoreId);
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
        $iStoreId = $this->getStore()->getId();
        $aMethods = Mage::getSingleton('shipping/config')->getActiveCarriers($iStoreId);

        $aDeliveryTypes = array();
        foreach ($aMethods as $sCarrierCode => $oCarrier) {
            if (!$sTitle = Mage::getStoreConfig("carriers/$sCarrierCode/title", $iStoreId)) {
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