<?php
/**
 * OnePica_AvaTax
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0), a
 * copy of which is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   OnePica
 * @package    OnePica_AvaTax
 * @author     OnePica Codemaster <codemaster@onepica.com>
 * @copyright  Copyright (c) 2015 One Pica, Inc.
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

/**
 * The AvaTax16 Invoice model
 *
 * @category   OnePica
 * @package    OnePica_AvaTax
 * @author     OnePica Codemaster <codemaster@onepica.com>
 */
class OnePica_AvaTax_Model_Service_Avatax16_Invoice extends OnePica_AvaTax_Model_Service_Avatax16_Tax
{
    /**
     * An array of line numbers to product ids
     *
     * @var array
     */
    protected $_lineToItemId = array();

    /**
     * Save order in AvaTax system
     *
     * @see OnePica_AvaTax_Model_Observer::salesOrderPlaceAfter()
     * @param Mage_Sales_Model_Order_Invoice $invoice
     * @param OnePica_AvaTax_Model_Records_Queue $queue
     * @return bool
     * @throws OnePica_AvaTax_Exception
     * @throws OnePica_AvaTax_Model_Service_Exception_Commitfailure
     * @throws OnePica_AvaTax_Model_Service_Exception_Unbalanced
     */
    public function invoice($invoice, $queue)
    {
        $order = $invoice->getOrder();
        $storeId = $order->getStoreId();
        $invoiceDate = $this->_convertGmtDate($invoice->getCreatedAt(), $storeId);

        $shippingAddress = ($order->getShippingAddress()) ? $order->getShippingAddress() : $order->getBillingAddress();
        if (!$shippingAddress) {
            throw new OnePica_AvaTax_Exception($this->__('There is no address attached to this order'));
        }

        $configModel = $this->getService()->getServiceConfig()->init($storeId);
        $config = $configModel->getLibConfig();

        // Set up document for request
        $this->_request = new OnePica_AvaTax16_Document_Request();

        // set up header
        $header = new OnePica_AvaTax16_Document_Request_Header();
        $header->setAccountId($config->getAccountId());
        $header->setCompanyCode($config->getCompanyCode());
        $header->setTransactionType(self::TRANSACTION_TYPE_SALE);
        $header->setDocumentCode($invoice->getIncrementId());
        $header->setCustomerCode($this->_getConfigHelper()->getSalesPersonCode($storeId));
        $header->setVendorCode(self::DEFAULT_VENDOR_CODE);
        $header->setTransactionDate($invoiceDate);
        $header->setTaxCalculationDate($this->_getDateModel()->date('Y-m-d'));
        $header->setDefaultLocations($this->_getHeaderDefaultLocations($shippingAddress));
        $header->setDefaultAvalaraGoodsAndServicesType($this->_getConfigHelper()
            ->getDefaultAvalaraGoodsAndServicesType($storeId));
        $header->setDefaultAvalaraGoodsAndServicesModifierType($this->_getConfigHelper()
            ->getDefaultAvalaraGoodsAndServicesModifierType($storeId));
        $header->setDefaultTaxPayerCode($this->_getConfigHelper()->getDefaultTaxPayerCode($storeId));
        $header->setDefaultUseType($this->_getConfigHelper()->getDefaultUseType($storeId));
        $header->setDefaultBuyerType($this->_getConfigHelper()->getDefaultBuyerType($storeId));

        $this->_request->setHeader($header);

        $this->_addShipping($invoice);
        $items = $invoice->getItemsCollection();
        $this->_initProductCollection($items);
        $this->_initTaxClassCollection($invoice);
        //Added code for calculating tax for giftwrap items
        $this->_addGwOrderAmount($invoice);
        $this->_addGwItemsAmount($invoice);
        $this->_addGwPrintedCardAmount($invoice);
    }

    /**
     * Adds shipping cost to request as item
     *
     * @param Mage_Sales_Model_Order_Invoice|Mage_Sales_Model_Order_Creditmemo $object
     * @param bool $credit
     * @return int|bool
     */
    protected function _addShipping($object, $credit = false)
    {
        if ($object->getBaseShippingAmount() == 0) {
            return false;
        }

        $lineNumber = $this->_getNewLineCode();;
        $storeId = $object->getStore()->getId();
        $taxClass = Mage::helper('tax')->getShippingTaxClass($storeId);

        $amount = $object->getBaseShippingAmount();
        $amount = $credit ? (-1 * $amount) : $amount;

        $line = new OnePica_AvaTax16_Document_Request_Line();
        $line->setLineCode($lineNumber);
        $shippingSku = $this->_getConfigHelper()->getShippingSku($storeId);
        $line->setItemCode($shippingSku ? $shippingSku : self::DEFAULT_SHIPPING_ITEMS_SKU);
        $line->setItemDescription(self::DEFAULT_SHIPPING_ITEMS_DESCRIPTION);
        $line->setTaxCode($taxClass);
        $line->setNumberOfItems(1);
        $line->setlineAmount($amount);
        $line->setDiscounted('false');

        $this->_lineToItemId[$lineNumber] = $shippingSku;
        $this->_lines[$lineNumber] = $line;
        $this->_setLinesToRequest();
        return $lineNumber;
    }

    /**
     * Adds giftwraporder cost to request as item
     *
     * @param Mage_Sales_Model_Order_Invoice|Mage_Sales_Model_Order_Creditmemo $object
     * @param bool $credit
     * @return int|bool
     */
    protected function _addGwOrderAmount($object, $credit = false)
    {
        if ($object->getGwPrice() == 0) {
            return false;
        }

        $lineNumber = $this->_getNewLineCode();
        $storeId = $object->getStore()->getId();
        $amount = $object->getGwBasePrice();
        $amount = $credit ? (-1 * $amount) : $amount;

        $line = new OnePica_AvaTax16_Document_Request_Line();
        $line->setLineCode($lineNumber);
        $gwOrderSku = $this->_getConfigHelper()->getGwOrderSku($storeId);
        $line->setItemCode($gwOrderSku ? $gwOrderSku : self::DEFAULT_GW_ORDER_SKU);
        $line->setItemDescription(self::DEFAULT_GW_ORDER_DESCRIPTION);
        $line->setTaxCode($this->_getGiftTaxClassCode($storeId));
        $line->setNumberOfItems(1);
        $line->setlineAmount($amount);
        $line->setDiscounted('false');

        $this->_lineToItemId[$lineNumber] = $gwOrderSku;
        $this->_lines[$lineNumber] = $line;
        $this->_setLinesToRequest();
        return $lineNumber;
    }

    /**
     * Adds giftwrapitems cost to request as item
     *
     * @param Mage_Sales_Model_Order_Invoice|Mage_Sales_Model_Order_Creditmemo $object
     * @param bool $credit
     * @return int|bool
     */
    protected function _addGwItemsAmount($object, $credit = false)
    {
        if ($object->getGwItemsPrice() == 0) {
            return false;
        }

        $lineNumber = $this->_getNewLineCode();
        $storeId = $object->getStore()->getId();

        $amount = $object->getGwItemsBasePrice();
        $amount = $credit ? (-1 * $amount) : $amount;

        $line = new OnePica_AvaTax16_Document_Request_Line();
        $line->setLineCode($lineNumber);
        $gwItemsSku = $this->_getConfigHelper()->getGwItemsSku($storeId);
        $line->setItemCode($gwItemsSku ? $gwItemsSku : self::DEFAULT_GW_ITEMS_SKU);
        $line->setItemDescription(self::DEFAULT_GW_ITEMS_DESCRIPTION);
        $line->setTaxCode($this->_getGiftTaxClassCode($storeId));
        $line->setNumberOfItems(1);
        $line->setlineAmount($amount);
        $line->setDiscounted('false');

        $this->_lineToItemId[$lineNumber] = $gwItemsSku;
        $this->_lines[$lineNumber] = $line;
        $this->_setLinesToRequest();
        return $lineNumber;
    }

    /**
     * Adds giftwrap printed card cost to request as item
     *
     * @param Mage_Sales_Model_Order_Invoice|Mage_Sales_Model_Order_Creditmemo $object
     * @param bool $credit
     * @return int|bool
     */
    protected function _addGwPrintedCardAmount($object, $credit = false)
    {
        if (!$object->getGwPrintedCardBasePrice()) {
            return false;
        }

        $lineNumber = $this->_getNewLineCode();
        $storeId = $object->getStore()->getId();

        $amount = $object->getGwPrintedCardBasePrice();
        $amount = $credit ? (-1 * $amount) : $amount;

        $line = new OnePica_AvaTax16_Document_Request_Line();
        $line->setLineCode($lineNumber);
        $gwPrintedCardSku = $this->_getConfigHelper()->getGwPrintedCardSku($storeId);
        $line->setItemCode($gwPrintedCardSku ? $gwPrintedCardSku : self::DEFAULT_GW_PRINTED_CARD_SKU);
        $line->setItemDescription(self::DEFAULT_GW_PRINTED_CARD_DESCRIPTION);
        $line->setTaxCode($this->_getGiftTaxClassCode($storeId));
        $line->setNumberOfItems(1);
        $line->setlineAmount($amount);
        $line->setDiscounted('false');

        $this->_lineToItemId[$lineNumber] = $gwPrintedCardSku;
        $this->_lines[$lineNumber] = $line;
        $this->_setLinesToRequest();
        return $lineNumber;
    }

    /**
     * Retrieve converted date taking into account the current time zone and store.
     *
     * @param string $gmt
     * @param int    $storeId
     * @return string
     */
    protected function _convertGmtDate($gmt, $storeId)
    {
        return Mage::app()->getLocale()->storeDate($storeId, $gmt)->toString(Varien_Date::DATE_INTERNAL_FORMAT);
    }
}
