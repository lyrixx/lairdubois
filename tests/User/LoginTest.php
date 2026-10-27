<?php

namespace Tests\User;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Component\DomCrawler\Crawler;
use Tests\WebTestCase;

/**
 * Login through the form of /login, logout, remember me and switch user.
 *
 * The accounts of the production database keep the password hash of FOSUserBundle: sha512, 5000
 * iterations, base64, with a salt. They must log in without a reset across the whole migration,
 * including the replacement of FOSUserBundle (#24): the hash below is computed the same way, and
 * written as is in the database, whatever the encoder the application uses.
 */
final class LoginTest extends WebTestCase
{
    private const PASSWORD = 'Copeaux-de-chene-1';
    private const SALT = 'Vq7k3ZbN0pXe9yLr2TfHs6Wm1JcAuGd5Qo8iEnKx4Bw';
    // MessageDigestPasswordEncoder('sha512', true, 5000) of FOSUserBundle, for PASSWORD and SALT
    private const SHA512_HASH = '/r+tTBYXOOfHdCRUDwJuesex6ZpOpafKuYRyXvNtY4RK7S7Qm97lWAr4U8SC/O/IGuxFMoxvooYgaqiTy6kCYA==';

    public function testLoginWithSha512PasswordHash(): void
    {
        $client = static::createClient();
        $this->setPasswordHash(UserFixtures::USER, self::SHA512_HASH, self::SALT);

        $client = $this->login($client, UserFixtures::USER, self::PASSWORD);

        self::assertSame('https://lairdubois.test/', $client->getResponse()->headers->get('Location'));
        self::assertLoggedInAs($client, UserFixtures::USER);
        // The hash stays as it is
        self::assertSame(self::SHA512_HASH, static::findUser(UserFixtures::USER)->getPassword());
    }

    public function testLoginWithEmail(): void
    {
        $client = $this->login(static::createClient(), 'user2@lairdubois.test', UserFixtures::USER2);

        self::assertLoggedInAs($client, UserFixtures::USER2);
    }

    public function testLoginRedirectsToTheProtectedPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/notifications');
        self::assertSame('https://lairdubois.test/login', $client->getResponse()->headers->get('Location'));

        $this->login($client, UserFixtures::USER, UserFixtures::USER);

        self::assertSame('https://lairdubois.test/notifications', $client->getResponse()->headers->get('Location'));
        self::assertLoggedInAs($client, UserFixtures::USER);
    }

    public function testLoginWithWrongPassword(): void
    {
        $client = $this->login(static::createClient(), UserFixtures::USER, 'wrong');

        self::assertSame('https://lairdubois.test/login', $client->getResponse()->headers->get('Location'));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Identifiants invalides.', $crawler->filter('.alert-danger')->text(null, false));
        self::assertNotLoggedIn($client);
    }

    public function testLoginOfDisabledAccount(): void
    {
        $client = static::createClient();
        static::getEntityManager()->getConnection()->update('tbl_core_user', ['enabled' => 0], ['username_canonical' => UserFixtures::USER]);

        $client = $this->login($client, UserFixtures::USER, UserFixtures::USER);

        // As a wrong password: the fix of CVE-2021-21424 (account enumeration) hides the reason
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Identifiants invalides.', $crawler->filter('.alert-danger')->text(null, false));
        self::assertNotLoggedIn($client);
    }

    public function testLogout(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $client->request('GET', '/logout', [], [], ['HTTP_REFERER' => 'https://lairdubois.test/creations/1.html']);

        self::assertSame('https://lairdubois.test/creations/1.html', $client->getResponse()->headers->get('Location'));
        self::assertNotLoggedIn($client);
    }

    public function testRememberMe(): void
    {
        $client = $this->login(static::createClient(), UserFixtures::USER, UserFixtures::USER);
        $rememberMe = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($rememberMe);

        // A new session, as after closing the browser: only the remember me cookie is left
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set($rememberMe);

        self::assertLoggedInAs($client, UserFixtures::USER);
    }

    public function testSwitchUserAsAdmin(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);

        $client->request('GET', '/notifications?_switch_to=user');
        self::assertSame('https://lairdubois.test/notifications', $client->getResponse()->headers->get('Location'));
        self::assertLoggedInAs($client, UserFixtures::USER);

        $client->request('GET', '/notifications?_switch_to=_exit');
        self::assertLoggedInAs($client, UserFixtures::ADMIN);
    }

    public function testSwitchUserAsUser(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $client->request('GET', '/notifications?_switch_to=user2');

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertLoggedInAs($client, UserFixtures::USER);
    }

    private function login(Client $client, string $username, string $password): Client
    {
        $crawler = $client->request('GET', '/login');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $form = $this->getLoginForm($crawler)->form([
            '_username' => $username,
            '_password' => $password,
        ]);
        $client->submit($form);

        return $client;
    }

    private function getLoginForm(Crawler $crawler): Crawler
    {
        return $crawler->filter('form[action$="/login_check"]');
    }

    private function setPasswordHash(string $username, string $hash, string $salt): void
    {
        static::getEntityManager()->getConnection()->update('tbl_core_user', ['password' => $hash, 'salt' => $salt], ['username_canonical' => $username]);
    }

    /**
     * A page for ROLE_USER only: /@me/parametres redirects to the settings of the logged in user.
     */
    private static function assertLoggedInAs(Client $client, string $username): void
    {
        $client->request('GET', '/@me/parametres');
        self::assertSame("/@{$username}/parametres", $client->getResponse()->headers->get('Location'));
    }

    private static function assertNotLoggedIn(Client $client): void
    {
        $client->request('GET', '/@me/parametres');
        self::assertSame('https://lairdubois.test/login', $client->getResponse()->headers->get('Location'));
    }
}
