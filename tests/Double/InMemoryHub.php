<?php

namespace Tests\Double;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * Mercure hub of the test environment (config_test.yml): keeps the updates instead of publishing
 * them to the hub. The URLs and the JWT (cookie of the subscribers) are those of the decorated hub.
 */
final class InMemoryHub implements HubInterface
{
    private HubInterface $hub;

    /** @var list<Update> */
    private array $updates = [];

    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    public function publish(Update $update): string
    {
        $this->updates[] = $update;

        // The id that the hub gives to the update
        return 'urn:uuid:in-memory-' . \count($this->updates);
    }

    /**
     * @return list<Update>
     */
    public function getUpdates(): array
    {
        return $this->updates;
    }

    public function getUrl(): string
    {
        return $this->hub->getUrl();
    }

    public function getPublicUrl(): string
    {
        return $this->hub->getPublicUrl();
    }

    public function getProvider(): TokenProviderInterface
    {
        return $this->hub->getProvider();
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->hub->getFactory();
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->hub->getProtocolVersion();
    }

    public function getCookieName(): string
    {
        return $this->hub->getCookieName();
    }
}
