<?php
/**
 * O2TI Process Order via Rabbit Mq.
 *
 * Copyright © 2024 O2TI. All rights reserved.
 *
 * @author    Bruno Elisei <brunoelisei@o2ti.com>
 * @license   See LICENSE for license details.
 */

namespace O2TI\ProcessOrderViaRabbitMQ\Model;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Notification\NotifierInterface as NotifierPool;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface as Logger;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use O2TI\ProcessOrderViaRabbitMQ\Model\Publish;

/**
 * Abstract Process Order PagBank.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class AbstractProcessOrder
{
    /**
     * Time due for Pix.
     */
    public const TIME_DUE_PIX = 5;

    /**
     * Time due for Deep Link.
     */
    public const TIME_DUE_DEEP_LINK = 5;

    /**
     * Time due for Boleto.
     */
    public const TIME_DUE_BOLETO = 2880;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteria;

    /**
     * @var TransactionRepositoryInterface
     */
    protected $transaction;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var NotifierPool
     */
    protected $notifierPool;

    /**
     * @var CreditmemoFactory
     */
    protected $creditMemoFactory;

    /**
     * @var CreditmemoService
     */
    protected $creditMemoService;

    /**
     * @var Invoice
     */
    protected $invoice;

    /**
     * @var Publish
     */
    protected $publish;

    /**
     * @var DateTime
     */
    protected $date;

    /**
     * @var TimezoneInterface
     */
    protected $localeDate;

    /**
     * @param Json                           $json
     * @param SearchCriteriaBuilder          $searchCriteria
     * @param TransactionRepositoryInterface $transaction
     * @param OrderRepository                $orderRepository
     * @param Logger                         $logger
     * @param NotifierPool                   $notifierPool
     * @param CreditmemoFactory              $creditMemoFactory
     * @param CreditmemoService              $creditMemoService
     * @param Invoice                        $invoice
     * @param Publish                        $publish
     * @param DateTime                       $date
     * @param TimezoneInterface              $localeDate
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Json $json,
        SearchCriteriaBuilder $searchCriteria,
        TransactionRepositoryInterface $transaction,
        OrderRepository $orderRepository,
        Logger $logger,
        NotifierPool $notifierPool,
        CreditmemoFactory $creditMemoFactory,
        CreditmemoService $creditMemoService,
        Invoice $invoice,
        Publish $publish,
        DateTime $date,
        TimezoneInterface $localeDate
    ) {
        $this->json = $json;
        $this->searchCriteria = $searchCriteria;
        $this->transaction = $transaction;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
        $this->notifierPool = $notifierPool;
        $this->creditMemoFactory = $creditMemoFactory;
        $this->creditMemoService = $creditMemoService;
        $this->invoice = $invoice;
        $this->publish = $publish;
        $this->date = $date;
        $this->localeDate = $localeDate;
    }

    /**
     * Init Process Order.
     *
     * @param array $data
     *
     * @return OrderRepository|null
     */
    public function initProcessOrder($data)
    {
        $transaction = $this->findTransaction($data);

        if ($transaction) {
            $order = $this->loadOrder($transaction);
            $this->logger->info(__('O2TI --- Process Order --- Order #%1', $order->getIncrementId()));
            if ($this->isInvalidNotification($order)) {
                return false;
            }

            return $order;
        }

        return false;
    }

    /**
     * Process Order.
     *
     * @param string $pagbankData
     *
     * @return void
     */
    public function processOrder($pagbankData)
    {
        $data = $this->json->unserialize($pagbankData);

        $order = $this->initProcessOrder($data);

        if ($order) {
            $payment = $order->getPayment();

            try {
                $payment->update(true);
                $order->save();

                // Cancela os pagamentos fora do prazo
                $this->expireOrder($order);

            } catch (\Exception $exc) {
                $this->logger->error(__(
                    'O2TI --- Process Order --- Error in process order: %1, error: ',
                    $order->getIncrementId(),
                    $exc->getMessage()
                ));
            }
        }
    }

    /**
     * Expire Order.
     *
     * @param OrderRepository $order
     *
     * @return void
     */
    public function expireOrder($order)
    {
        if (!$this->isInvalidNotification($order)) {
            $payment = $order->getPayment();

            $hasExpire = $this->hasExpired($payment);

            if ($hasExpire) {
                $order->isPaymentReview(0);
                $this->setExpiredPayment($payment);
                $order->cancel(true);
                $comment = __('Order cancelled, payment deadline has expired.');
                $order->addStatusToHistory($order->getStatus(), $comment, true);
                $order->save();
                $this->logger->info(__(
                    'O2TI --- Process Order --- Cancelled deadline has expired #%1',
                    $order->getIncrementId()
                ));
            }
        }
    }

    /**
     * Find Transaction.
     *
     * @param array $data
     *
     * @return TransactionRepositoryInterface|null
     */
    public function findTransaction($data)
    {
        $paymentId = $data['pagbankOrderId'];
        $result = [];
        $searchCriteria = $this->searchCriteria->addFilter('txn_id', $paymentId)
            ->addFilter('txn_type', 'order')
            ->create();
        
            /** @var TransactionRepositoryInterface $transactionCollection */
        $transactionCollection = $this->transaction->getList($searchCriteria);

        if ($transactionCollection->getSize() > 0) {
            $transaction = $transactionCollection->getFirstItem();
            return $transaction;
        }

        if (!isset($data['count'])) {
            $data['count'] = 0;
        }

        $data = [
            'source'         => 'retry',
            'pagbankOrderId' => $paymentId,
            'count'          => (int) $data['count'] + 1
        ];

        // Envia para uma nova tentativa de processamento.
        $this->publish->execute($this->json->serialize($data));

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
                'O2TI --- Process Order --- Error load order entity_id: %1, Error: ',
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

    /**
     * Has Expired - see if payment time has expired.
     *
     * @param InfoInterface $payment
     *
     * @return int
     */
    public function hasExpired($payment)
    {
        $method = $payment->getMethod();

        $due = '-10080';

        $initExpireIn = strtotime((string) $payment->getAdditionalInformation('expiration_date'));

        if ($method === 'pagbank_paymentmagento_pix') {
            $due = self::TIME_DUE_PIX * -1;
        }

        if ($method === 'pagbank_paymentmagento_boleto') {
            $due = self::TIME_DUE_BOLETO * -1;
        }

        if ($method === 'pagbank_paymentmagento_deep_link') {
            $due = self::TIME_DUE_DEEP_LINK * -1;
        }

        $initDateNow = $this->date->gmtDate('Y-m-d\TH:i:s.uP', strtotime("{$due} minutes"));

        $dateNow = $this->localeDate->date($initDateNow)->format('Y-m-d H:i:s');

        $expireIn = $this->localeDate->date($initExpireIn)->format('Y-m-d H:i:s');

        return ($dateNow > $expireIn) ? 1 : 0;
    }

    /**
     * Set Expired Payment - cancel order if expired.
     *
     * @param InfoInterface $payment
     *
     * @return void
     */
    public function setExpiredPayment($payment)
    {
        $payment->deny(false);
        $payment->registerVoidNotification();
    }
}
