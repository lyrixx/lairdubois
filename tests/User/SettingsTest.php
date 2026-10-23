<?php

namespace Tests\User;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\UserWitness;
use Ladb\CoreBundle\Utils\CryptoUtils;
use Symfony\Bundle\FrameworkBundle\Client;
use Tests\Double\OfflineLocalisableUtils;
use Tests\WebTestCase;

/**
 * Settings of an account (/@{username}/parametres), password change and email unsubscription.
 */
final class SettingsTest extends WebTestCase
{
    public function testEditOwnSettings(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->submitSettings($client, UserFixtures::USER, [
            'ladb_usersettings[displayname]' => 'Menuisière du dimanche',
            'ladb_usersettings[location]' => 'Bordeaux, France',
        ]);

        self::assertSame('/@user', $client->getResponse()->headers->get('Location'));
        $user = static::findUser(UserFixtures::USER);
        self::assertSame('Menuisière du dimanche', $user->getDisplayname());
        self::assertSame('Bordeaux, France', $user->getLocation());
        // Geocoded (OfflineLocalisableUtils in test)
        self::assertSame(OfflineLocalisableUtils::ADDRESSES['Bordeaux, France']['latitude'], $user->getLatitude());
        self::assertSame(OfflineLocalisableUtils::ADDRESSES['Bordeaux, France']['longitude'], $user->getLongitude());

        $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Menuisière du dimanche', $client->getResponse()->getContent());
    }

    public function testChangeUsername(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->submitSettings($client, UserFixtures::USER, ['ladb_usersettings[username]' => 'menuisiere']);

        self::assertSame('/@menuisiere', $client->getResponse()->headers->get('Location'));
        $witness = static::getEntityManager()->getRepository(UserWitness::class)->findOneBy(['username' => UserFixtures::USER]);
        self::assertSame('menuisiere', $witness->getUser()->getUsernameCanonical());

        // The old address redirects to the new one
        $client->request('GET', '/@user');
        self::assertSame('/@menuisiere', $client->getResponse()->headers->get('Location'));
    }

    public function testEditSettingsWithInvalidValues(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $crawler = $this->submitSettings($client, UserFixtures::USER, [
            'ladb_usersettings[username]' => UserFixtures::USER2,
            'ladb_usersettings[email]' => 'user2@lairdubois.test',
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('utilisateur est déjà utilisé.', $client->getResponse()->getContent());
        static::getEntityManager()->clear();
        self::assertSame('user@lairdubois.test', static::findUser(UserFixtures::USER)->getEmail());
    }

    public function testEditSettingsOfAnotherUser(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);

        $client->request('GET', '/@user/parametres');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testEditSettingsAsAdmin(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);

        $this->submitSettings($client, UserFixtures::USER, ['ladb_usersettings[displayname]' => 'Modérée']);

        self::assertSame('/@user', $client->getResponse()->headers->get('Location'));
        self::assertSame('Modérée', static::findUser(UserFixtures::USER)->getDisplayname());
    }

    public function testEditSettingsAnonymous(): void
    {
        $client = static::createClient();

        $client->request('GET', '/@user/parametres');

        self::assertSame('https://lairdubois.test/login', $client->getResponse()->headers->get('Location'));
    }

    public function testChangePassword(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $client->request('GET', '/parametres/change-password');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->submit($crawler->filter('form[action="/parametres/change-password"]')->form([
            'fos_user_change_password_form[current_password]' => UserFixtures::USER,
            'fos_user_change_password_form[plainPassword][first]' => 'Nouveau-mot-de-passe-1',
            'fos_user_change_password_form[plainPassword][second]' => 'Nouveau-mot-de-passe-1',
        ]));

        self::assertSame('/me', $client->getResponse()->headers->get('Location'));
        $client->getCookieJar()->clear();
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->filter('form[action$="/login_check"]')->form([
            '_username' => UserFixtures::USER,
            '_password' => 'Nouveau-mot-de-passe-1',
        ]));
        self::assertSame('https://lairdubois.test/', $client->getResponse()->headers->get('Location'));
    }

    public function testChangePasswordWithWrongCurrentPassword(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $client->request('GET', '/parametres/change-password');

        $crawler = $client->submit($crawler->filter('form[action="/parametres/change-password"]')->form([
            'fos_user_change_password_form[current_password]' => 'wrong',
            'fos_user_change_password_form[plainPassword][first]' => 'Nouveau-mot-de-passe-1',
            'fos_user_change_password_form[plainPassword][second]' => 'Nouveau-mot-de-passe-1',
        ]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Le mot de passe est invalide.', $crawler->filter('form[action="/parametres/change-password"]')->text());
    }

    public function testChangePasswordAnonymous(): void
    {
        $client = static::createClient();

        $client->request('GET', '/parametres/change-password');

        self::assertSame('https://lairdubois.test/login', $client->getResponse()->headers->get('Location'));
    }

    public function testUnsubscribeFromNotifications(): void
    {
        $client = static::createClient();
        $encryptedEmail = $client->getContainer()->get(CryptoUtils::NAME)->encryptString('user@lairdubois.test');

        $client->request('GET', '/email/unsubscribe/notifications/'.$encryptedEmail);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $meta = static::findUser(UserFixtures::USER)->getMeta();
        self::assertFalse($meta->getIncomingMessageEmailNotificationEnabled());
        self::assertFalse($meta->getNewFollowerEmailNotificationEnabled());
        self::assertTrue($meta->getWeekNewsEmailEnabled());
    }

    public function testUnsubscribeFromWeekNews(): void
    {
        $client = static::createClient();
        $encryptedEmail = $client->getContainer()->get(CryptoUtils::NAME)->encryptString('user@lairdubois.test');

        $client->request('GET', '/email/unsubscribe/weeknews/'.$encryptedEmail);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $meta = static::findUser(UserFixtures::USER)->getMeta();
        self::assertFalse($meta->getWeekNewsEmailEnabled());
        self::assertTrue($meta->getIncomingMessageEmailNotificationEnabled());
    }

    /**
     * @param array<string, string> $values
     */
    private function submitSettings(Client $client, string $username, array $values): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $client->request('GET', "/@{$username}/parametres");
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return $client->submit($crawler->filter('form[name="ladb_usersettings"]')->form($values));
    }
}
