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
use O2TI\ProcessOrderViaRabbitMQ\Model\AbstractProcessOrder;

/**
 * Pagbank order synchronization queue consumer.
 */
class Consume extends AbstractProcessOrder
{
    /**
     * Process Order synchronization.
     *
     * @param string $pagbankData
     * @throws LocalizedException
     */
    public function execute(string $pagbankData) : void
    {
        try {

            $data = $this->json->unserialize($pagbankData);
            $processMessage = true;
            
            if ($data['source'] === 'retry') {
                if (isset($data['count']) && $data['count'] > 5) {
                    $processMessage = false;
                }
            }

            if ($processMessage) {
                $this->processOrder($pagbankData);
            }

        } catch (LocalizedException $exc) {
            $this->logger->error(__(
                'O2TI --- Process Order --- Consume --- Error: %1',
                $exc->getMessage()
            ));
        }
    }
}
