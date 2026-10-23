<?php

namespace Tests\Double;

use Gos\Bundle\WebSocketBundle\Pusher\PusherInterface;

/**
 * Websocket pusher of the test environment (config_test.yml): keeps the messages instead of
 * sending them to the websocket server.
 */
final class InMemoryPusher implements PusherInterface
{
    /** @var list<array{data: string|array<mixed>, routeName: string, routeParameters: array<mixed>}> */
    private array $messages = [];

    public function push($data, $routeName, array $routeParameters = [], array $context = []): void
    {
        $this->messages[] = ['data' => $data, 'routeName' => $routeName, 'routeParameters' => $routeParameters];
    }

    /**
     * @return list<array{data: string|array<mixed>, routeName: string, routeParameters: array<mixed>}>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getConfig(): array
    {
        return [];
    }

    public function setConfig($config): void
    {
    }

    public function close(): void
    {
    }

    public function getName(): string
    {
        return 'wamp';
    }
}
