<?php

namespace Tests\Double;

use Symfony\Component\Console\Event\ConsoleSignalEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Listener of the test environment (config_test.yml): once a test catches the signals, keeps the
 * command each signal is dispatched to, and keeps the process from exiting
 * (Tests\Command\CommandTest). Until then, a signal ends the process, as without it.
 */
final class RecordingSignalListener
{
    private bool $catching = false;

    /** @var list<string> */
    private array $signaledCommands = [];

    /**
     * Until the next reboot of the kernel.
     */
    public function catchSignals(): void
    {
        $this->catching = true;
    }

    #[AsEventListener]
    public function onConsoleSignal(ConsoleSignalEvent $event): void
    {
        if ($this->catching) {
            $this->signaledCommands[] = $event->getCommand()->getName();
            $event->abortExit();
        }
    }

    /**
     * @return list<string>
     */
    public function getSignaledCommands(): array
    {
        return $this->signaledCommands;
    }
}
