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
 * Base class for the cronjob classes
 */
class Idealo_Direktkauf_Model_Cronjobs_Base
{
    
    /**
     * Property to save the column names of certain tables
     * 
     * @var array
     */
    protected $_aTableColumns = array();
    
    /**
     * Currently set store scope
     * 
     * @var Mage_Core_Model_Store
     */
    protected $_oStore = null;
    
    /**
     * Get all stores
     * 
     * @return Mage_Core_Model_Store[]
     */
    protected function _getAllStores()
    {
        if (!Mage::helper('idealo_direktkauf')->isMultistoreActive()) {
            $aStores = array(Mage::app()->getDefaultStoreView());
        } else {
            $aStores = Mage::app()->getStores();
        }
        
        return $aStores;
    }
    
    /**
     * Set current store scope
     * 
     * @param Mage_Core_Model_Store $oStore
     * @return void
     */
    protected function _setStore(Mage_Core_Model_Store $oStore)
    {
        Mage::helper('idealo_direktkauf')->setStore($oStore);
        $this->_oStore = $oStore;
    }
    
    /**
     * Return the default store
     * 
     * @return Mage_Core_Model_Store
     */
    protected function _getStore()
    {
        if ($this->_oStore === null) {
            return Mage::app()->getDefaultStoreView();
        }
        
        return $this->_oStore;
    }
    
    /**
     * Return store id
     * 
     * @return string
     */
    protected function _getShopId()
    {
        return $this->_getStore()->getStoreId();
    }
    
    /**
     * Return the stores name
     * 
     * @return string
     */
    protected function _getStoreName()
    {
        return $this->_getStore()->getFrontendName().' - '.$this->_getStore()->getName();
    }
    
    /**
     * DB quote the given array
     * 
     * @param array $aArray
     * @return array
     */
    protected function _quoteArray($aArray) 
    {
        $oConnection = $this->_getWriteConnection();
        foreach ($aArray as $sKey => $sValue) {
            $aArray[$sKey] = $oConnection->quote($sValue);
        }
        
        return $aArray;
    }
    
    /**
     * Return all columns of the given tablename
     * 
     * @param string $sTable
     * @return array
     */
    protected function _getTableColumns($sTable)
    {
        if (!isset($this->_aTableColumns[$sTable])) {
            $aColumns = array();

            $aDescribeTable = $this->_fetchAll("DESCRIBE ".$sTable);
            if ($aDescribeTable && is_array($aDescribeTable)) {
                foreach ($aDescribeTable as $aRow) {
                    $aColumns[] = $aRow['Field'];
                }
            }
            
            $this->_aTableColumns[$sTable] = $aColumns;
        }
        
        return $this->_aTableColumns[$sTable];
    }
    
    /**
     * Check if the columns in the array exist in the mysql table
     * Mainly used for backwards compatibility
     * 
     * @param array $aData
     * @param string $sTable
     * @return array
     */
    protected function _checkData($aData, $sTable)
    {
        $aExistingColumns = $this->_getTableColumns($sTable);
        $aNewData = array();
        foreach ($aData as $sColumnName => $sValue) {
            if (array_search($sColumnName, $aExistingColumns)) {
                $aNewData[$sColumnName] = $sValue;
            }
        }
        
        return $aNewData;
    }
    
    /**
     * Insert a record into the database
     * 
     * @param array $aData
     * @param string $sTableModel
     * @return string|bool
     */
    protected function _insertRecord($aData, $sTableModel)
    {
        $sTable = $this->_getTableName($sTableModel);
        if ($sTable && is_array($aData) && !empty($aData) > 0) {
            $aData = $this->_checkData($aData, $sTable);
            
            $aFields = array_keys($aData);
            
            $sQuery = "INSERT INTO {$sTable} (".implode(',', $aFields).") VALUES(".implode(", ", $this->_quoteArray($aData)).")";
            $sInsertedId = $this->_executeWriteQuery($sQuery);
            return $sInsertedId;
        }
        
        return false;
    }
    
    /**
     * Send send shop order request to idealo
     * 
     * @param string $sIdealoOrderNr
     * @param string $sShopOrderNr
     * @param string $sOrderId
     * @return void
     */
    protected function _sendShopOrderNr($sIdealoOrderNr, $sShopOrderNr, $sOrderId) 
    {
        $oClient = Mage::helper('idealo_direktkauf')->getClient();
        $blSuccess = $oClient->sendOrderNr($sIdealoOrderNr, $sShopOrderNr);
        if ($blSuccess === false && $oClient->getCurlError() != '') {
            $this->_sendShopOrderErrorMail($sIdealoOrderNr, $sShopOrderNr, $oClient);
        } else {
            $sTable = $this->_getTableName('sales/order');
            if ($sTable) {
                $sQuery = " UPDATE
                                {$sTable}
                            SET
                                idealo_ordernr_sent = NOW()
                            WHERE
                                entity_id = '{$sOrderId}' AND
                                store_id = '{$this->_getShopId()}'";
                $this->_executeWriteQuery($sQuery);
                
                $this->_writeLogEntry('Sent shop order-nr '.$sShopOrderNr.' for idealo order-nr: '.$sIdealoOrderNr, Zend_Log::INFO);
            }
        }
    }
    
    /**
     * Send order revocation request to idealo
     * 
     * @param string $sIdealoOrderNr
     * @param string $sReason
     * @param string $sMessage
     * @return void
     */
    protected function _sendOrderRevocation($sIdealoOrderNr, $sReason = '', $sMessage = '') 
    {
        $oClient = Mage::helper('idealo_direktkauf')->getClient();
        $sResponse = $oClient->sendRevocationStatus($sIdealoOrderNr, $sReason, $sMessage);
        if ($sResponse === false && $oClient->getCurlError() != '') {
            $this->_sendRevocationErrorMail($sIdealoOrderNr, $oClient);
        } else {
            $this->_writeLogEntry('Sent revoke status to Idealo for IdealoOrderNr: '.$sIdealoOrderNr.' with message: '.$sMessage, Zend_Log::INFO);
        }
    }
    
    /**
     * Send error mail for when the send shop order request failed
     * 
     * @param string $sIdealoOrderNr
     * @param string $sShopOrderNr
     * @param object $oClient
     * @return void
     */
    protected function _sendShopOrderErrorMail($sIdealoOrderNr, $sShopOrderNr, $oClient)
    {
        $sSubject = "Idealo order-nr request had an error";
        $sText  = "The cronjob tried to send the shop-order-nr {$sShopOrderNr} to idealo for the following idealo order-nr: {$sIdealoOrderNr}\n";
        $sText .= "but an error occured:\n\n";
        $sText .= "Curl-error: {$oClient->getCurlError()}\n";
        $sText .= "Curl-error-nr: {$oClient->getCurlErrno()}\n";
        $sText .= "HTTP-code: {$oClient->getHttpStatus()}\n";
        $this->_sendErrorMail($sSubject, $sText);
    }
    
    /**
     * Send error mail for when the send fulfillment request failed
     * 
     * @param string $sIdealoOrderNr
     * @param object $oClient
     * @return void
     */
    protected function _sendFulfillmentErrorMail($sIdealoOrderNr, $oClient) 
    {
        $sSubject = "Idealo fulfillment request had an error";
        $sText  = "The cronjob tried to send the fulfillment status to idealo for the following idealo order-nr: {$sIdealoOrderNr}\n";
        $sText .= "but an error occured:\n\n";
        $sText .= "Curl-error: {$oClient->getCurlError()}\n";
        $sText .= "Curl-error-nr: {$oClient->getCurlErrno()}\n";
        $sText .= "HTTP-code: {$oClient->getHttpStatus()}\n";
        $this->_sendErrorMail($sSubject, $sText);
    }
    
    /**
     * Send error mail for when the send revocation request failed
     * 
     * @param string $sIdealoOrderNr
     * @param object $oClient
     * @return void
     */
    protected function _sendRevocationErrorMail($sIdealoOrderNr, $oClient) 
    {
        $sSubject = "Idealo revocation request had an error";
        $sText  = "The cronjob tried to send the revocation status to idealo for the following idealo order-nr: {$sIdealoOrderNr}\n";
        $sText .= "but an error occured:\n\n";
        $sText .= "Curl-error: {$oClient->getCurlError()}\n";
        $sText .= "Curl-error-nr: {$oClient->getCurlErrno()}\n";
        $sText .= "HTTP-code: {$oClient->getHttpStatus()}\n";
        $this->_sendErrorMail($sSubject, $sText);
    }
    
    /**
     * Send error email for when get orders request failed
     * 
     * @param object $oClient
     * @return void
     */
    protected function _sendGetOrdersErrorMail($oClient)
    {
        $sSubject = "Idealo orders could not be requested";
        $sText  = "Tried to request the orders from ideale but an error occured:\n\n";
        $sText .= "Curl-error: {$oClient->getCurlError()}\n";
        $sText .= "Curl-error-nr: {$oClient->getCurlErrno()}\n";
        $sText .= "HTTP-code: {$oClient->getHttpStatus()}\n";
        $this->_sendErrorMail($sSubject, $sText);
    }

    /**
     * Send error email for when the mapped test product does not exist in the shop
     * 
     * @param string $sSku
     * @param string $sProductId
     * @return void
     */
    protected function _sendTestProductNotFoundError($sSku, $sProductId)
    {
        $sSubject = "Idealo test product not existing";
        $sText  = "Given SKU: ".$sSku."\n";
        $sText .= "Mapped to test-product-id: ".$sProductId."\n";
        $sText .= "Test product does not exist though.";
        $this->_sendErrorMail($sSubject, $sText);
    }
    
    /**
     * Send error email when the auth token was not configured
     * 
     * @return void
     */
    protected function _sendConnectionDataMissingError()
    {
        $sSubject = "Idealo API not configured";
        $sText  = "Please go into your Magento admin and configure your authorization-token!";
        $this->_sendErrorMail($sSubject, $sText);
    }
    
    /**
     * Send an error mail for the handle order process
     * 
     * @param array $aData
     * @param string $sErrorTypeMessage
     * @return void
     */
    protected function _sendHandleOrderError($aData, $sErrorTypeMessage = '')
    {
        $sSubject = "Idealo order could not be handled - ".$sErrorTypeMessage;
        $sText  = "An error of type ".$sErrorTypeMessage." occured while trying to process order with idealo order nr: ".$aData['order_number']."\n\n";
        $sText .= "Here's the full Data array of that order:\n".print_r($aData, true);
        $this->_sendErrorMail($sSubject, $sText);
    }
    
    /**
     * Write a log entry
     * 
     * @param string $sMessage
     * @param int $iLogLevel
     * @return void
     */
    protected function _writeLogEntry($sMessage, $iLogLevel = null) 
    {
        if (Mage::helper('idealo_direktkauf')->isLoggingActive()) {
            Mage::log($sMessage, $iLogLevel, 'idealo_direktkauf.log', true);
        }
    }
    
    /**
     * Method informs about an exception that has been catched via mail
     * 
     * @param Exception $oEx
     * @param string $sMethod
     * @return void
     */
    protected function _sendExceptionMail(Exception $oEx, $sMethod = '') 
    {
        $sMethodAddition = $sMethod != '' ? "(".$sMethod.")" : "";
        $sSubject = "Idealo Exception occured";
        $sText  = "While trying to perform a method ".$sMethodAddition." the following exception:\n\n";
        $sText .= $oEx->getMessage();
        $this->_sendErrorMail($sSubject, $sText);
    }
    
    /**
     * Send error email
     * 
     * @param string $sSubject
     * @param string $sText
     * @return void
     */
    protected function _sendErrorMail($sSubject, $sText) 
    {
        $this->_writeLogEntry($sText, Zend_Log::ERR);
        $sText .= '('.date('Y-m-d H:i:s').')';
        $sErrorMail = Mage::helper('idealo_direktkauf')->getErrorEmailAddress();
        if ($sErrorMail) {
            mail($sErrorMail, $sSubject, $sText);
        }
    }
    
    /**
     * Return if this idealo module is configured for live-mode
     * 
     * @return bool
     */
    protected function _isLiveMode()
    {
        if (Mage::helper('idealo_direktkauf')->getMode() == 'live') {
            return true;
        }
        
        return false;
    }
    
    /**
     * Return table name for the given object
     * 
     * @param string $sModel
     * @return string
     */
    protected function _getTableName($sModel)
    {
        return Mage::getSingleton('core/resource')->getTableName($sModel);
    }
    
    /**
     * Return a single result from a query
     * 
     * @param string $sQuery
     * @return string|bool
     */
    protected function _fetchOne($sQuery)
    {
        $oConnection = $this->_getReadConnection();
        $sReturn = $oConnection->fetchOne($sQuery);
        if (!$sReturn) {
            return false;
        }
        
        return $sReturn;
    }
    
    /**
     * Return the complete answer from a query
     * 
     * @param string $sQuery
     * @return array|bool
     */
    protected function _fetchAll($sQuery)
    {
        $oConnection = $this->_getReadConnection();
        $aReturn = $oConnection->fetchAll($sQuery);
        if (!$aReturn) {
            return false;
        }
        
        return $aReturn;
    }
    
    /**
     * Return write connection to the DB
     * 
     * @return Varien_Db_Adapter_Interface
     */
    protected function _getWriteConnection()
    {
        $oResource = Mage::getSingleton('core/resource');
        return $oResource->getConnection('core_write');
    }
    
    /**
     * Return read connection to the DB
     * 
     * @return Varien_Db_Adapter_Interface
     */
    protected function _getReadConnection()
    {
        $oResource = Mage::getSingleton('core/resource');
        return $oResource->getConnection('core_read');
    }
    
    /**
     * Execute INSERT, UPDATE or DELETE Mysql queries
     * 
     * @param string $sQuery
     * @return string
     */
    protected function _executeWriteQuery($sQuery)
    {
        $oConnection = $this->_getWriteConnection();
        $oConnection->query($sQuery);
        return $oConnection->lastInsertId();
    }
    
}