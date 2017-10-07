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
 * Class for "Import orders" cronjob
 */
class Idealo_Direktkauf_Model_Cronjobs_ImportOrders extends Idealo_Direktkauf_Model_Cronjobs_Base
{

    /**
     * Product map for testing
     * Idealo-SKU => Magento-product-id
     *
     * @var array
     */
    protected $_aTestProductMap = array(
        '100000_a' => '893',
        '100001_a' => '773',
        '100002_a' => '734',
        '100003_a' => '685',
        '100004_a' => '630',
        '100005_a' => '587',
        '100006_a' => '551',
        '100007_a' => '538',
        '100008_a' => '503',
        '100009_a' => '394',
        '100010_a' => '355',
        '100000_1a' => '249',
    );

    /**
     * Property to save quote item ids
     *
     * @var array
     */
    protected $_aQuoteItemMap = array();

    /**
     * Property to save increment ids
     *
     * @var array
     */
    protected $_aReservedIncrementIds = array();

    /**
     * Property to save vat rules
     *
     * @var array
     */
    protected $_aVatRuleIds = array();
    
    /**
     * Array with all tokens already imported
     * 
     * @var array
     */
    protected $_aImportedTokens = array();

    /**
     * Array with all vats existing in the order products
     *
     * @var array|null
     */
    protected $_aOrderVats = null;

    /**
     * Product array
     *
     * @var array
     */
    protected $_aProducts = array();

    /**
     * Calculate the item quantity from the given order
     *
     * @param array $aOrder
     * @return int
     */
    protected function _getItemQuantity($aOrder)
    {
        $iCount = 0;
        foreach ($aOrder['line_items'] as $aOrderItem) {
            $iCount += $aOrderItem['quantity'];
        }

        return $iCount;
    }

    protected function _getMainVat($aOrder)
    {
        $aOrderVats = $this->_getOrderVats($aOrder);
        $aVatRate1 = array_shift($aOrderVats);
        return $aVatRate1['vatrate'];
    }

    /**
     * Return vat rule id for the given idealo order
     *
     * @param array $aOrder
     * @param double $dMainVat
     * @return string
     */
    protected function _getVatRuleId($aOrder, $dMainVat)
    {
        $sTable = $this->_getTableName('tax/tax_calculation_rate');
        $sVatIdent = $dMainVat.'_'.$aOrder['billing_address']['country'];
        if (!isset($this->_aVatRuleIds[$sVatIdent])) {
            $sQuery = " SELECT
                            tax_calculation_rate_id
                        FROM
                            {$sTable}
                        WHERE
                            tax_country_id = '{$aOrder['billing_address']['country']}' AND
                            rate = '{$dMainVat}'
                        LIMIT 1";
            $sVatRuleId = $this->_fetchOne($sQuery);
            if (!$sVatRuleId) {
                $sVatRuleId = '0';
            }

            $this->_aVatRuleids[$sVatIdent] = $sVatRuleId;
        }

        return $this->_aVatRuleids[$sVatIdent];
    }

    /**
     * Collect all vats existing in the order products
     *
     * @param $aOrder
     * @return array
     */
    protected function _getOrderVats($aOrder)
    {
        if ($this->_aOrderVats === null) {
            $aOrderVats = array();
            foreach ($aOrder['line_items'] as $aItem) {
                $sProductId = $this->_getProductId($aItem['sku']);

                $dVat = $this->_getVatRateForProduct($sProductId, $aOrder);
                if (array_key_exists((string)$dVat, $aOrderVats) === false) {
                    $aOrderVats[(string)$dVat] = array('vatprice' => 0, 'netprice' => 0, 'vatrate' => $dVat);
                }
                $dVatPrice = $this->_getVatPrice($aItem['price'], $dVat);
                $dNetPrice = $this->_getNetPrice($aItem['price'], $dVat);
                $aOrderVats[(string)$dVat]['vatprice'] += $dVatPrice;
                $aOrderVats[(string)$dVat]['netprice'] += $dNetPrice;
            }
            krsort($aOrderVats);

            $this->_aOrderVats = $aOrderVats;
        }
        return $this->_aOrderVats;
    }

    protected function _getOrderVatSum($aOrder)
    {
        $aOrderVats = $this->_getOrderVats($aOrder);

        $dVatSum = 0;
        foreach ($aOrderVats as $aVatValue) {
            $dVatSum += $aVatValue['vatprice'];
        }
        return $dVatSum;
    }

    /**
     * Get order net sum
     *
     * @param array $aOrder
     * @return double
     */
    protected function _getOrderNetSum($aOrder)
    {
        $aOrderVats = $this->_getOrderVats($aOrder);

        $dNetSum = 0;
        foreach ($aOrderVats as $aVatValue) {
            $dNetSum += $aVatValue['netprice'];
        }
        return $dNetSum;
    }

    /**
     * Create dummy address with country and postcode
     *
     * @param string $sCountry
     * @param string $sZip
     * @return Mage_Sales_Model_Quote_Address
     */
    protected function _getAddress($sCountry, $sZip)
    {
        /** @var Mage_Sales_Model_Quote_Address $oAddress */
        $oAddress = Mage::getModel('sales/quote_address');
        $oAddress->setCountryId($sCountry);
        $oAddress->setPostcode($sZip);
        return $oAddress;
    }

    /**
     * Return product object
     *
     * @param $sProductId
     * @return Mage_Catalog_Model_Product
     */
    protected function _getProduct($sProductId)
    {
        if (array_key_exists($sProductId, $this->_aProducts) === false) {
            $this->_aProducts[$sProductId] = Mage::getModel('catalog/product')->load($sProductId);
        }
        return $this->_aProducts[$sProductId];
    }

    /**
     * @param string $sProductId
     * @param array $aOrder
     * @return double
     */
    protected function _getVatRateForProduct($sProductId, $aOrder)
    {
        $oShippingAddress = null;
        if (isset($aOrder['shipping_address'])) {
            $oShippingAddress = $this->_getAddress($aOrder['shipping_address']['country'], $aOrder['shipping_address']['zip']);
        }
        $oBillingAddress = null;
        if (isset($aOrder['billing_address'])) {
            $oBillingAddress = $this->_getAddress($aOrder['billing_address']['country'], $aOrder['billing_address']['zip']);
        }

        $oProduct = $this->_getProduct($sProductId);
        $oStore = Mage::getModel('core/store')->load($this->_getShopId());

        /** @var Mage_Tax_Model_Calculation $oTaxCalc */
        $oTaxCalc = Mage::getModel('tax/calculation');
        $oRequest = $oTaxCalc->getRateRequest($oShippingAddress, $oBillingAddress, null, $oStore);
        $dVatRate = $oTaxCalc->getRate($oRequest->setProductClassId($oProduct->getTaxClassId()));

        return $dVatRate;
    }

    /**
     * Calculate net price from brut price
     *
     * @param double $dBrutPrice
     * @param double $dVat
     * @return double
     */
    protected function _getNetPrice($dBrutPrice, $dVat)
    {
        $dNetPrice = $dBrutPrice / (1 + ($dVat / 100));

        return $dNetPrice;
    }

    /**
     * Calculate vat amount of a price
     *
     * @param double $dBrutPrice
     * @param double $dVat
     * @return double
     */
    protected function _getVatPrice($dBrutPrice, $dVat)
    {
        $dVatPrice = ($dBrutPrice / (100 + $dVat)) * $dVat;
        return $dVatPrice;
    }

    /**
     * Reserve new Magento order nr
     *
     * @return string
     */
    public function getReservedOrderId()
    {
        return Mage::getSingleton('eav/config')->getEntityType('order')->fetchNewIncrementId($this->_getShopId());
    }

    /**
     * Get new order status from the config

     * @return string
     */
    protected function _getOrderStatusInfo()
    {
        return Mage::helper('idealo_direktkauf')->getNewOrderStatus();
    }

    /**
     * Fill the data to a quote entity and write it into the DB
     * Returns the entity id
     *
     * @param array $aOrder
     * @return string
     */
    protected function _addQuote($aOrder)
    {
        $sNetPrice = $this->_getOrderNetSum($aOrder);

        $sIncrementId = $this->getReservedOrderId();
        $this->_aReservedIncrementIds[$aOrder['order_number']] = $sIncrementId;

        $aQuote = array();
        $aQuote['store_id'] = $this->_getShopId();
        $aQuote['created_at'] = date('Y-m-d H:i:s');
        $aQuote['updated_at'] = date('Y-m-d H:i:s');
        $aQuote['items_count'] = count($aOrder['line_items']);
        $aQuote['items_qty'] = $this->_getItemQuantity($aOrder);
        $aQuote['store_to_base_rate'] = '1';
        $aQuote['store_to_quote_rate'] = '1';
        $aQuote['base_currency_code'] = $aOrder['currency'];
        $aQuote['store_currency_code'] = $aOrder['currency'];
        $aQuote['quote_currency_code'] = $aOrder['currency'];
        $aQuote['grand_total'] = $aOrder['total_price'];//brut complete
        $aQuote['base_grand_total'] = $aOrder['total_price'];//brut complete
        $aQuote['customer_id'] = NULL;
        $aQuote['customer_tax_class_id'] = $this->_getVatRuleId($aOrder, $this->_getMainVat($aOrder));
        $aQuote['applied_rule_ids'] = NULL;
        $aQuote['global_currency_code'] = $aOrder['currency'];
        $aQuote['base_to_global_rate'] = '1';
        $aQuote['base_to_quote_rate'] = '1';
        $aQuote['subtotal'] = $sNetPrice;//net article sum
        $aQuote['base_subtotal'] = $sNetPrice;//net article sum
        $aQuote['subtotal_with_discount'] = $sNetPrice;
        $aQuote['base_subtotal_with_discount'] = $sNetPrice;
        $aQuote['is_changed'] = '1';
        $aQuote['trigger_recollect'] = '0';

        $aQuote['is_active'] = '0';# vll doch erst auf 1 setzen und sp�ter auf 0?
        $aQuote['is_virtual'] = '0';
        $aQuote['is_multi_shipping'] = '0';
        $aQuote['orig_order_id'] = '0';
        $aQuote['checkout_method'] = 'guest';
        $aQuote['customer_group_id'] = '0';
        $aQuote['customer_email'] = $aOrder['customer']['email'];
        $aQuote['customer_firstname'] = $aOrder['billing_address']['given_name'];
        $aQuote['customer_lastname'] = $aOrder['billing_address']['family_name'];
        $aQuote['customer_note_notify'] = '1';
        $aQuote['customer_is_guest'] = '1';
        $aQuote['reserved_order_id'] = $sIncrementId;

        $iQuoteId = $this->_insertRecord($aQuote, 'sales/quote');

        $this->_addQuoteAddress($aOrder, $iQuoteId, 'billing');
        $this->_addQuoteAddress($aOrder, $iQuoteId, 'shipping');

        foreach ($aOrder['line_items'] as $aOrderItem) {
            $this->_addQuoteItem($aOrder, $aOrderItem, $iQuoteId);
        }

        $this->_addQuotePayment($aOrder, $iQuoteId);

        return $iQuoteId;
    }

    /**
     * Build an address-hash
     *
     * @param array $aAddress
     * @return string
     */
    protected function _getAddressHash($aAddress)
    {
        $sHash  = $aAddress['address1'];
        $sHash .= $aAddress['address2'];
        $sHash .= $aAddress['city'];
        $sHash .= $aAddress['country'];
        $sHash .= $aAddress['given_name'];
        $sHash .= $aAddress['family_name'];
        $sHash .= $aAddress['zip'];
        $sHash .= $aAddress['salutation'];
        return md5($sHash);
    }

    /**
     * Check if products billing and shipping addresses are different
     *
     * @param array $aOrder
     * @return bool
     */
    protected function _hasDifferentShippingAddress($aOrder)
    {
        if ($this->_getAddressHash($aOrder['billing_address']) == $this->_getAddressHash($aOrder['shipping_address'])) {
            return false;
        }

        return true;
    }

    /**
     * Add quote item id to a property
     * Will be used later by the order
     *
     * @param string $sIdealoOrderNr
     * @param string $sProductId
     * @param string $sQuoteItemId
     * @return void
     */
    protected function _addQuoteItemId($sIdealoOrderNr, $sProductId, $sQuoteItemId)
    {
        if (!isset($this->_aQuoteItemMap[$sIdealoOrderNr])) {
            $this->_aQuoteItemMap[$sIdealoOrderNr] = array();
        }

        $this->_aQuoteItemMap[$sIdealoOrderNr][$sProductId] = $sQuoteItemId;
    }

    /**
     * Get previously added quote item id for the order
     *
     * @param string $sOrderId
     * @param string $sSku
     * @return string|bool
     */
    protected function _getQuoteItemId($sOrderId, $sSku)
    {
        if (isset($this->_aQuoteItemMap[$sOrderId][$sSku])) {
            return $this->_aQuoteItemMap[$sOrderId][$sSku];
        }

        return false;
    }

    /**
     * Get the mapped shipping info
     *
     * @param array $aOrder
     * @return array
     */
    protected function _getShippingInfo($aOrder)
    {
        $aAvailableDeliveryTypes = Mage::helper('idealo_direktkauf')->getShopDeliveryTypes();
        $aDeliveryMapping = Mage::helper('idealo_direktkauf')->getDeliveryMapping();

        $sCarrier = '';
        $sShippingId = $aOrder['fulfillment']['type'];
        if (isset($aDeliveryMapping[$sShippingId])) {
            $sCarrier = $aDeliveryMapping[$sShippingId]['carrier'];
            $sShippingId = $aDeliveryMapping[$sShippingId]['type'];
        }

        $sShippingTitle = $sShippingId;
        if (isset($aAvailableDeliveryTypes[$sShippingId])) {
            $sShippingTitle = $aAvailableDeliveryTypes[$sShippingId];
        }

        $dShippingNetPrice = $this->_getShippingPrice($aOrder, false);
        $dShippingBrutPrice = $this->_getShippingPrice($aOrder, true);
        $dShippingTaxAmount = $dShippingBrutPrice - $dShippingNetPrice;

        return array(
            'id' => $sShippingId,
            'title' => $sShippingTitle,
            'carrier' => $sCarrier,
            'shipping_amount_net' => $dShippingNetPrice,
            'shipping_amount_brut' => $dShippingBrutPrice,
            'shipping_amount_tax' => $dShippingTaxAmount
        );
    }

    /**
     * Fill the data to a quote address entity and write it into the DB
     * Returns the entity id
     *
     * @param array $aOrder
     * @param int $iQuoteId
     * @param string $sType
     * @return string
     */
    protected function _addQuoteAddress($aOrder, $iQuoteId, $sType)
    {
        $sKey = 'billing_address';
        $iSameAsBilling = '0';
        $blIsShipping = false;
        if ($sType == 'shipping') {
            $blIsShipping = true;
            $sKey = 'shipping_address';
            $iSameAsBilling = $this->_hasDifferentShippingAddress($aOrder) === false ? '1' : '0';
        }

        $dNetOrderSum = $this->_getOrderNetSum($aOrder);

        $aShippingInfo = $this->_getShippingInfo($aOrder);

        $dVatOrderSum = $aOrder['total_line_items_price'] - $dNetOrderSum + $aShippingInfo['shipping_amount_tax'];

        $sStreet = $aOrder[$sKey]['address1'];
        if (!empty($aOrder[$sKey]['address2'])) {
            $sStreet .= "\n".$aOrder[$sKey]['address2'];
        }

        $aAddress = array();
        $aAddress['quote_id'] = $iQuoteId;

        $aAddress['email'] = $aOrder['customer']['email'];
        $aAddress['telephone'] = $aOrder['customer']['phone'];
        $aAddress['firstname'] = $aOrder[$sKey]['given_name'];
        $aAddress['lastname'] = $aOrder[$sKey]['family_name'];
        $aAddress['street'] = $sStreet;
        $aAddress['city'] = $aOrder[$sKey]['city'];
        $aAddress['postcode'] = $aOrder[$sKey]['zip'];
        $aAddress['country_id'] = $aOrder[$sKey]['country'];
        $aAddress['subtotal_with_discount'] = '0';

        $aAddress['created_at'] = date('Y-m-d H:i:s');
        $aAddress['updated_at'] = date('Y-m-d H:i:s');
        $aAddress['address_type'] = $sType;
        $aAddress['same_as_billing'] = $iSameAsBilling;
        $aAddress['free_shipping'] = $aOrder['total_shipping'] == 0 ? '1' : '0';
        if ($blIsShipping) {
            $aAddress['shipping_method'] = $aShippingInfo['id'];
            $aAddress['shipping_description'] = $aShippingInfo['title'];
        }

        $aAddress['weight'] = $blIsShipping ? $this->_getItemQuantity($aOrder) : 0;
        $aAddress['subtotal'] = $blIsShipping ? $dNetOrderSum : 0;
        $aAddress['base_subtotal'] = $blIsShipping ? $dNetOrderSum : 0;
        $aAddress['tax_amount'] = $blIsShipping ? $dVatOrderSum : 0;
        $aAddress['base_tax_amount'] = $blIsShipping ? $dVatOrderSum : 0;
        $aAddress['shipping_amount'] = $blIsShipping ? $aShippingInfo['shipping_amount_net'] : 0;
        $aAddress['base_shipping_amount'] = $blIsShipping ? $aShippingInfo['shipping_amount_net'] : 0;
        $aAddress['shipping_tax_amount'] = $blIsShipping ? $aShippingInfo['shipping_amount_tax'] : 0;
        $aAddress['base_shipping_tax_amount'] = $blIsShipping ? $aShippingInfo['shipping_amount_tax'] : 0;
        $aAddress['discount_amount'] = '0';
        $aAddress['base_discount_amount'] = '0';
        $aAddress['grand_total'] = $blIsShipping ? $aOrder['total_price'] : 0;
        $aAddress['base_grand_total'] = $blIsShipping ? $aOrder['total_price'] : 0;
        $aAddress['applied_taxes'] = 'a:0:{}';// is this needed? big serialized array included normally
        $aAddress['subtotal_incl_tax'] = $blIsShipping ? $aOrder['total_line_items_price']: 0;
        $aAddress['shipping_incl_tax'] = $blIsShipping ? $aShippingInfo['shipping_amount_brut'] : 0;
        $aAddress['base_shipping_incl_tax'] = $blIsShipping ? $aShippingInfo['shipping_amount_brut'] : 0;

        return $this->_insertRecord($aAddress, 'sales/quote_address');
    }

    /**
     * Check if the product id exists in the db
     *
     * @param string $sProductId
     * @return bool
     */
    protected function _productIdExists($sProductId)
    {
        $sTable = $this->_getTableName('catalog/product');
        if ($sTable) {
            $sQuery = "SELECT entity_id FROM ".$sTable." WHERE entity_id = '{$sProductId}'";
            $sProductId = $this->_fetchOne($sQuery);
            if ($sProductId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the Magento product id by the given idealo SKU
     * If test-mode is active it tries to grab the id from a test-array
     *
     * @param string $sSku
     * @return string|bool
     */
    protected function _getProductId($sSku)
    {
        $sTable = $this->_getTableName('catalog/product');
        if ($sTable) {
            $sQuery = "SELECT entity_id FROM ".$sTable." WHERE sku = '{$sSku}'";
            $sProductId = $this->_fetchOne($sQuery);
            if ($sProductId) {
                return $sProductId;
            }
        }

        if (!$sProductId && $this->_isLiveMode() === false && isset($this->_aTestProductMap[$sSku])) {
            if ($this->_productIdExists($this->_aTestProductMap[$sSku])) {
                return $this->_aTestProductMap[$sSku];
            } else {
                $this->_sendTestProductNotFoundError($sSku, $this->_aTestProductMap[$sSku]);
            }
        }

        return false;
    }

    /**
     * Return the parent product id if its a configured product or false if not
     *
     * @param string $sProductId
     * @return string|bool
     */
    protected function _getParentProductId($sProductId)
    {
        $sTable = $this->_getTableName('catalog/product_relation');
        if ($sTable) {
            $sQuery = "SELECT parent_id FROM ".$sTable." WHERE child_id = '{$sProductId}'";
            $sParentId = $this->_fetchOne($sQuery);
            if ($sParentId) {
                return $sParentId;
            }
        }

        return false;
    }

    /**
     * Check if the order already exists
     *
     * @param array $aOrder
     * @return bool
     */
    protected function _orderAlreadyExists($aOrder)
    {
        $sTable = $this->_getTableName('sales/order');
        if ($sTable) {
            $sQuery = "SELECT entity_id FROM {$sTable} WHERE idealo_order_nr = '".($aOrder['order_number'])."' LIMIT 1";
            $sOrderId = $this->_fetchOne($sQuery);
            if ($sOrderId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update order address ids to the order entity
     *
     * @param string $sOrderId
     * @param string $sBillingAddressId
     * @param string $sShippingAddressId
     * @return void
     */
    protected function _updateOrderWithAddressIds($sOrderId, $sBillingAddressId, $sShippingAddressId)
    {
        $sTable = $this->_getTableName('sales/order');
        if ($sTable) {
            $sQuery = " UPDATE
                            {$sTable}
                        SET
                            billing_address_id = '{$sBillingAddressId}',
                            shipping_address_id = '{$sShippingAddressId}'
                        WHERE
                            entity_id = '{$sOrderId}'";
            $this->_executeWriteQuery($sQuery);
        }
    }

    /**
     * Fill the data to a quote item entity and write it into the DB
     * Returns the entity id
     *
     * @param array $aOrder
     * @param array $aOrderItem
     * @param int $iQuoteId
     * @return string
     */
    protected function _addQuoteItem($aOrder, $aOrderItem, $iQuoteId)
    {
        $sProductId = $this->_getProductId($aOrderItem['sku']);

        $sMainId = $sProductId;
        $sParentId = $this->_getParentProductId($sProductId);

        $sType = 'simple';
        if ($sParentId !== false) {
            $sType = 'configurable';
            $sMainId = $sParentId;
        }

        $dVatRate = $this->_getVatRateForProduct($sProductId, $aOrder);

        $dBrutPrice = $aOrderItem['item_price'];
        $dTotalBrutPrice = $aOrderItem['price'];
        $dNetPrice = $this->_getNetPrice($dBrutPrice, $dVatRate);
        $dTotalNetPrice = $dNetPrice * $aOrderItem['quantity'];
        $dTotalVatAmount = $dTotalBrutPrice - $dTotalNetPrice;

        //configurable
        $aQuoteItem = array();
        $aQuoteItem['quote_id'] = $iQuoteId;
        $aQuoteItem['created_at'] = date('Y-m-d H:i:s');
        $aQuoteItem['updated_at'] = date('Y-m-d H:i:s');
        $aQuoteItem['product_id'] = $sMainId;
        $aQuoteItem['store_id'] = $this->_getShopId();
        $aQuoteItem['is_virtual'] = '0';
        $aQuoteItem['sku'] = $aOrderItem['sku'];
        $aQuoteItem['name'] = $aOrderItem['title'];
        $aQuoteItem['applied_rule_ids'] = NULL;
        $aQuoteItem['free_shipping'] = '0';
        $aQuoteItem['is_qty_decimal'] = '0';
        $aQuoteItem['weight'] = '1';
        $aQuoteItem['qty'] = $aOrderItem['quantity'];
        $aQuoteItem['price'] = $dNetPrice;
        $aQuoteItem['base_price'] = $dNetPrice;
        $aQuoteItem['discount_percent'] = '0';
        $aQuoteItem['discount_amount'] = '0';
        $aQuoteItem['base_discount_amount'] = '0';
        $aQuoteItem['tax_percent'] = $dVatRate;
        $aQuoteItem['tax_amount'] = $dTotalVatAmount;
        $aQuoteItem['base_tax_amount'] = $dTotalVatAmount;
        $aQuoteItem['row_total'] = $dTotalNetPrice;
        $aQuoteItem['base_row_total'] = $dTotalNetPrice;
        $aQuoteItem['row_total_with_discount'] = '0';//looks like its always 0
        $aQuoteItem['row_weight'] = '1';
        $aQuoteItem['product_type'] = $sType;
        $aQuoteItem['base_cost'] = NULL;
        $aQuoteItem['price_incl_tax'] = $dBrutPrice;
        $aQuoteItem['base_price_incl_tax'] = $dBrutPrice;
        $aQuoteItem['row_total_incl_tax'] = $dTotalBrutPrice;
        $aQuoteItem['base_row_total_incl_tax'] = $dTotalBrutPrice;
        $aQuoteItem['hidden_tax_amount'] = '0';
        $aQuoteItem['base_hidden_tax_amount'] = '0';
        $aQuoteItem['weee_tax_disposition'] = '0';
        $aQuoteItem['weee_tax_row_disposition'] = '0';
        $aQuoteItem['base_weee_tax_disposition'] = '0';
        $aQuoteItem['base_weee_tax_row_disposition'] = '0';
        $aQuoteItem['weee_tax_applied'] = 'a:0:{}';
        $aQuoteItem['weee_tax_applied_amount'] = '0';
        $aQuoteItem['weee_tax_applied_row_amount'] = '0';
        $aQuoteItem['base_weee_tax_applied_amount'] = '0';

        $sQuoteItemId = $this->_insertRecord($aQuoteItem, 'sales/quote_item');

        $this->_addQuoteItemId($aOrder['order_number'], $sMainId, $sQuoteItemId);

        if ($sParentId !== false) {
            //simple
            $aQuoteItem['quote_id'] = $iQuoteId;
            $aQuoteItem['created_at'] = date('Y-m-d H:i:s');
            $aQuoteItem['updated_at'] = date('Y-m-d H:i:s');
            $aQuoteItem['product_id'] = $sProductId;
            $aQuoteItem['store_id'] = $this->_getShopId();
            $aQuoteItem['parent_item_id'] = $sQuoteItemId;
            $aQuoteItem['is_virtual'] = '0';
            $aQuoteItem['sku'] = $aOrderItem['sku'];
            $aQuoteItem['name'] = $aOrderItem['title'];
            $aQuoteItem['applied_rule_ids'] = NULL;
            $aQuoteItem['free_shipping'] = '0';
            $aQuoteItem['is_qty_decimal'] = '0';
            $aQuoteItem['no_discount'] = '0';
            $aQuoteItem['weight'] = '1';
            $aQuoteItem['qty'] = '1';// qty is always set to 1 for simple items
            $aQuoteItem['price'] = '0';
            $aQuoteItem['base_price'] = '0';
            $aQuoteItem['custom_price'] = NULL;
            $aQuoteItem['discount_percent'] = '0';
            $aQuoteItem['discount_amount'] = '0';
            $aQuoteItem['base_discount_amount'] = '0';
            $aQuoteItem['tax_percent'] = '0';
            $aQuoteItem['tax_amount'] = '0';
            $aQuoteItem['base_tax_amount'] = '0';
            $aQuoteItem['row_total'] = '0';
            $aQuoteItem['base_row_total'] = '0';
            $aQuoteItem['row_total_with_discount'] = '0';
            $aQuoteItem['row_weight'] = '0';
            $aQuoteItem['product_type'] = 'simple';
            $aQuoteItem['base_tax_before_discount'] = NULL;
            $aQuoteItem['tax_before_discount'] = NULL;
            $aQuoteItem['original_custom_price'] = NULL;
            $aQuoteItem['redirect_url'] = NULL;
            $aQuoteItem['base_cost'] = NULL;
            $aQuoteItem['price_incl_tax'] = NULL;
            $aQuoteItem['base_price_incl_tax'] = NULL;
            $aQuoteItem['row_total_incl_tax'] = NULL;
            $aQuoteItem['base_row_total_incl_tax'] = NULL;
            $aQuoteItem['hidden_tax_amount'] = NULL;
            $aQuoteItem['base_hidden_tax_amount'] = NULL;
            $aQuoteItem['gift_message_id'] = NULL;
            $aQuoteItem['weee_tax_disposition'] = '0';
            $aQuoteItem['weee_tax_row_disposition'] = '0';
            $aQuoteItem['base_weee_tax_disposition'] = '0';
            $aQuoteItem['base_weee_tax_row_disposition'] = '0';
            $aQuoteItem['weee_tax_applied'] = 'a:0:{}';
            $aQuoteItem['weee_tax_applied_amount'] = '0';
            $aQuoteItem['weee_tax_applied_row_amount'] = '0';
            $aQuoteItem['base_weee_tax_applied_amount'] = '0';
            $aQuoteItem['event_id'] = NULL;
            $aQuoteItem['giftregistry_item_id'] = NULL;
            $aQuoteItem['gw_id'] = NULL;
            $aQuoteItem['gw_base_price'] = NULL;
            $aQuoteItem['gw_price'] = NULL;
            $aQuoteItem['gw_base_tax_amount'] = NULL;
            $aQuoteItem['gw_tax_amount'] = NULL;

            $sSimpleQuoteItemId = $this->_insertRecord($aQuoteItem, 'sales/quote_item');

            $this->_addQuoteItemId($aOrder['order_number'], $sProductId, $sSimpleQuoteItemId);
        }

        return $sQuoteItemId;
    }

    /**
     * Fill the data to a quote payment entity and write it into the DB
     * Returns the entity id
     *
     * @param array $aOrder
     * @param int $iQuoteId
     * @return string
     */
    protected function _addQuotePayment($aOrder, $iQuoteId)
    {
        $aQuotePayment = array();
        $aQuotePayment['quote_id'] = $iQuoteId;
        $aQuotePayment['created_at'] = date('Y-m-d H:i:s');
        $aQuotePayment['updated_at'] = date('Y-m-d H:i:s');
        $aQuotePayment['method'] = $this->_getPaymentMethod($aOrder);
        $aQuotePayment['additional_information'] = NULL;

        return $this->_insertRecord($aQuotePayment, 'sales/quote_payment');
    }

    /**
     * Fill the data to a order address entity and write it into the DB
     * Returns the entity id
     *
     * @param array $aOrder
     * @param int $iOrderId
     * @param string $sType
     * @return string
     */
    protected function _addOrderAddress($aOrder, $iOrderId, $sType)
    {
        $sKey = 'billing_address';
        if ($sType == 'shipping') {
            $sKey = 'shipping_address';
        }

        $sStreet = $aOrder[$sKey]['address1'];
        if (!empty($aOrder[$sKey]['address2'])) {
            $sStreet .= "\n".$aOrder[$sKey]['address2'];
        }

        $aAddress = array();
        $aAddress['parent_id'] = $iOrderId;
        $aAddress['customer_address_id'] = NULL;
        $aAddress['customer_id'] = NULL;
        $aAddress['fax'] = NULL;
        $aAddress['postcode'] = $aOrder[$sKey]['zip'];
        $aAddress['lastname'] = $aOrder[$sKey]['family_name'];
        $aAddress['street'] = $sStreet;
        $aAddress['city'] = $aOrder[$sKey]['city'];
        $aAddress['email'] = $aOrder['customer']['email'];
        $aAddress['telephone'] = $aOrder['customer']['phone'];
        $aAddress['country_id'] = $aOrder[$sKey]['country'];
        $aAddress['firstname'] = $aOrder[$sKey]['given_name'];
        $aAddress['address_type'] = $sType;

        return $this->_insertRecord($aAddress, 'sales/order_address');
    }

    /**
     * Fill the data to a order payment entity and write it into the DB
     * Returns the entity id
     *
     * @param array $aOrder
     * @param int $iOrderId
     * @return string
     */
    protected function _addOrderPayment($aOrder, $iOrderId)
    {
        $dShippingNetPrice = $this->_getShippingPrice($aOrder, false);

        $aOrderPayment = array();
        $aOrderPayment['parent_id'] = $iOrderId;
        $aOrderPayment['last_trans_id'] = $aOrder['payment']['transaction_id'];
        $aOrderPayment['base_shipping_amount'] = $dShippingNetPrice;
        $aOrderPayment['shipping_amount'] = $dShippingNetPrice;
        $aOrderPayment['base_amount_ordered'] = $aOrder['total_price'];
        $aOrderPayment['amount_ordered'] = $aOrder['total_price'];
        $aOrderPayment['additional_data'] = NULL;
        $aOrderPayment['cc_exp_month'] = '0';
        $aOrderPayment['cc_ss_start_year'] = '0';
        $aOrderPayment['method'] = $this->_getPaymentMethod($aOrder);
        $aOrderPayment['cc_last4'] = NULL;
        $aOrderPayment['cc_ss_start_month'] = '0';
        $aOrderPayment['cc_owner'] = NULL;
        $aOrderPayment['cc_type'] = NULL;
        $aOrderPayment['po_number'] = NULL;
        $aOrderPayment['cc_exp_year'] = '0';
        $aOrderPayment['cc_ss_issue'] = NULL;
        $aOrderPayment['cc_number_enc'] = NULL;
        $aOrderPayment['additional_information'] = NULL;

        return $this->_insertRecord($aOrderPayment, 'sales/order_payment');
    }

    /**
     * Fill the data to a order status history entity and write it into the DB
     * Returns the entity id
     *
     * @param array $aOrder
     * @param int $iOrderId
     * @return string
     */
    protected function _addOrderStatusHistory($aOrder, $iOrderId)
    {
        $aStatusInfo = $this->_getOrderStatusInfo();

        $aOrderStatus = array();
        $aOrderStatus['parent_id'] = $iOrderId;
        $aOrderStatus['is_customer_notified'] = '1';
        $aOrderStatus['status'] = $aStatusInfo['status'];
        $aOrderStatus['created_at'] = date('Y-m-d H:i:s');
        $aOrderStatus['entity_name'] = 'order';

        return $this->_insertRecord($aOrderStatus, 'sales/order_status_history');
    }

    /**
     * Hook for handling the stock
     * Not known if needed yet
     *
     * @return void
     */
    protected function _handleStock()
    {
        //is this needed?
    }

    /**
     * Return the protect code in the way Magento would too
     *
     * @return string
     */
    protected function _getProtectCode()
    {
        return substr(md5(uniqid(mt_rand(), true) . ':' . microtime(true)), 5, 6);
    }

    /**
     * Add fulfillment options to the order
     *
     * @param  array $aOrder     idealo order array
     * @param  array $aShopOrder magento shop order array
     * @return array magento shop order array
     */
    protected function _addFulfillmentOptions($aOrder, $aShopOrder)
    {
        if (!empty($aOrder['fulfillment']['fulfillment_options'])) {
            $sDelimiter = ';';

            $sType = '';
            $sPrice = '';
            foreach ($aOrder['fulfillment']['fulfillment_options'] as $aOption) {
                $sType .= $aOption['name'].$sDelimiter;
                $sPrice .= $aOption['price'].$sDelimiter;
            }

            $aShopOrder['idealo_fulfillment_type'] = rtrim($sType, $sDelimiter);
            $aShopOrder['idealo_fulfillment_price'] = rtrim($sPrice, $sDelimiter);
        }

        return $aShopOrder;
    }

    /**
     * Fill the data to a order entity and write it into the DB
     * Returns the order id
     *
     * @param array $aOrder
     * @param int $iQuoteId
     * @return string
     */
    protected function _handleOrder($aOrder, $iQuoteId)
    {
        $dNetBasketSum = $this->_getOrderNetSum($aOrder);

        $sIncrementId = $this->_aReservedIncrementIds[$aOrder['order_number']];

        $aShippingInfo = $this->_getShippingInfo($aOrder);
        $aStatusInfo = $this->_getOrderStatusInfo();

        $dVatOrderSum = $aOrder['total_line_items_price'] - $dNetBasketSum + $aShippingInfo['shipping_amount_tax'];

        $aShopOrder = array();
        $aShopOrder['store_id'] = $this->_getShopId();
        $aShopOrder['quote_id'] = $iQuoteId;
        $aShopOrder['increment_id'] = $sIncrementId;
        $aShopOrder['idealo_order_nr'] = $aOrder['order_number'];
        $aShopOrder['state'] = $aStatusInfo['state'];
        $aShopOrder['status'] = $aStatusInfo['status'];
        $aShopOrder['protect_code'] = $this->_getProtectCode();
        $aShopOrder['shipping_method'] = $aShippingInfo['id'];
        $aShopOrder['shipping_description'] = $aShippingInfo['title'];
        $aShopOrder['idealo_delivery_carrier'] = $aShippingInfo['carrier'];
        $aShopOrder['is_virtual'] = '0';
        $aShopOrder['base_grand_total'] = $aOrder['total_price'];
        $aShopOrder['grand_total'] = $aOrder['total_price'];
        $aShopOrder['base_subtotal_incl_tax'] = $aOrder['total_line_items_price'];
        $aShopOrder['subtotal_incl_tax'] = $aOrder['total_line_items_price'];
        $aShopOrder['base_shipping_amount'] = $aShippingInfo['shipping_amount_net'];
        $aShopOrder['shipping_amount'] = $aShippingInfo['shipping_amount_net'];
        $aShopOrder['base_shipping_incl_tax'] = $aShippingInfo['shipping_amount_brut'];
        $aShopOrder['shipping_incl_tax'] = $aShippingInfo['shipping_amount_brut'];
        $aShopOrder['base_shipping_tax_amount'] = $aShippingInfo['shipping_amount_tax'];
        $aShopOrder['shipping_tax_amount'] = $aShippingInfo['shipping_amount_tax'];
        $aShopOrder['base_subtotal'] = $dNetBasketSum;
        $aShopOrder['subtotal'] = $dNetBasketSum;
        $aShopOrder['base_tax_amount'] = $dVatOrderSum;
        $aShopOrder['tax_amount'] = $dVatOrderSum;
        $aShopOrder['base_discount_amount'] = '0';
        $aShopOrder['discount_amount'] = '0';
        $aShopOrder['base_shipping_discount_amount'] = '0';
        $aShopOrder['shipping_discount_amount'] = '0';
        $aShopOrder['base_hidden_tax_amount'] = '0';
        $aShopOrder['hidden_tax_amount'] = '0';
        $aShopOrder['base_shipping_hidden_tax_amnt'] = '0';
        $aShopOrder['shipping_hidden_tax_amount'] = '0';
        $aShopOrder['base_to_global_rate'] = '1';
        $aShopOrder['base_to_order_rate'] = '1';
        $aShopOrder['store_to_base_rate'] = '1';
        $aShopOrder['store_to_order_rate'] = '1';
        $aShopOrder['total_qty_ordered'] = $this->_getItemQuantity($aOrder);
        $aShopOrder['total_item_count'] = count($aOrder['line_items']);
        $aShopOrder['customer_is_guest'] = '1';
        $aShopOrder['customer_note_notify'] = '1';
        $aShopOrder['customer_group_id'] = '0';
        $aShopOrder['base_currency_code'] = $aOrder['currency'];
        $aShopOrder['customer_email'] = $aOrder['customer']['email'];
        $aShopOrder['customer_firstname'] = $aOrder['billing_address']['given_name'];
        $aShopOrder['customer_lastname'] = $aOrder['billing_address']['family_name'];
        $aShopOrder['global_currency_code'] = $aOrder['currency'];
        $aShopOrder['order_currency_code'] = $aOrder['currency'];
        $aShopOrder['store_currency_code'] = $aOrder['currency'];
        $aShopOrder['store_name'] = $this->_getStoreName();
        $aShopOrder['created_at'] = date('Y-m-d H:i:s');
        $aShopOrder['updated_at'] = date('Y-m-d H:i:s');

        $aShopOrder = $this->_addFulfillmentOptions($aOrder, $aShopOrder);

        $aShopOrder = $this->_modifyOrder($aOrder, $aShopOrder);

        $sOrderId = $this->_insertRecord($aShopOrder, 'sales/order');

        $sBillingAddressId = $this->_addOrderAddress($aOrder, $sOrderId, 'billing');
        $sShippingAddressId = $this->_addOrderAddress($aOrder, $sOrderId, 'shipping');

        $this->_updateOrderWithAddressIds($sOrderId, $sBillingAddressId, $sShippingAddressId);

        foreach ($aOrder['line_items'] as $aOrderItem) {
            $this->_handleOrderarticles($aOrder, $aOrderItem, $sOrderId);
        }

        $this->_addOrderPayment($aOrder, $sOrderId);
        $this->_addOrderStatusHistory($aOrder, $sOrderId);

        $this->_addOrderGridEntry($sOrderId);
        $this->_sendShopOrderNr($aOrder['order_number'], $aShopOrder['increment_id'], $sOrderId);

        return $sOrderId;
    }

    /**
     * Copy order entity to the grid table
     *
     * @param string $sOrderId
     * @return void
     */
    protected function _addOrderGridEntry($sOrderId)
    {
        $sTableA = $this->_getTableName('sales/order_grid');
        $sTableB = $this->_getTableName('sales/order');
        $sTableC = $this->_getTableName('sales/order_address');
        $sQuery = "
        INSERT INTO `{$sTableA}` (
            `entity_id`, `status`, `store_id`, `customer_id`, `base_grand_total`, `base_total_paid`, `grand_total`, `total_paid`, `increment_id`, `base_currency_code`, `order_currency_code`, `store_name`, `created_at`, `updated_at`, `billing_name`, `shipping_name`
        ) SELECT
            `main_table`.`entity_id`,
            `main_table`.`status`,
            `main_table`.`store_id`,
            `main_table`.`customer_id`,
            `main_table`.`base_grand_total`,
            `main_table`.`base_total_paid`,
            `main_table`.`grand_total`,
            `main_table`.`total_paid`,
            `main_table`.`increment_id`,
            `main_table`.`base_currency_code`,
            `main_table`.`order_currency_code`,
            `main_table`.`store_name`,
            `main_table`.`created_at`,
            `main_table`.`updated_at`,
            CONCAT(IFNULL(table_billing_name.firstname, ''), ' ', IFNULL(table_billing_name.middlename, ''), ' ', IFNULL(table_billing_name.lastname, '')) AS `billing_name`,
            CONCAT(IFNULL(table_shipping_name.firstname, ''), ' ', IFNULL(table_shipping_name.middlename, ''), ' ', IFNULL(table_shipping_name.lastname, '')) AS `shipping_name`
        FROM
            `{$sTableB}` AS `main_table`
        LEFT JOIN
            `{$sTableC}` AS `table_billing_name` ON `main_table`.`billing_address_id`=`table_billing_name`.`entity_id`
        LEFT JOIN
            `{$sTableC}` AS `table_shipping_name` ON `main_table`.`shipping_address_id`=`table_shipping_name`.`entity_id`
        WHERE
            (main_table.entity_id IN('{$sOrderId}'))
        ON DUPLICATE KEY
        UPDATE
            `entity_id` = VALUES(`entity_id`),
            `status` = VALUES(`status`),
            `store_id` = VALUES(`store_id`),
            `customer_id` = VALUES(`customer_id`),
            `base_grand_total` = VALUES(`base_grand_total`),
            `base_total_paid` = VALUES(`base_total_paid`),
            `grand_total` = VALUES(`grand_total`),
            `total_paid` = VALUES(`total_paid`),
            `increment_id` = VALUES(`increment_id`),
            `base_currency_code` = VALUES(`base_currency_code`),
            `order_currency_code` = VALUES(`order_currency_code`),
            `store_name` = VALUES(`store_name`),
            `created_at` = VALUES(`created_at`),
            `updated_at` = VALUES(`updated_at`),
            `billing_name` = VALUES(`billing_name`),
            `shipping_name` = VALUES(`shipping_name`)
        ";
        $this->_executeWriteQuery($sQuery);
    }

    /**
     * Hook for extensions
     *
     * @param array $aOrder
     * @param array $aShopOrder
     * @return array
     */
    protected function _modifyOrder($aOrder, $aShopOrder)
    {
        return $aShopOrder;
    }

    /**
     * Get shipping price for given tax configuration
     *
     * @param array $aOrder
     * @param bool  $blIncludeTax
     * @return double
     */
    protected function _getShippingPrice($aOrder, $blIncludeTax = null)
    {
        if ($blIncludeTax === true) {
            return $aOrder['total_shipping']; // brut price
        }

        $oShippingAddress = null;
        if (isset($aOrder['shipping_address'])) {
            $oShippingAddress = $this->_getAddress($aOrder['shipping_address']['country'], $aOrder['shipping_address']['zip']);
        }
        $oBillingAddress = null;
        if (isset($aOrder['billing_address'])) {
            $oBillingAddress = $this->_getAddress($aOrder['billing_address']['country'], $aOrder['billing_address']['zip']);
        }

        $oTax = Mage::getSingleton('tax/calculation');
        $request = $oTax->getRateRequest($oShippingAddress, $oBillingAddress, null, $this->_getStore());
        $request->setProductClassId(Mage::helper('tax')->getShippingTaxClass($this->_getStore()));
        $percent = $oTax->getRate($request);

        $dTaxAmount = Mage::helper('tax')->getCalculator()->calcTaxAmount($aOrder['total_shipping'], $percent, true);
        $dNetPrice = $aOrder['total_shipping'] - $dTaxAmount;
        return $dNetPrice;
    }

    /**
     * Fill the data to a orderarticle entity and write it into the DB
     * Returns the entity_id of the orderarticle
     *
     * @param array $aOrder
     * @param array $aOrderarticle
     * @param string $sOrderId
     * @return string
     */
    protected function _handleOrderarticles($aOrder, $aOrderarticle, $sOrderId)
    {
        $sProductId = $this->_getProductId($aOrderarticle['sku']);
        $sMainId = $sProductId;
        $sParentId = $this->_getParentProductId($sProductId);
        $sType = 'simple';
        if ($sParentId !== false) {
            $sType = 'configurable';
            $sMainId = $sParentId;
        }

        $dVatRate = $this->_getVatRateForProduct($sProductId, $aOrder);

        $dBrutPrice = $aOrderarticle['item_price'];
        $dTotalBrutPrice = $aOrderarticle['price'];
        $dNetPrice = $this->_getNetPrice($dBrutPrice, $dVatRate);
        $dTotalNetPrice = $dNetPrice * $aOrderarticle['quantity'];
        $dTotalVatAmount = $dTotalBrutPrice - $dTotalNetPrice;

        $dOriginalPrice = $dNetPrice;
        if (Mage::helper('tax')->priceIncludesTax($this->_getStore())) {
            $dOriginalPrice = $dBrutPrice;
        }

        //configurable
        $aOrderItem = array();
        $aOrderItem['order_id'] = $sOrderId;
        $aOrderItem['quote_item_id'] = $this->_getQuoteItemId($aOrder['order_number'], $sMainId);
        $aOrderItem['store_id'] = $this->_getShopId();
        $aOrderItem['created_at'] = date('Y-m-d H:i:s');
        $aOrderItem['updated_at'] = date('Y-m-d H:i:s');
        $aOrderItem['product_id'] = $sMainId;
        $aOrderItem['product_type'] = $sType;
        $aOrderItem['weight'] = '1';
        $aOrderItem['is_virtual'] = '0';
        $aOrderItem['sku'] = $aOrderarticle['sku'];
        $aOrderItem['name'] = $aOrderarticle['title'];
        $aOrderItem['is_qty_decimal'] = '0';
        $aOrderItem['qty_ordered'] = $aOrderarticle['quantity'];
        $aOrderItem['price'] = $dNetPrice;
        $aOrderItem['base_price'] = $dNetPrice;
        $aOrderItem['original_price'] = $dOriginalPrice;
        $aOrderItem['base_original_price'] = $dOriginalPrice;
        $aOrderItem['tax_percent'] = $dVatRate;
        $aOrderItem['tax_amount'] = $dTotalVatAmount;
        $aOrderItem['base_tax_amount'] = $dTotalVatAmount;
        $aOrderItem['discount_percent'] = '0';
        $aOrderItem['discount_amount'] = '0';
        $aOrderItem['base_discount_amount'] = '0';
        $aOrderItem['row_total'] = $dTotalNetPrice;
        $aOrderItem['base_row_total'] = $dTotalNetPrice;
        $aOrderItem['row_weight'] = '1';
        $aOrderItem['price_incl_tax'] = $dBrutPrice;
        $aOrderItem['base_price_incl_tax'] = $dBrutPrice;
        $aOrderItem['row_total_incl_tax'] = $dTotalBrutPrice;
        $aOrderItem['base_row_total_incl_tax'] = $dTotalBrutPrice;
        $aOrderItem['hidden_tax_amount'] = '0';
        $aOrderItem['base_hidden_tax_amount'] = '0';
        $aOrderItem['is_nominal'] = '0';
        $aOrderItem['gift_message_available'] = '1';
        $aOrderItem['base_weee_tax_applied_amount'] = '0';
        $aOrderItem['base_weee_tax_applied_row_amnt'] = '0';
        $aOrderItem['weee_tax_applied_amount'] = '0';
        $aOrderItem['weee_tax_applied_row_amount'] = '0';
        $aOrderItem['weee_tax_applied'] = 'a:0:{}';
        $aOrderItem['weee_tax_disposition'] = '0';
        $aOrderItem['weee_tax_row_disposition'] = '0';
        $aOrderItem['base_weee_tax_disposition'] = '0';
        $aOrderItem['base_weee_tax_row_disposition'] = '0';

        $sOrderItemId = $this->_insertRecord($aOrderItem, 'sales/order_item');

        if ($sParentId !== false) {
            //simple
            $aOrderItem = array();
            $aOrderItem['order_id'] = $sOrderId;
            $aOrderItem['parent_item_id'] = $sOrderItemId;
            $aOrderItem['quote_item_id'] = $this->_getQuoteItemId($aOrder['order_number'], $sProductId);
            $aOrderItem['store_id'] = $this->_getShopId();
            $aOrderItem['created_at'] = date('Y-m-d H:i:s');
            $aOrderItem['updated_at'] = date('Y-m-d H:i:s');
            $aOrderItem['product_id'] = $sProductId;
            $aOrderItem['product_type'] = 'simple';
            $aOrderItem['weight'] = '1';
            $aOrderItem['is_virtual'] = '0';
            $aOrderItem['sku'] = $aOrderarticle['sku'];
            $aOrderItem['name'] = $aOrderarticle['title'];
            $aOrderItem['is_qty_decimal'] = '0';
            $aOrderItem['qty_ordered'] = $aOrderarticle['quantity'];
            $aOrderItem['price'] = '0';
            $aOrderItem['base_price'] = '0';
            $aOrderItem['original_price'] = '0';
            $aOrderItem['tax_percent'] = '0';
            $aOrderItem['tax_amount'] = '0';
            $aOrderItem['base_tax_amount'] = '0';
            $aOrderItem['discount_percent'] = '0';
            $aOrderItem['discount_amount'] = '0';
            $aOrderItem['base_discount_amount'] = '0';
            $aOrderItem['row_total'] = '0';
            $aOrderItem['base_row_total'] = '0';
            $aOrderItem['row_weight'] = '0';
            $aOrderItem['is_nominal'] = '0';
            $aOrderItem['gift_message_available'] = '1';
            $aOrderItem['base_weee_tax_applied_amount'] = '0';
            $aOrderItem['weee_tax_applied_amount'] = '0';
            $aOrderItem['weee_tax_applied_row_amount'] = '0';
            $aOrderItem['weee_tax_applied'] = 'a:0:{}';
            $aOrderItem['weee_tax_disposition'] = '0';
            $aOrderItem['weee_tax_row_disposition'] = '0';
            $aOrderItem['base_weee_tax_disposition'] = '0';
            $aOrderItem['base_weee_tax_row_disposition'] = '0';

            $sSimpleOrderItemId = $this->_insertRecord($aOrderItem, 'sales/order_item');
        }

        return $sOrderItemId;
    }

    /**
     * Get the mapped Magento payment method
     *
     * @param array $aOrder
     * @return string
     */
    protected function _getPaymentMethod($aOrder)
    {
        $sPaymentType = $aOrder['payment']['payment_method'];

        $aPaymentMap = Mage::helper('idealo_direktkauf')->getPaymentMapping();
        if ($aPaymentMap && isset($aPaymentMap[$sPaymentType])) {
            $sPaymentType = $aPaymentMap[$sPaymentType];
        }

        return $sPaymentType;
    }

    /**
     * Checks if payment is available in shop
     *
     * @param string $sPaymentType
     * @return bool
     */
    protected function _checkPaymentAvailable( $sPaymentType )
    {
        $aActivePaymentTypes = Mage::helper('idealo_direktkauf')->getShopPaymentTypes();
        if (isset($aActivePaymentTypes[$sPaymentType])) {
            return true;
        }

        return false;
    }

    /**
     * Checks if all articles of order are available in shop
     *
     * @param array $aArticles
     * @return bool
     */
    protected function _orderArticlesExisting( $aArticles )
    {
        $blArticlesValid = true;
        if (is_array($aArticles) && !empty($aArticles)) {
            foreach ($aArticles as $aItem) {
                if ($aItem['sku']) {
                    $sProductId = $this->_getProductId($aItem['sku']);
                    if (!$sProductId) {
                        $blArticlesValid = false;
                    }
                } else {
                    $blArticlesValid = false;
                }
            }
        } else {
            $blArticlesValid = false;
        }

        return $blArticlesValid;
    }

    /**
     * Checks if essential data harmonizes with shop data
     *
     * @param array $aOrder
     * @return bool
     */
    protected function _orderDataIsValid($aOrder)
    {
        $blOrderIsValid                     = true;
        $this->sLastOrderHandleErrorType    = '';

        if ($this->_orderAlreadyExists($aOrder)) {
            $blOrderIsValid = false;
            if (!$blOrderIsValid) {
                $this->sLastOrderHandleErrorType = 'Order already exists';
            }
        }

        if ($blOrderIsValid) {
            // check if payment isset and active
            $sPaymentType = $this->_getPaymentMethod($aOrder);
            $blOrderIsValid = $this->_checkPaymentAvailable($sPaymentType);
            if (!$blOrderIsValid) {
                $this->sLastOrderHandleErrorType = 'Payment not available in shop';
            }
        }

        if ($blOrderIsValid) {
            // check if articles are available in shop
            $blOrderIsValid = $this->_orderArticlesExisting($aOrder['line_items']);
            if (!$blOrderIsValid) {
                $this->sLastOrderHandleErrorType = 'Not all articles are available in shop';
            }
        }

        return $blOrderIsValid;
    }

    /**
     * Request all orders from idealo
     *
     * @return array
     */
    protected function _getOrders()
    {
        $aOrders = array();

        try {
            $oClient = Mage::helper('idealo_direktkauf')->getClient();
            $aOrders = $oClient->getOrders();
            // false = not response from idealo api
            // null = reponse could not be decoded
            if ($aOrders === false || $aOrders === null) {
                $this->_sendGetOrdersErrorMail($oClient);
            }
        } catch (Exception $oEx) {
            $this->_sendExceptionMail($oEx, 'script: Idealo_Direktkauf_Model_Cronjobs_ImportOrders::_getOrders()');
        }

        return $aOrders;
    }

    /**
     * Import all orders from idealo
     *
     * @return void
     */
    protected function _importOrders()
    {
        $aOrders = $this->_getOrders();
        if (is_array($aOrders) && !empty($aOrders)) {
            foreach ($aOrders as $aOrder) {
                try {
                    if ($this->_orderDataIsValid($aOrder)) {
                        $iQuoteId = $this->_addQuote($aOrder);
                        Mage::dispatchEvent('idealo_direktkauf_import_order_handle_before',
                            array(
                                'quote_id' => $iQuoteId,
                                'idealo_order_object' => $aOrder
                            )
                        );
                        $magentoOrderId = $this->_handleOrder($aOrder, $iQuoteId);
                        Mage::dispatchEvent('idealo_direktkauf_import_order_handle_after',
                            array(
                                'quote_id' => $iQuoteId,
                                'order_id' => $magentoOrderId,
                                'idealo_order_object' => $aOrder
                            )
                        );
                    } else {
                        $this->_sendHandleOrderError($aOrder, $this->sLastOrderHandleErrorType);
                        $this->_sendOrderRevocation($aOrder['order_number'], 'MERCHANT_DECLINE', $this->sLastOrderHandleErrorType);
                    }
                } catch (Exception $oEx) {
                    $this->_sendHandleOrderError($aOrder, $oEx->getMessage());
                }
            }
        } else {
            // this is the case when there was a correct response from the idealo API and the json could be decoded but it
            // simly has 0 orders included
        }
    }

    /**
     * Check if needed connection data is set
     *
     * @return bool
     */
    protected function _connectionDataIsSet()
    {
        if (!Mage::helper('idealo_direktkauf')->getAuthToken()) {
            return false;
        }
        
        return true;
    }

    /**
     * Main method to start this cronjob
     *
     * @return void
     */
    public function start()
    {
        $aStores = $this->_getAllStores();
        foreach ($aStores as $oStore) {
            $this->_setStore($oStore);
            
            $sToken = Mage::helper('idealo_direktkauf')->getAuthToken();
            if (Mage::helper('idealo_direktkauf')->isActive() && array_search($sToken, $this->_aImportedTokens) === false) {
                if ($this->_connectionDataIsSet()) {
                    $this->_importOrders();
                } else {
                    $this->_sendConnectionDataMissingError();
                }
                
                $this->_aImportedTokens[] = $sToken;
            }
        }
    }
}