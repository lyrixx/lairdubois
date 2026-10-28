<?php

namespace Tests\Webpush;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Webpush\UserSubscription;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tests\WebTestCase;

/**
 * The subscriptions to the web push notifications, as ladb-webpush-client.js sends them: the
 * PushSubscription of the browser as is, in JSON, POST to subscribe and DELETE to unsubscribe.
 */
final class SubscriptionTest extends WebTestCase
{
    private const SUBSCRIPTION = [
        'endpoint' => 'https://push.example.test/send/subscription',
        'expirationTime' => null,
        'keys' => [
            'p256dh' => 'BHRokM_VvfQj55aPbnoh2J8uvpHQs4SNdajH5XUTqOjf_rsMfUHHy5iAQOpnkcOJaUGdqc8EhgNvnsu3I2DNXcM',
            'auth' => 'PbPBY0VewTVZ-S6wnkqrOA',
        ],
    ];

    public function testSubscribes(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        self::send($client, 'POST', json_encode(self::SUBSCRIPTION));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $subscriptions = self::findSubscriptions();
        self::assertCount(1, $subscriptions);
        self::assertSame(UserFixtures::USER, $subscriptions[0]->getUser()->getUsername());
        self::assertSame(self::SUBSCRIPTION['endpoint'], $subscriptions[0]->getEndpoint());
        self::assertSame(self::SUBSCRIPTION['keys']['p256dh'], $subscriptions[0]->getPublicKey());
        self::assertSame(self::SUBSCRIPTION['keys']['auth'], $subscriptions[0]->getAuthToken());

        // The browser sends it again on each visit of the settings
        self::send($client, 'POST', json_encode(self::SUBSCRIPTION));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        self::assertCount(1, self::findSubscriptions());
    }

    public function testUnsubscribes(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        self::send($client, 'POST', json_encode(self::SUBSCRIPTION));

        self::send($client, 'DELETE', json_encode(self::SUBSCRIPTION));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        self::assertCount(0, self::findSubscriptions());

        self::send($client, 'DELETE', json_encode(self::SUBSCRIPTION));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testRefusesAnInvalidSubscription(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        self::send($client, 'POST', '{"keys": {}}');
        self::assertSame(400, $client->getResponse()->getStatusCode());

        self::send($client, 'POST', 'not JSON');
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testSendsAnonymousToLogin(): void
    {
        $client = static::createClient();

        self::send($client, 'POST', json_encode(self::SUBSCRIPTION));
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
        self::assertCount(0, self::findSubscriptions());
    }

    private static function send(KernelBrowser $client, string $method, string $content): void
    {
        $client->request($method, '/webpush/', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $content);
    }

    /**
     * @return list<UserSubscription>
     */
    private static function findSubscriptions(): array
    {
        return static::getEntityManager()->getRepository(UserSubscription::class)->findBy(['subscriptionHash' => md5(self::SUBSCRIPTION['endpoint'])]);
    }
}
