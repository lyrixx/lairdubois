<?php

namespace Tests\Command;

use Tests\WebTestCase;

/**
 * ladb:user:create, which replaces fos:user:create of FOSUserBundle (#24) with the same arguments and
 * options: the first admin of an installation (docs/01-installation.md).
 */
final class UserCreateTest extends WebTestCase
{
    private const PASSWORD = 'Copeaux-de-chene-1';

    public function testCreate(): void
    {
        [$exitCode, $output] = static::runCommand('ladb:user:create Copeau copeau@lairdubois.test '.self::PASSWORD);

        self::assertSame(0, $exitCode, $output);
        self::assertStringEndsWith("Created user Copeau\n", $output);
        $user = static::findUser('copeau');
        self::assertSame('Copeau', $user->getUsername());
        self::assertSame('Copeau', $user->getDisplayname());
        self::assertSame('copeau@lairdubois.test', $user->getEmailCanonical());
        self::assertTrue($user->isEnabled());
        self::assertSame(['ROLE_USER'], $user->getRoles());

        $client = static::createClient();
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->filter('form[action$="/login_check"]')->form([
            '_username' => 'copeau',
            '_password' => self::PASSWORD,
        ]));
        self::assertSame('https://lairdubois.test/', $client->getResponse()->headers->get('Location'));
    }

    public function testCreateInactiveSuperAdmin(): void
    {
        [$exitCode, $output] = static::runCommand('ladb:user:create Copeau copeau@lairdubois.test '.self::PASSWORD.' --super-admin --inactive');

        self::assertSame(0, $exitCode, $output);
        $user = static::findUser('copeau');
        self::assertFalse($user->isEnabled());
        self::assertSame(['ROLE_SUPER_ADMIN', 'ROLE_USER'], $user->getRoles());
    }
}
