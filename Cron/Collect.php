<?php
/**
 * Copyright © 2016 MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\AlsoBought\Cron;

use Magento\Store\Model\StoresConfig;
use MageWorx\AlsoBought\Model\Relation;
use Psr\Log\LoggerInterface;
use Magento\Framework\Locale\ResolverInterface;

class Collect
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var StoresConfig
     */
    protected $storesConfig;

    /**
     * @var Relation
     */
    protected $relation;

    /**
     * @var ResolverInterface
     */
    protected $localeResolver;

    /**
     * @param LoggerInterface $logger
     * @param StoresConfig $storesConfig
     * @param Relation $relation
     * @param ResolverInterface $localeResolver
     */
    public function __construct(
        LoggerInterface $logger,
        StoresConfig $storesConfig,
        Relation $relation,
        ResolverInterface $localeResolver
    )
    {
        $this->logger = $logger;
        $this->storesConfig = $storesConfig;
        $this->relation = $relation;
        $this->localeResolver = $localeResolver;
    }

    public function execute()
    {
        if (!$this->storesConfig->getStoresConfigByPath('mageworx_alsobought/general/cron_active')) {
            $this->logger->info('Collecting: cron inactive');
            return $this;
        }

        $this->localeResolver->emulate(0);
        $this->logger->info('Collecting started...');
        $this->relation->collectRelations();
        $this->logger->info('Collecting was successful. End.');
        $this->localeResolver->revert();

        return $this;
    }
}
