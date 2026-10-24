<?php

namespace Tests\Collection;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Collection\Collection;
use Ladb\CoreBundle\Entity\Collection\Entry;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Component\DomCrawler\Form;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * A collection gathers contents of others. It is private at its creation, an admin unpublishes
 * it, and its author deletes it once private. The collection of the fixtures, published by
 * "user", has creation 1, plan 1 and howto 1.
 *
 * Not a draftable publication: PublicationTestCase does not fit it (no draft, no chown).
 */
final class CollectionTest extends WebTestCase
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
        self::assertStringContainsStringIgnoringCase('mes meubles de salon', $crawler->filter('title')->text());
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
     * The tab of a type of its contents.
     */
    public function testShowType(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $this->path('show_type', ['id' => self::ID, 'entityType' => Creation::TYPE]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $crawler->filter('a[href*="/creations/1-"]'));
    }

    public function testList(): void
    {
        self::assertTrue($this->isListed($this->getShowPath()));
    }

    /**
     * The collections of a content.
     */
    public function testListByEntity(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $this->path('list_byentity', ['entityType' => Creation::TYPE, 'entityId' => self::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $crawler->filter(\sprintf('a[href$="%s"]', $this->getShowPath())));
    }

    /**
     * The collections of the user, to add a content to.
     */
    public function testListAvailable(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $crawler = static::xhr($client, 'GET', $this->path('list_available', ['entityType' => Find::TYPE, 'entityId' => self::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsStringIgnoringCase('mes meubles de salon', $crawler->text());
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
        yield 'list available' => ['GET', 'list_available', ['entityType' => Find::TYPE, 'entityId' => self::ID]];
        yield 'list available, page' => ['GET', 'list_available_page', ['entityType' => Find::TYPE, 'entityId' => self::ID, 'page' => 1]];
        yield 'entry create' => ['GET', 'entry_create', ['id' => self::ID, 'entityType' => Find::TYPE, 'entityId' => self::ID]];
        yield 'entry delete' => ['GET', 'entry_delete', ['id' => self::ID, 'entityType' => Creation::TYPE, 'entityId' => self::ID]];
    }

    /**
     * A new collection is private: only its author and the admins see it.
     */
    public function testCreate(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER2);

        $client->submit($this->getNewForm($client)->setValues($this->getNewFormValues()));

        self::assertSame(302, $client->getResponse()->getStatusCode());
        $collection = $this->findLast();
        self::assertTrue($client->getResponse()->isRedirect($this->path('show', ['id' => $collection->getId()])));
        self::assertSame(UserFixtures::USER2, $collection->getUser()->getUsername());
        self::assertTrue($collection->getIsPrivate());
        self::assertSame([$privateCount + 1, $publicCount], $this->getCounts(UserFixtures::USER2));

        $this->assertShowStatus(200, UserFixtures::USER2, $collection->getId());
        $this->assertShowStatus(200, UserFixtures::ADMIN, $collection->getId());
        $this->assertShowStatus(404, UserFixtures::USER, $collection->getId());
        $this->assertShowStatus(404, null, $collection->getId());
    }

    /**
     * Created from a content, the collection gets it, and the page of the content comes back.
     */
    public function testCreateWithEntry(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $find = $this->findFind();

        $form = $this->getNewForm($client)->setValues($this->getNewFormValues());
        $client->request('POST', $form->getUri().'?entityType='.Find::TYPE.'&entityId='.self::ID, $form->getPhpValues());

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/trouvailles/'.$find->getSluggedId().'.html', $client->getResponse()->headers->get('Location'));
        $collection = $this->findLast();
        self::assertSame(1, $collection->getEntryCount());
        self::assertSame($find->getPrivateCollectionCount() + 1, $this->findFind()->getPrivateCollectionCount());
    }

    /**
     * An invalid form comes back with its errors, and keeps the content to add.
     */
    public function testCreateInvalid(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $lastId = $this->findLast()->getId();

        $form = $this->getNewForm($client);
        $crawler = $client->request('POST', $form->getUri().'?entityType='.Find::TYPE.'&entityId='.self::ID, $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($lastId, $this->findLast()->getId());
        self::assertNotCount(0, $crawler->filter('.alert-danger'));
        self::assertCount(1, $crawler->filter(\sprintf('form[action="%s"]', $this->path('create', ['entityType' => Find::TYPE, 'entityId' => self::ID]))));
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
        $form = $crawler->filter(\sprintf('form[action="%s"]', $this->path('update', ['id' => self::ID])))->form([
            'ladb_collection_collection[title]' => 'mes meubles de cuisine',
        ]);

        $client->submit($form);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $client->getCrawler()->filter('.alert-success'));
        self::assertSame('Mes meubles de cuisine', $this->find()->getTitle());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUpdaters(): iterable
    {
        yield 'author' => [UserFixtures::USER];
        yield 'admin' => [UserFixtures::ADMIN];
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

    /**
     * Its contents count it as a private collection, and it leaves the index.
     */
    public function testUnpublishHidesTheCollection(): void
    {
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER);
        $creationCount = $this->findCreation()->getPublicCollectionCount();

        $this->unpublish();

        self::assertTrue($this->find()->getIsPrivate());
        self::assertSame([$privateCount + 1, $publicCount - 1], $this->getCounts(UserFixtures::USER));
        self::assertSame($creationCount - 1, $this->findCreation()->getPublicCollectionCount());
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
        [$privateCount, $publicCount] = $this->getCounts(UserFixtures::USER);

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('publish', ['id' => self::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        if (302 === $status) {
            self::assertTrue($this->find()->getIsPublic());
            self::assertSame([$privateCount - 1, $publicCount + 1], $this->getCounts(UserFixtures::USER));
            self::assertTrue($this->isListed($this->getShowPath()));
        } else {
            self::assertTrue($this->find()->getIsPrivate());
        }
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
     * Only the admins delete a public collection: its author once it is private.
     *
     * @dataProvider provideDeleters
     */
    public function testDelete(string $username, bool $private, int $status): void
    {
        if ($private) {
            $this->unpublish();
        }
        $creationCount = $this->countCollectionsOfCreation();

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('delete', ['id' => self::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        if (302 === $status) {
            self::assertTrue($client->getResponse()->isRedirect($this->path('list')));
            self::assertNull($this->find());
            self::assertCount(0, static::getEntityManager()->getRepository(Entry::class)->findBy(['collection' => self::ID]));
            self::assertSame($creationCount - 1, $this->countCollectionsOfCreation());
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

    /**
     * @dataProvider provideEntryEditors
     */
    public function testAddEntry(string $username, int $status): void
    {
        $entryCount = $this->find()->getEntryCount();
        $findCount = $this->findFind()->getPublicCollectionCount();

        $client = static::createClientAs($username);
        static::xhr($client, 'GET', $this->path('entry_create', ['id' => self::ID, 'entityType' => Find::TYPE, 'entityId' => self::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        $added = 200 === $status ? 1 : 0;
        self::assertSame($entryCount + $added, $this->find()->getEntryCount());
        self::assertSame($findCount + $added, $this->findFind()->getPublicCollectionCount());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideEntryEditors(): iterable
    {
        yield 'author' => [UserFixtures::USER, 200];
        yield 'other user' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    /**
     * Only a public content goes in a collection.
     */
    public function testAddDraftEntry(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', '/trouvailles/1/unpublish?rtu=/');
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $entryCount = $this->find()->getEntryCount();

        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'GET', $this->path('entry_create', ['id' => self::ID, 'entityType' => Find::TYPE, 'entityId' => self::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame($entryCount, $this->find()->getEntryCount());
    }

    /**
     * @dataProvider provideEntryEditors
     */
    public function testRemoveEntry(string $username, int $status): void
    {
        $entryCount = $this->find()->getEntryCount();
        $creationCount = $this->findCreation()->getPublicCollectionCount();

        $client = static::createClientAs($username);
        static::xhr($client, 'GET', $this->path('entry_delete', ['id' => self::ID, 'entityType' => Creation::TYPE, 'entityId' => self::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        $removed = 200 === $status ? 1 : 0;
        self::assertSame($entryCount - $removed, $this->find()->getEntryCount());
        self::assertSame($creationCount - $removed, $this->findCreation()->getPublicCollectionCount());
    }

    private function path(string $action, array $parameters = []): string
    {
        return static::generatePath('core_collection_'.$action, $parameters);
    }

    private function createClientFor(?string $username): Client
    {
        return null === $username ? static::createClient() : static::createClientAs($username);
    }

    private function find(int $id = self::ID): ?Collection
    {
        return static::getEntityManager()->getRepository(Collection::class)->find($id);
    }

    private function findLast(): Collection
    {
        return static::getEntityManager()->getRepository(Collection::class)->findOneBy([], ['id' => 'DESC']);
    }

    private function countCollectionsOfCreation(): int
    {
        $creation = $this->findCreation();

        return $creation->getPrivateCollectionCount() + $creation->getPublicCollectionCount();
    }

    private function findCreation(): Creation
    {
        return static::getEntityManager()->getRepository(Creation::class)->find(self::ID);
    }

    private function findFind(): Find
    {
        return static::getEntityManager()->getRepository(Find::class)->find(self::ID);
    }

    /**
     * @return array<string, string>
     */
    private function getNewFormValues(): array
    {
        return [
            'ladb_collection_collection[title]' => 'mes outils de tournage',
            'ladb_collection_collection[body]' => 'Les gouges et les tours qui me font envie.',
            'ladb_collection_collection[tags]' => 'tournage,outil',
        ];
    }

    private function getNewForm(Client $client): Form
    {
        $crawler = $client->request('GET', $this->path('new'));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return $crawler->filter(\sprintf('form[action="%s"]', $this->path('create')))->form();
    }

    /**
     * @return array{int, int} The private and public collection counts of $username
     */
    private function getCounts(string $username): array
    {
        $meta = static::findUser($username)->getMeta();

        return [$meta->getPrivateCollectionCount(), $meta->getPublicCollectionCount()];
    }

    private function unpublish(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('unpublish', ['id' => self::ID, 'rtu' => '/']));
        self::assertSame(302, $client->getResponse()->getStatusCode());
    }

    private function getShowPath(): string
    {
        return $this->path('show', ['id' => $this->find()->getSluggedId()]);
    }

    /**
     * Whether the list of the collections, which comes from the index, links to $showPath.
     */
    private function isListed(string $showPath): bool
    {
        $client = static::createClient();
        $client->getContainer()->get('fos_elastica.index.ladb')->refresh();
        $client->request('GET', $this->path('list'));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return 0 < $client->getCrawler()->filter(\sprintf('a[href$="%s"]', $showPath))->count();
    }

    private function assertShowStatus(int $status, ?string $username, int $id = self::ID): void
    {
        $client = $this->createClientFor($username);
        $client->request('GET', $this->path('show', ['id' => $id]));

        self::assertSame($status, $client->getResponse()->getStatusCode(), \sprintf('Show of #%d as %s.', $id, $username ?? 'anonymous'));
    }
}
