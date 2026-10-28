<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Message\WebpushNotificationMessage;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Envelope;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The interactions every type of content shares (comments, likes, watches...), tested on the
 * creation of id 1 of the fixtures: published by "user", commented, liked and watched by "user2".
 */
abstract class InteractionTestCase extends WebTestCase
{
    use XhrTrait;

    protected const CREATION_ID = 1;

    protected static function findCreation(): Creation
    {
        return static::getEntityManager()->getRepository(Creation::class)->find(self::CREATION_ID);
    }

    /**
     * Unpublishes the creation, as an admin: it is then a draft, that only its author and the
     * admins see.
     */
    protected static function unpublishCreation(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', static::generatePath('core_creation_unpublish', ['id' => self::CREATION_ID, 'rtu' => '/']));
        self::assertSame(302, $client->getResponse()->getStatusCode());
    }

    protected static function createClientFor(?string $username): KernelBrowser
    {
        return null === $username ? static::createClient() : static::createClientAs($username);
    }

    protected static function assertRedirectsToLogin(KernelBrowser $client): void
    {
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * The messages for the webpush notifications, sent to RabbitMQ by the last request of the
     * client.
     *
     * @return list<WebpushNotificationMessage>
     */
    protected static function getWebpushNotifications(KernelBrowser $client): array
    {
        return array_map(
            fn (Envelope $envelope) => $envelope->getMessage(),
            $client->getContainer()->get('tests.webpush_notification_transport')->getSent(),
        );
    }
}
