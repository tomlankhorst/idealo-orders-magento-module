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
 * Source class for order status options
 */
class Idealo_Direktkauf_Model_Source_OrderStatus
{
    // set null to enable all possible
    protected $_states = array(
        Mage_Sales_Model_Order::STATE_NEW,
        Mage_Sales_Model_Order::STATE_PENDING_PAYMENT,
        Mage_Sales_Model_Order::STATE_PROCESSING,
        Mage_Sales_Model_Order::STATE_HOLDED,
        Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW,
    );

    /**
     * @return array
     */
    public function toArray()
    {
        
    }

    /**
     * Get option array
     * 
     * @return array
     */
    public function toOptionArray()
    {
        $data = array();
        $options = $this->toGroupArray();
        foreach ($options as $stateCode => $stateConfig) {
            if (!array_key_exists('values', $stateConfig)) {
                continue;
            }
            $stateValues = $stateConfig['values'];

            if (array_key_exists('label', $stateConfig)) {
                $stateLabel = $stateConfig['label'];
            }
            else {
                $stateLabel = Mage::helper('adminhtml')->__($stateCode);
            }

            if (!array_key_exists($stateCode, $data)) {
                $data[$stateCode] = array(
                    'label' => $stateLabel,
                    'value' => array(),
                );
            }

            foreach ($stateValues as $key => $value) {
                $keyValue = $stateCode . '|' . $key;
                $data[$stateCode]['value'][$keyValue] = array(
                    'value' => $keyValue,
                    'label' => Mage::helper('adminhtml')->__($value)
                );
            }
        }

        array_unshift($data, Mage::helper('adminhtml')->__('-- Please Select --'));

        return $data;
    }

    /**
     * Get group array
     * 
     * @return array
     */
    public function toGroupArray()
    {
        $states = Mage::getSingleton('sales/order_config')->getStates();

        $stateStatusArray = array();
        foreach ($this->_states as $state) {
            $stateStatuses = Mage::getSingleton('sales/order_config')->getStateStatuses($state);

            if (array_key_exists($state, $states)) {
                $stateLabel = $states[$state];
            }
            else {
                $stateLabel = Mage::helper('adminhtml')->__($state);
            }

            $stateStatusArray[$state] = array(
                'label' => $stateLabel,
                'values' => $stateStatuses
            );
        }
        return $stateStatusArray;
    }

}
