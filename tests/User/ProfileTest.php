<?php

namespace Tests\User;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Member;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Fos\UserManager;
use Tests\WebTestCase;

/**
 * Profiles (/@{username}), the lists of accounts and the administration of an account. The
 * smoke test already calls every page: here, what they show and who may see them.
 */
final class ProfileTest extends WebTestCase
{
    public function testShowProfile(): void
    {
        $client = static::createClient();

        $client->request('GET', '/@user/a-propos');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('Lyon, France', $content);
        self::assertStringContainsString('<strong>chêne</strong>', $content);
        // Neither the settings nor the administration for a visitor
        self::assertStringNotContainsString('href="/@user/parametres"', $content);
        self::assertStringNotContainsString('href="/@user/admin"', $content);
    }

    public function testShowOwnProfile(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $client->request('GET', '/@user/a-propos');

        self::assertStringContainsString('href="/@user/parametres"', $client->getResponse()->getContent());

        $client->request('GET', '/@me');
        self::assertSame('/@user', $client->getResponse()->headers->get('Location'));
        $client->request('GET', '/me');
        self::assertSame('/@user', $client->getResponse()->headers->get('Location'));
    }

    public function testShowProfileOfAnotherUser(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);

        $client->request('GET', '/@user/a-propos');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('href="/@user/parametres"', $client->getResponse()->getContent());
    }

    public function testShowProfileAsAdmin(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);

        $client->request('GET', '/@user/a-propos');
        self::assertStringContainsString('href="/@user/admin"', $client->getResponse()->getContent());

        $client->request('GET', '/@user/admin');
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testShowAdminAsUser(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $client->request('GET', '/@user/admin');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testOldAndUnknownAddresses(): void
    {
        $client = static::createClient();

        $client->request('GET', '/user');
        self::assertSame('/@user', $client->getResponse()->headers->get('Location'));

        $client->request('GET', '/@inconnu');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testListUsers(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/boiseux');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $crawler->filter('a[href="/@user"]')->count());

        $crawler = $client->request('GET', '/collectifs');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $crawler->filter('a[href="/@team"]')->count());
    }

    public function testDeactivateAndActivate(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);

        $client->request('GET', '/@user2/admin/deactivate');

        self::assertSame('/@user2/admin', $client->getResponse()->headers->get('Location'));
        self::assertFalse(static::findUser(UserFixtures::USER2)->isEnabled());
        // Hidden, except for the admins
        $client->request('GET', '/@user2');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $anonymous = static::createClient();
        $anonymous->request('GET', '/@user2');
        self::assertSame(404, $anonymous->getResponse()->getStatusCode());

        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', '/@user2/admin/activate');

        self::assertSame('/@user2/admin', $client->getResponse()->headers->get('Location'));
        self::assertTrue(static::findUser(UserFixtures::USER2)->isEnabled());
    }

    public function testEmpty(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);

        $client->request('GET', '/@user2/admin/empty');

        self::assertSame('/@user2/admin', $client->getResponse()->headers->get('Location'));
        $user2 = static::findUser(UserFixtures::USER2);
        self::assertSame(0, $user2->getMeta()->getFollowingCount());
        self::assertSame(0, $user2->getMeta()->getSentLikeCount());
        self::assertSame(0, static::findUser(UserFixtures::USER)->getMeta()->getFollowerCount());
    }

    public function testConvertToTeam(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);

        $client->request('GET', '/@user2/admin/converttoteam');

        self::assertSame('/@user2/admin', $client->getResponse()->headers->get('Location'));
        $user2 = static::findUser(UserFixtures::USER2);
        self::assertTrue($user2->getIsTeam());
        self::assertContains('ROLE_TEAM', $user2->getRoles());
        // No longer a member of a team
        self::assertFalse(static::getEntityManager()->getRepository(Member::class)->existsByTeamAndUser(static::findUser(UserFixtures::TEAM), $user2));
    }

    /**
     * An account without content: deleting one with contents answers 500 (#48).
     */
    public function testDelete(): void
    {
        $userManager = static::bootKernel()->getContainer()->get(UserManager::NAME);
        $user = $userManager->createUser();
        $user->setUsername('apprenti');
        $user->setEmail('apprenti@lairdubois.test');
        $user->setPlainPassword('apprenti');
        $user->setEnabled(true);
        $userManager->updateUser($user);
        $client = static::createClientAs(UserFixtures::ADMIN);

        $client->request('GET', '/@apprenti/admin/delete');

        self::assertSame('/', $client->getResponse()->headers->get('Location'));
        self::assertNull(static::getEntityManager()->getRepository(User::class)->findOneBy(['usernameCanonical' => 'apprenti']));
    }

    public function testAdminActionsAsUser(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        foreach (['deactivate', 'activate', 'empty', 'converttoteam', 'delete'] as $action) {
            $client->request('GET', "/@user2/admin/{$action}");
            self::assertSame(404, $client->getResponse()->getStatusCode(), $action);
        }
        self::assertTrue(static::findUser(UserFixtures::USER2)->isEnabled());
    }
}
