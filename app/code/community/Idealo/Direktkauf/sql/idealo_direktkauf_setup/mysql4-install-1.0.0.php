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

$installer = $this;
$installer->startSetup();

$tableOrder = $this->getTable('sales/order');
$tableOrderGrid = $this->getTable('sales/order_grid');

$installer->run("
    ALTER TABLE {$tableOrder} ADD `idealo_order_nr` VARCHAR(32);
    ALTER TABLE {$tableOrder} ADD `idealo_delivery_carrier` VARCHAR(32);
    ALTER TABLE {$tableOrder} ADD `idealo_ordernr_sent` DATETIME;
    ALTER TABLE {$tableOrder} ADD `idealo_fulfillment_sent` DATETIME;
    ALTER TABLE {$tableOrder} ADD `idealo_trackingcode_sent` DATETIME;
    ALTER TABLE {$tableOrder} ADD `idealo_revocation_sent` DATETIME;
    
    ALTER TABLE {$tableOrderGrid} ADD `idealo_order_nr` VARCHAR(32);
");
    
$installer->endSetup();