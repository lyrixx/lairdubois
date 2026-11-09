<?php

namespace Tests\Command;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Activity\AbstractActivity;
use Ladb\CoreBundle\Entity\Core\Block\Video;
use Ladb\CoreBundle\Entity\Core\Notification;
use Ladb\CoreBundle\Entity\Core\Spotlight;
use Ladb\CoreBundle\Entity\Core\Witness;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Entity\Opencutlist\Access;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Ladb\CoreBundle\Utils\VideoHostingUtils;
use Symfony\Component\Mime\Email;
use Tests\Double\OfflineHttpStream;
use Tests\Double\OfflineVideoHostingUtils;
use Tests\WebTestCase;

/**
 * The crons of the production, as the installation guide gives them (docs/01-installation.md,
 * step 13), on the fixtures; then what they do, on data prepared for each of them. The changes are
 * rolled back with the test.
 */
final class CrontabTest extends WebTestCase
{
    private const INSTALLATION_GUIDE = __DIR__.'/../../docs/01-installation.md';

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
        foreach (self::getCrontab() as $commandLine) {
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
     * Every line of the guide which starts with a schedule is a cron of the crontab: a line the
     * pattern of getCrontab() misses would not be tested.
     */
    public function testCrontabIsComplete(): void
    {
        preg_match_all('~^(?:[\d*/,-]+ ){5}.*$~m', file_get_contents(self::INSTALLATION_GUIDE), $matches);

        self::assertNotEmpty($matches[0]);
        self::assertCount(\count($matches[0]), self::getCrontab());
        foreach (array_keys(self::KNOWN_FAILURES) as $commandLine) {
            self::assertContains($commandLine, self::getCrontab(), "{$commandLine} is not in the crontab anymore: remove it from the known failures.");
        }
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
     * The command lines of the crontab, without "php bin/console --env=prod".
     *
     * @return list<string>
     */
    private static function getCrontab(): array
    {
        preg_match_all('~^(?:\S+ ){5}php /var/www/www\.lairdubois\.fr/bin/console --env=prod (.+) &> /dev/null$~m', file_get_contents(self::INSTALLATION_GUIDE), $matches);

        return $matches[1];
    }

    /**
     * The command line of the crontab which runs a command.
     */
    private static function getCron(string $name): string
    {
        foreach (self::getCrontab() as $commandLine) {
            if (str_starts_with($commandLine, $name.' ')) {
                return $commandLine;
            }
        }

        throw new \LogicException("The crontab does not run {$name}.");
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
