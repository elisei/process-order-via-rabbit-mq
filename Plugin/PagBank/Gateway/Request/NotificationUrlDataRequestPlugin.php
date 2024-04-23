<?php
/**
 * O2TI Process Order via Rabbit Mq.
 *
 * Copyright © 2024 O2TI. All rights reserved.
 *
 * @author    Bruno Elisei <brunoelisei@o2ti.com>
 * @license   See LICENSE for license details.
 */

namespace O2TI\ProcessOrderViaRabbitMQ\Plugin\PagBank\Gateway\Request;

use PagBank\PaymentMagento\Gateway\Request\NotificationUrlDataRequest;
use Magento\Framework\UrlInterface;

class NotificationUrlDataRequestPlugin
{
    /**
     * @var UrlInterface
     */
    protected $frontendUrlBuilder;

    /**
     * @param UrlInterface $frontendUrlBuilder
     */
    public function __construct(
        UrlInterface $frontendUrlBuilder
    ) {
        $this->frontendUrlBuilder = $frontendUrlBuilder;
    }

    /**
     * Plugin for modifying notification URL data request.
     *
     * @param NotificationUrlDataRequest $subject
     * @param \Closure                   $proceed
     * @param array                      $buildSubject
     * @return array
     */
    public function aroundBuild(
        NotificationUrlDataRequest $subject,
        \Closure $proceed,
        array $buildSubject
    ) {
        $result = $proceed($buildSubject);

        $notificationUrl = $this->frontendUrlBuilder->getUrl('o2ti/notification/order');

        $result[NotificationUrlDataRequest::NOTIFICATION_URLS][0] = $notificationUrl;

        return $result;
    }
}
