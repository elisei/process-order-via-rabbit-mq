<?php
/**
 * O2TI Process Order via Rabbit Mq.
 *
 * Copyright © 2024 O2TI. All rights reserved.
 *
 * @author    Bruno Elisei <brunoelisei@o2ti.com>
 * @license   See LICENSE for license details.
 */

namespace O2TI\ProcessOrderViaRabbitMQ\Plugin\PagBank\Cron;

use Magento\Framework\Notification\NotifierInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\Order\Payment\Transaction;
use PagBank\PaymentMagento\Gateway\Config\Config;
use PagBank\PaymentMagento\Model\Console\Command\Orders\Update;
use O2TI\ProcessOrderViaRabbitMQ\Model\Publish;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\TransactionRepositoryInterface;

/**
 * Class Modified Get Status Update in PagBank.
 */
class ModifiedGetStatusUpdate extends \PagBank\PaymentMagento\Cron\GetStatusUpdate
{
    /**
     * @var Publish
     */
    protected $publish;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var TransactionRepositoryInterface
     */
    protected $transactionRepository;

    /**
     * Constructor.
     *
     * @param Logger                         $logger
     * @param Config                         $config
     * @param NotifierInterface              $notifierInterface
     * @param Update                         $update
     * @param CollectionFactory              $collectionFactory
     * @param Publish                        $publish
     * @param Json                           $json
     * @param TransactionRepositoryInterface $transactionRepository
     */
    public function __construct(
        Logger $logger,
        Config $config,
        NotifierInterface $notifierInterface,
        Update $update,
        CollectionFactory $collectionFactory,
        Publish $publish,
        Json $json,
        \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository,
    ) {
        parent::__construct($logger, $config, $notifierInterface, $update, $collectionFactory);
        $this->publish = $publish;
        $this->json = $json;
        $this->transactionRepository = $transactionRepository;
    }

    /**
     * Find Pix.
     *
     * @return void
     */
    public function findPix()
    {
        $orders = $this->getFilterdOrders(self::PAYMENT_METHOD_PIX);

        if ($orders) {
            foreach ($orders as $order) {
                $this->processOrderUpdate($order);
            }
        }
    }

    /**
     * Find Deep Link.
     *
     * @return void
     */
    public function findDeepLink()
    {
        $orders = $this->getFilterdOrders(self::PAYMENT_METHOD_DEEP_LINK);

        if ($orders) {
            foreach ($orders as $order) {
                $this->processOrderUpdate($order);
            }
        }
    }

    /**
     * Find Boleto.
     *
     * @return void
     */
    public function findBoleto()
    {
        $orders = $this->getFilterdOrders(self::PAYMENT_METHOD_BOLETO);

        if ($orders) {
            foreach ($orders as $order) {
                $this->processOrderUpdate($order);
            }
        }
    }

    /**
     * Find Credit Card.
     *
     * @return void
     */
    public function findCc()
    {
        $orders = $this->getFilterdOrders(self::PAYMENT_METHOD_CC);

        if ($orders) {
            foreach ($orders as $order) {
                $this->processOrderUpdate($order);
            }
        }
    }

    /**
     * Find Vault.
     *
     * @return void
     */
    public function findVault()
    {
        $orders = $this->getFilterdOrders(self::PAYMENT_METHOD_VAULT);

        if ($orders) {
            foreach ($orders as $order) {
                $this->processOrderUpdate($order);
            }
        }
    }

    /**
     * Process order update.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    protected function processOrderUpdate($order)
    {
        $payment = $order->getPayment();
        $transactionId = null;

        if ($payment && $payment->getId()) {

            $orderTransaction = $this->transactionRepository->getByTransactionType(
                Transaction::TYPE_ORDER,
                $payment->getId(),
                $payment->getOrder()->getId()
            );

            $transactionId = $orderTransaction->getTxnId();
        }

        $data = [
            'source'         => 'cron',
            'pagbankOrderId' => $transactionId,
        ];

        $this->publish->execute($this->json->serialize($data));
    }
}
