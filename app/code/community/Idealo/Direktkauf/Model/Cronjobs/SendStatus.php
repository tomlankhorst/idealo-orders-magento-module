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
 * Class for "Send status" cronjob
 */
class Idealo_Direktkauf_Model_Cronjobs_SendStatus extends Idealo_Direktkauf_Model_Cronjobs_Base
{
    
    /**
     * Needed for backwardcompatibility because Magento changed the column name
     * 
     * @return string
     */
    protected function _getTrackNumberColumnName()
    {
        $sMagentoVersion = Mage::getVersion();
        $sTriggerVersion = '1.6.0';
        if (version_compare($sMagentoVersion, $sTriggerVersion, '>=')) {
            return 'track_number';
        }
        return 'number';
    }
    
    /**
     * Returns all orders which were sent recently
     * 
     * @return array
     */
    protected function _getFulfilledOrders()
    {
        $sTableA = $this->_getTableName('sales/order');
        $sTableB = $this->_getTableName('sales/shipment');
        $sTableC = $this->_getTableName('sales/shipment_track');
        $sColumnTrackNumber = $this->_getTrackNumberColumnName();
        $sQuery = " SELECT 
                        a.entity_id AS 'order_id', 
                        a.idealo_order_nr AS 'idealo_order_nr',
                        b.entity_id AS 'shipment_id',
                        c.{$sColumnTrackNumber} AS 'track_number',
                        a.idealo_delivery_carrier AS 'shipment_carrier'
                    FROM
                        {$sTableA} AS a
                    LEFT JOIN
                        {$sTableB} AS b ON a.entity_id = b.order_id
                    LEFT JOIN
                        {$sTableC} AS c ON a.entity_id = c.order_id
                    WHERE
                        idealo_order_nr != '' AND
                        (
                            (b.entity_id IS NOT NULL AND a.idealo_fulfillment_sent IS NULL) OR
                            (c.entity_id IS NOT NULL AND a.idealo_trackingcode_sent IS NULL)
                        )";
        $aOrders = $this->_fetchAll($sQuery);
        if (!$aOrders) {
            $aOrders = array();
        }
        return $aOrders;
    }
    
    /**
     * Send fulfillment status to idealo and mark order in DB as fulfillment_send
     * 
     * @param array $aOrderInfo
     * @return void
     */
    protected function _handleFulfillment($aOrderInfo)
    {
        $oClient = Mage::helper('idealo_direktkauf')->getClient();

        $blSuccess = false;
        try {
            $blSuccess = $oClient->sendFulfillmentStatus($aOrderInfo['idealo_order_nr'], $aOrderInfo['track_number'], $aOrderInfo['shipment_carrier']);
        } catch (Exception $oEx) {
            $this->_sendExceptionMail($oEx, 'script: Idealo_Direktkauf_Model_Cronjobs_SendStatus::_handleFulfillment()');
        }
        
        if ($blSuccess === false && $oClient->getCurlError() != '') {
            $this->_sendFulfillmentErrorMail($aOrderInfo['idealo_order_nr'], $oClient);
        } else {
            $sTableOrder = $this->_getTableName('sales/order');
            if (!empty($aOrderInfo['track_number'])) {
                $sQuery = "UPDATE {$sTableOrder} SET idealo_fulfillment_sent = NOW(), idealo_trackingcode_sent = NOW() WHERE entity_id = '{$aOrderInfo['order_id']}'";
            } else {
                $sQuery = "UPDATE {$sTableOrder} SET idealo_fulfillment_sent = NOW() WHERE entity_id = '{$aOrderInfo['order_id']}'";
                $aOrderInfo['shipment_carrier'] = ''; // removing it only for logging purposes
            }
            $this->_executeWriteQuery($sQuery);
            
            $this->_writeLogEntry('Sent fulfillment status for idealo order-nr: '.$aOrderInfo['idealo_order_nr'].' trackcode: '.$aOrderInfo['track_number'].' carrier: '.$aOrderInfo['shipment_carrier'], Zend_Log::INFO);
        }
    }
    
    /**
     * Handle all fulfillments
     * 
     * @return void
     */
    protected function _handleFulfillments()
    {
        $aFulfillmentInfo = $this->_getFulfilledOrders();
        foreach ($aFulfillmentInfo as $aOrderInfo) {
            $this->_handleFulfillment($aOrderInfo);
        }
    }
    
    /**
     * Returns all orders which were canceled recently
     * 
     * @return array
     */
    protected function _getRevocationOrders()
    {
        $sTableA = $this->_getTableName('sales/order');
        $sTableB = $this->_getTableName('sales/shipment');
        $sQuery = " SELECT 
                        a.entity_id AS 'order_id', 
                        a.idealo_order_nr AS 'idealo_order_nr',
                        b.entity_id AS 'shipment_id'
                    FROM
                        {$sTableA} AS a
                    LEFT JOIN
                        {$sTableB} AS b ON a.entity_id = b.order_id
                    WHERE
                        a.idealo_order_nr != '' AND
                        a.state = 'canceled' AND
                        a.status = 'canceled' AND
                        a.idealo_revocation_sent IS NULL";
        $aOrders = $this->_fetchAll($sQuery);
        if (!$aOrders) {
            $aOrders = array();
        }
        return $aOrders;
    }
    
    /**
     * Send revocation status to idealo and mark order in DB as revocation_send
     * 
     * @param array $aOrderInfo
     * @return void
     */
    protected function _handleRevocation($aOrderInfo)
    {
        $sReason = Mage::helper('idealo_direktkauf')->getCancellationReason();
        
        /**
         * If order has been already sent storno reason is fixed to RETOUR
         * @see https://tickets.fatchip.de/view.php?id=21081#c72425
         */
        if ($aOrderInfo['shipment_id']) {
            $sReason = "RETOUR";
        }
        
        $oClient = Mage::helper('idealo_direktkauf')->getClient();
        
        $blSuccess = false;
        try {
            $blSuccess = $oClient->sendRevocationStatus($aOrderInfo['idealo_order_nr'], $sReason);
        } catch (Exception $oEx) {
            $this->_sendExceptionMail($oEx, 'script: Idealo_Direktkauf_Model_Cronjobs_SendStatus::_handleRevocation()');
        }
        
        if ($blSuccess === false && $oClient->getCurlError() != '') {
            $this->_sendRevocationErrorMail($aOrderInfo['idealo_order_nr'], $oClient);
        } else {
            $sTableOrder = $this->_getTableName('sales/order');
            $sQuery = "UPDATE {$sTableOrder} SET idealo_revocation_sent = NOW() WHERE entity_id = '{$aOrderInfo['order_id']}'";
            $this->_executeWriteQuery($sQuery);
            
            $this->_writeLogEntry('Sent revocation status for idealo order-nr: '.$aOrderInfo['idealo_order_nr'], Zend_Log::INFO);
        }
    }
    
    /**
     * Handle all revocations
     * 
     * @return void
     */
    protected function _handleRevocations()
    {
        $aRevocationInfo = $this->_getRevocationOrders();
        foreach ($aRevocationInfo as $aOrderInfo) {
            $this->_handleRevocation($aOrderInfo);
        }
    }
    
    /**
     * Return all orders for which the shop order-nr has not been sent to idealo yet
     * 
     * @return array
     */
    protected function _getUnsentOrderNumbers()
    {
        $sTable = $this->_getTableName('sales/order');
        $sQuery = " SELECT 
                        entity_id AS 'order_id', 
                        increment_id AS 'order_nr',
                        idealo_order_nr AS 'idealo_order_nr'
                    FROM
                        {$sTable}
                    WHERE
                        idealo_order_nr != '' AND
                        idealo_ordernr_sent IS NULL";
        $aOrders = $this->_fetchAll($sQuery);
        if (!$aOrders) {
            $aOrders = array();
        }
        return $aOrders;
    }
    
    /**
     * Handle all order numbers
     * 
     * @return void
     */
    protected function _handleOrderNumbers()
    {
        $aOrderNrInfo = $this->_getUnsentOrderNumbers();
        foreach ($aOrderNrInfo as $aOrderInfo) {
            $this->_sendShopOrderNr($aOrderInfo['idealo_order_nr'], $aOrderInfo['order_nr'], $aOrderInfo['order_id']);
        }
    }
    
    /**
     * Main method to start this cronjob
     * 
     * @return array
     */
    public function start()
    {
        try {
            $this->_handleFulfillments();
            $this->_handleRevocations();
            $this->_handleOrderNumbers();
        } catch(Exception $oEx) {
            $this->_sendExceptionMail($oEx, 'script: Idealo_Direktkauf_Model_Cronjobs_SendStatus::start()');
        }
    }

}