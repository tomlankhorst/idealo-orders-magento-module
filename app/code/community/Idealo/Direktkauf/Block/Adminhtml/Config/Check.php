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
 * Config check block for admin config
 */
class Idealo_Direktkauf_Block_Adminhtml_Config_Check 
    extends Mage_Adminhtml_Block_System_Config_Form_Field_Array_Abstract
{

    /**
     * constructor
     * 
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('idealo/direktkauf/config/check.phtml');
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
                'label' =>'A',
                'style' => '',
            )
        );
        parent::_prepareToRender();
    }

    /**
     * Return if idealo configuration is complete
     * 
     * @return bool
     */
    public function isIdealoConfigComplete()
    {
        if (!$this->isIdealoTokenMissing() && !$this->isIdealoEmailMissing() && $this->isIdealoTokenCorrect()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Return is API token is not configured
     * 
     * @return bool
     */
    public function isIdealoTokenMissing()
    {
        $sToken = Mage::helper('idealo_direktkauf')->getAuthToken();
        return empty($sToken);
    }
    
    /**
     * Return is email address is not configured
     * 
     * @return bool
     */
    public function isIdealoEmailMissing()
    {
        $sToken = Mage::helper('idealo_direktkauf')->getErrorEmailAddress();
        return empty($sToken);        
    }
    
    /**
     * Return if idealo token is correct
     * 
     * @return bool
     */
    public function isIdealoTokenCorrect()
    {
        $blSuccess = false;

        $oClient = Mage::helper('idealo_direktkauf')->getClient();
        $aOrders = $oClient->getOrders();
        if (is_array($aOrders) && !empty($aOrders) || $oClient->getHttpStatus() == 200) {
            $blSuccess = true;
        }
        
        return $blSuccess;
    }

}