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

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
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
     * @var SearchCriteriaBuilder
     */
    private SearchCriteriaBuilder $searchCriteria;

    /**
     * @var TransactionRepositoryInterface
     */
    private TransactionRepositoryInterface $transaction;

    /**
     * @var json
     */
    private Json $json;

    /**
     * @var orderRepository
     */
    private OrderRepository $orderRepository;

    /**
     * @param PublisherInterface $publisher
     * @param LoggerInterface $logger
     * @param SearchCriteriaBuilder $searchCriteria
     * @param TransactionRepositoryInterface $transaction
     * @param OrderRepository $orderRepository
     * @param Json $json
     */
    public function __construct(
        PublisherInterface $publisher,
        LoggerInterface $logger,
        SearchCriteriaBuilder $searchCriteria,
        TransactionRepositoryInterface $transaction,
        OrderRepository $orderRepository,
        Json $json
    ) {
        $this->publisher = $publisher;
        $this->logger = $logger;
        $this->searchCriteria = $searchCriteria;
        $this->transaction = $transaction;
        $this->orderRepository = $orderRepository;
        $this->json = $json;
    }

    /**
     * Publish media content synchronization message to the message queue
     *
     * @param string $pagbankData
     */
    public function execute(string $pagbankData) : void
    {
        $data = $this->json->unserialize($pagbankData);
        $transaction = $this->findTransaction($data);
        if ($transaction) {
            $order = $this->loadOrder($transaction);
            if (!$this->isInvalidNotification($order)) {
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
    }

    /**
     * @param $data
     * @return null
     */
    public function findTransaction($data)
    {
        $paymentId = $data['pagbankOrderId'];
        $searchCriteria = $this->searchCriteria->addFilter('txn_id', $paymentId)
            ->addFilter('txn_type', 'order')
            ->create();

        /** @var TransactionRepositoryInterface $transactionCollection */
        $transactionCollection = $this->transaction->getList($searchCriteria);

        if ($transactionCollection->getSize() > 0) {
            return $transactionCollection->getFirstItem();
        }

        return null;
    }

    /**
     * Find Order.
     *
     * @param TransactionRepositoryInterface $transaction
     *
     * @return OrderRepository|null
     */
    public function loadOrder($transaction)
    {
        $orderId = $transaction->getOrderId();

        try {
            /** @var OrderRepository $order */
            $order = $this->orderRepository->get($orderId);

            return $order;
        } catch (LocalizedException $exc) {
            $this->logger->error(__(
                'O2TI --- Process Order --- Error load order on publish entity_id: %1, Error: ',
                $orderId,
                $exc->getMessage()
            ));
            return null;
        }
    }

    /**
     * Is Invalid Notification.
     *
     * @param OrderRepository $order
     *
     * @return bool
     */
    public function isInvalidNotification($order)
    {
        $state = $order->getState();

        if ($state !== Order::STATE_NEW && $state !== Order::STATE_PAYMENT_REVIEW) {
            return true;
        }

        return false;
    }
}
