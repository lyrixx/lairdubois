<?php

namespace Tests\Double;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\RawMessage;

/**
 * Listener of the test environment (config_test.yml): keeps the emails as the transport sends
 * them, after the other listeners (DKIM signature) (Tests\Core\RabbitMqTest). Not reset after
 * each message of the worker (reset_on_message), unlike the message logger of Mailer.
 */
final class RecordingMessageListener
{
    /** @var list<RawMessage> */
    private array $messages = [];

    #[AsEventListener(priority: -1024)]
    public function onMessage(MessageEvent $event): void
    {
        $this->messages[] = $event->getMessage();
    }

    /**
     * The emails of the requests or commands since the last reboot of the kernel, the ones Mailer
     * puts in the queue included (MessageEvent::isQueued()).
     *
     * @return list<RawMessage>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
