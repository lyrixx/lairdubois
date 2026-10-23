<?php

namespace Tests;

use Doctrine\ORM\EntityManagerInterface;
use Ladb\CoreBundle\Entity\Core\User;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as BaseWebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Base class of the functional tests: a client on the host of the site, logged in or not, and
 * the database of the fixtures. Each test runs in a transaction rolled back at its end
 * (dama/doctrine-test-bundle), even across the kernel reboots of the client.
 *
 * The rollback reaches neither the Elasticsearch index nor uploads/ (shared with the dev
 * environment). When the tests of a class changed the index (publication, deletion...), it is
 * filled again from the database after the last one. The files a test deletes from uploads/ (the
 * pictures of a deleted content...) are put back after it; the ones it creates stay.
 */
abstract class WebTestCase extends BaseWebTestCase
{
    // trusted_hosts of config_dev.yml: any other host answers 400
    private const HOST = 'lairdubois.test';

    // Firewall of security.yml, whose context is its name
    private const FIREWALL = 'main';

    private const UPLOADS_DIR = __DIR__.'/../uploads';

    // Hard links to the files of uploads/, on the same filesystem: they keep a deleted file
    private const UPLOADS_BACKUP_DIR = __DIR__.'/../var/tests/uploads';

    private static ?int $indexWriteCount = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$indexWriteCount = self::countIndexWrites();

        $fs = new Filesystem();
        $fs->remove(self::UPLOADS_BACKUP_DIR);
        $fs->mkdir(self::UPLOADS_BACKUP_DIR);
        foreach (new \FilesystemIterator(self::UPLOADS_DIR) as $file) {
            if ($file->isFile()) {
                link($file->getPathname(), self::UPLOADS_BACKUP_DIR.'/'.$file->getFilename());
            }
        }
    }

    protected function tearDown(): void
    {
        // The writes to the index are searchable after its next refresh, every second: the next
        // test sees all of them, whatever the time between the two
        $container = null === static::$kernel ? null : static::$kernel->getContainer();
        if (null !== $container) {
            $container->get('fos_elastica.index.ladb')->refresh();
        }

        foreach (new \FilesystemIterator(self::UPLOADS_BACKUP_DIR) as $file) {
            if (!file_exists(self::UPLOADS_DIR.'/'.$file->getFilename())) {
                link($file->getPathname(), self::UPLOADS_DIR.'/'.$file->getFilename());
            }
        }

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(self::UPLOADS_BACKUP_DIR);

        if (self::countIndexWrites() !== self::$indexWriteCount) {
            $application = new Application(static::bootKernel());
            $application->setAutoExit(false);
            $exitCode = $application->run(new ArrayInput(['command' => 'fos:elastica:populate']), $output = new BufferedOutput());
            static::ensureKernelShutdown();
            if (0 !== $exitCode) {
                throw new \RuntimeException("The index could not be filled again:\n".$output->fetch());
            }
        }

        parent::tearDownAfterClass();
    }

    /**
     * An anonymous client, on HTTPS and on the host of the site.
     */
    protected static function createClient(array $options = [], array $server = []): Client
    {
        return parent::createClient($options, $server + ['HTTP_HOST' => self::HOST, 'HTTPS' => 'on']);
    }

    /**
     * A client logged in as one of the accounts of the fixtures (UserFixtures::ADMIN, USER, USER2),
     * without going through the login form.
     */
    protected static function createClientAs(string $username, array $options = [], array $server = []): Client
    {
        $client = static::createClient($options, $server);
        $user = static::findUser($username);

        $session = $client->getContainer()->get('session');
        $session->set('_security_'.self::FIREWALL, serialize(new UsernamePasswordToken($user, null, self::FIREWALL, $user->getRoles())));
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $client;
    }

    /**
     * The entity manager of the kernel of the last client: the one its requests use until the
     * next one (the client reboots the kernel before each request, except the first one).
     */
    protected static function getEntityManager(): EntityManagerInterface
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }

        return static::$kernel->getContainer()->get('doctrine')->getManager();
    }

    protected static function findUser(string $username): User
    {
        $user = static::getEntityManager()->getRepository(User::class)->findOneBy(['usernameCanonical' => $username]);
        if (null === $user) {
            throw new \LogicException(\sprintf('The fixtures have no user "%s".', $username));
        }

        return $user;
    }

    /**
     * Documents indexed and deleted since the creation of the index.
     */
    private static function countIndexWrites(): int
    {
        $indexing = static::bootKernel()->getContainer()->get('fos_elastica.index.ladb')->getStats()->get('_all', 'primaries', 'indexing');
        static::ensureKernelShutdown();

        return $indexing['index_total'] + $indexing['delete_total'];
    }
}
