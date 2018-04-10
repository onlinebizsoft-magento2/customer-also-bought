<?php
/**
 * Copyright © 2016 MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\AlsoBought\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;

class Relation extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Serializable field: amounts
     *
     * @var array
     */
    protected $_serializableFields = [
        'relations_serialized' => [null, []]
    ];

    protected function _construct()
    {
        $this->_init('mageworx_alsobought_index','entity_id');
    }

    protected function _afterLoad(AbstractModel $object)
    {
        $this->unserializeFields($object);

        parent::_afterLoad($object);
        return $this;
    }
}