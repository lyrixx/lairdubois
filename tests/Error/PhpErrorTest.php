<?php

namespace Tests\Error;

use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Tests\WebTestCase;

/**
 * A PHP \Error (call on null, TypeError) gives the error page, like an exception
 * (framework.handle_all_throwables): it escaped the kernel before #205.
 */
final class PhpErrorTest extends WebTestCase
{
    public function testPhpErrorGivesTheErrorPage(): void
    {
        $client = static::createClient();
        $client->getContainer()->get('event_dispatcher')->addListener(KernelEvents::CONTROLLER, static function (ControllerEvent $event): void {
            // Not in the subrequest of the error controller
            if ($event->isMainRequest()) {
                throw new \TypeError('A PHP error of the test.');
            }
        });

        $client->request('GET', '/');

        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('A PHP error of the test.', $client->getResponse()->getContent());
    }
}
