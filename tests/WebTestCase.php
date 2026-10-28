<?php

namespace Tests;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\ORM\EntityManagerInterface;
use Elastica\Index;
use Ladb\CoreBundle\Entity\Core\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as BaseWebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Base class of the functional tests: a client on the host of the site, logged in or not, and
 * the database of the fixtures. Each test runs in a transaction rolled back at its end
 * (dama/doctrine-test-bundle), even across the kernel reboots of the client.
 *
 * The rollback reaches neither the Elasticsearch indexes nor uploads/ (shared with the dev
 * environment). When the tests of a class changed an index (publication, deletion...), it is
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

    /** @var array<string, int> */
    private static array $indexWriteCounts = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$indexWriteCounts = self::countIndexWrites();

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
        $container = static::$booted ? static::$kernel->getContainer() : null;
        if (null !== $container) {
            static::getIndexes()->refresh();

            // AbstractController::createLock() keeps a lock per action and user for a few
            // seconds, against double submissions: the next test would get a 404. Memcached
            // holds nothing else but the refresh dates of the unlisted counters of each user
            // (UserUtils), for 3 to 5 minutes.
            $memcached = new \Memcached();
            $memcached->addServer($container->getParameter('memcached_host'), $container->getParameter('memcached_port'));
            $memcached->flush();
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

        // Only the indexes the tests changed: filling the 27 takes much longer
        $changedIndexes = array_keys(array_diff_assoc(self::countIndexWrites(), self::$indexWriteCounts));
        if ([] !== $changedIndexes) {
            $application = new Application(static::bootKernel());
            $application->setAutoExit(false);
            foreach ($changedIndexes as $index) {
                $exitCode = $application->run(new ArrayInput(['command' => 'fos:elastica:populate', '--index' => $index]), $output = new BufferedOutput());
                if (0 !== $exitCode) {
                    static::ensureKernelShutdown();

                    throw new \RuntimeException(\sprintf("The index %s could not be filled again:\n%s", $index, $output->fetch()));
                }
            }
            static::ensureKernelShutdown();
        }

        parent::tearDownAfterClass();
    }

    /**
     * An anonymous client, on HTTPS and on the host of the site.
     */
    protected static function createClient(array $options = [], array $server = []): KernelBrowser
    {
        // The kernel booted before, if any (fixtures read before the client, another client):
        // the parent shuts it down too, but deprecates it and throws from Symfony 5.0
        static::ensureKernelShutdown();

        return parent::createClient($options, $server + ['HTTP_HOST' => self::HOST, 'HTTPS' => 'on']);
    }

    /**
     * A client logged in as one of the accounts of the fixtures (UserFixtures::ADMIN, USER, USER2),
     * without going through the login form.
     */
    protected static function createClientAs(string $username, array $options = [], array $server = []): KernelBrowser
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
        // No kernel yet, or the one of the previous test, shut down
        if (!static::$booted) {
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
     * The emails sent during the last request of the client, which needs $client->enableProfiler()
     * before it. Nothing is delivered (config_test.yml).
     *
     * @return list<\Swift_Message>
     */
    protected static function getSentEmails(KernelBrowser $client): array
    {
        return array_values($client->getProfile()->getCollector('swiftmailer')->getMessages());
    }

    /**
     * The recipients of an email, before delivery_address of config_dev.yml replaced them (it
     * keeps them in the X-Swift-To header).
     *
     * @return list<string>
     */
    protected static function getEmailRecipients(\Swift_Message $message): array
    {
        return array_keys($message->getHeaders()->get('X-Swift-To')->getFieldBodyModel());
    }

    /**
     * Handles a message as the worker of its transport ("messenger:consume view") would, in the
     * container of the last client or kernel: by its handlers, without sending it again.
     */
    protected static function consumeMessage(object $message, string $transportName): void
    {
        static::getContainer()->get('messenger.default_bus')->dispatch(new Envelope($message, [new ReceivedStamp($transportName)]));
    }

    /**
     * Runs a console command line ("ladb:cron:offers --force") in a new kernel, in the
     * transaction of the test: its changes are rolled back with it. Its exceptions are not
     * caught. The kernel stays booted after it: static::getEntityManager() and the services are
     * the ones of the command.
     *
     * @return array{int, string} The exit code and the output
     */
    protected static function runCommand(string $commandLine): array
    {
        $application = new Application(static::bootKernel());
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        // No signal handler: the console of Symfony 5.2+ registers one per run (pcntl), which chains
        // the previous ones and keeps their application, kernel and command until the end of the
        // process
        $application->setSignalsToDispatchEvent();
        $exitCode = $application->run(new StringInput($commandLine), $output = new BufferedOutput());

        return [$exitCode, $output->fetch()];
    }

    /**
     * The emails sent by the last static::runCommand(). Nothing is delivered (config_test.yml).
     *
     * @return list<\Swift_Message>
     */
    protected static function getSentEmailsByCommand(): array
    {
        // The logger keeps a copy of each message twice: when the command sends it to the memory
        // spool, then when the end of the command flushes the spool
        $messages = [];
        foreach (static::$kernel->getContainer()->get('swiftmailer.mailer.default.plugin.messagelogger')->getMessages() as $message) {
            $messages[$message->getId()] = $message;
        }

        return array_values($messages);
    }

    /**
     * The indexes of the environment, one per type of content (config.yml), as a single one: a
     * single request refreshes or reads all of them.
     */
    protected static function getIndexes(): Index
    {
        return static::getContainer()->get('fos_elastica.client.default')->getIndex(static::getContainer()->getParameter('elasticsearch_index_prefix').'*');
    }

    /**
     * Documents indexed and deleted since the creation of each index, by its name in config.yml.
     *
     * @return array<string, int>
     */
    private static function countIndexWrites(): array
    {
        // Out of a test, a plain connection: when this boot rebuilds the container, its cache
        // warmers connect to the database, and the connection dama/doctrine-test-bundle keeps
        // for the tests would start with a transaction the first test cannot begin again
        $keepStaticConnections = StaticDriver::isKeepStaticConnections();
        StaticDriver::setKeepStaticConnections(false);
        try {
            static::bootKernel();
            $prefix = static::getContainer()->getParameter('elasticsearch_index_prefix');
            $counts = [];
            foreach (static::getIndexes()->getStats()->getData()['indices'] as $name => $stats) {
                $indexing = $stats['primaries']['indexing'];
                $counts[substr($name, \strlen($prefix))] = $indexing['index_total'] + $indexing['delete_total'];
            }
            static::ensureKernelShutdown();
        } finally {
            StaticDriver::setKeepStaticConnections($keepStaticConnections);
        }

        return $counts;
    }
}
