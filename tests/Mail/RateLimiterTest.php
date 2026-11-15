<?php

namespace Tests\Mail;

use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Tests\WebTestCase;

/**
 * The worker of the queue "mailer" sends 100 emails every 2 minutes at most, the throughput of the spool of
 * Swiftmailer before (rate_limiter of its transport, limiter.mailer in config.yml). Its state is in Memcached: one
 * limit for all the workers of the queue, whatever their process or their server.
 */
final class RateLimiterTest extends WebTestCase
{
    public function testWorkerOfTheMailsWaitsAfter100In2Minutes(): void
    {
        static::bootKernel();
        $locator = static::getContainer()->get('messenger.rate_limiter_locator');
        // The one the worker reads (ConsumeMessagesCommand), by transport: only the mails are limited
        self::assertSame(['mailer'], array_keys($locator->getProvidedServices()));

        // As the worker: a hit per message, without key
        $limiter = self::getLimiter()->create();
        for ($i = 0; $i < 100; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted(), "Email {$i} is refused.");
        }
        $refused = $limiter->consume();
        self::assertFalse($refused->isAccepted());
        self::assertSame(100, $refused->getLimit());
        // The end of the window of the first email, rounded up to the second
        $wait = (float) $refused->getRetryAfter()->format('U.u') - microtime(true);
        self::assertGreaterThan(0, $wait);
        self::assertLessThanOrEqual(121, $wait);
    }

    public function testLimitIsSharedByTheWorkers(): void
    {
        static::bootKernel();
        self::assertTrue(self::getLimiter()->create()->consume(100)->isAccepted());

        // Another worker: another kernel, that sees the 100 emails of the first one
        static::ensureKernelShutdown();
        static::bootKernel();
        self::assertFalse(self::getLimiter()->create()->consume()->isAccepted());

        // In Memcached, that all the servers share: emptied, it starts a new window
        $memcached = new \Memcached();
        $memcached->addServer(static::getContainer()->getParameter('memcached_host'), static::getContainer()->getParameter('memcached_port'));
        $memcached->flush();
        self::assertTrue(self::getLimiter()->create()->consume()->isAccepted());
    }

    private static function getLimiter(): RateLimiterFactoryInterface
    {
        return static::getContainer()->get('messenger.rate_limiter_locator')->get('mailer');
    }
}
