<?php

/**
 * Class Reverb_ReverbSync_Model_Mapper_Product
 *
 * This model meant to be referenced as a Singleton via Mage::getSingleton() functionality
 *
 * @category    Reverb
 * @package     Reverb_ReverbSync
 * @author      Sean Dunagan
 * @author      Timur Zaynullin <zztimur@gmail.com>
 */

class Reverb_ReverbSync_Model_Mapper_Product
{
    const LISTING_CREATION_ENABLED_CONFIG_PATH = 'ReverbSync/reverbDefault/enable_image_sync';
    const LISTING_DEFAULT_CONDITION_CONFIG_PATH = 'ReverbSync/reverbDefault/revCond';
    const LISTING_DEFAULT_OFFER_ENABLED_CONFIG_PATH = 'ReverbSync/reverbDefault/offers_enabled';
    const REVERB_LISTING_FIELD_PRODUCT_ATTRIBUTE_CONFIG = 'ReverbSync/listings_field_attributes/%s';

    const REVERB_CONDITION_PRODUCT_ATTRIBUTE = 'reverb_condition';

    protected $_image_sync_is_enabled = null;
    protected $_condition = null;
    protected $_has_inventory = null;
    protected $_listingsUpdateSyncHelper = null;
    protected $_categorySyncHelper = null;
    protected $_reverbConditionSourceModel = null;
    protected $_reverb_field_product_attributes = array();

    protected $_arbitrary_magento_to_reverb_field_mapping = null;
    protected $_reverb_fields_mapped_to_magento_attributes = array('make', 'model', 'shipping_profile_name', 'finish', 'year');

    //LEGACY CODE: function to Map the Magento and Reverb attributes
    public function getUpdateListingWrapper(Mage_Catalog_Model_Product $product)
    {
        $reverbListingWrapper = Mage::getModel('reverbSync/wrapper_listing');
        $sku = $product->getSku();
        // $condition = $this->_getCondition();

        $fieldsArray = array('sku'=> $sku);

        if ($this->_getListingsUpdateSyncHelper()->isTitleUpdateEnabled())
        {
            $fieldsArray['title'] = $product->getName();
        }

        if ($this->_getListingsUpdateSyncHelper()->isPriceUpdateEnabled())
        {
            $fieldsArray['price'] = $this->getProductPrice($product);
        }

        if ($this->_getListingsUpdateSyncHelper()->isDescriptionUpdateEnabled())
        {
            $fieldsArray['description'] = $this->getProductDescription($product);
        }

        if ($this->_getListingsUpdateSyncHelper()->isInventoryQtyUpdateEnabled())
        {
            $hasInventory = $this->_getHasInventory();
            $fieldsArray['has_inventory'] = $hasInventory;

            $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
            $fieldsArray['inventory'] = $stock->getQty();
        }

        $this->_addMappedAttributes($fieldsArray, $product);
        $this->_addArbitraryMappedAttributes($fieldsArray, $product);
        $this->_addProductAcceptOffers($fieldsArray, $product);
        $this->addCategoryToFieldsArray($fieldsArray, $product);
        $this->addProductConditionIfSet($fieldsArray, $product);

        $reverbListingWrapper->setApiCallContentData($fieldsArray);
        $reverbListingWrapper->setMagentoProduct($product);

        return $reverbListingWrapper;
    }

    public function getCreateListingWrapper(Mage_Catalog_Model_Product $product)
    {
        $reverbListingWrapper = Mage::getModel('reverbSync/wrapper_listing');
        $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
        $qty = $stock->getQty();
        $price = $this->getProductPrice($product);
        $name = $product->getName();
        $description = $this->getProductDescription($product);
        $sku = $product->getSku();
        $hasInventory = $this->_getHasInventory();

        $fieldsArray = array(
            'title'=> $name,
            'sku'=> $sku,
            'description'=>$description,
            "has_inventory"=>$hasInventory,
            "inventory"=>$qty,
            "price"=>$price
        );

        $this->_addMappedAttributes($fieldsArray, $product);
        $this->_addArbitraryMappedAttributes($fieldsArray, $product);
        $this->_addProductAcceptOffers($fieldsArray, $product);
        $this->addProductImagesToFieldsArray($fieldsArray, $product);
        $this->addCategoryToFieldsArray($fieldsArray, $product);
        $this->addProductConditionIfSet($fieldsArray, $product);

        $reverbListingWrapper->setApiCallContentData($fieldsArray);
        $reverbListingWrapper->setMagentoProduct($product);

        return $reverbListingWrapper;
    }

    /**
     * @param array $fieldsArray
     * @param Mage_Catalog_Model_Product $product
     */
    protected function _addArbitraryMappedAttributes(&$fieldsArray, $product)
    {
        $magento_to_reverb_field_mapping = $this->_getArbitraryMagentoToReverbFieldMapping();
        foreach($magento_to_reverb_field_mapping as $fieldMapping)
        {
            /* @var $fieldMapping Reverb_ReverbSync_Model_Field_Mapping */
            $magento_attribute_code = $fieldMapping->getMagentoAttributeCode();
            $reverb_api_field = $fieldMapping->getReverbApiField();
            $product_data_value = $product->getData($magento_attribute_code);
            if (!is_null($product_data_value))
            {
                $fieldsArray[$reverb_api_field] = $product_data_value;
            }
        }
    }

    /**
     * @return array
     */
    protected function _getArbitraryMagentoToReverbFieldMapping()
    {
        if (is_null($this->_arbitrary_magento_to_reverb_field_mapping))
        {
            $this->_arbitrary_magento_to_reverb_field_mapping
                = Mage::getModel('reverbSync/field_mapping')->getCollection()->getItems();
        }
    
        return $this->_arbitrary_magento_to_reverb_field_mapping;
    }
    
    public function getProductPrice($product)
    {
        $attribute_for_reverb_price = $this->getMagentoPriceAttributeToMapToReverbPrice();
        if (!empty($attribute_for_reverb_price))
        {
            $reverb_price = $product->getData($attribute_for_reverb_price);
            if (!empty($reverb_price))
            {
                return $reverb_price;
            }
        }
        return $product->getPrice();
    }

    public function getProductDescription($product)
    {
        $_reverb_allowed_tags = '<ul>,<ol>,<li>,<strong>,<b>,<em>,<i>,<u>,<div>,<p>,<br>';
        $_descr = Mage::helper('core')->stripTags($this->getProductValueForListing($product, 'description'), $_reverb_allowed_tags);

        return $_descr;
    }

    public function getProductValueForListing($product, $reverb_field)
    {
        $_attribute_code = $this->getMagentoProductAttributeForReverbField($reverb_field);

        if (empty($_attribute_code))
            return null;

        $_attribute_value = $product->getResource()
            ->getAttribute($_attribute_code)
            ->getFrontend()
            ->getValue($product);

        if (empty($_attribute_value))
            $_attribute_value = $product->getData($_attribute_code);

        return $_attribute_value;
    }

    public function getMagentoProductAttributeForReverbField($reverb_field)
    {
        if (!isset($this->_reverb_field_product_attributes[$reverb_field]))
        {
            $config_value = sprintf(self::REVERB_LISTING_FIELD_PRODUCT_ATTRIBUTE_CONFIG, $reverb_field);
            $this->_reverb_field_product_attributes[$reverb_field] = Mage::getStoreConfig($config_value);
        }

        return $this->_reverb_field_product_attributes[$reverb_field];
    }

    public function addProductConditionIfSet(array &$fieldsArray, $product)
    {
        $_product_condition = $product->getAttributeText(self::REVERB_CONDITION_PRODUCT_ATTRIBUTE);

        // Get default value if condition is not set
        if (empty($_product_condition))
            $_product_condition = Mage::getStoreConfig(self::LISTING_DEFAULT_CONDITION_CONFIG_PATH);

        if (!empty($_product_condition) && $this->_getReverbConditionSourceModel()->isValidConditionValue($_product_condition))
            $fieldsArray['condition'] = $_product_condition;

        return $fieldsArray;
    }

    public function addCategoryToFieldsArray(array &$fieldsArray, $product)
    {
        $fieldsArray = $this->_getCategorySyncHelper()->addCategoriesToListingFieldsArray($fieldsArray, $product);
        return $fieldsArray;
    }

    public function addProductImagesToFieldsArray(&$fieldsArray, Mage_Catalog_Model_Product $product)
    {
        if (!$this->_getImageSyncIsEnabled())
        {
            return;
        }

        try
        {
            $gallery_image_urls_array = array();
            // If the product has a base image set, we want that image to be the first image in the eventual images
            //      array that is sent to Reverb
            $base_image_url = $this->_getProductBaseImageUrl($product);
            if (!is_null($base_image_url))
            {
                $gallery_image_urls_array[] = $base_image_url;
            }
            // Add all gallery images to the array that is sent to Reverb
            $galleryImagesCollection = $product->getMediaGalleryImages();
            if (is_object($galleryImagesCollection))
            {
                $gallery_image_items = $galleryImagesCollection->getItems();
                foreach($gallery_image_items as $galleryImageObject)
                {
                    $gallery_image_url = $galleryImageObject->getUrl();
                    // If the base image is not null and the base image url is not the same as this gallery image' url
                    if ((!is_null($base_image_url)) && (strcmp($base_image_url, $gallery_image_url)))
                    {
                        // Set the gallery image url to be added to the array which is communicated to Reverb
                        $gallery_image_urls_array[] = $gallery_image_url;
                    }
                }
                // Remove any potential duplicates
                $unique_image_urls_array = array_unique($gallery_image_urls_array);
                $fieldsArray['photos'] = $unique_image_urls_array;
            }
        }
        catch(Exception $e)
        {
            // Do nothing here
        }
    }

    /**
     * Returns the product's base image if one is defined
     *
     * @param Mage_Catalog_Model_Product $product
     * @return string|null
     */
    protected function _getProductBaseImageUrl(Mage_Catalog_Model_Product $product)
    {
        $product_base_image = $product->getImage();
        if(empty($product_base_image) || ($product_base_image == 'no_selection'))
        {
            return null;
        }

        $base_image_url = Mage::getModel('catalog/product_media_config')->getMediaUrl($product_base_image);
        return $base_image_url;
    }

    /**
     * @return string
     */
    public function getReverbConditionAttribute()
    {
        return self::REVERB_CONDITION_PRODUCT_ATTRIBUTE;
    }

    /**
     * Returns the Magento attributes which are mapped to Reverb listing sync API request fields
     *
     * @return array
     */
    public function getMagentoAttributesMappedToReverbAttributes()
    {
        $magento_attribute_codes = array();

        foreach($this->_reverb_fields_mapped_to_magento_attributes as $reverb_field)
        {
            $attribute_code = $this->getMagentoProductAttributeForReverbField($reverb_field);
            if (!empty($attribute_code))
            {
                $magento_attribute_codes[] = $attribute_code;
            }
        }

        $arbitrary_magento_to_reverb_field_mapping = $this->_getArbitraryMagentoToReverbFieldMapping();
        foreach($arbitrary_magento_to_reverb_field_mapping as $fieldMapping)
        {
            /* @var $fieldMapping Reverb_ReverbSync_Model_Field_Mapping */
            $magento_attribute_code = $fieldMapping->getMagentoAttributeCode();
            $magento_attribute_codes[] = $magento_attribute_code;
        }

        return $magento_attribute_codes;
    }

    public function getMagentoPriceAttributeToMapToReverbPrice()
    {
        $price_attribute_code = $this->getMagentoProductAttributeForReverbField('price');
        return (!empty($price_attribute_code)) ? $price_attribute_code : 'price';
    }

    protected function _addMappedAttributes(&$fieldsArray, $product)
    {
        foreach($this->_reverb_fields_mapped_to_magento_attributes as $reverb_field)
        {
            $product_value = $this->getProductValueForListing($product, $reverb_field);
            if ((!is_null($product_value)) && ($product_value !== ''))
            {
                $fieldsArray[$reverb_field] = $product_value;
            }
        }
    }

    protected function _addProductAcceptOffers(&$fieldsArray, $product)
    {
        $_offers_enabled = $product->getData('reverb_offers_enabled');
        $_reverb_offers_enabled = false;

        if (!empty($_offers_enabled)){
            // Accept offers set on product
            if (1 == $_offers_enabled) {
                $_reverb_offers_enabled = true;
            }

            if (2 == $_offers_enabled) {
                $_reverb_offers_enabled = false;
            }
        } else {
            $_reverb_offers_enabled = Mage::getStoreConfig(self::LISTING_DEFAULT_OFFER_ENABLED_CONFIG_PATH);
        }

        return $fieldsArray['offers_enabled'] = $_reverb_offers_enabled;
    }

    protected function _getReverbConditionSourceModel()
    {
        if (is_null($this->_reverbConditionSourceModel))
        {
            $this->_reverbConditionSourceModel = Mage::getSingleton('reverbSync/source_listing_condition');
        }

        return $this->_reverbConditionSourceModel;
    }

    /**
     * @return Reverb_ReverbSync_Helper_Sync_Category
     */
    protected function _getCategorySyncHelper()
    {
        if (is_null($this->_categorySyncHelper))
        {
            $this->_categorySyncHelper = Mage::helper('ReverbSync/sync_category');
        }

        return $this->_categorySyncHelper;
    }

    protected function _getListingsUpdateSyncHelper()
    {
        if (is_null($this->_listingsUpdateSyncHelper))
        {
            $this->_listingsUpdateSyncHelper = Mage::helper('ReverbSync/sync_listings_update');
        }

        return $this->_listingsUpdateSyncHelper;
    }

    protected function _getImageSyncIsEnabled()
    {
        if (is_null($this->_image_sync_is_enabled))
        {
            $this->_image_sync_is_enabled = Mage::getStoreConfig(self::LISTING_CREATION_ENABLED_CONFIG_PATH);
        }

        return $this->_image_sync_is_enabled;
    }

    protected function _getCondition()
    {
        if (is_null($this->_condition))
        {
            $this->_condition = Mage::getStoreConfig('ReverbSync/reverbDefault/revCond');
        }

        return $this->_condition;
    }

    protected function _getHasInventory()
    {
        if (is_null($this->_has_inventory))
        {
            $this->_has_inventory = Mage::getStoreConfig('ReverbSync/reverbDefault/revInvent');
        }

        return $this->_has_inventory;
    }
}
