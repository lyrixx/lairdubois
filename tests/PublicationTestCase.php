<?php

namespace Tests;

use Ladb\CoreBundle\DataFixtures\FixtureFactory;
use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\AbstractDraftableAuthoredPublication;
use Ladb\CoreBundle\Entity\Core\Activity\Publish;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;

/**
 * The actions a publication type shares with the others (show, new, create, edit, update,
 * publish, unpublish, delete, lock, chown), with the same rights: anonymous, author, other user,
 * admin. They act on the content of id 1 of the fixtures, published by "user"; "user2" is the
 * other user.
 *
 * A subclass gives the type, the values of the form of a new content, and tests what is its own.
 */
abstract class PublicationTestCase extends WebTestCase
{
    // The content of the type in the fixtures, published by "user"
    protected const ID = 1;

    /**
     * Tests known to fail, by name with their data set ('testChown with data set "admin"'), with
     * their issue. They are marked incomplete, and fail once they pass: remove them from here
     * when their issue is fixed.
     *
     * @var array<string, int>
     */
    protected const KNOWN_FAILURES = [];

    // Whether an unpublished content keeps its publish activities: a republishable one (offer)
    // gets an activity on its first publication only
    protected const KEEPS_ITS_ACTIVITIES_WHEN_UNPUBLISHED = false;

    /**
     * Prefix of the names of the routes of the type: "core_creation" for "core_creation_show"...
     */
    abstract protected static function getRoutePrefix(): string;

    /**
     * @return class-string<AbstractDraftableAuthoredPublication>
     */
    abstract protected static function getEntityClass(): string;

    /**
     * Name of the counters of the type in UserMeta: "Creation" for getPrivateCreationCount()...
     */
    abstract protected static function getCounterName(): string;

    /**
     * The values of a valid form of a new content of $owner, by field name.
     *
     * @return array<string, string>
     */
    abstract protected function getNewFormValues(string $owner): array;

    /**
     * Where the creation of a content redirects to.
     */
    protected function getCreatedPath(AbstractDraftableAuthoredPublication $publication): string
    {
        return $this->path('show', ['id' => $publication->getSluggedId()]);
    }

    protected function runTest()
    {
        $issue = static::KNOWN_FAILURES[$this->getName()] ?? null;
        if (null === $issue) {
            return parent::runTest();
        }

        try {
            parent::runTest();
        } catch (AssertionFailedError $e) {
            self::markTestIncomplete(\sprintf('Known failure, see #%d. %s', $issue, $e->getMessage()));
        }

        self::fail(\sprintf('It passes now: remove it from the known failures (#%d).', $issue));
    }

    /**
     * @dataProvider provideUsers
     */
    public function testShow(?string $username): void
    {
        $client = $this->createClientFor($username);
        $crawler = $client->request('GET', $this->path('show', ['id' => static::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsStringIgnoringCase($this->find(static::ID)->getTitle(), $crawler->filter('title')->text(null, false));
    }

    /**
     * @dataProvider provideActions
     */
    public function testActionSendsAnonymousToLogin(string $method, string $action, array $parameters): void
    {
        $client = static::createClient();
        $client->request($method, $this->path($action, $parameters));

        self::assertRedirectsToLogin($client);
    }

    /**
     * @return iterable<string, array{string, string, array<string, int>}>
     */
    public static function provideActions(): iterable
    {
        yield 'new' => ['GET', 'new', []];
        yield 'create' => ['POST', 'create', []];
        yield 'edit' => ['GET', 'edit', ['id' => static::ID]];
        yield 'update' => ['POST', 'update', ['id' => static::ID]];
        yield 'publish' => ['GET', 'publish', ['id' => static::ID]];
        yield 'unpublish' => ['GET', 'unpublish', ['id' => static::ID]];
        yield 'delete' => ['GET', 'delete', ['id' => static::ID]];
        yield 'lock' => ['GET', 'lock', ['id' => static::ID]];
        yield 'chown' => ['GET', 'chown', ['id' => static::ID]];
    }

    public function testCreate(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER);

        $this->submitNewForm($client, UserFixtures::USER, $this->getNewFormValues(UserFixtures::USER));

        $publication = $this->findLast();
        self::assertSame(static::findUser(UserFixtures::USER)->getId(), $publication->getUser()->getId());
        self::assertTrue($publication->getIsDraft());
        self::assertTrue($client->getResponse()->isRedirect($this->getCreatedPath($publication)));
        self::assertSame([$privateCount + 1, $publicCount], $this->getCounts(UserFixtures::USER));

        // A draft: only its author and the admins see it
        $this->assertShowStatus(200, UserFixtures::USER, $publication->getId());
        $this->assertShowStatus(200, UserFixtures::ADMIN, $publication->getId());
        $this->assertShowStatus(404, UserFixtures::USER2, $publication->getId());
        $this->assertShowStatus(404, null, $publication->getId());
    }

    public function testCreateForTeam(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        [$privateCount] = $this->getCounts(UserFixtures::TEAM);

        $this->submitNewForm($client, UserFixtures::TEAM, $this->getNewFormValues(UserFixtures::TEAM));

        $publication = $this->findLast();
        self::assertSame(UserFixtures::TEAM, $publication->getUser()->getUsername());
        self::assertSame($privateCount + 1, $this->getCounts(UserFixtures::TEAM)[0]);
    }

    public function testCreateInvalid(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $lastId = $this->findLast()->getId();

        $client->submit($this->getNewForm($client, UserFixtures::USER));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $client->getCrawler()->filter('.alert-danger'));
        self::assertSame($lastId, $this->findLast()->getId());
    }

    /**
     * An empty title arrives as null, which Length ignores: the only message is the one of NotBlank (#177).
     *
     * @dataProvider provideInvalidTitles
     */
    public function testCreateWithInvalidTitle(string $title, string $error): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $form = $this->getNewForm($client, UserFixtures::USER);
        $fieldName = $this->getFieldName($form, 'title');
        $form[$fieldName] = $title;

        $crawler = $client->submit($form);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([$error], static::getFieldErrors($crawler, $fieldName));
    }

    public static function provideInvalidTitles(): iterable
    {
        yield 'empty' => ['', 'Cette valeur ne doit pas être vide.'];
        yield 'too short' => ['abc', 'Cette chaîne est trop courte. Elle doit avoir au minimum 4 caractères.'];
    }

    /**
     * @dataProvider provideEditors
     */
    public function testEdit(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $client->request('GET', $this->path('edit', ['id' => static::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideEditors(): iterable
    {
        yield 'author' => [UserFixtures::USER, 200];
        yield 'other user' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    /**
     * @dataProvider provideUpdaters
     */
    public function testUpdate(string $username): void
    {
        $client = static::createClientAs($username);
        $crawler = $client->request('GET', $this->path('edit', ['id' => static::ID]));
        $form = $this->selectForm($crawler, $this->path('update', ['id' => static::ID]));
        $form[$this->getFieldName($form, 'title')] = 'Un titre mis à jour';

        $client->submit($form);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $client->getCrawler()->filter('.alert-success'));
        self::assertSame('Un titre mis à jour', $this->find(static::ID)->getTitle());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUpdaters(): iterable
    {
        yield 'author' => [UserFixtures::USER];
        yield 'admin' => [UserFixtures::ADMIN];
    }

    public function testUpdateAsOtherUser(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $crawler = $client->request('GET', $this->path('edit', ['id' => static::ID]));
        $form = $this->selectForm($crawler, $this->path('update', ['id' => static::ID]));
        $form[$this->getFieldName($form, 'title')] = 'Un titre mis à jour';

        // The access is checked before the form: its token, the one of the admin, does not matter
        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotSame('Un titre mis à jour', $this->find(static::ID)->getTitle());
    }

    /**
     * @dataProvider provideUnpublishers
     */
    public function testUnpublish(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $client->request('GET', $this->path('unpublish', ['id' => static::ID, 'rtu' => '/']));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame(302 !== $status, $this->find(static::ID)->getIsPublic());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideUnpublishers(): iterable
    {
        yield 'author' => [UserFixtures::USER, 404];
        yield 'other user' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 302];
    }

    public function testUnpublishHidesTheContent(): void
    {
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER);
        $activityCount = $this->countPublishActivities();

        $client = $this->unpublish();

        self::assertTrue($client->getResponse()->isRedirect('/'));
        self::assertTrue($this->find(static::ID)->getIsDraft());
        self::assertSame([$privateCount + 1, $publicCount - 1], $this->getCounts(UserFixtures::USER));
        self::assertSame(static::KEEPS_ITS_ACTIVITIES_WHEN_UNPUBLISHED ? $activityCount : 0, $this->countPublishActivities());
        self::assertFalse($this->isListed($this->getShowPath()));

        // Gone for the others, as it was published
        $this->assertShowStatus(200, UserFixtures::USER);
        $this->assertShowStatus(410, UserFixtures::USER2);
        $this->assertShowStatus(410, null);
    }

    /**
     * @dataProvider providePublishers
     */
    public function testPublish(string $username, int $status): void
    {
        $this->unpublish();

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('publish', ['id' => static::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame(302 !== $status, $this->find(static::ID)->getIsDraft());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function providePublishers(): iterable
    {
        yield 'author' => [UserFixtures::USER, 302];
        yield 'other user' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 302];
    }

    public function testPublishShowsTheContent(): void
    {
        $this->unpublish();
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER);

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('publish', ['id' => static::ID]));

        $publication = $this->find(static::ID);
        self::assertTrue($client->getResponse()->isRedirect($this->path('show', ['id' => $publication->getSluggedId()])));
        self::assertTrue($publication->getIsPublic());
        self::assertSame([$privateCount - 1, $publicCount + 1], $this->getCounts(UserFixtures::USER));
        self::assertSame(1, $this->countPublishActivities());
        self::assertTrue($this->isListed($this->getShowPath()));
        $this->assertShowStatus(200, null);
    }

    public function testPublishPublished(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('publish', ['id' => static::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @dataProvider provideDeleters
     */
    public function testDelete(string $username, int $status): void
    {
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER);
        $showPath = $this->getShowPath();

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('delete', ['id' => static::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        if (302 === $status) {
            self::assertTrue($client->getResponse()->isRedirect($this->path('list')));
            self::assertNull($this->find(static::ID));
            self::assertSame([$privateCount, $publicCount - 1], $this->getCounts(UserFixtures::USER));
            self::assertSame(0, $this->countPublishActivities());
            self::assertFalse($this->isListed($showPath));
            $this->assertShowStatus(410, UserFixtures::USER);
        } else {
            self::assertNotNull($this->find(static::ID));
        }
    }

    /**
     * Only the admins delete a published content: its author unpublishes it first.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function provideDeleters(): iterable
    {
        yield 'author' => [UserFixtures::USER, 404];
        yield 'other user' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 302];
    }

    /**
     * @dataProvider provideDraftDeleters
     */
    public function testDeleteDraft(string $username, int $status): void
    {
        $this->unpublish();
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER);

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('delete', ['id' => static::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        if (302 === $status) {
            self::assertNull($this->find(static::ID));
            self::assertSame([$privateCount - 1, $publicCount], $this->getCounts(UserFixtures::USER));
        } else {
            self::assertNotNull($this->find(static::ID));
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideDraftDeleters(): iterable
    {
        yield 'author' => [UserFixtures::USER, 302];
        yield 'other user' => [UserFixtures::USER2, 404];
    }

    /**
     * @dataProvider provideLockers
     */
    public function testLock(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $client->request('GET', $this->path('lock', ['id' => static::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame(302 === $status, $this->find(static::ID)->getIsLocked());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideLockers(): iterable
    {
        yield 'author' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 302];
    }

    /**
     * Once unpublished, a locked content stays a draft.
     */
    public function testPublishLocked(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('lock', ['id' => static::ID]));
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->unpublish();

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('publish', ['id' => static::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertTrue($this->find(static::ID)->getIsDraft());
    }

    /**
     * @dataProvider provideChowners
     */
    public function testChown(string $username, string $owner, int $status): void
    {
        [, $publicCount] = $this->getCounts(UserFixtures::USER);
        [, $ownerPublicCount] = $this->getCounts($owner);

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('chown', ['id' => static::ID, 'owner' => $owner]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        if (302 === $status) {
            self::assertSame($owner, $this->find(static::ID)->getUser()->getUsername());
            self::assertSame($publicCount - 1, $this->getCounts(UserFixtures::USER)[1]);
            self::assertSame($ownerPublicCount + 1, $this->getCounts($owner)[1]);
        } else {
            self::assertSame(UserFixtures::USER, $this->find(static::ID)->getUser()->getUsername());
        }
    }

    /**
     * An admin gives the content to anyone, its author only to one of its teams.
     *
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideChowners(): iterable
    {
        yield 'admin to another user' => [UserFixtures::ADMIN, UserFixtures::USER2, 302];
        yield 'author to its team' => [UserFixtures::USER, UserFixtures::TEAM, 302];
        yield 'author to another user' => [UserFixtures::USER, UserFixtures::USER2, 404];
        yield 'other user to itself' => [UserFixtures::USER2, UserFixtures::USER2, 404];
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function provideUsers(): iterable
    {
        yield 'anonymous' => [null];
        yield 'author' => [UserFixtures::USER];
        yield 'other user' => [UserFixtures::USER2];
        yield 'admin' => [UserFixtures::ADMIN];
    }

    protected function path(string $action, array $parameters = []): string
    {
        return static::$kernel->getContainer()->get('router')->generate(static::getRoutePrefix().'_'.$action, $parameters);
    }

    protected function createClientFor(?string $username): KernelBrowser
    {
        return null === $username ? static::createClient() : static::createClientAs($username);
    }

    protected function find(int $id): ?AbstractDraftableAuthoredPublication
    {
        return static::getEntityManager()->getRepository(static::getEntityClass())->find($id);
    }

    protected function findLast(): AbstractDraftableAuthoredPublication
    {
        return static::getEntityManager()->getRepository(static::getEntityClass())->findOneBy([], ['id' => 'DESC']);
    }

    /**
     * Gets the form of a new content as $owner (a user, or a team of the user of $client),
     * fills it with $values and submits it. The response is the one of the creation.
     *
     * @param array<string, string> $values
     */
    protected function submitNewForm(KernelBrowser $client, string $owner, array $values): void
    {
        $client->submit($this->getNewForm($client, $owner)->setValues($values));

        // On an invalid form, the message tells which field
        self::assertSame(302, $client->getResponse()->getStatusCode(), implode("\n", $client->getCrawler()->filter('.help-block')->each(fn (Crawler $node) => $node->text(null, false))));
    }

    /**
     * The form of a new content as $owner: a user, or a team of the user of $client.
     */
    protected function getNewForm(KernelBrowser $client, string $owner): Form
    {
        $crawler = $client->request('GET', $this->path('new', UserFixtures::TEAM === $owner ? ['owner' => $owner] : []));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return $this->selectForm($crawler, $this->path('create'));
    }

    /**
     * The factory of the fixtures: the pictures and files of a new content.
     */
    protected static function getFixtureFactory(): FixtureFactory
    {
        return static::$kernel->getContainer()->get('tests.fixture_factory');
    }

    /**
     * A picture of $username, copied from the ones of the fixtures, for the form of a new
     * content.
     *
     * @return string Its id
     */
    protected static function createPicture(string $username, string $name): string
    {
        $picture = static::getFixtureFactory()->picture(static::findUser($username), 'wood-2.jpg', $name);
        static::getEntityManager()->flush();

        return (string) $picture->getId();
    }

    protected function selectForm(Crawler $crawler, string $action): Form
    {
        return $crawler->filter(\sprintf('form[action="%s"]', $action))->form();
    }

    /**
     * Unpublishes the content of the fixtures, as an admin.
     */
    protected function unpublish(): KernelBrowser
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('unpublish', ['id' => static::ID, 'rtu' => '/']));
        self::assertSame(302, $client->getResponse()->getStatusCode());

        return $client;
    }

    /**
     * @return array{int, int} The private and public counts of $username
     */
    protected function getCounts(string $username): array
    {
        $meta = static::findUser($username)->getMeta();

        return [
            $meta->{'getPrivate'.static::getCounterName().'Count'}(),
            $meta->{'getPublic'.static::getCounterName().'Count'}(),
        ];
    }

    protected function countPublishActivities(): int
    {
        return \count(static::getEntityManager()->getRepository(Publish::class)->findBy([
            'entityType' => \constant(static::getEntityClass().'::TYPE'),
            'entityId' => static::ID,
        ]));
    }

    /**
     * Whether the list of the type, which comes from the index, links to $showPath for anonymous.
     */
    protected function isListed(string $showPath): bool
    {
        $client = static::createClient();
        static::getIndexes()->refresh();
        $client->request('GET', $this->path('list'));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return 0 < $client->getCrawler()->filter(\sprintf('a[href$="%s"]', $showPath))->count();
    }

    protected function assertShowStatus(int $status, ?string $username, int $id = self::ID): void
    {
        $client = $this->createClientFor($username);
        $client->request('GET', $this->path('show', ['id' => $id]));

        self::assertSame($status, $client->getResponse()->getStatusCode(), \sprintf('Show of #%d as %s.', $id, $username ?? 'anonymous'));
    }

    protected static function assertRedirectsToLogin(KernelBrowser $client): void
    {
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * The path of the page of the content of the fixtures.
     */
    protected function getShowPath(): string
    {
        return $this->path('show', ['id' => $this->find(static::ID)->getSluggedId()]);
    }

    /**
     * The full name of a field of the form: "ladb_wonder_creation[title]" for "title".
     */
    protected function getFieldName(Form $form, string $field): string
    {
        foreach (array_keys($form->all()) as $name) {
            if (str_ends_with($name, '['.$field.']') && 1 === substr_count($name, '[')) {
                return $name;
            }
        }

        throw new \LogicException(\sprintf('The form has no field "%s".', $field));
    }
}
