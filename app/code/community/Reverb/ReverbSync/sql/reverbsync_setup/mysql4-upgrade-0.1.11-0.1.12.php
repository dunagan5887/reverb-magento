<?php

/**
 * This migration script was intentionally committed twice as version 0.1.11 and 0.1.12 due to potential
 *  inconsistencies in databases caused by the reversion of git commits on the master branch
 */

$installer = $this;
$installer->startSetup();

/*
    This line has been commented out as this functionality is now deprecated. Population of the categories table
    now takes place in a later migration file in this sequence
*/
//Mage::getResourceSingleton('reverbSync/category_reverb')->initializeReverbCategoriesTable();

$installer->endSetup();
