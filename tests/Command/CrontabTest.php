<?php

namespace Tests\Command;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Activity;
use Ladb\CoreBundle\Entity\Core\Activity\AbstractActivity;
use Ladb\CoreBundle\Entity\Core\Block\Video;
use Ladb\CoreBundle\Entity\Core\Notification;
use Ladb\CoreBundle\Entity\Core\Spotlight;
use Ladb\CoreBundle\Entity\Core\Witness;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Entity\Opencutlist\Access;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Ladb\CoreBundle\Scheduler\CronScheduleProvider;
use Ladb\CoreBundle\Utils\VideoHostingUtils;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Mime\Email;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Tests\Double\OfflineHttpStream;
use Tests\Double\OfflineVideoHostingUtils;
use Tests\WebTestCase;

/**
 * The crons of the production, as their schedule gives them (CronScheduleProvider, #300), on the
 * fixtures; then what they do, on data prepared for each of them. The changes are rolled back with
 * the test.
 */
final class CrontabTest extends WebTestCase
{
    // Written by ladb:cron:sitemaps in web/, shared with the dev environment: put back after
    // each test
    private const SITEMAPS = __DIR__.'/../../web/sitemap-*.xml';

    /**
     * Crons known to fail, with their issue. A known failure that passes fails the test: remove it
     * from here once its issue is fixed.
     *
     * @var array<string, int>
     */
    private const KNOWN_FAILURES = [];

    // The types of activity, in the order of ladb:cron:notification:email
    private const NOTIFICATION_EMAIL_ACTIVITIES = [
        Activity\Comment::class,
        Activity\Contribute::class,
        Activity\Follow::class,
        Activity\Like::class,
        Activity\Mention::class,
        Activity\Publish::class,
        Activity\Vote::class,
        Activity\Join::class,
        Activity\Write::class,
        Activity\Answer::class,
        Activity\Testify::class,
        Activity\Review::class,
        Activity\Feedback::class,
        Activity\Invite::class,
        Activity\Request::class,
    ];

    /** @var array<string, string> */
    private array $sitemaps = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(self::SITEMAPS) as $file) {
            $this->sitemaps[$file] = file_get_contents($file);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob(self::SITEMAPS) as $file) {
            unlink($file);
        }
        foreach ($this->sitemaps as $file => $content) {
            file_put_contents($file, $content);
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCrons(): iterable
    {
        foreach (self::getCommandLines() as $commandLine) {
            yield $commandLine => [$commandLine];
        }
    }

    /**
     * @dataProvider provideCrons
     */
    public function testCronRuns(string $commandLine): void
    {
        try {
            [$exitCode, $output] = static::runCommandLine($commandLine);
            $failure = 0 === $exitCode ? null : "{$commandLine} exits with {$exitCode}:\n{$output}";
        } catch (\Throwable $e) {
            $failure = "{$commandLine} throws ".\get_class($e).': '.$e->getMessage();
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
     * Each cron, at its time in Paris, put in the queue "cron" by the worker of the schedule
     * (RedispatchMessage), where it is a command line for the handler of Console.
     */
    public function testEachCronIsRedispatchedToTheQueueCron(): void
    {
        $nextRuns = [];
        foreach (self::getRecurringMessages() as $recurringMessage) {
            $trigger = $recurringMessage->getTrigger();
            self::assertInstanceOf(CronExpressionTrigger::class, $trigger);
            $messages = iterator_to_array(self::getMessages($recurringMessage), false);
            self::assertCount(1, $messages);
            self::assertInstanceOf(RedispatchMessage::class, $messages[0]);
            self::assertSame('cron', $messages[0]->transportNames);
            self::assertInstanceOf(RunCommandMessage::class, $messages[0]->envelope);
            $commandLine = $messages[0]->envelope->input;
            self::assertEquals(new RunCommandMessage($commandLine), $messages[0]->envelope);
            $nextRuns[] = [(string) $trigger, $commandLine, $trigger->getNextRunDate(new \DateTimeImmutable('2026-11-13 00:30:00 UTC'))->format(\DATE_ATOM)];
        }

        // The crontab of the production server, at the time of Paris: 03:00 is 02:00 UTC in November
        self::assertSame([
            ['0 4 * * 5', 'ladb:mailing:weeknews --force', '2026-11-13T04:00:00+01:00'],
            ['0 * * * *', 'ladb:cron:spotlight --force', '2026-11-13T02:00:00+01:00'],
            ['*/2 * * * *', 'ladb:cron:notification:populate --force', '2026-11-13T01:32:00+01:00'],
            ['0 7,11,17,20 * * *', 'ladb:cron:notification:email --force', '2026-11-13T07:00:00+01:00'],
            ['0 3 * * *', 'ladb:cron:sitemaps --force', '2026-11-13T03:00:00+01:00'],
            ['*/5 * * * *', 'ladb:cron:workflow:thumbnails --force', '2026-11-13T01:35:00+01:00'],
            ['0 3 * * *', 'ladb:cron:offers --force', '2026-11-13T03:00:00+01:00'],
            ['*/5 * * * *', 'ladb:cron:opencutlist:access:analyze --force', '2026-11-13T01:35:00+01:00'],
        ], $nextRuns);
    }

    /**
     * The schedule of the container keeps its state and takes its lock (Memcached, config.yml), and
     * runs only the last missed run of a cron.
     */
    public function testScheduleIsStatefulAndLocked(): void
    {
        $schedule = static::getContainer()->get(CronScheduleProvider::class);

        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertCount(\count(self::getRecurringMessages()), $schedule->getRecurringMessages());
        self::assertNotNull($schedule->getState());
        self::assertNotNull($schedule->getLock());
        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun());
    }

    /**
     * The worker of the queue "cron" runs the command of the cron (RunCommandMessageHandler of
     * Console), in its kernel.
     */
    public function testCronRunsInTheWorker(): void
    {
        $notificationCount = $this->countNotifications();
        static::getEntityManager()->createQuery('DELETE '.Notification::class.' n')->execute();
        static::getEntityManager()->createQuery('UPDATE '.AbstractActivity::class.' a SET a.isPendingNotifications = true')->execute();
        static::scheduleCron(self::getCron('ladb:cron:notification:populate'));

        $queued = iterator_to_array(static::getContainer()->get('tests.cron_transport')->get(), false);
        self::assertCount(1, $queued);
        self::assertEquals(new RunCommandMessage('ladb:cron:notification:populate --force'), $queued[0]->getMessage());
        // As the worker of the queue
        static::getContainer()->get('messenger.default_bus')->dispatch($queued[0]->with(new ReceivedStamp('cron')));

        self::assertSame($notificationCount, $this->countNotifications());
    }

    /**
     * A creation of the last 7 days with 15 likes or more is put in the spotlight, and its author
     * gets an email. Twitter and Facebook are disabled in the code, Mastodon is offline
     * (config_test.yml).
     */
    public function testSpotlight(): void
    {
        $this->update(Creation::class, 1, ['likeCount' => 15, 'createdAt' => new \DateTime()]);

        [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:cron:spotlight'));

        self::assertSame(0, $exitCode, $output);
        $spotlight = static::getEntityManager()->getRepository(Spotlight::class)->findOneLast();
        self::assertNotNull($spotlight);
        self::assertSame([Creation::TYPE, 1], [$spotlight->getEntityType(), $spotlight->getEntityId()]);
        self::assertSame($spotlight, static::getEntityManager()->find(Creation::class, 1)->getSpotlight());
        $this->assertEmailsSentTo([UserFixtures::USER]);
    }

    /**
     * An offer is unpublished 30 days after its last change, and its author gets an email.
     */
    public function testExpiredOfferIsUnpublished(): void
    {
        $this->update(Offer::class, 1, ['changedAt' => (new \DateTime())->modify('-'.Offer::ACTIVE_LIFETIME)->modify('-1 day')]);
        [$publicOfferCount, $privateOfferCount, $commentCount, $sentLikeCount] = self::getOfferCounters();

        [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:cron:offers'));

        self::assertSame(0, $exitCode, $output);
        self::assertTrue(static::getEntityManager()->find(Offer::class, 1)->getIsDraft());
        self::assertNotNull(self::findOfferWitness());
        self::assertSame([$publicOfferCount - 1, $privateOfferCount + 1, $commentCount - 1, $sentLikeCount - 1], self::getOfferCounters());
        self::assertSame(['ladb.publication.unpublished'], self::getPublicationEventsByCommand());
        $this->assertEmailsSentTo([UserFixtures::USER]);
    }

    /**
     * An offer is deleted 60 days after its last change.
     */
    public function testOutdatedOfferIsDeleted(): void
    {
        $this->update(Offer::class, 1, ['changedAt' => (new \DateTime())->modify('-'.Offer::FULL_LIFETIME)->modify('-1 day')]);

        [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:cron:offers'));

        self::assertSame(0, $exitCode, $output);
        static::getEntityManager()->clear();
        self::assertNull(static::getEntityManager()->find(Offer::class, 1));
        self::assertSame(['ladb.publication.deleted'], self::getPublicationEventsByCommand());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOfferLifetimes(): iterable
    {
        yield 'expired' => [Offer::ACTIVE_LIFETIME];
        yield 'outdated' => [Offer::FULL_LIFETIME];
    }

    /**
     * Without --force, only the output tells what the cron would do.
     *
     * @dataProvider provideOfferLifetimes
     */
    public function testOffersCronChangesNothingWithoutForce(string $lifetime): void
    {
        $this->update(Offer::class, 1, ['changedAt' => (new \DateTime())->modify('-'.$lifetime)->modify('-1 day')]);
        $counters = self::getOfferCounters();

        [$exitCode, $output] = static::runCommandLine('ladb:cron:offers --verbose');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('[FAKE]', $output);
        static::getEntityManager()->clear();
        $offer = static::getEntityManager()->find(Offer::class, 1);
        self::assertNotNull($offer);
        self::assertFalse($offer->getIsDraft());
        self::assertTrue($offer->getIsPublic());
        self::assertNull(self::findOfferWitness());
        self::assertSame($counters, self::getOfferCounters());
        self::assertSame([], self::getPublicationEventsByCommand());
        self::assertSame([], static::getSentEmailsByCommand());
    }

    /**
     * The entity manager forgets each offer once it is done: the next ones are unpublished too.
     */
    public function testEveryExpiredOfferIsUnpublished(): void
    {
        static::runCommandLine('ladb:fixtures:volume --count=2');
        static::getEntityManager()->createQuery('UPDATE '.Offer::class.' o SET o.changedAt = :date')
            ->setParameter('date', (new \DateTime())->modify('-'.Offer::ACTIVE_LIFETIME)->modify('-1 day'))
            ->execute();
        $offerCount = static::getEntityManager()->getRepository(Offer::class)->count();
        self::assertGreaterThan(1, $offerCount);

        [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:cron:offers'));

        self::assertSame(0, $exitCode, $output);
        self::assertSame($offerCount, static::getEntityManager()->getRepository(Offer::class)->count(['isDraft' => true]));
        self::assertCount($offerCount, static::getSentEmailsByCommand());
    }

    /**
     * The entity manager forgets each offer once it is done: the next ones are deleted too.
     */
    public function testEveryOutdatedOfferIsDeleted(): void
    {
        static::runCommandLine('ladb:fixtures:volume --count=2');
        static::getEntityManager()->createQuery('UPDATE '.Offer::class.' o SET o.changedAt = :date, o.publishCount = 1')
            ->setParameter('date', (new \DateTime())->modify('-'.Offer::FULL_LIFETIME)->modify('-1 day'))
            ->execute();
        self::assertGreaterThan(1, static::getEntityManager()->getRepository(Offer::class)->count());

        [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:cron:offers'));

        self::assertSame(0, $exitCode, $output);
        self::assertSame(0, static::getEntityManager()->getRepository(Offer::class)->count());
    }

    /**
     * The activities give their notifications again (castor app:db:fixtures runs this cron once
     * on the activities of the fixtures).
     */
    public function testNotificationPopulate(): void
    {
        $notificationCount = $this->countNotifications();
        self::assertGreaterThan(0, $notificationCount);
        static::getEntityManager()->createQuery('DELETE '.Notification::class.' n')->execute();
        static::getEntityManager()->createQuery('UPDATE '.AbstractActivity::class.' a SET a.isPendingNotifications = true')->execute();

        [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:cron:notification:populate'));

        self::assertSame(0, $exitCode, $output);
        self::assertSame($notificationCount, $this->countNotifications());
        self::assertSame([], static::getEntityManager()->getRepository(AbstractActivity::class)->findByPendingNotifications());
    }

    /**
     * The notifications of the fixtures wait for their email: each recipient gets one by type of
     * activity.
     */
    public function testNotificationEmail(): void
    {
        self::assertGreaterThan(0, $this->countNotificationsPendingEmail());

        [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:cron:notification:email'));

        self::assertSame(0, $exitCode, $output);
        self::assertNotEmpty(static::getSentEmailsByCommand());
        self::assertSame(0, $this->countNotificationsPendingEmail());
    }

    /**
     * Several recipients for most types of activity in the fixtures, read one after the other:
     * one email by type and by recipient, with all its notifications, in the order of the types of
     * the command, then of the ids of the recipients, and the entity manager does not keep them.
     */
    public function testNotificationEmailReadsTheNotificationsByRecipient(): void
    {
        $pendingEmails = $this->getPendingNotificationEmails();
        self::assertGreaterThan(\count(array_unique(array_column($pendingEmails, 1))), \count($pendingEmails));

        [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:cron:notification:email'));

        self::assertSame(0, $exitCode, $output);
        $translator = static::getContainer()->get('translator');
        self::assertSame(
            array_map(fn (array $email): array => [$email[0], $translator->trans('notification.choice.'.$email[1], ['%count%' => $email[2]])], $pendingEmails),
            array_map(fn (Email $message): array => [...static::getEmailRecipients($message), $message->getSubject()], static::getSentEmailsByCommand())
        );
        self::assertSame(0, $this->countNotificationsPendingEmail());
        self::assertSame(0, static::getEntityManager()->getUnitOfWork()->size());
    }

    /**
     * Without --force, only the output tells what the cron would do.
     */
    public function testNotificationEmailChangesNothingWithoutForce(): void
    {
        $pendingCount = $this->countNotificationsPendingEmail();
        self::assertGreaterThan(0, $pendingCount);

        [$exitCode, $output] = static::runCommandLine('ladb:cron:notification:email --verbose');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('[Fake]', $output);
        self::assertSame([], static::getSentEmailsByCommand());
        self::assertSame($pendingCount, $this->countNotificationsPendingEmail());
    }

    /**
     * The users who accept it get the news of the last 7 days.
     */
    public function testWeekNews(): void
    {
        $this->update(Creation::class, 1, ['createdAt' => new \DateTime()]);

        [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:mailing:weeknews'));

        self::assertSame(0, $exitCode, $output);
        self::assertNotEmpty(static::getSentEmailsByCommand());
    }

    /**
     * More accounts than a batch of the command: each of them gets the news once, in the order of
     * their ids, the same news as the first one, and the entity manager does not keep them.
     */
    public function testWeekNewsReadsTheAccountsByBatches(): void
    {
        $this->update(Creation::class, 1, ['createdAt' => new \DateTime()]);
        $connection = static::getEntityManager()->getConnection();
        $columns = fn (string $table): array => array_column($connection->fetchAllAssociative("SHOW COLUMNS FROM {$table}"), 'Field');
        $userColumns = implode(', ', array_diff($columns('tbl_core_user'), ['id', 'username', 'username_canonical', 'email', 'email_canonical', 'displayname', 'confirmation_token', 'meta_id']));
        $metaColumns = implode(', ', array_diff($columns('tbl_core_user_meta'), ['id']));
        for ($i = 1; $i <= 250; $i++) {
            $connection->executeStatement("INSERT INTO tbl_core_user_meta ({$metaColumns}) SELECT {$metaColumns} FROM tbl_core_user_meta WHERE id = (SELECT meta_id FROM tbl_core_user WHERE id = 2)");
            $connection->executeStatement("INSERT INTO tbl_core_user (username, username_canonical, email, email_canonical, displayname, meta_id, {$userColumns}) SELECT CONCAT(username, '-', {$i}), CONCAT(username_canonical, '-', {$i}), CONCAT({$i}, '-', email), CONCAT({$i}, '-', email_canonical), CONCAT(displayname, ' ', {$i}), LAST_INSERT_ID(), {$userColumns} FROM tbl_core_user WHERE id = 2");
        }
        $emails = $connection->fetchFirstColumn('SELECT u.email FROM tbl_core_user u INNER JOIN tbl_core_user_meta m ON m.id = u.meta_id WHERE u.enabled = 1 AND u.is_team = 0 AND u.email_confirmed = 1 AND m.week_news_email_enabled = 1 ORDER BY u.id');

        [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:mailing:weeknews'));

        self::assertSame(0, $exitCode, $output);
        $messages = static::getSentEmailsByCommand();
        self::assertSame($emails, array_merge(...array_map(fn (Email $message): array => static::getEmailRecipients($message), $messages)));
        // The greeting and the unsubscribe link are those of the account
        $news = fn (Email $message): string => preg_replace('~^(Bonjour|Se désabonner) .*$~mu', '', $message->getTextBody());
        foreach ($messages as $message) {
            self::assertSame($news($messages[0]), $news($message));
        }
        self::assertLessThan(100, static::getEntityManager()->getUnitOfWork()->size());
    }

    /**
     * The sitemaps list the contents with their pictures and videos (the data of the videos come
     * from Tests\Double\OfflineVideoHostingUtils).
     */
    public function testSitemaps(): void
    {
        $creation = static::getEntityManager()->find(Creation::class, 1);
        $video = new Video();
        $video->setUrl('https://www.youtube.com/watch?v=aqz-KE-bpKQ');
        $video->setKind(VideoHostingUtils::KIND_YOUTUBE);
        $video->setEmbedIdentifier('aqz-KE-bpKQ');
        $video->setSortIndex(1);
        $creation->addBodyBlock($video);
        static::getEntityManager()->flush();

        [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:cron:sitemaps'));

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('/sitemap-wonder-creations.xml', file_get_contents(\dirname(self::SITEMAPS).'/sitemap-index.xml'));
        $sitemap = file_get_contents(\dirname(self::SITEMAPS).'/sitemap-wonder-creations.xml');
        self::assertStringContainsString('/creations/1-', $sitemap);
        self::assertStringContainsString(OfflineVideoHostingUtils::SITEMAP_TITLE.' aqz-KE-bpKQ', $sitemap);
    }

    /**
     * More contents than a batch of the command: each of them once, in the order of their ids, and
     * the entity manager does not keep them.
     */
    public function testSitemapsReadTheContentsByBatches(): void
    {
        $connection = static::getEntityManager()->getConnection();
        $columns = array_diff(array_column($connection->fetchAllAssociative('SHOW COLUMNS FROM tbl_blog_post'), 'Field'), ['id', 'slug']);
        $columns = implode(', ', $columns);
        for ($i = 1; $i <= 450; $i++) {
            $connection->executeStatement("INSERT INTO tbl_blog_post (slug, {$columns}) SELECT CONCAT(slug, '-', {$i}), {$columns} FROM tbl_blog_post WHERE id = 1");
        }
        $ids = array_map(intval(...), $connection->fetchFirstColumn('SELECT id FROM tbl_blog_post WHERE is_draft = 0 ORDER BY id'));

        [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:cron:sitemaps'));

        self::assertSame(0, $exitCode, $output);
        preg_match_all('~<loc>[^<]+/blog/(\d+)-[^<]+</loc>~', file_get_contents(\dirname(self::SITEMAPS).'/sitemap-blog-posts.xml'), $matches);
        self::assertSame($ids, array_map(intval(...), $matches[1]));
        self::assertLessThan(200, static::getEntityManager()->getUnitOfWork()->size());
    }

    /**
     * A workflow without a thumbnail gets a screenshot of its diagram (Chromium is offline:
     * Tests\Double\OfflineWebScreenshotUtils).
     */
    public function testWorkflowThumbnails(): void
    {
        $this->update(Workflow::class, 1, ['mainPicture' => null]);

        [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:cron:workflow:thumbnails'));

        self::assertSame(0, $exitCode, $output);
        self::assertNotNull(static::getEntityManager()->find(Workflow::class, 1)->getMainPicture());
        self::assertSame([static::getContainer()->getParameter('router_default_uri').'/processus/1/internal/diagram'], static::getContainer()->get('tests.web_screenshot_utils')->getUrls());
    }

    /**
     * Without --force, no capture: it would write the file and the picture.
     */
    public function testWorkflowThumbnailsCaptureNothingWithoutForce(): void
    {
        $this->update(Workflow::class, 1, ['mainPicture' => null]);

        [$exitCode, $output] = static::runCommandLine('ladb:cron:workflow:thumbnails');

        self::assertSame(0, $exitCode, $output);
        self::assertSame([], static::getContainer()->get('tests.web_screenshot_utils')->getUrls());
        self::assertNull(static::getEntityManager()->find(Workflow::class, 1)->getMainPicture());
    }

    /**
     * The downloads of OpenCutList get the OS and the version of SketchUp from their user agent,
     * and a location from their IP address (ip-api.com, offline: Tests\Double\OfflineHttpStream).
     */
    public function testOpencutlistAccessAnalyze(): void
    {
        $access = new Access();
        $access->setKind(Access::KIND_DOWNLOAD);
        $access->setClientIp4('192.0.2.1');
        $access->setClientUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) SketchUp Pro/23.1 (PC) Safari/537.36');
        static::getEntityManager()->persist($access);
        static::getEntityManager()->flush();

        OfflineHttpStream::register([
            'http://ip-api.com/json/192.0.2.1?lang=fr&fields=status,message,continentCode,country,countryCode,city,lat,lon' => json_encode(['status' => 'success', 'continentCode' => 'EU', 'country' => 'France', 'countryCode' => 'FR', 'city' => 'Bordeaux', 'lat' => 44.84, 'lon' => -0.58]),
        ]);
        try {
            [$exitCode, $output] = static::runCommandLine(self::getCron('ladb:cron:opencutlist:access:analyze'));
        } finally {
            OfflineHttpStream::unregister();
        }

        self::assertSame(0, $exitCode, $output);
        $access = static::getEntityManager()->find(Access::class, $access->getId());
        self::assertTrue($access->getAnalyzed());
        self::assertSame(Access::OS_WIN, $access->getClientOS());
        self::assertSame(Access::SKETCHUP_FAMILY_PRO, $access->getClientSketchupFamily());
        self::assertSame('23.1', $access->getClientSketchupVersion());
        self::assertSame(['EU', 'FR', 'Bordeaux, France'], [$access->getContinentCode(), $access->getCountryCode(), $access->getLocation()]);
    }

    /**
     * The command lines of the crons, in the order of the schedule.
     *
     * @return list<string>
     */
    private static function getCommandLines(): array
    {
        return array_map(static fn (RecurringMessage $recurringMessage): string => self::getCommandLine($recurringMessage), self::getRecurringMessages());
    }

    private static function getCommandLine(RecurringMessage $recurringMessage): string
    {
        foreach (self::getMessages($recurringMessage) as $message) {
            return $message->envelope->input;
        }

        throw new \LogicException('A cron without message.');
    }

    /**
     * The crons, as CronScheduleProvider gives them, without the state and the lock of the
     * container: for the data providers.
     *
     * @return list<RecurringMessage>
     */
    private static function getRecurringMessages(): array
    {
        return array_values((new CronScheduleProvider())->getSchedule()->getRecurringMessages());
    }

    /**
     * The messages of a run of a cron, as the worker of the schedule gets them.
     *
     * @return iterable<object>
     */
    private static function getMessages(RecurringMessage $recurringMessage): iterable
    {
        return $recurringMessage->getMessages(new MessageContext('cron', $recurringMessage->getId(), $recurringMessage->getTrigger(), new \DateTimeImmutable()));
    }

    private static function getCron(string $name): string
    {
        foreach (self::getCommandLines() as $commandLine) {
            if (str_starts_with($commandLine, $name.' ')) {
                return $commandLine;
            }
        }

        throw new \LogicException("The schedule does not run {$name}.");
    }

    /**
     * Changes fields of an entity without its listeners (Gedmo would set the dates again).
     *
     * @param class-string         $class
     * @param array<string, mixed> $fields
     */
    private function update(string $class, int $id, array $fields): void
    {
        $queryBuilder = static::getEntityManager()->createQueryBuilder()->update($class, 'e')->where('e.id = :id')->setParameter('id', $id);
        foreach ($fields as $field => $value) {
            $queryBuilder->set("e.{$field}", ":{$field}")->setParameter($field, $value);
        }
        $queryBuilder->getQuery()->execute();
    }

    /**
     * What a change of the offer 1 moves: the counters of its author, then those of the author of
     * its comment and its like.
     *
     * @return array{int, int, int, int}
     */
    private static function getOfferCounters(): array
    {
        static::getEntityManager()->clear();
        $author = static::findUser(UserFixtures::USER)->getMeta();
        $reader = static::findUser(UserFixtures::USER2)->getMeta();

        return [$author->getPublicOfferCount(), $author->getPrivateOfferCount(), $reader->getCommentCount(), $reader->getSentLikeCount()];
    }

    private static function findOfferWitness(): ?Witness
    {
        return static::getEntityManager()->getRepository(Witness::class)->findOneByEntityTypeAndEntityId(Offer::TYPE, 1);
    }

    /**
     * The publication events of the last static::runCommandLine().
     *
     * @return list<string>
     */
    private static function getPublicationEventsByCommand(): array
    {
        $events = array_column(static::getContainer()->get('event_dispatcher')->getCalledListeners(), 'event');

        return array_values(array_unique(array_filter($events, static fn (string $event): bool => str_starts_with($event, 'ladb.publication.'))));
    }

    private function countNotifications(): int
    {
        return (int) static::getEntityManager()->createQuery('SELECT COUNT(n.id) FROM '.Notification::class.' n')->getSingleScalarResult();
    }

    private function countNotificationsPendingEmail(): int
    {
        return (int) static::getEntityManager()->createQuery('SELECT COUNT(n.id) FROM '.Notification::class.' n WHERE n.isPendingEmail = true')->getSingleScalarResult();
    }

    /**
     * The emails ladb:cron:notification:email has to send, in its order: recipient, type of
     * activity and number of notifications. The accounts of the fixtures accept every notification
     * by email.
     *
     * @return list<array{string, string, int}>
     */
    private function getPendingNotificationEmails(): array
    {
        $emails = [];
        foreach (self::NOTIFICATION_EMAIL_ACTIVITIES as $activityClass) {
            $rows = static::getEntityManager()->createQuery('SELECT u.email, COUNT(n.id) AS notificationCount FROM '.Notification::class.' n INNER JOIN n.user u INNER JOIN n.activity a WHERE n.isPendingEmail = true AND n.isListed = false AND a INSTANCE OF '.$activityClass.' GROUP BY u.id ORDER BY u.id')->getArrayResult();
            foreach ($rows as $row) {
                $emails[] = [$row['email'], $activityClass::STRIPPED_NAME, (int) $row['notificationCount']];
            }
        }

        return $emails;
    }

    /**
     * @param list<string> $usernames
     */
    private function assertEmailsSentTo(array $usernames): void
    {
        $recipients = [];
        foreach (static::getSentEmailsByCommand() as $message) {
            $recipients = [...$recipients, ...static::getEmailRecipients($message)];
        }
        $emails = array_map(fn (string $username): string => static::findUser($username)->getEmail(), $usernames);

        self::assertSame($emails, $recipients);
    }
}
