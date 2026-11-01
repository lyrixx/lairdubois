<?php

namespace Tests\User;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Manager\Core\UserManager;
use Tests\WebTestCase;

/**
 * The plain password is erased once hashed (UserManager), and never written in the session: neither
 * __serialize() nor serialize() of User write it (#222). Symfony no longer erases it after the
 * authentication (User::eraseCredentials(), deprecated in 7.3, removed in 8.0).
 */
final class PlainPasswordTest extends WebTestCase
{
    private const PASSWORD = 'Copeaux-de-chene-1';

    public function testUpdateUserErasesThePlainPasswordOnceHashed(): void
    {
        $user = static::findUser(UserFixtures::USER);
        $user->setPlainPassword(self::PASSWORD);

        static::getContainer()->get(UserManager::class)->updateUser($user);

        self::assertNull($user->getPlainPassword());
        self::assertTrue(password_verify(self::PASSWORD, $user->getPassword()));
    }

    public function testSerializationLeavesThePlainPasswordOut(): void
    {
        $user = new User();
        $user->setUsername(UserFixtures::USER);
        $user->setPassword('hash');
        $user->setPlainPassword(self::PASSWORD);

        self::assertStringNotContainsString(self::PASSWORD, serialize($user));
        self::assertStringNotContainsString(self::PASSWORD, $user->serialize());
        self::assertStringContainsString('hash', serialize($user));
    }

    public function testRegistrationLeavesNoPlainPasswordInTheSession(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/salut/');
        $client->submit($crawler->filter('form[action="/salut/"]')->form([
            'fos_user_registration_form[username]' => 'nouveau',
            'fos_user_registration_form[email]' => 'nouveau@lairdubois.test',
            'fos_user_registration_form[plainPassword][first]' => self::PASSWORD,
            'fos_user_registration_form[plainPassword][second]' => self::PASSWORD,
        ]));
        self::assertSame('/salut/confirmed', $client->getResponse()->headers->get('Location'));

        $token = $client->getRequest()->getSession()->get('_security_main');
        self::assertStringContainsString('"nouveau"', $token);
        self::assertStringNotContainsString(self::PASSWORD, $token);
    }

    /**
     * The form sets the plain password on the user of the token, that a refused form neither hashes
     * nor erases: the session gets the user without it all the same.
     */
    public function testRefusedChangePasswordLeavesNoPlainPasswordInTheSession(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $client->request('GET', '/parametres/change-password');
        $client->submit($crawler->filter('form[action="/parametres/change-password"]')->form([
            'fos_user_change_password_form[current_password]' => 'wrong',
            'fos_user_change_password_form[plainPassword][first]' => self::PASSWORD,
            'fos_user_change_password_form[plainPassword][second]' => self::PASSWORD,
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $token = $client->getRequest()->getSession()->get('_security_main');
        self::assertStringContainsString('"user"', $token);
        self::assertStringNotContainsString(self::PASSWORD, $token);
    }
}
