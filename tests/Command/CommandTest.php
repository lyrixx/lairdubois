<?php

namespace Tests\Command;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Tests\WebTestCase;

/**
 * Runs every command of the ladb namespace on the fixtures, verbose, with its main options: none
 * may fail. The changes of --force are rolled back with the test.
 *
 * The lines of the crontab of the production are in CrontabTest, with the effects of the crons.
 */
final class CommandTest extends WebTestCase
{
    // Without --force, most commands only tell what they would do
    private const DRY_RUN_AND_FORCE = ['', '--force'];

    /**
     * The options of each run of a command. A new command without an entry here or in UNTESTED
     * fails the test.
     *
     * @var array<string, list<string>>
     */
    private const COMMANDS = [
        'ladb:change:owner' => [
            '--target-username='.UserFixtures::USER2.' --entity-type='.Creation::TYPE.' --entity-id=1',
            '--target-username='.UserFixtures::USER2.' --entity-type='.Creation::TYPE.' --entity-id=1 --force',
        ],
        'ladb:cleanup:finishes' => self::DRY_RUN_AND_FORCE,
        'ladb:cleanup:joins' => self::DRY_RUN_AND_FORCE,
        'ladb:cleanup:likes' => self::DRY_RUN_AND_FORCE,
        'ladb:cleanup:pictures' => self::DRY_RUN_AND_FORCE,
        'ladb:cleanup:resources' => self::DRY_RUN_AND_FORCE,
        'ladb:cleanup:tools' => self::DRY_RUN_AND_FORCE,
        // --force deletes the files of uploads/ the test database does not know: the ones of the
        // dev environment
        'ladb:cleanup:uploads' => [''],
        'ladb:cleanup:votes' => self::DRY_RUN_AND_FORCE,
        'ladb:cleanup:watches' => self::DRY_RUN_AND_FORCE,
        'ladb:cleanup:woods' => self::DRY_RUN_AND_FORCE,
        'ladb:cron:notification' => self::DRY_RUN_AND_FORCE,
        'ladb:cron:notification:email' => self::DRY_RUN_AND_FORCE,
        'ladb:cron:notification:populate' => self::DRY_RUN_AND_FORCE,
        'ladb:cron:offers' => self::DRY_RUN_AND_FORCE,
        'ladb:cron:opencutlist:access:analyze' => self::DRY_RUN_AND_FORCE,
        // --force writes web/sitemap-*.xml: CrontabTest puts them back
        'ladb:cron:sitemaps' => [''],
        'ladb:cron:spotlight' => ['', '--force', '--force --no-posting', '--force-twitter --force-facebook --force-mastodon'],
        'ladb:cron:workflow:thumbnails' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:articlessortindex' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:bodyblockpicturecount' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:changedat' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:completioncounters' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:contributioncounters' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:defaultavatars' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:heightratio100' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:htmlbodies' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:licenseversion' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:likes' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:picturecenter' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:resourceskinds' => self::DRY_RUN_AND_FORCE,
        // No --force: it always writes
        'ladb:generate:tagusages' => [''],
        'ladb:generate:textures' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:visibility' => self::DRY_RUN_AND_FORCE,
        'ladb:generate:votecount' => self::DRY_RUN_AND_FORCE,
        // doctrine:schema:update --force, with nothing to update: the test database is in sync with
        // the mapping (doctrine:schema:validate in the CI)
        'ladb:init' => [''],
        'ladb:mailing:weeknews' => ['', '--force --limit=1'],
        'ladb:migrate:finds' => self::DRY_RUN_AND_FORCE,
        'ladb:migrate:signs' => self::DRY_RUN_AND_FORCE,
        'ladb:migrate:users' => self::DRY_RUN_AND_FORCE,
        'ladb:reset:stickers' => self::DRY_RUN_AND_FORCE,
        'ladb:reset:unreadmessagecount' => self::DRY_RUN_AND_FORCE,
        'ladb:reset:updatedat' => self::DRY_RUN_AND_FORCE,
    ];

    /**
     * Commands which cannot run in a test, with the reason.
     *
     * @var array<string, string>
     */
    private const UNTESTED = [
        'ladb:backup:dumpdb' => 'mysqldump is in no image, and it would dump the database of the database_name parameter, not the test one.',
        'ladb:clear' => 'It drops and creates the test database again, out of the transaction of the test.',
        'ladb:load' => 'doctrine:fixtures:load asks for a confirmation on the standard input, then purges the database and loads the fixtures again (castor app:db:fixtures does it before the tests).',
    ];

    /**
     * Command lines known to fail, with their issue. A known failure that passes fails the test:
     * remove it from here once its issue is fixed.
     *
     * @var array<string, int>
     */
    private const KNOWN_FAILURES = [];

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCommandLines(): iterable
    {
        foreach (self::COMMANDS as $name => $runs) {
            foreach ($runs as $options) {
                $commandLine = trim("{$name} {$options}");

                yield $commandLine => [$commandLine];
            }
        }
    }

    /**
     * @dataProvider provideCommandLines
     */
    public function testCommandRuns(string $commandLine): void
    {
        try {
            [$exitCode, $output] = static::runCommand("{$commandLine} --verbose");
            $failure = 0 === $exitCode ? null : "{$commandLine} exits with {$exitCode}:\n{$output}";
        } catch (\Throwable $e) {
            $failure = "{$commandLine} throws ".self::describe($e);
        }

        $issue = self::KNOWN_FAILURES[$commandLine] ?? null;
        if (null !== $issue) {
            if (null === $failure) {
                self::fail("{$commandLine} passes now: remove the known failure (#{$issue}).");
            }
            self::markTestIncomplete("Known failure, see #{$issue}. {$failure}");
        }
        if (null !== $failure) {
            self::fail($failure);
        }
        $this->addToAssertionCount(1);
    }

    /**
     * A DDL statement would commit the transaction of the test.
     */
    public function testInitHasNothingToUpdate(): void
    {
        [$exitCode, $output] = static::runCommand('ladb:init');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Nothing to update', $output);
    }

    public function testChangeOwner(): void
    {
        [$exitCode, $output] = static::runCommand('ladb:change:owner --target-username='.UserFixtures::USER2.' --entity-type='.Creation::TYPE.' --entity-id=1 --force');

        self::assertSame(0, $exitCode, $output);
        static::getEntityManager()->clear();
        self::assertSame(UserFixtures::USER2, static::getEntityManager()->find(Creation::class, 1)->getUser()->getUsername());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUntestedCommands(): iterable
    {
        foreach (self::UNTESTED as $name => $reason) {
            yield $name => [$name, $reason];
        }
    }

    /**
     * Lists the untested commands in the results.
     *
     * @dataProvider provideUntestedCommands
     */
    public function testUntestedCommand(string $name, string $reason): void
    {
        self::markTestSkipped($reason);
    }

    public function testEveryCommandIsListed(): void
    {
        $names = [];
        foreach ((new Application(static::bootKernel()))->all('ladb') as $command) {
            $names[] = $command->getName();
        }

        foreach ($names as $name) {
            self::assertTrue(isset(self::COMMANDS[$name]) || isset(self::UNTESTED[$name]), "The command {$name} has no options in the command test: add some, or list it as untested.");
        }
        foreach ([...array_keys(self::COMMANDS), ...array_keys(self::UNTESTED)] as $name) {
            self::assertContains($name, $names, "The command {$name} does not exist anymore: remove it from the command test.");
        }
        $commandLines = array_keys(iterator_to_array(self::provideCommandLines()));
        foreach (array_keys(self::KNOWN_FAILURES) as $commandLine) {
            self::assertContains($commandLine, $commandLines, "{$commandLine} is not tested anymore: remove it from the known failures.");
        }
    }

    /**
     * The exception, where it is thrown and the last frame of the application before it.
     */
    private static function describe(\Throwable $e): string
    {
        $description = \sprintf('%s: %s (%s:%d', \get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && str_starts_with($frame['file'], \dirname(__DIR__, 2).'/src/') && $frame['file'] !== $e->getFile()) {
                $description .= \sprintf(', from %s:%d', $frame['file'], $frame['line']);

                break;
            }
        }

        return $description.')';
    }
}
