<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Core\View;
use Ladb\CoreBundle\Entity\Webpush\UserSubscription;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The messages of the pages go through RabbitMQ, and the worker of the stack consumes them
 * (messenger:consume), as in prod: the transports of Messenger on the RabbitMQ of the stack, in
 * exchanges and queues of the tests (config_test.yml), and the worker in the transaction of the
 * test. With the amqp extension 2, the transport of Symfony 4.4 failed on each acknowledgement and
 * stopped the worker (#168).
 */
final class RabbitMqTest extends InteractionTestCase
{
    private const VIEW_QUEUE = 'test_view';
    private const WEBPUSH_NOTIFICATION_QUEUE = 'test_webpush_notification';
    private const ENDPOINT = 'https://push.example.test/send/expired';

    /**
     * @var array{host: string, port: int, vhost: string, login: string, password: string}
     */
    private static array $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['LADB_TEST_MESSENGER_TRANSPORT_DSN'] = 'amqp://';

        $container = static::bootKernel()->getContainer();
        self::$credentials = [
            'host' => $container->getParameter('rabbitmq_host'),
            'port' => (int) $container->getParameter('rabbitmq_port'),
            'vhost' => $container->getParameter('rabbitmq_vhost'),
            'login' => $container->getParameter('rabbitmq_user'),
            'password' => $container->getParameter('rabbitmq_password'),
        ];
        // The exchanges and the queues, empty: a failed run leaves its message
        foreach (['view' => self::VIEW_QUEUE, 'webpush_notification' => self::WEBPUSH_NOTIFICATION_QUEUE] as $transportName => $queueName) {
            $container->get("tests.{$transportName}_transport")->setup();
            self::onQueue($queueName, static fn (\AMQPQueue $queue) => $queue->purge());
        }
        static::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LADB_TEST_MESSENGER_TRANSPORT_DSN']);
        parent::tearDown();
    }

    public function testShownView(): void
    {
        $viewCount = static::findCreation()->getViewCount();
        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', '/creations/1.html');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(1, self::countMessages(self::VIEW_QUEUE));

        self::consume('view');

        self::assertSame(0, self::countMessages(self::VIEW_QUEUE));
        self::assertSame($viewCount + 1, static::findCreation()->getViewCount());
        self::assertNotNull(static::getEntityManager()->getRepository(View::class)->findOneBy([
            'entityType' => Creation::TYPE,
            'entityId' => self::CREATION_ID,
            'user' => static::findUser(UserFixtures::USER2),
            'kind' => View::KIND_SHOWN,
        ]));
    }

    /**
     * A like notifies the author of the creation. Its push service answers that the subscription
     * has expired: the handler deletes it.
     */
    public function testWebpushNotification(): void
    {
        $subscriptionId = self::subscribe(static::findUser(UserFixtures::USER));
        $client = static::createClientAs(UserFixtures::ADMIN);
        static::xhr($client, 'GET', static::generatePath('core_like_create', ['entityType' => Creation::TYPE, 'entityId' => self::CREATION_ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(1, self::countMessages(self::WEBPUSH_NOTIFICATION_QUEUE));

        $pushHandler = static::bootKernel()->getContainer()->get('tests.webpush_push_handler');
        $pushHandler->expire(self::ENDPOINT);
        self::consume('webpush_notification');

        self::assertCount(1, $pushHandler->getRequests());
        self::assertSame(0, self::countMessages(self::WEBPUSH_NOTIFICATION_QUEUE));
        self::assertNull(static::getEntityManager()->getRepository(UserSubscription::class)->find($subscriptionId));
    }

    /**
     * One message of the transport, in the kernel booted last (a new one if none is).
     */
    private static function consume(string $transportName): void
    {
        $application = new Application(static::$booted ? static::$kernel : static::bootKernel());
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        // As static::runCommand()
        $application->setSignalsToDispatchEvent();
        $exitCode = $application->run(new StringInput("messenger:consume {$transportName} --limit=1 --time-limit=10"), $output = new BufferedOutput());

        self::assertSame(0, $exitCode, $output->fetch());
    }

    /**
     * The messages ready in the queue. The kernel is shut down first: its connection to RabbitMQ is
     * closed, and a message received without an acknowledgement goes back to its queue.
     */
    private static function countMessages(string $queueName): int
    {
        static::ensureKernelShutdown();
        gc_collect_cycles();

        return self::onQueue($queueName, static fn (\AMQPQueue $queue): int => $queue->declareQueue());
    }

    /**
     * @template T
     *
     * @param callable(\AMQPQueue): T $callback
     *
     * @return T
     */
    private static function onQueue(string $queueName, callable $callback)
    {
        $connection = new \AMQPConnection(self::$credentials);
        $connection->connect();
        try {
            $queue = new \AMQPQueue(new \AMQPChannel($connection));
            $queue->setName($queueName);
            $queue->setFlags(\AMQP_PASSIVE);

            return $callback($queue);
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * A subscription of a browser (Tests\Webpush\NotificationTest), whose keys encrypt the payload.
     */
    private static function subscribe(User $user): int
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        $details = openssl_pkey_get_details($key)['ec'];
        $point = "\x04".str_pad($details['x'], 32, "\0", \STR_PAD_LEFT).str_pad($details['y'], 32, "\0", \STR_PAD_LEFT);
        $subscription = new UserSubscription($user, md5(self::ENDPOINT), [
            'endpoint' => self::ENDPOINT,
            'expirationTime' => null,
            'keys' => [
                'p256dh' => rtrim(strtr(base64_encode($point), '+/', '-_'), '='),
                'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            ],
        ]);
        $entityManager = static::getEntityManager();
        $entityManager->persist($subscription);
        $entityManager->flush();

        return $subscription->getId();
    }
}
