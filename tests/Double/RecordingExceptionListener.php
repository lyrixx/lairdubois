<?php

namespace Tests\Double;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Listener of the test environment (config_test.yml): keeps the first throwable that reached the
 * kernel (Tests\Smoke\RouteSmokeTest), before the listeners that may replace it.
 */
final class RecordingExceptionListener
{
    private ?\Throwable $throwable = null;

    #[AsEventListener(priority: 2048)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $this->throwable ??= $event->getThrowable();
    }

    /**
     * The first throwable of the requests since the last reboot of the kernel, subrequests
     * included.
     */
    public function getThrowable(): ?\Throwable
    {
        return $this->throwable;
    }
}
