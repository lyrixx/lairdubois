<?php

namespace Tests\User;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Registration;
use Ladb\CoreBundle\Entity\Message\ThreadMeta;
use Symfony\Bundle\FrameworkBundle\Client;
use Tests\WebTestCase;

/**
 * Registration (/salut/, FOSUserBundle, without its email confirmation), then the confirmation of
 * the email address of the site (/email/check, /email/confirm/{token}).
 */
final class RegistrationTest extends WebTestCase
{
    public function testRegister(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/salut/');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('form[action="/salut/"]')->form([
            'fos_user_registration_form[username]' => 'nouveau',
            'fos_user_registration_form[email]' => 'nouveau@lairdubois.test',
            'fos_user_registration_form[plainPassword][first]' => 'Copeaux-de-chene-1',
            'fos_user_registration_form[plainPassword][second]' => 'Copeaux-de-chene-1',
        ]);
        $client->enableProfiler();
        $client->submit($form);

        self::assertSame('/salut/confirmed', $client->getResponse()->headers->get('Location'));

        // Notification to the team of the site
        $messages = static::getSentEmails($client);
        self::assertCount(1, $messages);
        self::assertSame(['contact@lairdubois.fr'], static::getEmailRecipients($messages[0]));
        self::assertSame('Notification de nouvel utilisateur', $messages[0]->getSubject());

        $user = static::findUser('nouveau');
        self::assertTrue($user->isEnabled());
        self::assertFalse($user->getEmailConfirmed());
        self::assertNotNull($user->getAvatar());
        $em = static::getEntityManager();
        self::assertNotNull($em->getRepository(Registration::class)->findOneBy(['user' => $user]));

        // Welcome message from admin_username
        $threadMeta = $em->getRepository(ThreadMeta::class)->findOneBy(['participant' => $user]);
        self::assertNotNull($threadMeta);
        self::assertSame('Bienvenue !', $threadMeta->getThread()->getSubject());
        self::assertSame(UserFixtures::ADMIN, $threadMeta->getThread()->getCreatedBy()->getUsername());

        // Logged in after the registration
        $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('GET', '/@me/parametres');
        self::assertSame('/@nouveau/parametres', $client->getResponse()->headers->get('Location'));

        // Then with its password
        $client->getCookieJar()->clear();
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->filter('form[action$="/login_check"]')->form([
            '_username' => 'nouveau',
            '_password' => 'Copeaux-de-chene-1',
        ]));
        $client->request('GET', '/@me/parametres');
        self::assertSame('/@nouveau/parametres', $client->getResponse()->headers->get('Location'));
    }

    public function testRegisterWithTakenUsernameAndEmail(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/salut/');

        $crawler = $client->submit($crawler->filter('form[action="/salut/"]')->form([
            'fos_user_registration_form[username]' => UserFixtures::USER,
            'fos_user_registration_form[email]' => 'user2@lairdubois.test',
            'fos_user_registration_form[plainPassword][first]' => 'Copeaux-de-chene-1',
            'fos_user_registration_form[plainPassword][second]' => 'Copeaux-de-chene-2',
        ]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $errors = $crawler->filter('form[action="/salut/"]')->text();
        self::assertStringContainsString("Le nom d'utilisateur est déjà utilisé.", $errors);
        self::assertStringContainsString("L'adresse e-mail est déjà utilisée.", $errors);
        self::assertStringContainsString('Les deux mots de passe ne sont pas identiques.', $errors);
    }

    public function testConfirmEmail(): void
    {
        $client = $this->createClientAsUnconfirmed(UserFixtures::USER);

        $client->enableProfiler();
        $client->request('GET', '/email/check');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $messages = static::getSentEmails($client);
        self::assertCount(1, $messages);
        self::assertSame(['user@lairdubois.test'], static::getEmailRecipients($messages[0]));
        self::assertSame('Confirmation de votre adresse e-mail', $messages[0]->getSubject());
        self::assertSame(1, preg_match('{https://lairdubois\.test(/email/confirm/[\w-]+)}', $messages[0]->getBody(), $matches));

        $client->request('GET', $matches[1]);

        self::assertSame('/', $client->getResponse()->headers->get('Location'));
        $user = static::findUser(UserFixtures::USER);
        self::assertTrue($user->getEmailConfirmed());
        self::assertNull($user->getConfirmationToken());
    }

    public function testConfirmEmailWithInvalidToken(): void
    {
        $client = $this->createClientAsUnconfirmed(UserFixtures::USER);

        $client->request('GET', '/email/confirm/invalid');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertFalse(static::findUser(UserFixtures::USER)->getEmailConfirmed());
    }

    public function testConfirmEmailOfAnotherUser(): void
    {
        static::getEntityManager()->getConnection()->update('tbl_core_user', ['email_confirmed' => 0, 'confirmation_token' => 'token-of-user2'], ['username_canonical' => UserFixtures::USER2]);
        $client = $this->createClientAsUnconfirmed(UserFixtures::USER);

        $client->request('GET', '/email/confirm/token-of-user2');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertFalse(static::findUser(UserFixtures::USER2)->getEmailConfirmed());
    }

    public function testConfirmEmailAnonymous(): void
    {
        $client = static::createClient();

        $client->request('GET', '/email/check');

        self::assertSame('https://lairdubois.test/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * The accounts of the fixtures have a confirmed email address.
     */
    private function createClientAsUnconfirmed(string $username): Client
    {
        static::getEntityManager()->getConnection()->update('tbl_core_user', ['email_confirmed' => 0], ['username_canonical' => $username]);

        return static::createClientAs($username);
    }
}
