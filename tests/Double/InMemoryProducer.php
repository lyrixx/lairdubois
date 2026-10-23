<?php

namespace Tests\Double;

use OldSound\RabbitMqBundle\RabbitMq\ProducerInterface;

/**
 * RabbitMQ producer of the test environment (config_test.yml): keeps the messages instead of
 * sending them.
 */
final class InMemoryProducer implements ProducerInterface
{
    /** @var list<array{body: string, routingKey: string, properties: array<string, mixed>}> */
    private array $messages = [];

    public function publish($msgBody, $routingKey = '', $additionalProperties = []): void
    {
        $this->messages[] = ['body' => $msgBody, 'routingKey' => $routingKey, 'properties' => $additionalProperties];
    }

    /**
     * @return list<array{body: string, routingKey: string, properties: array<string, mixed>}>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Called by OldSoundRabbitMqBundle::shutdown() on every producer the kernel instantiated.
     */
    public function close(): void
    {
    }
}
