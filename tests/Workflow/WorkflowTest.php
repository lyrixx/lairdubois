<?php

namespace Tests\Workflow;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Activity\Publish;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Workflow\Task;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * A workflow is private at its creation, with a first task; its author publishes it, an admin
 * unpublishes it, and its author deletes it once private. The workflow of the fixtures, published
 * by "user", has two tasks ("Débiter les planches", then "Poncer et huiler"), a label and a part.
 *
 * Hiddable, not draftable: PublicationTestCase does not fit it (no draft, the visibility plays its
 * role).
 */
final class WorkflowTest extends WebTestCase
{
    use XhrTrait;

    private const ID = 1;

    /**
     * @dataProvider provideUsers
     */
    public function testShow(?string $username): void
    {
        $client = $this->createClientFor($username);
        $crawler = $client->request('GET', $this->path('show', ['id' => self::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsStringIgnoringCase('fabrication d\'une table basse', $crawler->filter('title')->text(null, false));
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

    /**
     * The tasks of the workspace come from the JS (core_workflow_task_list); the author and the
     * admins edit them and get the changes from the Mercure hub, the others only read them. The
     * JS itself: see #11.
     *
     * @dataProvider provideWorkspaceUsers
     */
    public function testShowWorkspace(string $username, bool $readOnly): void
    {
        // Read from $_SERVER, which the client of the tests does not fill: bots get the page
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
        try {
            $client = static::createClientAs($username);
            $client->request('GET', $this->path('show', ['id' => self::ID, 'layout' => 'workspace']));
        } finally {
            unset($_SERVER['HTTP_USER_AGENT']);
        }

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $content = $client->getResponse()->getContent();
        self::assertStringContainsString("listTaskPath: '/processus/1/tasks'", $content);
        self::assertStringContainsString('readOnly: '.($readOnly ? 'true' : 'false'), $content);
        self::assertSame(!$readOnly, str_contains($content, "mercureTopic: 'https://lairdubois.fr/workflows/1'"));
    }

    /**
     * The workspace subscribes to the changes of its workflow on the Mercure hub with a cookie
     * that the page sets: its JWT allows the workflows of the author, all of them for an admin.
     * None for a read-only workspace: the hub refuses a subscriber without a JWT.
     *
     * @dataProvider provideSubscribeClaims
     *
     * @param list<string>|null $subscribe
     */
    public function testShowWorkspaceSetsTheSubscriptionCookie(?string $username, ?array $subscribe): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
        try {
            $client = $this->createClientFor($username);
            $client->request('GET', $this->path('show', ['id' => self::ID, 'layout' => 'workspace']));
        } finally {
            unset($_SERVER['HTTP_USER_AGENT']);
        }

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $cookies = array_values(array_filter($client->getResponse()->headers->getCookies(), static function (Cookie $cookie): bool {
            return 'mercureAuthorization' === $cookie->getName();
        }));
        if (null === $subscribe) {
            self::assertSame([], $cookies);

            return;
        }
        self::assertCount(1, $cookies);
        // Only sent to the hub, never read by the JS
        self::assertSame('/.well-known/mercure', $cookies[0]->getPath());
        self::assertTrue($cookies[0]->isHttpOnly());
        self::assertTrue($cookies[0]->isSecure());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookies[0]->getSameSite());

        $jwt = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($client->getContainer()->getParameter('mercure_jwt_secret')));
        $token = $jwt->parser()->parse($cookies[0]->getValue());
        self::assertTrue($jwt->validator()->validate($token, new SignedWith($jwt->signer(), $jwt->verificationKey())));
        self::assertSame(['publish' => [], 'subscribe' => $subscribe], $token->claims()->get('mercure'));
        // A year, as remember_me: the hub closes the subscription when the JWT expires. Counted in
        // local time, as the bundle does: an hour off across a change of daylight saving time
        self::assertEqualsWithDelta((new \DateTimeImmutable('+31536000 seconds'))->getTimestamp(), $cookies[0]->getExpiresTime(), 60);
        self::assertSame($cookies[0]->getExpiresTime(), $token->claims()->get('exp')->getTimestamp());
    }

    /**
     * @return iterable<string, array{?string, list<string>|null}>
     */
    public static function provideSubscribeClaims(): iterable
    {
        // The workflows of "user" (id 2)
        yield 'author' => [UserFixtures::USER, ['https://lairdubois.fr/users/2/workflows/{id}']];
        yield 'other user' => [UserFixtures::USER2, null];
        yield 'anonymous' => [null, null];
        yield 'admin' => [UserFixtures::ADMIN, ['https://lairdubois.fr/workflows/{id}']];
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideWorkspaceUsers(): iterable
    {
        yield 'author' => [UserFixtures::USER, false];
        yield 'other user' => [UserFixtures::USER2, true];
        yield 'admin' => [UserFixtures::ADMIN, false];
    }

    /**
     * Bots get the page instead of the workspace.
     */
    public function testShowWorkspaceToBot(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
        try {
            $client = static::createClient();
            $client->request('GET', $this->path('show', ['id' => self::ID, 'layout' => 'workspace']));
        } finally {
            unset($_SERVER['HTTP_USER_AGENT']);
        }

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('listTaskPath', $client->getResponse()->getContent());
    }

    public function testList(): void
    {
        self::assertTrue($this->isListed($this->getShowPath()));
    }

    /**
     * The contents linked to the workflow: creation 1 of the fixtures.
     */
    public function testCreations(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $this->path('creations', ['id' => self::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $crawler->filter('a[href*="/creations/1-"]'));
    }

    /**
     * The durations per label (XHR, from the page of the workflow).
     */
    public function testStatistics(): void
    {
        $client = static::createClient();
        static::xhr($client, 'GET', $this->path('statistics', ['id' => self::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString("labels: ['Débit']", $client->getResponse()->getContent());
        self::assertStringContainsString("backgroundColor: ['#1abc9c']", $client->getResponse()->getContent());
    }

    /**
     * The widget of the workflow, in the body of another content.
     */
    public function testWidget(): void
    {
        $client = static::createClient();
        $crawler = static::xhr($client, 'GET', $this->path('widget', ['id' => self::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $crawler->filter(\sprintf('a[href$="%s"]', $this->getShowPath())));
    }

    /**
     * @dataProvider provideActions
     */
    public function testActionSendsAnonymousToLogin(string $method, string $action, array $parameters): void
    {
        $client = static::createClient();
        $client->request($method, $this->path($action, $parameters));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string, string, array<string, int>}>
     */
    public static function provideActions(): iterable
    {
        yield 'new' => ['GET', 'new', []];
        yield 'create' => ['POST', 'create', []];
        yield 'edit' => ['GET', 'edit', ['id' => self::ID]];
        yield 'update' => ['POST', 'update', ['id' => self::ID]];
        yield 'publish' => ['GET', 'publish', ['id' => self::ID]];
        yield 'unpublish' => ['GET', 'unpublish', ['id' => self::ID]];
        yield 'delete' => ['GET', 'delete', ['id' => self::ID]];
        yield 'lock' => ['GET', 'lock', ['id' => self::ID]];
        yield 'chown' => ['GET', 'chown', ['id' => self::ID]];
        yield 'copy' => ['GET', 'copy', ['id' => self::ID]];
        yield 'restart' => ['GET', 'restart', ['id' => self::ID]];
    }

    /**
     * A new workflow is private, with a first task, and opens in the workspace.
     */
    public function testCreate(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER);

        $this->submitNewForm($client, UserFixtures::USER);

        $workflow = $this->findLast();
        self::assertTrue($client->getResponse()->isRedirect($this->path('show', ['id' => $workflow->getId(), 'layout' => 'workspace'])));
        self::assertSame(UserFixtures::USER, $workflow->getUser()->getUsername());
        self::assertSame('Fabrication d\'un tabouret', $workflow->getTitle());
        self::assertTrue($workflow->getIsPrivate());
        self::assertSame([$privateCount + 1, $publicCount], $this->getCounts(UserFixtures::USER));
        self::assertSame(1, $workflow->getTaskCount());
        self::assertSame(['Tâche 1 : Changer le monde'], $workflow->getTasks()->map(fn (Task $task) => $task->getTitle())->toArray());
        self::assertSame(Task::STATUS_WORKABLE, $workflow->getTasks()->first()->getStatus());
    }

    public function testCreateForTeam(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        [$privateCount] = $this->getCounts(UserFixtures::TEAM);

        $this->submitNewForm($client, UserFixtures::TEAM);

        self::assertSame(UserFixtures::TEAM, $this->findLast()->getUser()->getUsername());
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
     * @dataProvider provideEditors
     */
    public function testEdit(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $client->request('GET', $this->path('edit', ['id' => self::ID]));

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
        $crawler = $client->request('GET', $this->path('edit', ['id' => self::ID]));
        $form = $this->selectForm($crawler, $this->path('update', ['id' => self::ID]));
        $form['ladb_workflow_workflow[title]'] = 'Un titre mis à jour';

        $client->submit($form);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $client->getCrawler()->filter('.alert-success'));
        self::assertSame('Un titre mis à jour', $this->find()->getTitle());
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
        $crawler = $client->request('GET', $this->path('edit', ['id' => self::ID]));
        $form = $this->selectForm($crawler, $this->path('update', ['id' => self::ID]));
        $form['ladb_workflow_workflow[title]'] = 'Un titre mis à jour';

        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotSame('Un titre mis à jour', $this->find()->getTitle());
    }

    /**
     * @dataProvider provideUnpublishers
     */
    public function testUnpublish(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $client->request('GET', $this->path('unpublish', ['id' => self::ID, 'rtu' => '/']));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame(302 !== $status, $this->find()->getIsPublic());
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

    public function testUnpublishHidesTheWorkflow(): void
    {
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER);
        $creationCount = $this->findCreation()->getWorkflowCount();

        $client = $this->unpublish();

        self::assertTrue($client->getResponse()->isRedirect('/'));
        self::assertTrue($this->find()->getIsPrivate());
        self::assertSame([$privateCount + 1, $publicCount - 1], $this->getCounts(UserFixtures::USER));
        self::assertSame($creationCount - 1, $this->findCreation()->getWorkflowCount());
        self::assertSame(0, $this->countPublishActivities());
        self::assertFalse($this->isListed($this->getShowPath()));
        $this->assertShowStatus(200, UserFixtures::USER);
    }

    /**
     * "Ce processus est privé. Vous seul pouvez le consulter.": its author and the admins only;
     * gone for the others, as it was published. The widget is for no one.
     *
     * @dataProvider providePrivatePages
     */
    public function testShowPrivate(string $route, ?string $username, int $status): void
    {
        $this->unpublish();

        $client = $this->createClientFor($username);
        static::xhr($client, 'GET', static::generatePath($route, ['id' => self::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, ?string, int}>
     */
    public static function providePrivatePages(): iterable
    {
        yield 'show, anonymous' => ['core_workflow_show', null, 410];
        yield 'show, author' => ['core_workflow_show', UserFixtures::USER, 200];
        yield 'show, other user' => ['core_workflow_show', UserFixtures::USER2, 410];
        yield 'show, admin' => ['core_workflow_show', UserFixtures::ADMIN, 200];
        yield 'widget, anonymous' => ['core_workflow_widget', null, 404];
        yield 'widget, author' => ['core_workflow_widget', UserFixtures::USER, 404];
        yield 'tasks, anonymous' => ['core_workflow_task_list', null, 410];
        yield 'tasks, author' => ['core_workflow_task_list', UserFixtures::USER, 200];
        yield 'tasks, other user' => ['core_workflow_task_list', UserFixtures::USER2, 410];
        yield 'statistics, other user' => ['core_workflow_statistics', UserFixtures::USER2, 410];
    }

    /**
     * Never published: not found for the others.
     */
    public function testShowNew(): void
    {
        $this->submitNewForm(static::createClientAs(UserFixtures::USER), UserFixtures::USER);
        $id = $this->findLast()->getId();

        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', $this->path('show', ['id' => $id]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testUnpublishPrivate(): void
    {
        $this->unpublish();
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER);

        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('unpublish', ['id' => self::ID, 'rtu' => '/']));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame([$privateCount, $publicCount], $this->getCounts(UserFixtures::USER));
    }

    /**
     * @dataProvider providePublishers
     */
    public function testPublish(string $username, int $status): void
    {
        $this->unpublish();

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('publish', ['id' => self::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame(302 === $status, $this->find()->getIsPublic());
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

    public function testPublishShowsTheWorkflow(): void
    {
        $this->unpublish();
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER);
        $creationCount = $this->findCreation()->getWorkflowCount();

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('publish', ['id' => self::ID]));

        self::assertTrue($client->getResponse()->isRedirect($this->getShowPath()));
        self::assertTrue($this->find()->getIsPublic());
        self::assertSame([$privateCount - 1, $publicCount + 1], $this->getCounts(UserFixtures::USER));
        self::assertSame($creationCount + 1, $this->findCreation()->getWorkflowCount());
        self::assertSame(1, $this->countPublishActivities());
        self::assertTrue($this->isListed($this->getShowPath()));
    }

    /**
     * @dataProvider providePublicPublishers
     */
    public function testPublishPublic(string $username): void
    {
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER);
        $creationCount = $this->findCreation()->getWorkflowCount();

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('publish', ['id' => self::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame([$privateCount, $publicCount], $this->getCounts(UserFixtures::USER));
        self::assertSame($creationCount, $this->findCreation()->getWorkflowCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePublicPublishers(): iterable
    {
        yield 'author' => [UserFixtures::USER];
        yield 'admin' => [UserFixtures::ADMIN];
    }

    /**
     * Only the admins delete a public workflow: its author once it is private.
     *
     * @dataProvider provideDeleters
     */
    public function testDelete(string $username, bool $private, int $status): void
    {
        if ($private) {
            $this->unpublish();
        }
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER);
        $showPath = $this->getShowPath();

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('delete', ['id' => self::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        if (302 === $status) {
            self::assertTrue($client->getResponse()->isRedirect($this->path('list')));
            self::assertNull($this->find());
            self::assertCount(0, static::getEntityManager()->getRepository(Task::class)->findBy(['workflow' => self::ID]));
            self::assertSame($private ? [$privateCount - 1, $publicCount] : [$privateCount, $publicCount - 1], $this->getCounts(UserFixtures::USER));
            self::assertFalse($this->isListed($showPath));
        } else {
            self::assertNotNull($this->find());
        }
    }

    /**
     * @return iterable<string, array{string, bool, int}>
     */
    public static function provideDeleters(): iterable
    {
        yield 'author, public' => [UserFixtures::USER, false, 404];
        yield 'author, private' => [UserFixtures::USER, true, 302];
        yield 'other user, private' => [UserFixtures::USER2, true, 404];
        yield 'admin, public' => [UserFixtures::ADMIN, false, 302];
    }

    public function testShowDeleted(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('delete', ['id' => self::ID]));
        self::assertSame(302, $client->getResponse()->getStatusCode());

        $client = static::createClient();
        $client->request('GET', $this->path('show', ['id' => self::ID]));

        self::assertSame(410, $client->getResponse()->getStatusCode());
    }

    /**
     * @dataProvider provideLockers
     */
    public function testLock(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $client->request('GET', $this->path('lock', ['id' => self::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame(302 === $status, $this->find()->getIsLocked());
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
     * Once unpublished, a locked workflow stays private.
     */
    public function testPublishLocked(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('lock', ['id' => self::ID]));
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->unpublish();

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('publish', ['id' => self::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertTrue($this->find()->getIsPrivate());
    }

    /**
     * @dataProvider provideChowners
     */
    public function testChown(string $username, string $owner, int $status): void
    {
        [, $publicCount] = $this->getCounts(UserFixtures::USER);
        [, $ownerPublicCount] = $this->getCounts($owner);

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('chown', ['id' => self::ID, 'owner' => $owner]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        if (302 === $status) {
            self::assertTrue($client->getResponse()->isRedirect($this->getShowPath()));
            self::assertSame($owner, $this->find()->getUser()->getUsername());
            self::assertSame($publicCount - 1, $this->getCounts(UserFixtures::USER)[1]);
            self::assertSame($ownerPublicCount + 1, $this->getCounts($owner)[1]);
        } else {
            self::assertSame(UserFixtures::USER, $this->find()->getUser()->getUsername());
        }
    }

    /**
     * An admin gives the workflow to anyone, its author only to one of its teams.
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
     * Its author duplicates a workflow: a private one, with the same tasks, labels and parts.
     */
    public function testCopy(): void
    {
        [$privateCount] = $this->getCounts(UserFixtures::USER);

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('copy', ['id' => self::ID]));

        $copy = $this->findLast();
        self::assertNotSame(self::ID, $copy->getId());
        self::assertTrue($client->getResponse()->isRedirect($this->path('show', ['id' => $copy->getSluggedId()])));
        self::assertSame(UserFixtures::USER, $copy->getUser()->getUsername());
        self::assertTrue($copy->getIsPrivate());
        self::assertSame('Fabrication d\'une table basse - copie', $copy->getTitle());
        self::assertSame($privateCount + 1, $this->getCounts(UserFixtures::USER)[0]);
        self::assertSame(['Débiter les planches', 'Poncer et huiler'], $this->getTaskTitles($copy));
        self::assertSame(['Débit'], $copy->getLabels()->map(fn ($label) => $label->getName())->toArray());
        self::assertSame(['Plateau'], $copy->getParts()->map(fn ($part) => $part->getName())->toArray());
        self::assertCount(0, $copy->getInspirations());
    }

    /**
     * Another account copies it too: the copy is inspired by the workflow.
     */
    public function testCopyAsOtherUser(): void
    {
        [$authorPrivateCount] = $this->getCounts(UserFixtures::USER);
        [$privateCount] = $this->getCounts(UserFixtures::USER2);
        $copyCount = $this->find()->getCopyCount();

        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', $this->path('copy', ['id' => self::ID]));

        $copy = $this->findLast();
        self::assertTrue($client->getResponse()->isRedirect($this->path('show', ['id' => $copy->getSluggedId()])));
        self::assertSame(UserFixtures::USER2, $copy->getUser()->getUsername());
        self::assertTrue($copy->getIsPrivate());
        self::assertSame(['Débiter les planches', 'Poncer et huiler'], $this->getTaskTitles($copy));
        self::assertSame([self::ID], array_values($copy->getInspirations()->map(fn (Workflow $workflow) => $workflow->getId())->toArray()));
        self::assertSame($copyCount + 1, $this->find()->getCopyCount());

        self::assertSame([$authorPrivateCount, $privateCount + 1], [$this->getCounts(UserFixtures::USER)[0], $this->getCounts(UserFixtures::USER2)[0]]);
    }

    /**
     * The tasks start again: their durations become the estimated ones, the first ones are
     * workable, the others pending. The workspaces get it from the Mercure hub.
     */
    public function testRestart(): void
    {
        $this->findTask('Débiter les planches')->setDuration(1800);
        $this->setStatus('Débiter les planches', Task::STATUS_DONE);
        $this->setStatus('Poncer et huiler', Task::STATUS_RUNNING);

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('restart', ['id' => self::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $task = $this->findTask('Débiter les planches');
        self::assertSame([0, 1800], [$task->getDuration(), $task->getEstimatedDuration()]);
        self::assertSame([0, 1800], [$this->find()->getDuration(), $this->find()->getEstimatedDuration()]);
        $updates = $client->getContainer()->get('tests.mercure_hub')->getUpdates();
        self::assertCount(1, $updates);
        // The workflow of "user" (id 2)
        self::assertSame(['https://lairdubois.fr/workflows/1', 'https://lairdubois.fr/users/2/workflows/1'], $updates[0]->getTopics());
        self::assertTrue($updates[0]->isPrivate());
        $data = json_decode($updates[0]->getData(), true);
        self::assertSame(['workflowInfos', 'updatedTaskInfos'], array_keys($data));
        self::assertCount(2, $data['updatedTaskInfos']);
        self::assertSame(Task::STATUS_WORKABLE, $this->findTask('Débiter les planches')->getStatus());
        self::assertSame(Task::STATUS_PENDING, $this->findTask('Poncer et huiler')->getStatus());
    }

    /**
     * Only the author of the workflow and the admins restart it, as they change its tasks.
     */
    public function testRestartAsOtherUser(): void
    {
        $this->setStatus('Débiter les planches', Task::STATUS_DONE);

        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', $this->path('restart', ['id' => self::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame(Task::STATUS_DONE, $this->findTask('Débiter les planches')->getStatus());
        self::assertSame([], $client->getContainer()->get('tests.mercure_hub')->getUpdates());
    }

    private function path(string $action, array $parameters = []): string
    {
        return static::generatePath('core_workflow_'.$action, $parameters);
    }

    private function createClientFor(?string $username): KernelBrowser
    {
        return null === $username ? static::createClient() : static::createClientAs($username);
    }

    private function find(int $id = self::ID): ?Workflow
    {
        return static::getEntityManager()->getRepository(Workflow::class)->find($id);
    }

    private function findLast(): Workflow
    {
        return static::getEntityManager()->getRepository(Workflow::class)->findOneBy([], ['id' => 'DESC']);
    }

    private function findTask(string $title, int $workflowId = self::ID): Task
    {
        return static::getEntityManager()->getRepository(Task::class)->findOneBy(['workflow' => $workflowId, 'title' => $title]);
    }

    /**
     * @return list<string>
     */
    private function getTaskTitles(Workflow $workflow): array
    {
        $titles = $workflow->getTasks()->map(fn (Task $task) => $task->getTitle())->toArray();
        sort($titles);

        return $titles;
    }

    private function setStatus(string $title, int $status): void
    {
        $this->findTask($title)->setStatus($status);
        static::getEntityManager()->flush();
    }

    private function findCreation(): Creation
    {
        return static::getEntityManager()->getRepository(Creation::class)->find(1);
    }

    private function submitNewForm(KernelBrowser $client, string $owner): void
    {
        $client->submit($this->getNewForm($client, $owner)->setValues([
            'ladb_workflow_workflow[title]' => 'fabrication d\'un tabouret',
            'ladb_workflow_workflow[body]' => 'Un tabouret en chêne, assemblé à tenons et mortaises.',
            'ladb_workflow_workflow[tags]' => 'tabouret,chêne',
        ]));

        // On an invalid form, the message tells which field
        self::assertSame(302, $client->getResponse()->getStatusCode(), implode("\n", $client->getCrawler()->filter('.help-block')->each(fn (Crawler $node) => $node->text(null, false))));
    }

    private function getNewForm(KernelBrowser $client, string $owner): Form
    {
        $crawler = $client->request('GET', $this->path('new', UserFixtures::TEAM === $owner ? ['owner' => $owner] : []));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return $this->selectForm($crawler, $this->path('create'));
    }

    private function selectForm(Crawler $crawler, string $action): Form
    {
        return $crawler->filter(\sprintf('form[action="%s"]', $action))->form();
    }

    /**
     * @return array{int, int} The private and public workflow counts of $username
     */
    private function getCounts(string $username): array
    {
        $meta = static::findUser($username)->getMeta();

        return [$meta->getPrivateWorkflowCount(), $meta->getPublicWorkflowCount()];
    }

    private function countPublishActivities(): int
    {
        return \count(static::getEntityManager()->getRepository(Publish::class)->findBy(['entityType' => Workflow::TYPE, 'entityId' => self::ID]));
    }

    private function unpublish(): KernelBrowser
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('unpublish', ['id' => self::ID, 'rtu' => '/']));
        self::assertSame(302, $client->getResponse()->getStatusCode());

        return $client;
    }

    private function getShowPath(): string
    {
        return $this->path('show', ['id' => $this->find()->getSluggedId()]);
    }

    /**
     * Whether the list of the workflows, which comes from the index, links to $showPath.
     */
    private function isListed(string $showPath): bool
    {
        $client = static::createClient();
        static::getIndexes()->refresh();
        $client->request('GET', $this->path('list'));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return 0 < $client->getCrawler()->filter(\sprintf('a[href$="%s"]', $showPath))->count();
    }

    private function assertShowStatus(int $status, ?string $username): void
    {
        $client = $this->createClientFor($username);
        $client->request('GET', $this->path('show', ['id' => self::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode(), \sprintf('Show as %s.', $username ?? 'anonymous'));
    }
}
