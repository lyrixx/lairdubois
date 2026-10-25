<?php

namespace Tests\User;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Symfony\Bundle\FrameworkBundle\Client;
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

        self::assertSame('/resetting/check-email?username=user%40lairdubois.test', $client->getResponse()->headers->get('Location'));
        $messages = static::getSentEmails($client);
        self::assertCount(1, $messages);
        self::assertSame(['user@lairdubois.test'], static::getEmailRecipients($messages[0]));
        self::assertSame(1, preg_match('{https://lairdubois\.test(/resetting/reset/[\w-]+)}', $messages[0]->getBody(), $matches));

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
        self::assertStringContainsString('Un e-mail a été envoyé.', $crawler->filter('.panel-body')->text());
    }

    private function requestReset(Client $client, string $username): void
    {
        $crawler = $client->request('GET', '/resetting/request');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->enableProfiler();
        $client->submit($crawler->filter('form[action="/resetting/send-email"]')->form(['username' => $username]));
    }

    private function login(Client $client, string $username, string $password): void
    {
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->filter('form[action$="/login_check"]')->form([
            '_username' => $username,
            '_password' => $password,
        ]));
    }
}
