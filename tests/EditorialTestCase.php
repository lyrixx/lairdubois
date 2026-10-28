<?php

namespace Tests;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\AbstractDraftableAuthoredPublication;
use Ladb\CoreBundle\Entity\Core\Activity\Publish;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;

/**
 * The contents of the team of the site, the blog posts and the FAQ: only the admins (or the
 * accounts with a role of their own, none in the fixtures) write, publish and delete them. They
 * act on the content of id 1 of the fixtures, published by "admin"; "user" is any other user.
 *
 * The same actions as PublicationTestCase, with other rights, and neither counters nor teams.
 */
abstract class EditorialTestCase extends WebTestCase
{
    // The content of the type in the fixtures, published by "admin"
    protected const ID = 1;

    /**
     * Tests known to fail, as in PublicationTestCase.
     *
     * @var array<string, int>
     */
    protected const KNOWN_FAILURES = [];

    // Whether the publication notifies the followers of the author: a publish activity
    protected const NOTIFIES_THE_FOLLOWERS = false;

    /**
     * Prefix of the names of the routes of the type: "core_blog_post" for "core_blog_post_show"...
     */
    abstract protected static function getRoutePrefix(): string;

    /**
     * @return class-string<AbstractDraftableAuthoredPublication>
     */
    abstract protected static function getEntityClass(): string;

    /**
     * The values of a valid form of a new content, by field name.
     *
     * @return array<string, string>
     */
    abstract protected function getNewFormValues(): array;

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
        $client = null === $username ? static::createClient() : static::createClientAs($username);
        $crawler = $client->request('GET', $this->path('show', ['id' => static::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsStringIgnoringCase($this->find(static::ID)->getTitle(), $crawler->filter('title')->text(null, false));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function provideUsers(): iterable
    {
        yield 'anonymous' => [null];
        yield 'user' => [UserFixtures::USER];
        yield 'admin' => [UserFixtures::ADMIN];
    }

    /**
     * @dataProvider provideActions
     */
    public function testActionSendsAnonymousToLogin(string $method, string $action): void
    {
        $client = static::createClient();
        $client->request($method, $this->path($action, 'new' === $action || 'create' === $action ? [] : ['id' => static::ID]));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * @dataProvider provideActions
     */
    public function testActionIsHiddenFromUsers(string $method, string $action): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $client->request($method, $this->path($action, 'new' === $action || 'create' === $action ? [] : ['id' => static::ID, 'rtu' => '/']));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertTrue($this->find(static::ID)->getIsPublic());
        self::assertFalse($this->find(static::ID)->getIsLocked());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideActions(): iterable
    {
        yield 'new' => ['GET', 'new'];
        yield 'create' => ['POST', 'create'];
        yield 'edit' => ['GET', 'edit'];
        yield 'update' => ['POST', 'update'];
        yield 'unpublish' => ['GET', 'unpublish'];
        yield 'delete' => ['GET', 'delete'];
        yield 'lock' => ['GET', 'lock'];
    }

    /**
     * A user does not publish the draft of an admin either.
     */
    public function testPublishAsUser(): void
    {
        $this->unpublish();

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('publish', ['id' => static::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertTrue($this->find(static::ID)->getIsDraft());
    }

    public function testCreate(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $crawler = $client->request('GET', $this->path('new'));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->submit($this->selectForm($crawler, $this->path('create'))->setValues($this->getNewFormValues()));

        // On an invalid form, the message tells which field
        self::assertSame(302, $client->getResponse()->getStatusCode(), implode("\n", $client->getCrawler()->filter('.help-block')->each(fn (Crawler $node) => $node->text(null, false))));
        $publication = $this->findLast();
        self::assertSame(UserFixtures::ADMIN, $publication->getUser()->getUsername());
        self::assertTrue($publication->getIsDraft());
        self::assertTrue($client->getResponse()->isRedirect($this->path('show', ['id' => $publication->getSluggedId()])));

        // A draft: only the admins see it
        $this->assertShowStatus(200, UserFixtures::ADMIN, $publication->getId());
        $this->assertShowStatus(404, UserFixtures::USER, $publication->getId());
        $this->assertShowStatus(404, null, $publication->getId());
    }

    public function testCreateInvalid(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $lastId = $this->findLast()->getId();
        $crawler = $client->request('GET', $this->path('new'));

        $client->submit($this->selectForm($crawler, $this->path('create')));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $client->getCrawler()->filter('.alert-danger'));
        self::assertSame($lastId, $this->findLast()->getId());
    }

    public function testUpdate(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $crawler = $client->request('GET', $this->path('edit', ['id' => static::ID]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $this->selectForm($crawler, $this->path('update', ['id' => static::ID]));
        $form[$this->getFieldName($form, 'title')] = 'Un titre mis à jour';

        $client->submit($form);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $client->getCrawler()->filter('.alert-success'));
        self::assertSame('Un titre mis à jour', $this->find(static::ID)->getTitle());
    }

    public function testUnpublishHidesTheContent(): void
    {
        $client = $this->unpublish();

        self::assertTrue($client->getResponse()->isRedirect('/'));
        self::assertTrue($this->find(static::ID)->getIsDraft());
        self::assertSame(0, $this->countPublishActivities());
        self::assertFalse($this->isListed($this->getShowPath()));

        // Gone for the others, as it was published
        $this->assertShowStatus(200, UserFixtures::ADMIN);
        $this->assertShowStatus(410, UserFixtures::USER);
        $this->assertShowStatus(410, null);
    }

    public function testPublishShowsTheContent(): void
    {
        $this->unpublish();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('publish', ['id' => static::ID]));

        $publication = $this->find(static::ID);
        self::assertTrue($client->getResponse()->isRedirect($this->path('show', ['id' => $publication->getSluggedId()])));
        self::assertTrue($publication->getIsPublic());
        self::assertSame(static::NOTIFIES_THE_FOLLOWERS ? 1 : 0, $this->countPublishActivities());
        self::assertTrue($this->isListed($this->getShowPath()));
        $this->assertShowStatus(200, null);
    }

    public function testPublishPublished(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('publish', ['id' => static::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testDelete(): void
    {
        $showPath = $this->getShowPath();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('delete', ['id' => static::ID]));

        self::assertTrue($client->getResponse()->isRedirect($this->path('list')));
        self::assertNull($this->find(static::ID));
        self::assertFalse($this->isListed($showPath));
        $this->assertShowStatus(410, null);
    }

    /**
     * Once unpublished, a locked content stays a draft, even for an admin.
     */
    public function testPublishLocked(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('lock', ['id' => static::ID]));
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertTrue($this->find(static::ID)->getIsLocked());
        $this->unpublish();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('publish', ['id' => static::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertTrue($this->find(static::ID)->getIsDraft());
    }

    protected function path(string $action, array $parameters = []): string
    {
        return static::$kernel->getContainer()->get('router')->generate(static::getRoutePrefix().'_'.$action, $parameters);
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
     * A picture of "admin", copied from the ones of the fixtures, for the form of a new content.
     *
     * @return string Its id
     */
    protected static function createPicture(string $name): string
    {
        $picture = static::$kernel->getContainer()->get('tests.fixture_factory')->picture(static::findUser(UserFixtures::ADMIN), 'wood-2.jpg', $name);
        static::getEntityManager()->flush();

        return (string) $picture->getId();
    }

    /**
     * Unpublishes the content of the fixtures, as an admin.
     */
    protected function unpublish(): Client
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('unpublish', ['id' => static::ID, 'rtu' => '/']));
        self::assertSame(302, $client->getResponse()->getStatusCode());

        return $client;
    }

    private function selectForm(Crawler $crawler, string $action): Form
    {
        return $crawler->filter(\sprintf('form[action="%s"]', $action))->form();
    }

    /**
     * The full name of a field of the form: "ladb_blog_post[title]" for "title".
     */
    private function getFieldName(Form $form, string $field): string
    {
        foreach (array_keys($form->all()) as $name) {
            if (str_ends_with($name, '['.$field.']') && 1 === substr_count($name, '[')) {
                return $name;
            }
        }

        throw new \LogicException(\sprintf('The form has no field "%s".', $field));
    }

    private function countPublishActivities(): int
    {
        return \count(static::getEntityManager()->getRepository(Publish::class)->findBy([
            'entityType' => \constant(static::getEntityClass().'::TYPE'),
            'entityId' => static::ID,
        ]));
    }

    /**
     * Whether the list of the type, which comes from the index, links to $showPath for anonymous.
     */
    private function isListed(string $showPath): bool
    {
        $client = static::createClient();
        static::getIndexes()->refresh();
        $client->request('GET', $this->path('list'));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return 0 < $client->getCrawler()->filter(\sprintf('a[href$="%s"]', $showPath))->count();
    }

    private function assertShowStatus(int $status, ?string $username, int $id = self::ID): void
    {
        $client = null === $username ? static::createClient() : static::createClientAs($username);
        $client->request('GET', $this->path('show', ['id' => $id]));

        self::assertSame($status, $client->getResponse()->getStatusCode(), \sprintf('Show of #%d as %s.', $id, $username ?? 'anonymous'));
    }

    /**
     * The path of the page of the content of the fixtures.
     */
    private function getShowPath(): string
    {
        return $this->path('show', ['id' => $this->find(static::ID)->getSluggedId()]);
    }
}
