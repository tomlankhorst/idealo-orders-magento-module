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
class Idealo_Direktkauf_Block_Adminhtml_Order_Info extends Mage_Core_Block_Template
{

    protected $_order;
    
    public function getOrder() 
    {
        if ($this->_order === null) {
            if (Mage::registry('current_order')) {
                $order = Mage::registry('current_order');
            } elseif (Mage::registry('order')) {
                $order = Mage::registry('order');
            } else {
                $order = new Varien_Object();
            }
            
            $this->_order = $order;
        }
        
        return $this->_order;
    }
    
    public function getIdealoOrderNr()
    {
        $oOrder = $this->getOrder();
        if ($oOrder) {
            $sIdealoOrderNr = $oOrder->getIdealoOrderNr();
            if ($sIdealoOrderNr) {
                return $sIdealoOrderNr;
            }
        }
        
        return false;
    }
    
    public function getFulfillmentOptions()
    {
        $aOptions = array();
        
        $oOrder = $this->getOrder();
        if ($oOrder) {
            $sDelimiter = ';';
            $sCurrency = $oOrder->getOrderCurrencyCode();
            
            $sFulfillmentTypes = $oOrder->getIdealoFulfillmentType();
            $sFulfillmentPrices = $oOrder->getIdealoFulfillmentPrice();
            
            $aFulfillmentTypes = explode($sDelimiter, $sFulfillmentTypes);
            $aFulfillmentPrices = explode($sDelimiter, $sFulfillmentPrices);
            
            for ($i = 0; $i < count($aFulfillmentTypes); $i++) {
                $aOptions[] = array(
                    'type' => $aFulfillmentTypes[$i],
                    'price' => $aFulfillmentPrices[$i],
                    'currency' => $sCurrency,
                );
            }
        }
        
        return $aOptions;
    }
    
    public function getPaymentTransactionId()
    {
        $oOrder = $this->getOrder();
        if (!$oOrder) {
            return false;
        }
        
        $oPayment = $oOrder->getPayment();
        if (!$oPayment) {
            return false;
        }
        
        return $oPayment->getLastTransId();
    }

}