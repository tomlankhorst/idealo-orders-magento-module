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
 * 
 */
class Idealo_Direktkauf_Adminhtml_Idealo_ImportOrdersController extends Mage_Adminhtml_Controller_Action
{

    /**
     * Starts the order import
     */
    public function indexAction()
    {
        $oImport = Mage::getModel('idealo_direktkauf/cronjobs_importOrders');
        $oImport->start();
        $this->getResponse()->setBody('Import finished');
    }
    
    /**
     * Check current user permission on resource and privilege
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('admin');
    }
}
