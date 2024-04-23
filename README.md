# O2TI_ProcessOrderViaRabbitMQ module

O Magento_ProcessOrderViaRabbitMQ module é uma implementação para processamento dos pedidos da PagBank serem realizados de forma assíncrona.

## Informação adicional

### Criação de preferência

Altera o fluxo de processamento do cron do módulo PagBank para escalar as transações na fila.

### Criação de plugin

Altera a url de notificação enviada ao PagBank para novo endpoint a fim de escalar o processamento de transações na fila.

#### Message Queue Consumer

- `pagbank.process.order` - roda o processamento dos pedidos

[Learn how to manage Message Queues](https://devdocs.magento.com/guides/v2.4/config-guide/mq/manage-message-queues.html).
