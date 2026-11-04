<?php

namespace Tests\Double;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * Listener of the test environment (config_test.yml): throws the error a test gives it before the
 * controller of the main request (Tests\Error\PhpErrorTest), as an error of the site would. It
 * does nothing until then.
 */
final class FailingControllerListener
{
    private ?\Throwable $error = null;

    /**
     * Throws the error before the controller of the next main requests, until the next reboot
     * of the kernel.
     */
    public function failWith(\Throwable $error): void
    {
        $this->error = $error;
    }

    #[AsEventListener]
    public function onKernelController(ControllerEvent $event): void
    {
        // Not in the subrequest of the error controller
        if (null !== $this->error && $event->isMainRequest()) {
            throw $this->error;
        }
    }
}
