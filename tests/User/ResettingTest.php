<?php

namespace Tests\User;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\WebTestCase;

/**
 * Forgotten password (/resetting/, FOSUserBundle): request, email with a link, new password.
 */
final class ResettingTest extends WebTestCase
{
    public function testResetPassword(): void
    {
        $client = static::createClient();

        $this->requestReset($client, 'user@lairdubois.test');

        self::assertSame('/resetting/check-email?username=user@lairdubois.test', $client->getResponse()->headers->get('Location'));
        $messages = static::getSentEmails($client);
        self::assertCount(1, $messages);
        self::assertSame(['user@lairdubois.test'], static::getEmailRecipients($messages[0]));
        self::assertSame(1, preg_match('{https://lairdubois\.test(/resetting/reset/[\w-]+)}', $messages[0]->getTextBody(), $matches));

        $crawler = $client->request('GET', $matches[1]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->submit($crawler->filter('form[action="'.$matches[1].'"]')->form([
            'fos_user_resetting_form[plainPassword][first]' => 'Nouveau-mot-de-passe-1',
            'fos_user_resetting_form[plainPassword][second]' => 'Nouveau-mot-de-passe-1',
        ]));

        // Logged in, on its profile
        self::assertSame('/me', $client->getResponse()->headers->get('Location'));
        $client->followRedirect();
        self::assertSame('/@user', $client->getResponse()->headers->get('Location'));
        self::assertNull(static::findUser(UserFixtures::USER)->getConfirmationToken());

        // The link works only once
        $client->request('GET', $matches[1]);
        self::assertSame('/login', $client->getResponse()->headers->get('Location'));

        // The new password replaces the old one
        $client->getCookieJar()->clear();
        $this->login($client, UserFixtures::USER, UserFixtures::USER);
        self::assertSame('https://lairdubois.test/login', $client->getResponse()->headers->get('Location'));
        $this->login($client, UserFixtures::USER, 'Nouveau-mot-de-passe-1');
        self::assertSame('https://lairdubois.test/', $client->getResponse()->headers->get('Location'));
    }

    public function testResetPasswordOfUnknownUser(): void
    {
        $client = static::createClient();

        $this->requestReset($client, 'inconnu');

        // As for a known user: the page does not tell whether the account exists
        self::assertSame('/resetting/check-email?username=inconnu', $client->getResponse()->headers->get('Location'));
        self::assertCount(0, static::getSentEmails($client));
    }

    public function testResetPasswordAlreadyRequested(): void
    {
        $client = static::createClient();
        $this->requestReset($client, UserFixtures::USER);
        self::assertCount(1, static::getSentEmails($client));

        $this->requestReset($client, UserFixtures::USER);

        self::assertSame('/resetting/check-email?username=user', $client->getResponse()->headers->get('Location'));
        self::assertCount(0, static::getSentEmails($client));
    }

    public function testResetPasswordLogsIn(): void
    {
        $client = static::createClient();

        $this->resetPassword($client, UserFixtures::USER, 'Nouveau-mot-de-passe-1');

        self::assertNotNull(static::findUser(UserFixtures::USER)->getLastLogin());
        $client->followRedirect();
        $crawler = $client->followRedirect();
        self::assertSame('/@user', $client->getRequest()->getPathInfo());
        self::assertStringContainsString('Le mot de passe a été réinitialisé avec succès.', $crawler->filter('.alert-success')->text(null, false));
    }

    /**
     * The new password logs in without remember me: the cookie of a previous login is deleted.
     */
    public function testResetPasswordDeletesTheRememberMeCookie(): void
    {
        $client = static::createClient();
        $this->login($client, UserFixtures::USER2, UserFixtures::USER2);
        self::assertNotNull($client->getCookieJar()->get('REMEMBERME'));

        $this->resetPassword($client, UserFixtures::USER, 'Nouveau-mot-de-passe-1');

        $cookies = array_values(array_filter($client->getResponse()->headers->getCookies(), static function (Cookie $cookie): bool {
            return 'REMEMBERME' === $cookie->getName();
        }));
        self::assertCount(1, $cookies);
        self::assertTrue($cookies[0]->isCleared());
        self::assertNull($client->getCookieJar()->get('REMEMBERME'));
        $client->request('GET', '/@me/parametres');
        self::assertSame('/@user/parametres', $client->getResponse()->headers->get('Location'));
    }

    /**
     * As FOSUserBundle, for which "enabled" means a proven email address: the new password enables
     * the account again and logs it in, even when an admin disabled it (#142).
     */
    public function testResetPasswordEnablesADisabledAccount(): void
    {
        $client = static::createClient();
        static::getEntityManager()->getConnection()->update('tbl_core_user', ['enabled' => 0], ['username_canonical' => UserFixtures::USER]);

        $this->resetPassword($client, UserFixtures::USER, 'Nouveau-mot-de-passe-1');

        self::assertTrue(static::findUser(UserFixtures::USER)->isEnabled());
        $client->request('GET', '/@me/parametres');
        self::assertSame('/@user/parametres', $client->getResponse()->headers->get('Location'));
    }

    /**
     * The link of the email is valid 24 hours after the request.
     */
    public function testResetPasswordWithExpiredLink(): void
    {
        $client = static::createClient();
        $this->setPasswordRequest(UserFixtures::USER, 'valid-token', new \DateTimeImmutable('-23 hours'));
        $this->setPasswordRequest(UserFixtures::USER2, 'expired-token', new \DateTimeImmutable('-24 hours -1 minute'));

        $client->request('GET', '/resetting/reset/valid-token');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/resetting/reset/expired-token');
        self::assertSame('/resetting/request', $client->getResponse()->headers->get('Location'));
    }

    public function testResetPasswordWithInvalidToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/resetting/reset/invalid');

        self::assertSame('/login', $client->getResponse()->headers->get('Location'));
    }

    public function testCheckEmailPage(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/resetting/check-email?username=user');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Un e-mail a été envoyé.', $crawler->filter('.panel-body')->text(null, false));
    }

    private function requestReset(KernelBrowser $client, string $username): void
    {
        $crawler = $client->request('GET', '/resetting/request');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->enableProfiler();
        $client->submit($crawler->filter('form[action="/resetting/send-email"]')->form(['username' => $username]));
    }

    /**
     * Requests a reset, then submits the new password through the link of the email.
     */
    private function resetPassword(KernelBrowser $client, string $username, string $password): void
    {
        $this->requestReset($client, $username);
        self::assertSame(1, preg_match('{https://lairdubois\.test(/resetting/reset/[\w-]+)}', static::getSentEmails($client)[0]->getTextBody(), $matches));

        $crawler = $client->request('GET', $matches[1]);
        $client->submit($crawler->filter('form[action="'.$matches[1].'"]')->form([
            'fos_user_resetting_form[plainPassword][first]' => $password,
            'fos_user_resetting_form[plainPassword][second]' => $password,
        ]));
        self::assertSame('/me', $client->getResponse()->headers->get('Location'));
    }

    private function setPasswordRequest(string $username, string $token, \DateTimeImmutable $requestedAt): void
    {
        static::getEntityManager()->getConnection()->update('tbl_core_user', ['confirmation_token' => $token, 'password_requested_at' => $requestedAt->format('Y-m-d H:i:s')], ['username_canonical' => $username]);
    }

    private function login(KernelBrowser $client, string $username, string $password): void
    {
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->filter('form[action$="/login_check"]')->form([
            '_username' => $username,
            '_password' => $password,
        ]));
    }
}
