<?php
/**
 * O2TI Process Order via Rabbit Mq.
 *
 * Copyright © 2024 O2TI. All rights reserved.
 *
 * @author    Bruno Elisei <brunoelisei@o2ti.com>
 * @license   See LICENSE for license details.
 */

declare(strict_types=1);

namespace O2TI\ProcessOrderViaRabbitMQ\Model;

use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;

/**
 * Publish pagbank order synchronization queue.
 */
class Publish
{
    /**
     * Pagbank synchronization queue topic name.
     */
    private const TOPIC_PAGBANK_PROCESS_ORDER = 'pagbank.process.order';

    /**
     * @var PublisherInterface
     */
    private $publisher;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param PublisherInterface $publisher
     * @param LoggerInterface $logger
     */
    public function __construct(
        PublisherInterface $publisher,
        LoggerInterface $logger
    ) {
        $this->publisher = $publisher;
        $this->logger = $logger;
    }

    /**
     * Publish media content synchronization message to the message queue
     *
     * @param string $pagbankData
     */
    public function execute(string $pagbankData) : void
    {
        
        try {
            $this->publisher->publish(
                self::TOPIC_PAGBANK_PROCESS_ORDER,
                $pagbankData
            );
            $this->logger->info(__(
                'O2TI --- Process Order --- Publish --- Data: %1',
                $pagbankData
            ));
        } catch (\Exception $exc) {
            $this->logger->error(__(
                'O2TI --- Process Order --- Publish --- Error: %1',
                $exc->getMessage()
            ));
        }
    }
}
