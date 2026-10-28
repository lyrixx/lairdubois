<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Like;
use Ladb\CoreBundle\Entity\Wonder\Creation;

/**
 * RabbitMQ does not answer when a page sends a message: the page is rendered all the same, and
 * the failure is logged. The transports of Messenger connect to a closed port.
 */
final class UnreachableRabbitMqTest extends InteractionTestCase
{
    // The host and the port of the options of the transports (config.yml) win over the ones of the DSN
    private const DSN = 'amqp://localhost?host=127.0.0.1&port=1';

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['LADB_TEST_MESSENGER_TRANSPORT_DSN'] = self::DSN;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LADB_TEST_MESSENGER_TRANSPORT_DSN']);
        parent::tearDown();
    }

    public function testShownView(): void
    {
        $client = static::createClient();
        $logOffset = self::getLogSize();
        $client->request('GET', '/creations/1.html');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertLogged('app.ERROR: Failed to publish shown view process in queue', $logOffset);
    }

    public function testWebpushNotification(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $logOffset = self::getLogSize();
        static::xhr($client, 'GET', static::generatePath('core_like_create', ['entityType' => Creation::TYPE, 'entityId' => self::CREATION_ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotNull(static::getEntityManager()->getRepository(Like::class)->findOneBy(['entityType' => Creation::TYPE, 'entityId' => self::CREATION_ID, 'user' => static::findUser(UserFixtures::ADMIN)]));
        self::assertLogged('app.ERROR: Failed to publish the webpush notification in queue.', $logOffset);
    }

    /**
     * In the log file: the logger collector of the profiler only has the deprecations of the container.
     */
    private static function assertLogged(string $message, int $logOffset): void
    {
        $log = (string) file_get_contents(self::getLogFile(), false, null, $logOffset);
        self::assertStringContainsString($message.' {"exception":"[object] (Symfony\\\\Component\\\\Messenger\\\\Exception\\\\TransportException(code: 0): Could not connect to the AMQP server.', $log);
    }

    private static function getLogSize(): int
    {
        clearstatcache();

        return file_exists(self::getLogFile()) ? filesize(self::getLogFile()) : 0;
    }

    private static function getLogFile(): string
    {
        return static::$kernel->getLogDir().'/test.log';
    }
}
