<?php
/**
 * Copyright © 2016 MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\AlsoBought\Helper;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{

    /**
     * Admin config settings
     */
    const XML_COLLECT_TIME = 'mageworx_alsobought/general/collect_time';
    const XML_APPLY_TIME = 'mageworx_alsobought/general/apply_time';

    /**
     * Return last applied changes time
     *
     * @return bool|string
     */
    public function getLastApplyTime()
    {
        $time = $this->scopeConfig->getValue(self::XML_APPLY_TIME);
        $timeFormatted = $this->formatTime($time);

        return $timeFormatted;
    }

    /**
     * Return last collected changes time
     *
     * @return bool|string
     */
    public function getLastCollectTime()
    {
        $time = $this->scopeConfig->getValue(self::XML_COLLECT_TIME);
        $timeFormatted = $this->formatTime($time);

        return $timeFormatted;
    }

    /**
     * Formats time and returns time-string or n/a (for null or eq.)
     *
     * @param null $time
     * @return bool|string
     */
    public function formatTime($time = null)
    {
        if (!$time) {
            $timeFormatted = 'n/a';
        } else {
            $timeFormatted = date('d-m-Y H:i:s', $time);
        }

        return $timeFormatted;
    }
}
