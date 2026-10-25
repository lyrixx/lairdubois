<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Activity\Like as LikeActivity;
use Ladb\CoreBundle\Entity\Core\Like;
use Ladb\CoreBundle\Entity\Wonder\Creation;

/**
 * The likes ("coups de coeur") of a content, on the creation of the fixtures, liked by "user2".
 * The site sends them in XHR; without XHR, the action redirects to "rtu" or to the referer.
 */
final class LikeTest extends InteractionTestCase
{
    public function testList(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', static::generatePath('core_like_list_byentity', ['entityType' => Creation::TYPE, 'entityId' => self::CREATION_ID]));

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
        static::xhr($client, 'GET', static::generatePath('core_like_delete', ['id' => $this->findLikeOf(UserFixtures::USER2)->getId()]));

        self::assertRedirectsToLogin($client);
    }

    /**
     * A like counts on the content, its author and the user who likes, makes an activity and a
     * webpush notification for the author, and makes the user watch the content.
     */
    public function testCreate(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $likeCount = static::findCreation()->getLikeCount();
        $watchCount = static::findCreation()->getWatchCount();
        $sentLikeCount = static::findUser(UserFixtures::ADMIN)->getMeta()->getSentLikeCount();
        $recievedLikeCount = static::findUser(UserFixtures::USER)->getMeta()->getRecievedLikeCount();

        static::xhr($client, 'GET', $this->getCreatePath());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $like = $this->findLikeOf(UserFixtures::ADMIN);
        self::assertSame(UserFixtures::USER, $like->getEntityUser()->getUsername());
        self::assertSame($likeCount + 1, static::findCreation()->getLikeCount());
        self::assertSame($watchCount + 1, static::findCreation()->getWatchCount());
        self::assertSame($sentLikeCount + 1, static::findUser(UserFixtures::ADMIN)->getMeta()->getSentLikeCount());
        self::assertSame($recievedLikeCount + 1, static::findUser(UserFixtures::USER)->getMeta()->getRecievedLikeCount());
        self::assertNotNull(static::getEntityManager()->getRepository(LikeActivity::class)->findOneBy(['like' => $like]));

        $notifications = static::getWebpushNotifications($client);
        self::assertCount(1, $notifications);
        self::assertSame(static::findUser(UserFixtures::USER)->getId(), $notifications[0]['userId']);
        self::assertStringStartsWith('Nouveau coup de coeur de ', $notifications[0]['body']);
    }

    /**
     * @dataProvider provideSiteRtus
     */
    public function testCreateWithoutXhrRedirectsToRtu(string $rtu): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->getCreatePath(['rtu' => $rtu]));

        self::assertTrue($client->getResponse()->isRedirect($rtu));
        self::assertNotNull($this->findLikeOf(UserFixtures::ADMIN));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSiteRtus(): iterable
    {
        yield 'path' => ['/creations/'];
        yield 'absolute URL on the host of the request' => ['https://lairdubois.test/creations/'];
    }

    /**
     * An empty "rtu", that the site never sends (forged URL), as none: the referer, or the home
     * page without it.
     */
    public function testCreateWithoutXhrAndWithEmptyRtuRedirectsToHome(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->getCreatePath().'?rtu=');

        self::assertTrue($client->getResponse()->isRedirect('/'));
        self::assertNotNull($this->findLikeOf(UserFixtures::ADMIN));
    }

    /**
     * A "rtu" that does not lead to the site (forged URL, #102), as none.
     *
     * @dataProvider provideForeignRtus
     */
    public function testCreateWithoutXhrAndWithForeignRtuRedirectsToHome($rtu): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->getCreatePath(['rtu' => $rtu]));

        self::assertTrue($client->getResponse()->isRedirect('/'));
        self::assertNotNull($this->findLikeOf(UserFixtures::ADMIN));
    }

    /**
     * @return iterable<string, array{string|string[]}>
     */
    public static function provideForeignRtus(): iterable
    {
        yield 'other host' => ['https://example.com/'];
        yield 'other host, the site as user info' => ['https://lairdubois.test@example.com/'];
        yield 'relative to the protocol' => ['//example.com/'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'other scheme on the host of the request' => ['ftp://lairdubois.test/'];
        yield 'backslash' => ['/\\example.com/'];
        yield 'tab' => ["/\t/example.com/"];
        yield 'array' => [['/creations/']];
    }

    /**
     * The like of "user2" exists: a second one does not count.
     */
    public function testCreateTwice(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $likeCount = static::findCreation()->getLikeCount();

        static::xhr($client, 'GET', $this->getCreatePath());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($likeCount, static::findCreation()->getLikeCount());
    }

    public function testCreateOwnContent(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $likeCount = static::findCreation()->getLikeCount();

        static::xhr($client, 'GET', $this->getCreatePath());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($likeCount, static::findCreation()->getLikeCount());
        self::assertNull($this->findLikeOf(UserFixtures::USER));
    }

    public function testCreateWithUnconfirmedEmail(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        static::findUser(UserFixtures::ADMIN)->setEmailConfirmed(false);
        static::getEntityManager()->flush();

        static::xhr($client, 'GET', $this->getCreatePath());

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNull($this->findLikeOf(UserFixtures::ADMIN));
    }

    public function testCreateOnDraft(): void
    {
        static::unpublishCreation();
        $client = static::createClientAs(UserFixtures::ADMIN);

        static::xhr($client, 'GET', $this->getCreatePath());

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testDelete(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $like = $this->findLikeOf(UserFixtures::USER2);
        $likeCount = static::findCreation()->getLikeCount();
        $sentLikeCount = static::findUser(UserFixtures::USER2)->getMeta()->getSentLikeCount();
        $recievedLikeCount = static::findUser(UserFixtures::USER)->getMeta()->getRecievedLikeCount();

        static::xhr($client, 'GET', static::generatePath('core_like_delete', ['id' => $like->getId()]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        self::assertNull($this->findLikeOf(UserFixtures::USER2));
        self::assertSame($likeCount - 1, static::findCreation()->getLikeCount());
        self::assertSame($sentLikeCount - 1, static::findUser(UserFixtures::USER2)->getMeta()->getSentLikeCount());
        self::assertSame($recievedLikeCount - 1, static::findUser(UserFixtures::USER)->getMeta()->getRecievedLikeCount());
        self::assertSame([], static::getEntityManager()->getRepository(LikeActivity::class)->findBy(['like' => $like->getId()]));
    }

    /**
     * Only the user who likes takes the like back, not even an admin.
     *
     * @dataProvider provideOtherUsers
     */
    public function testDeleteAsOtherUser(string $username): void
    {
        $client = static::createClientAs($username);
        static::xhr($client, 'GET', static::generatePath('core_like_delete', ['id' => $this->findLikeOf(UserFixtures::USER2)->getId()]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->findLikeOf(UserFixtures::USER2));
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
        return static::generatePath('core_like_create', ['entityType' => Creation::TYPE, 'entityId' => self::CREATION_ID] + $parameters);
    }

    private function findLikeOf(string $username): ?Like
    {
        return static::getEntityManager()->getRepository(Like::class)->findOneBy([
            'entityType' => Creation::TYPE,
            'entityId' => self::CREATION_ID,
            'user' => static::findUser($username),
        ]);
    }
}
