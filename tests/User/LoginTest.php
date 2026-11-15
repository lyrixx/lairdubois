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
    // The native hash of "user" in the fixtures when the remember me cookie of Symfony 4.4 below was
    // captured
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
            return '__Secure-mercure_access_token' === $cookie->getName();
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
     * The session cookie has a name of its own, the PHPSESSID of PHP before (#301): the delivery
     * closes the sessions opened before it, and each account logs in again once.
     */
    public function testSessionCookieName(): void
    {
        $client = $this->login(static::createClient(), UserFixtures::USER, UserFixtures::USER);
        $sessionCookie = $client->getCookieJar()->get('LADBSESSID');
        self::assertNotNull($sessionCookie);
        self::assertNull($client->getCookieJar()->get('PHPSESSID'));

        // The session alone, without the remember me cookie
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set(new Cookie('LADBSESSID', $sessionCookie->getValue()));
        self::assertLoggedInAs($client, UserFixtures::USER);

        // The same session under the name of before
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set(new Cookie('PHPSESSID', $sessionCookie->getValue()));
        self::assertNotLoggedIn($client);
    }

    /**
     * The remember me cookies of the members of the production, set by Symfony 3.4: the remember me
     * handler of the authenticator manager signs otherwise, the cookie is refused without error and
     * deleted, a login again once after the delivery (#177, choice 5).
     *
     * The cookie below is set by Symfony 4.4, in the format of 3.4: TokenBasedRememberMeServices and
     * AbstractRememberMeServices of 3.4.46 (the version of the production), 3.4.49 and 4.4.51 write
     * "<class>:<user name in base64>:<expiry date>:<HMAC-SHA256>", the whole in base64, the HMAC of
     * the class, the user name, the expiry date and the password hash by the secret. SecurityBundle
     * names the cookie REMEMBERME in both.
     */
    public function testRememberMeOfSymfony44(): void
    {
        $client = static::createClient();
        $this->setPasswordHash(UserFixtures::USER, self::SYMFONY_44_HASH, null);
        // Set by a login by the form with Symfony 4.4.51, its lifetime raised to 100 years so that
        // the test does not expire: it signs the expiry date, the user name and the password hash
        $client->getCookieJar()->set(new Cookie('REMEMBERME', 'TGFkYlxDb3JlQnVuZGxlXEVudGl0eVxDb3JlXFVzZXI6ZFhObGNnPT06NDk0NjgxNzgxODpjMDE1NTU2OGExZTc4NGYxMDcwNjY5NDU5YzM4YTJkYmVhMGM3YjZmOGI2NThkODgzNDNhNDBlZWNiOTNmNTE4'));

        self::assertNotLoggedIn($client);
        $cookies = array_values(array_filter($client->getResponse()->headers->getCookies(), static function (ResponseCookie $cookie): bool {
            return 'REMEMBERME' === $cookie->getName();
        }));
        self::assertCount(1, $cookies);
        self::assertTrue($cookies[0]->isCleared());
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
