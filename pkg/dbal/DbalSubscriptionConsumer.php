<?php

declare(strict_types=1);

namespace Enqueue\Dbal;

use Doctrine\DBAL\Connection;
use Interop\Queue\Consumer;
use Interop\Queue\SubscriptionConsumer;
use Ramsey\Uuid\Uuid;

class DbalSubscriptionConsumer implements SubscriptionConsumer
{
    use DbalConsumerHelperTrait;

    /**
     * @var DbalContext
     */
    private $context;

    /**
     * an item contains an array: [DbalConsumer $consumer, callable $callback];.
     *
     * @var array
     */
    private $subscribers;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    private $dbal;

    /**
     * Default 20 minutes in milliseconds.
     *
     * @var int
     */
    private $redeliveryDelay;

    /**
     * @param DbalContext $context
     */
    public function __construct(DbalContext $context)
    {
        $this->context = $context;
        $this->dbal = $this->context->getDbalConnection();
        $this->subscribers = [];

        $this->redeliveryDelay = 1200000;
    }

    public function getContext(): DbalContext
    {
        return $this->context;
    }

    public function getConnection(): Connection
    {
        return $this->dbal;
    }

    /**
     * Get interval between retry failed messages in milliseconds.
     */
    public function getRedeliveryDelay(): int
    {
        return $this->redeliveryDelay;
    }

    public function setRedeliveryDelay(int $redeliveryDelay): void
    {
        $this->redeliveryDelay = $redeliveryDelay;
    }

    public function consume(int $timeout = 0): void
    {
        if (empty($this->subscribers)) {
            throw new \LogicException('No subscribers');
        }

        $now = time();
        $timeout /= 1000;
        $deliveryId = (string) Uuid::uuid1();

        $queueNames = [];
        foreach (array_keys($this->subscribers) as $queueName) {
            $queueNames[$queueName] = $queueName;
        }

        $currentQueueNames = [];
        while (true) {
            if (empty($currentQueueNames)) {
                $currentQueueNames = $queueNames;
            }

            $message = $this->fetchMessage($currentQueueNames);

            if ($message) {
                $this->markMessageAsDeliveredToConsumer($message, $deliveryId);

                $message = $this->getMessageByDeliveryId($deliveryId);

                $dbalMessage = $this->getContext()->convertMessage($message);

                /**
                 * @var DbalConsumer
                 * @var callable     $callback
                 */
                list($consumer, $callback) = $this->subscribers[$message['queue']];

                if (false === call_user_func($callback, $dbalMessage, $consumer)) {
                    return;
                }

                unset($currentQueueNames[$message['queue']]);
            } else {
                $currentQueueNames = [];

                usleep(200000); // 200ms
            }

            if ($timeout && microtime(true) >= $now + $timeout) {
                return;
            }
        }
    }

    /**
     * @param DbalConsumer $consumer
     */
    public function subscribe(Consumer $consumer, callable $callback): void
    {
        if (false == $consumer instanceof DbalConsumer) {
            throw new \InvalidArgumentException(sprintf('The consumer must be instance of "%s" got "%s"', DbalConsumer::class, get_class($consumer)));
        }

        $queueName = $consumer->getQueue()->getQueueName();
        if (array_key_exists($queueName, $this->subscribers)) {
            if ($this->subscribers[$queueName][0] === $consumer && $this->subscribers[$queueName][1] === $callback) {
                return;
            }

            throw new \InvalidArgumentException(sprintf('There is a consumer subscribed to queue: "%s"', $queueName));
        }

        $this->subscribers[$queueName] = [$consumer, $callback];
    }

    /**
     * @param DbalConsumer $consumer
     */
    public function unsubscribe(Consumer $consumer): void
    {
        if (false == $consumer instanceof DbalConsumer) {
            throw new \InvalidArgumentException(sprintf('The consumer must be instance of "%s" got "%s"', DbalConsumer::class, get_class($consumer)));
        }

        $queueName = $consumer->getQueue()->getQueueName();

        if (false == array_key_exists($queueName, $this->subscribers)) {
            return;
        }

        if ($this->subscribers[$queueName][0] !== $consumer) {
            return;
        }

        unset($this->subscribers[$queueName]);
    }

    public function unsubscribeAll(): void
    {
        $this->subscribers = [];
    }
}
