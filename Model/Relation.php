<?php
/**
 * Copyright © 2016 MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\AlsoBought\Model;

class Relation extends \Magento\Framework\Model\AbstractModel
{

    const MAX_LINK_POSITION = 1000;
    /**
     * @var \Magento\Catalog\Model\ProductFactory
     */
    protected $productFactory;

    /**
     * @var \Magento\Store\Model\StoreManager
     */
    protected $storeManager;

    /**
     * @var \Magento\Catalog\Model\ProductLink\Repository
     */
    protected $productLinkRepository;

    /**
     * @var \Magento\Catalog\Api\Data\ProductLinkInterfaceFactory
     */
    protected $productLinkFactory;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product
     */
    protected $productResource;

    /**
     * @var \Magento\Catalog\Model\Product\Attribute\Source\Status
     */
    protected $productStatus;

    /**
     * @var \Magento\Catalog\Model\Product\Visibility
     */
    protected $productVisibility;

    /**
     * @var \Magento\Sales\Model\Order\Item
     */
    protected $orderItem;

    /**
     * @var \Magento\Config\Model\Config
     */
    protected $config;

    protected $result = [];

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Catalog\Model\ProductFactory $productFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Catalog\Model\ProductLink\Repository $productLinkRepository
     * @param \Magento\Catalog\Api\Data\ProductLinkInterfaceFactory $productLinkFactory
     * @param \Magento\Catalog\Model\ResourceModel\Product $productResource
     * @param \Magento\Catalog\Model\Product\Attribute\Source\Status $productStatus
     * @param \Magento\Catalog\Model\Product\Visibility $productVisibility
     * @param \Magento\Sales\Model\Order\Item $orderItem
     * @param \Magento\Config\Model\Config $config
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\ProductLink\Repository $productLinkRepository,
        \Magento\Catalog\Api\Data\ProductLinkInterfaceFactory $productLinkFactory,
        \Magento\Catalog\Model\ResourceModel\Product $productResource,
        \Magento\Catalog\Model\Product\Attribute\Source\Status $productStatus,
        \Magento\Catalog\Model\Product\Visibility $productVisibility,
        \Magento\Sales\Model\Order\Item $orderItem,
        \Magento\Config\Model\Config $config,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    )
    {
        $this->productFactory = $productFactory;
        $this->storeManager = $storeManager;
        $this->productLinkRepository = $productLinkRepository;
        $this->productLinkFactory = $productLinkFactory;
        $this->productResource = $productResource;
        $this->productStatus = $productStatus;
        $this->productVisibility = $productVisibility;
        $this->orderItem = $orderItem;
        $this->config = $config;
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('MageWorx\AlsoBought\Model\ResourceModel\Relation');
    }

    /**
     * Collect & save (in the separate table) new relations data
     *
     * @return $this
     * @throws \Exception
     */
    public function collectRelations()
    {
        $this->truncateCollection();
        $websites = $this->storeManager->getWebsites();
        /** @var \Magento\Store\Model\Website $website */
        foreach ($websites as $website) {

            $collection = $this->productFactory->create()
                ->getCollection()
                ->addWebsiteFilter($website->getId())
                ->addAttributeToFilter('status', ['in' => $this->productStatus->getVisibleStatusIds()])
                ->setVisibility($this->productVisibility->getVisibleInSiteIds());

            $productIds = $collection->getAllIds();

            foreach ($productIds as $productId) {
                $result = $this->collectNewDataForProduct($productId);
                if (empty($result)) {
                    continue;
                }
                $data = [
                    'base_product_id' => $productId,
                    'relations_serialized' => $result,
                    'website_id' => $website->getId()
                ];
                $this->setEntityId(null)->isObjectNew(true);
                $this->addData($data)->save();
            }
        }

        $this->config->setDataByPath('mageworx_alsobought/general/collect_time', time());
        $this->config->save();

        return $this;
    }

    /**
     * Truncate stored data
     *
     * @return $this
     */
    protected function truncateCollection()
    {
        /** @var ResourceModel\Relation\Collection $relationsCollection */
        $relationsCollection = $this->getCollection();
        $relationsCollection->truncate();

        return $this;
    }

    /**
     * Collect relation data by product id
     * Raw Query Example:
     *
     *   SELECT
     *     `main_table`.`product_id`,
     *     (SUM(`main_table`.`product_id`) / `main_table`.`product_id`) AS `frequency`,
     *     `sub_table`.`order_id`,
     *     `sub_table`.`product_id`
     *   FROM `sales_order_item` AS `main_table`
     *     LEFT JOIN `sales_order_item` AS `sub_table`
     *       ON `main_table`.`order_id` = `sub_table`.`order_id` AND `main_table`.`product_id` != `sub_table`.`product_id`
     *   WHERE (`main_table`.`product_id` = 1) AND (`sub_table`.`product_type` NOT IN ('configurable', 'bundle')) AND
     *   (`sub_table`.`parent_item_id` IS NULL)
     *   GROUP BY `sub_table`.`product_id`
     *   ORDER BY `frequency` DESC
     *
     * @param $productId
     * @return array
     */
    protected function collectNewDataForProduct($productId)
    {
        $restrictedProductTypes = [
            \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE,
            \Magento\Bundle\Model\Product\Type::TYPE_CODE
        ];

        $sum = new \Zend_Db_Expr('(SUM(`main_table`.`product_id`)/`main_table`.`product_id`)');
        $collection = $this->orderItem->getCollection()
            ->addFieldToSelect(['product_id', 'frequency' => $sum])
            ->removeFieldFromSelect('item_id');
        $select = $collection->getSelect();
        $connection = $collection->getConnection();
        $orderItemTable = $connection->getTableName('sales_order_item');

        $select->joinLeft(
            ['sub_table' => $orderItemTable],
            '`main_table`.`order_id` = `sub_table`.`order_id` AND `main_table`.`product_id` != `sub_table`.`product_id`',
            ['product_id']
        );
        $collection
            ->addFieldToFilter('main_table.product_id', ['eq' => $productId])
            ->addFieldToFilter('sub_table.product_type', ['nin' => $restrictedProductTypes])
            ->addFieldToFilter('sub_table.parent_item_id', ['null' => true])
            ->setOrder('frequency');
        $select->group('sub_table.product_id');

        $result = $connection->fetchAll($select);

        return $result;
    }

    /**
     * Apply related items for products (save new links)
     *
     * @return $this
     * @throws \Exception
     */
    public function applyRelations()
    {
        /** @var ResourceModel\Relation\Collection $relationsCollection */
        $relationsCollection = $this->getCollection();
        $websites = $this->storeManager->getWebsites();
        $this->result = [];

        foreach ($websites as $website) {
            $websiteId = $website->getId();
            $relationsCollection->addFieldToFilter('website_id', ['eq' => $websiteId]);
            $relationItems = $relationsCollection->load()->getItems();
            $this->processRelationItems($relationItems);
        }

        $this->config->setDataByPath('mageworx_alsobought/general/apply_time', time());
        $this->config->save();

        return $this;
    }

    /**
     * @param $relationItems
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function processRelationItems($relationItems)
    {
        /** @var \MageWorx\AlsoBought\Model\ResourceModel\Relation $item */
        foreach ($relationItems as $item) {

            $item->afterLoad($item);
            $productId = $item->getData('base_product_id');
            $productSku = $this->getProductSKU($productId);
            $relationsData = $item->getData('relations_serialized');
            $links = [];

            foreach ($relationsData as $relation) {
                $relatedProductSku = $this->getProductSKU($relation['product_id']);
                $frequency = $relation['frequency'];
                $position = $this->getLinkPosition($frequency);
                /** @var \Magento\Catalog\Api\Data\ProductLinkInterface $link */
                $link = $this->productLinkFactory->create();
                $link->setLinkType('related');
                $link->setLinkedProductSku($relatedProductSku);
                $link->setSku($productSku);
                $link->setPosition($position);
                $links[] = $link;
            }

            $this->applyRelationsToTheProduct($productSku, $productId, $links);
        }
    }

    /**
     * @param $productSku
     * @param $productId
     * @param array $links
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function applyRelationsToTheProduct($productSku, $productId, array $links)
    {
        /*
         * @see app/code/Magento/Catalog/Model/ProductRepository.php 401
         */
        try {
            /** @var \Magento\Catalog\Model\ProductLink\Link $link */
            foreach ($links as $link) {
                $this->productLinkRepository->save($link);
            }
        } catch (\Magento\Framework\Exception\CouldNotSaveException $e) {
            /*
             * @see app/code/Magento/Catalog/Model/ProductRepository.php 592
             * $this->processMediaGallery($product, $productDataArray['media_gallery_entries']);
             */
            $this->_logger->critical($e);
        }
    }

    /**
     * Get product SKU by it's id
     *
     * @param $productId
     * @return bool|int|string
     */
    protected function getProductSKU($productId)
    {
        $products = $this->productResource->getProductsSku([$productId]);
        if (empty($products)) {
            return false;
        }

        $productData = array_shift($products);
        $productSku = isset($productData['sku']) ? $productData['sku'] : false;

        return $productSku;
    }

    /**
     * Calculate current link position by converting its frequency.
     * Example:
     * frequency == 1000 -> position == 1
     * frequency == 500 -> position == 2
     * frequency == 1 -> position == 1000
     *
     * @param $frequency
     * @return int
     */
    protected function getLinkPosition($frequency)
    {
        $frequency = $frequency > 0 ?
            $frequency :
            self::MAX_LINK_POSITION;
        $position = intval(self::MAX_LINK_POSITION / $frequency);

        return $position;
    }
}