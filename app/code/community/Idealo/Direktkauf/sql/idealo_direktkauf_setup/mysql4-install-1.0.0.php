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

/** @var $this Mage_Core_Model_Resource_Setup */
/** @var $installer Mage_Core_Model_Resource_Setup */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();

$tableOrder = $this->getTable('sales/order');
$tableOrderGrid = $this->getTable('sales/order_grid');

if (!$connection->tableColumnExists($tableOrder, 'idealo_order_nr')) {
    $installer->run("ALTER TABLE {$tableOrder} ADD `idealo_order_nr` VARCHAR(32);");
}
if (!$connection->tableColumnExists($tableOrder, 'idealo_delivery_carrier')) {
    $installer->run("ALTER TABLE {$tableOrder} ADD `idealo_delivery_carrier` VARCHAR(32);");
}
if (!$connection->tableColumnExists($tableOrder, 'idealo_ordernr_sent')) {
    $installer->run("ALTER TABLE {$tableOrder} ADD `idealo_ordernr_sent` DATETIME;");
}
if (!$connection->tableColumnExists($tableOrder, 'idealo_fulfillment_sent')) {
    $installer->run("ALTER TABLE {$tableOrder} ADD `idealo_fulfillment_sent` DATETIME;");
}
if (!$connection->tableColumnExists($tableOrder, 'idealo_trackingcode_sent')) {
    $installer->run("ALTER TABLE {$tableOrder} ADD `idealo_trackingcode_sent` DATETIME;");
}
if (!$connection->tableColumnExists($tableOrder, 'idealo_revocation_sent')) {
    $installer->run("ALTER TABLE {$tableOrder} ADD `idealo_revocation_sent` DATETIME;");
}
if (!$connection->tableColumnExists($tableOrderGrid, 'idealo_order_nr')) {
    $installer->run("ALTER TABLE {$tableOrderGrid} ADD `idealo_order_nr` VARCHAR(32);");
}
    
$installer->endSetup();