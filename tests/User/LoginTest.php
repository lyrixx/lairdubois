<?php

namespace Tests\User;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;
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
    // The native hash of "user" in the fixtures when the values of Symfony 4.4 below were captured
    private const SYMFONY_44_HASH = '$argon2id$v=19$m=65536,t=4,p=1$ZkdxcUg5T1dpclFnYjJBUA$xaqpAZakiM6hwj+94/K42DTswt8FQX1+TXhKQWknOhk';

    public function testLoginWithSha512PasswordHash(): void
    {
        $client = static::createClient();
        $this->setPasswordHash(UserFixtures::USER, self::SHA512_HASH, self::SALT);

        $client = $this->login($client, UserFixtures::USER, self::PASSWORD);

        self::assertSame('https://lairdubois.test/', $client->getResponse()->headers->get('Location'));
        self::assertLoggedInAs($client, UserFixtures::USER);
        // Hashed again by the native encoder, which salts the hash itself (#24)
        $user = static::findUser(UserFixtures::USER);
        self::assertNotSame(self::SHA512_HASH, $user->getPassword());
        self::assertNotSame('unknown', password_get_info($user->getPassword())['algoName']);
        self::assertNull($user->getSalt());

        // Again, in a new session
        $client = $this->login(static::createClient(), UserFixtures::USER, self::PASSWORD);

        self::assertSame('https://lairdubois.test/', $client->getResponse()->headers->get('Location'));
        self::assertLoggedInAs($client, UserFixtures::USER);
    }

    /**
     * The first login of an account with the hash of FOSUserBundle changes its hash (#24): its other
     * sessions are logged out.
     */
    public function testLoginWithSha512PasswordHashLogsOutTheOtherSessions(): void
    {
        $this->setPasswordHash(UserFixtures::USER, self::SHA512_HASH, self::SALT);
        $otherSession = static::createClientAs(UserFixtures::USER);
        self::assertLoggedInAs($otherSession, UserFixtures::USER);

        $this->login(static::createClient(), UserFixtures::USER, self::PASSWORD);

        self::assertNotLoggedIn($otherSession);
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

    public function testLoginWithInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $client->submit($this->getLoginForm($crawler)->form([
            '_username' => UserFixtures::USER,
            '_password' => UserFixtures::USER,
            '_csrf_token' => 'invalid',
        ]));

        self::assertSame('https://lairdubois.test/login', $client->getResponse()->headers->get('Location'));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Jeton CSRF invalide.', $crawler->filter('.alert-danger')->text(null, false));
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

    /**
     * The subscription cookie of the workspaces (Mercure) lasts a year: the logout deletes it, as
     * the session.
     */
    public function testLogoutDeletesTheMercureCookie(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $client->request('GET', '/logout');

        $cookies = array_values(array_filter($client->getResponse()->headers->getCookies(), static function (ResponseCookie $cookie): bool {
            return 'mercureAuthorization' === $cookie->getName();
        }));
        self::assertCount(1, $cookies);
        self::assertTrue($cookies[0]->isCleared());
        self::assertSame('/.well-known/mercure', $cookies[0]->getPath());
        self::assertNull($cookies[0]->getDomain());
    }

    /**
     * The logout deletes the remember me cookie: the next visit is anonymous.
     */
    public function testLogoutDeletesTheRememberMeCookie(): void
    {
        $client = $this->login(static::createClient(), UserFixtures::USER, UserFixtures::USER);
        self::assertNotNull($client->getCookieJar()->get('REMEMBERME'));

        $client->request('GET', '/logout');

        $cookies = array_values(array_filter($client->getResponse()->headers->getCookies(), static function (ResponseCookie $cookie): bool {
            return 'REMEMBERME' === $cookie->getName();
        }));
        self::assertCount(1, $cookies);
        self::assertTrue($cookies[0]->isCleared());
        self::assertNull($client->getCookieJar()->get('REMEMBERME'));
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

    public function testSwitchUserToDisabledAccount(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $this->disable(UserFixtures::USER);

        $client->request('GET', '/notifications?_switch_to=user');

        // The user checker only checks a disabled account before the authentication
        self::assertSame('https://lairdubois.test/notifications', $client->getResponse()->headers->get('Location'));
        self::assertLoggedInAs($client, UserFixtures::USER);
    }

    public function testLoginWithUsernameInAnotherCase(): void
    {
        $client = $this->login(static::createClient(), 'USER', UserFixtures::USER);

        self::assertLoggedInAs($client, UserFixtures::USER);
    }

    public function testLoginSetsTheLastLogin(): void
    {
        $client = static::createClient();
        $this->clearLastLogin(UserFixtures::USER);

        $this->login($client, UserFixtures::USER, UserFixtures::USER);

        self::assertEqualsWithDelta(time(), static::findUser(UserFixtures::USER)->getLastLogin()->getTimestamp(), 60);
    }

    public function testRememberMeSetsTheLastLogin(): void
    {
        $client = $this->login(static::createClient(), UserFixtures::USER, UserFixtures::USER);
        $this->clearLastLogin(UserFixtures::USER);

        $this->keepOnlyRememberMe($client);
        self::assertLoggedInAs($client, UserFixtures::USER);

        self::assertEqualsWithDelta(time(), static::findUser(UserFixtures::USER)->getLastLogin()->getTimestamp(), 60);
    }

    public function testRememberMeOfDisabledAccount(): void
    {
        $client = $this->login(static::createClient(), UserFixtures::USER, UserFixtures::USER);
        $this->disable(UserFixtures::USER);

        $this->keepOnlyRememberMe($client);

        self::assertNotLoggedIn($client);
    }

    /**
     * The remember me cookie signs the password hash: a new password refuses it.
     */
    public function testRememberMeAfterAPasswordChange(): void
    {
        $client = $this->login(static::createClient(), UserFixtures::USER, UserFixtures::USER);
        $this->setPasswordHash(UserFixtures::USER, self::SHA512_HASH, self::SALT);

        $this->keepOnlyRememberMe($client);

        self::assertNotLoggedIn($client);
    }

    /**
     * The session of an account that an admin disables is logged out at its next request.
     */
    public function testSessionOfDisabledAccount(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        self::assertLoggedInAs($client, UserFixtures::USER);

        $this->disable(UserFixtures::USER);

        self::assertNotLoggedIn($client);
    }

    public function testSessionAfterAPasswordChange(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        self::assertLoggedInAs($client, UserFixtures::USER);

        $this->setPasswordHash(UserFixtures::USER, self::SHA512_HASH, self::SALT);

        self::assertNotLoggedIn($client);
    }

    public function testSessionAfterARoleChange(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        self::assertLoggedInAs($client, UserFixtures::USER);

        static::getEntityManager()->getConnection()->update('tbl_core_user', ['roles' => serialize(['ROLE_ADMIN'])], ['username_canonical' => UserFixtures::USER]);

        self::assertNotLoggedIn($client);
    }

    /**
     * A session opened before the replacement of FOSUserBundle (#24) stays logged in, with the roles
     * of its token: an admin stays admin.
     *
     * @dataProvider provideSessionsOfFosUserBundle
     */
    public function testSessionOfFosUserBundle(string $username, string $serializedToken, int $adminPageStatusCode): void
    {
        $client = static::createClient();
        $this->setPasswordHash($username, self::SHA512_HASH, self::SALT);
        $session = $client->getContainer()->get('session.factory')->createSession();
        $session->set('_security_main', $serializedToken);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        self::assertLoggedInAs($client, $username);
        $client->request('GET', '/@user/admin');
        self::assertSame($adminPageStatusCode, $client->getResponse()->getStatusCode());
    }

    /**
     * The token of the session, serialize()d by the code of #144 (FOSUserBundle 2.2, Symfony 4.4,
     * PHP 8.5), with the password and the salt of setPasswordHash(): the user in the "C:" format of
     * \Serializable, without its roles.
     *
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideSessionsOfFosUserBundle(): iterable
    {
        yield 'user' => [UserFixtures::USER, 'O:74:"Symfony\\Component\\Security\\Core\\Authentication\\Token\\UsernamePasswordToken":3:{i:0;N;i:1;s:4:"main";i:2;a:5:{i:0;C:32:"Ladb\\CoreBundle\\Entity\\Core\\User":271:{a:8:{i:0;s:88:"/r+tTBYXOOfHdCRUDwJuesex6ZpOpafKuYRyXvNtY4RK7S7Qm97lWAr4U8SC/O/IGuxFMoxvooYgaqiTy6kCYA==";i:1;s:43:"Vq7k3ZbN0pXe9yLr2TfHs6Wm1JcAuGd5Qo8iEnKx4Bw";i:2;s:4:"user";i:3;s:4:"user";i:4;b:1;i:5;i:2;i:6;s:20:"user@lairdubois.test";i:7;s:20:"user@lairdubois.test";}}i:1;b:1;i:2;a:1:{i:0;O:41:"Symfony\\Component\\Security\\Core\\Role\\Role":1:{s:47:"'."\0".'Symfony\\Component\\Security\\Core\\Role\\Role'."\0".'role";s:9:"ROLE_USER";}}i:3;a:0:{}i:4;a:1:{i:0;s:9:"ROLE_USER";}}}', 404];
        yield 'admin' => [UserFixtures::ADMIN, 'O:74:"Symfony\\Component\\Security\\Core\\Authentication\\Token\\UsernamePasswordToken":3:{i:0;N;i:1;s:4:"main";i:2;a:5:{i:0;C:32:"Ladb\\CoreBundle\\Entity\\Core\\User":275:{a:8:{i:0;s:88:"/r+tTBYXOOfHdCRUDwJuesex6ZpOpafKuYRyXvNtY4RK7S7Qm97lWAr4U8SC/O/IGuxFMoxvooYgaqiTy6kCYA==";i:1;s:43:"Vq7k3ZbN0pXe9yLr2TfHs6Wm1JcAuGd5Qo8iEnKx4Bw";i:2;s:5:"admin";i:3;s:5:"admin";i:4;b:1;i:5;i:1;i:6;s:21:"admin@lairdubois.test";i:7;s:21:"admin@lairdubois.test";}}i:1;b:1;i:2;a:2:{i:0;O:41:"Symfony\\Component\\Security\\Core\\Role\\Role":1:{s:47:"'."\0".'Symfony\\Component\\Security\\Core\\Role\\Role'."\0".'role";s:10:"ROLE_ADMIN";}i:1;O:41:"Symfony\\Component\\Security\\Core\\Role\\Role":1:{s:47:"'."\0".'Symfony\\Component\\Security\\Core\\Role\\Role'."\0".'role";s:9:"ROLE_USER";}}i:3;a:0:{}i:4;a:2:{i:0;s:10:"ROLE_ADMIN";i:1;s:9:"ROLE_USER";}}}', 200];
    }

    /**
     * A session opened with Symfony 4.4, after the replacement of FOSUserBundle, stays logged in
     * with Symfony 5.4, which keeps the Role class of the tokens of 4.x (#23).
     */
    public function testSessionOfSymfony44(): void
    {
        $client = static::createClient();
        $this->setPasswordHash(UserFixtures::USER, self::SYMFONY_44_HASH, null);
        $session = $client->getContainer()->get('session.factory')->createSession();
        // The token of a login by the form with Symfony 4.4.51 (PHP 8.5)
        $session->set('_security_main', 'O:74:"Symfony\\Component\\Security\\Core\\Authentication\\Token\\UsernamePasswordToken":3:{i:0;N;i:1;s:4:"main";i:2;a:5:{i:0;O:32:"Ladb\\CoreBundle\\Entity\\Core\\User":9:{i:0;s:97:"$argon2id$v=19$m=65536,t=4,p=1$ZkdxcUg5T1dpclFnYjJBUA$xaqpAZakiM6hwj+94/K42DTswt8FQX1+TXhKQWknOhk";i:1;N;i:2;s:4:"user";i:3;s:4:"user";i:4;b:1;i:5;i:2;i:6;s:20:"user@lairdubois.test";i:7;s:20:"user@lairdubois.test";i:8;a:0:{}}i:1;b:1;i:2;a:1:{i:0;O:41:"Symfony\\Component\\Security\\Core\\Role\\Role":1:{s:47:"'."\0".'Symfony\\Component\\Security\\Core\\Role\\Role'."\0".'role";s:9:"ROLE_USER";}}i:3;a:0:{}i:4;a:1:{i:0;s:9:"ROLE_USER";}}}');
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        self::assertLoggedInAs($client, UserFixtures::USER);
    }

    /**
     * A remember me cookie set by Symfony 4.4 logs in with Symfony 5.4 (#23).
     */
    public function testRememberMeOfSymfony44(): void
    {
        $client = static::createClient();
        $this->setPasswordHash(UserFixtures::USER, self::SYMFONY_44_HASH, null);
        // Set by a login by the form with Symfony 4.4.51, its lifetime raised to 100 years so that
        // the test does not expire: it signs the expiry date, the user name and the password hash
        $client->getCookieJar()->set(new Cookie('REMEMBERME', 'TGFkYlxDb3JlQnVuZGxlXEVudGl0eVxDb3JlXFVzZXI6ZFhObGNnPT06NDk0NjgxNzgxODpjMDE1NTU2OGExZTc4NGYxMDcwNjY5NDU5YzM4YTJkYmVhMGM3YjZmOGI2NThkODgzNDNhNDBlZWNiOTNmNTE4'));

        self::assertLoggedInAs($client, UserFixtures::USER);
    }

    private function login(KernelBrowser $client, string $username, string $password): KernelBrowser
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

    private function setPasswordHash(string $username, string $hash, ?string $salt): void
    {
        static::getEntityManager()->getConnection()->update('tbl_core_user', ['password' => $hash, 'salt' => $salt], ['username_canonical' => $username]);
    }

    private function disable(string $username): void
    {
        static::getEntityManager()->getConnection()->update('tbl_core_user', ['enabled' => 0], ['username_canonical' => $username]);
    }

    private function clearLastLogin(string $username): void
    {
        static::getEntityManager()->getConnection()->update('tbl_core_user', ['last_login' => null], ['username_canonical' => $username]);
    }

    /**
     * A new session, as after closing the browser: only the remember me cookie is left.
     */
    private function keepOnlyRememberMe(KernelBrowser $client): void
    {
        $rememberMe = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($rememberMe);
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set($rememberMe);
    }

    /**
     * A page for ROLE_USER only: /@me/parametres redirects to the settings of the logged in user.
     */
    private static function assertLoggedInAs(KernelBrowser $client, string $username): void
    {
        $client->request('GET', '/@me/parametres');
        self::assertSame("/@{$username}/parametres", $client->getResponse()->headers->get('Location'));
    }

    private static function assertNotLoggedIn(KernelBrowser $client): void
    {
        $client->request('GET', '/@me/parametres');
        self::assertSame('https://lairdubois.test/login', $client->getResponse()->headers->get('Location'));
    }
}
