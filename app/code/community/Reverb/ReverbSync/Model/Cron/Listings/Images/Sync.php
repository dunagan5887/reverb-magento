<?php
/**
 * Author: Sean Dunagan
 * Created: 8/14/15
 */

class Reverb_ReverbSync_Model_Cron_Listings_Images_Sync
    extends Reverb_Process_Model_Locked_File_Cron_Abstract
    implements Reverb_Process_Model_Locked_File_Cron_Interface
{
    const CRON_UNCAUGHT_EXCEPTION = 'Error processing the Reverb Listing Image Sync Process Queue: %s';

    protected $_suppress_failed_lock_attempt_error_messages = true;

    public function executeCron()
    {
        try
        {
            Mage::helper('reverb_process_queue/task_processor_unique')->processQueueTasks('listing_image_sync');
        }
        catch(Exception $e)
        {
            $error_message = sprintf(self::CRON_UNCAUGHT_EXCEPTION, $e->getMessage());
            Mage::log($error_message, null, 'reverb_process_queue_error.log');
            $exceptionToLog = new Exception($error_message);
            Mage::logException($exceptionToLog);
        }
    }

    public function getParallelThreadCount()
    {
        return 4;
    }

    public function getLockFileName()
    {
        return 'reverb_listing_image_sync';
    }

    public function getLockFileDirectory()
    {
        return Mage::getBaseDir('var') . DS . 'lock' . DS . 'reverb_listing_image_sync';
    }

    public function getCronCode()
    {
        return 'reverb_listing_image_sync';
    }

    protected function _logError($error_message)
    {
        Mage::getSingleton('reverbSync/log')->logListingImageSyncError($error_message);
    }
} 
