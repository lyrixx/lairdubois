<?php

namespace Tests\User;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Follower;
use Tests\WebTestCase;

/**
 * Subscriptions between accounts (/followers/), and their lists on the profiles. In the
 * fixtures, user2 follows user.
 *
 * The links of the site go through a page, which gives the Referer header to come back to:
 * without it, a request without XHR answers 500 (#42).
 */
final class FollowerTest extends WebTestCase
{
    private const REFERER = 'https://lairdubois.test/@user2';

    public function testFollow(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $user2 = static::findUser(UserFixtures::USER2);
        $followingCount = static::findUser(UserFixtures::USER)->getMeta()->getFollowingCount();
        $followerCount = $user2->getMeta()->getFollowerCount();

        $client->request('GET', "/followers/{$user2->getId()}/create", [], [], ['HTTP_REFERER' => self::REFERER]);

        self::assertSame(self::REFERER, $client->getResponse()->headers->get('Location'));
        $user = static::findUser(UserFixtures::USER);
        $user2 = static::findUser(UserFixtures::USER2);
        self::assertNotNull($this->findFollower($user->getId(), $user2->getId()));
        self::assertSame($followingCount + 1, $user->getMeta()->getFollowingCount());
        self::assertSame($followerCount + 1, $user2->getMeta()->getFollowerCount());

        $crawler = $client->request('GET', '/@user2/abonnes');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $crawler->filter('a[href="/@user"]')->count());
    }

    public function testFollowInXhr(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $user2 = static::findUser(UserFixtures::USER2);

        $client->request('GET', "/followers/{$user2->getId()}/create", [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->findFollower(static::findUser(UserFixtures::USER)->getId(), $user2->getId()));
    }

    public function testFollowAgain(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $user = static::findUser(UserFixtures::USER);
        $followerCount = $user->getMeta()->getFollowerCount();

        $client->request('GET', "/followers/{$user->getId()}/create", [], [], ['HTTP_REFERER' => self::REFERER]);

        self::assertSame(self::REFERER, $client->getResponse()->headers->get('Location'));
        self::assertSame($followerCount, static::findUser(UserFixtures::USER)->getMeta()->getFollowerCount());
    }

    public function testFollowSelf(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $user2 = static::findUser(UserFixtures::USER2);

        $client->request('GET', "/followers/{$user2->getId()}/create", [], [], ['HTTP_REFERER' => self::REFERER]);

        self::assertSame(self::REFERER, $client->getResponse()->headers->get('Location'));
        self::assertNull($this->findFollower($user2->getId(), $user2->getId()));
    }

    /**
     * AbstractController::createLock(): a second subscription within 3 seconds is refused.
     */
    public function testFollowTwiceInARow(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $user2 = static::findUser(UserFixtures::USER2);
        $admin = static::findUser(UserFixtures::ADMIN);

        $client->request('GET', "/followers/{$user2->getId()}/create", [], [], ['HTTP_REFERER' => self::REFERER]);
        $client->request('GET', "/followers/{$admin->getId()}/create", [], [], ['HTTP_REFERER' => self::REFERER]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNull($this->findFollower(static::findUser(UserFixtures::USER)->getId(), $admin->getId()));
    }

    public function testFollowAnonymous(): void
    {
        $client = static::createClient();

        $client->request('GET', '/followers/3/create');

        self::assertSame('https://lairdubois.test/login', $client->getResponse()->headers->get('Location'));
    }

    public function testUnfollow(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $user = static::findUser(UserFixtures::USER);
        $followerId = $this->findFollower(static::findUser(UserFixtures::USER2)->getId(), $user->getId())->getId();

        $client->request('GET', "/followers/{$followerId}/delete", [], [], ['HTTP_REFERER' => self::REFERER]);

        self::assertSame(self::REFERER, $client->getResponse()->headers->get('Location'));
        self::assertNull(static::getEntityManager()->getRepository(Follower::class)->find($followerId));
        self::assertSame(0, static::findUser(UserFixtures::USER)->getMeta()->getFollowerCount());
        self::assertSame(0, static::findUser(UserFixtures::USER2)->getMeta()->getFollowingCount());
    }

    public function testUnfollowForAnotherUser(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $follower = $this->findFollower(static::findUser(UserFixtures::USER2)->getId(), static::findUser(UserFixtures::USER)->getId());

        $client->request('GET', "/followers/{$follower->getId()}/delete", [], [], ['HTTP_REFERER' => self::REFERER]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotNull(static::getEntityManager()->getRepository(Follower::class)->find($follower->getId()));
    }

    public function testFollowLists(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/@user/abonnes');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $crawler->filter('a[href="/@user2"]')->count());

        $crawler = $client->request('GET', '/@user2/abonnements');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $crawler->filter('a[href="/@user"]')->count());
    }

    private function findFollower(int $userId, int $followingUserId): ?Follower
    {
        return static::getEntityManager()->getRepository(Follower::class)->findOneBy(['user' => $userId, 'followingUser' => $followingUserId]);
    }
}
