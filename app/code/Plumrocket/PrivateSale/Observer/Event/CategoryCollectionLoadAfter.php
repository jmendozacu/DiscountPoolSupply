<?php
/**
 * Plumrocket Inc.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End-user License Agreement
 * that is available through the world-wide-web at this URL:
 * http://wiki.plumrocket.net/wiki/EULA
 * If you are unable to obtain it through the world-wide-web, please
 * send an email to support@plumrocket.com so we can send you a copy immediately.
 *
 * @package     Plumrocket Private Sales and Flash Sales v4.x.x
 * @copyright   Copyright (c) 2016 Plumrocket Inc. (http://www.plumrocket.com)
 * @license     http://wiki.plumrocket.net/wiki/EULA  End-user License Agreement
 */

namespace Plumrocket\PrivateSale\Observer\Event;

class CategoryCollectionLoadAfter extends EventObserver
{
    /**
     * {@inheritdoc}
     */
	public function execute(\Magento\Framework\Event\Observer $observer)
	{
		if ($this->_allowProcess()) {
            $collection = $observer->getEvent()->getCategoryCollection();
            if (!$collection->hasFlag('dont_remove_not_active')) {
                foreach ($collection as $item) {
                    if ($item->getPrivatesaleDateStart()) {
                        if (!$this->event->checkCategory($item, true)) {
                            $collection->removeItemByKey($item->getId());
                        }
                    }
                }
            }
        }
	}
}
