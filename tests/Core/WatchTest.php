<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Watch;
use Ladb\CoreBundle\Entity\Wonder\Creation;

/**
 * The watches ("suivis") of a content, which notify their users of its activity: on the
 * creation of the fixtures, watched by its author "user" and by "user2" (who commented). The
 * site sends them in XHR; without XHR, the action redirects to "rtu" or to the referer.
 */
final class WatchTest extends InteractionTestCase
{
    public function testList(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', static::generatePath('core_watch_list_byentity', ['entityType' => Creation::TYPE, 'entityId' => self::CREATION_ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $crawler->filter('a[href="/@user2"]'));
    }

    public function testCreateSendsAnonymousToLogin(): void
    {
        $client = static::createClient();
        static::xhr($client, 'GET', $this->getCreatePath());

        self::assertRedirectsToLogin($client);
    }

    public function testDeleteSendsAnonymousToLogin(): void
    {
        $client = static::createClient();
        static::xhr($client, 'GET', static::generatePath('core_watch_delete', ['id' => $this->findWatchOf(UserFixtures::USER2)->getId()]));

        self::assertRedirectsToLogin($client);
    }

    public function testCreate(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $watchCount = static::findCreation()->getWatchCount();

        static::xhr($client, 'GET', $this->getCreatePath());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->findWatchOf(UserFixtures::ADMIN));
        self::assertSame($watchCount + 1, static::findCreation()->getWatchCount());
    }

    public function testCreateWithoutXhrRedirectsToRtu(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->getCreatePath(['rtu' => '/creations/']));

        self::assertTrue($client->getResponse()->isRedirect('/creations/'));
        self::assertNotNull($this->findWatchOf(UserFixtures::ADMIN));
    }

    public function testCreateTwice(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $watchCount = static::findCreation()->getWatchCount();

        static::xhr($client, 'GET', $this->getCreatePath());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($watchCount, static::findCreation()->getWatchCount());
        self::assertCount(1, static::getEntityManager()->getRepository(Watch::class)->findBy(['entityType' => Creation::TYPE, 'entityId' => self::CREATION_ID, 'user' => static::findUser(UserFixtures::USER2)]));
    }

    public function testDelete(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $watchCount = static::findCreation()->getWatchCount();

        static::xhr($client, 'GET', static::generatePath('core_watch_delete', ['id' => $this->findWatchOf(UserFixtures::USER2)->getId()]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        self::assertNull($this->findWatchOf(UserFixtures::USER2));
        self::assertSame($watchCount - 1, static::findCreation()->getWatchCount());
    }

    /**
     * Only its user deletes a watch, not even an admin.
     *
     * @dataProvider provideOtherUsers
     */
    public function testDeleteAsOtherUser(string $username): void
    {
        $client = static::createClientAs($username);
        static::xhr($client, 'GET', static::generatePath('core_watch_delete', ['id' => $this->findWatchOf(UserFixtures::USER2)->getId()]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->findWatchOf(UserFixtures::USER2));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOtherUsers(): iterable
    {
        yield 'author of the content' => [UserFixtures::USER];
        yield 'admin' => [UserFixtures::ADMIN];
    }

    private function getCreatePath(array $parameters = []): string
    {
        return static::generatePath('core_watch_create', ['entityType' => Creation::TYPE, 'entityId' => self::CREATION_ID] + $parameters);
    }

    private function findWatchOf(string $username): ?Watch
    {
        return static::getEntityManager()->getRepository(Watch::class)->findOneBy([
            'entityType' => Creation::TYPE,
            'entityId' => self::CREATION_ID,
            'user' => static::findUser($username),
        ]);
    }
}
