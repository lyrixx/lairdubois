<?php

namespace Tests\Knowledge;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Activity\Contribute;
use Ladb\CoreBundle\Entity\Core\Watch;
use Ladb\CoreBundle\Entity\Knowledge\AbstractKnowledge;
use Ladb\CoreBundle\Entity\Knowledge\Value\BaseValue;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Tests\WebTestCase;

/**
 * What the knowledge types share (woods, books, software, tools, providers, schools). A knowledge
 * has no author, no draft and no edit form: any user creates one with its mandatory values, then
 * fills it with values that others vote for (ValueTest). It is published at its creation, and
 * only the admins delete it.
 *
 * They act on the knowledge of id 1 of the fixtures, whose values are from "user".
 */
abstract class KnowledgeTestCase extends WebTestCase
{
    use ValueTrait;

    protected const ID = 1;

    // The values of a new knowledge: the ones of getNewFormValues()
    protected const NEW_VALUE_COUNT = 2;

    /**
     * Prefix of the names of the routes of the type: "core_wood" for "core_wood_show"...
     */
    abstract protected static function getRoutePrefix(): string;

    /**
     * @return class-string<AbstractKnowledge>
     */
    abstract protected static function getEntityClass(): string;

    /**
     * The values of a valid form of a new knowledge, by field name, without their source type.
     *
     * @return array<string, string>
     */
    abstract protected function getNewFormValues(): array;

    /**
     * A value for a field that the knowledge of the fixtures has no value for.
     *
     * @return array{string, array<string, string>, mixed} The field, the values of its form by
     *                                                     field name, and the data the knowledge
     *                                                     gets for the field
     */
    abstract protected function getProposal(): array;

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

    public function testList(): void
    {
        self::assertTrue($this->isListed($this->getShowPath(static::ID)));
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
        yield 'delete' => ['GET', 'delete', ['id' => static::ID]];
    }

    public function testNewWithUnconfirmedEmail(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        static::findUser(UserFixtures::USER2)->setEmailConfirmed(false);
        static::getEntityManager()->flush();

        $client->request('GET', $this->path('new'));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * Any user creates a knowledge, published at once: its mandatory values are its proposals.
     */
    public function testCreate(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $proposalCount = static::findUser(UserFixtures::USER2)->getMeta()->getProposalCount();
        $lastId = $this->findLast()->getId();

        $form = $this->getNewForm($client)->setValues($this->getNewFormValues());
        foreach (array_keys($form->all()) as $name) {
            if (str_ends_with($name, '[sourceType]')) {
                $form[$name] = (string) BaseValue::SOURCE_TYPE_PERSONAL;
            }
        }
        $client->submit($form);

        // On an invalid form, the message tells which field
        self::assertSame(302, $client->getResponse()->getStatusCode(), implode("\n", $client->getCrawler()->filter('.help-block, .alert-danger')->each(fn (Crawler $node) => $node->text(null, false))));
        $knowledge = $this->findLast();
        self::assertGreaterThan($lastId, $knowledge->getId());
        self::assertTrue($client->getResponse()->isRedirect($this->getShowPath($knowledge->getId())));
        self::assertSame(1, $knowledge->getContributorCount());

        $values = $this->findValues($knowledge);
        self::assertCount(static::NEW_VALUE_COUNT, $values);
        foreach ($values as $value) {
            self::assertSame(UserFixtures::USER2, $value->getUser()->getUsername());
            self::assertCount(1, static::getEntityManager()->getRepository(Contribute::class)->findBy(['value' => $value]));
        }
        self::assertSame($proposalCount + static::NEW_VALUE_COUNT, static::findUser(UserFixtures::USER2)->getMeta()->getProposalCount());

        self::assertTrue($this->isListed($this->getShowPath($knowledge->getId())));
        $client = static::createClient();
        $client->request('GET', $this->path('show', ['id' => $knowledge->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testCreateInvalid(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $lastId = $this->findLast()->getId();

        $client->submit($this->getNewForm($client));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $client->getCrawler()->filter('.alert-danger'));
        self::assertSame($lastId, $this->findLast()->getId());
    }

    /**
     * @dataProvider provideDeleters
     */
    public function testDelete(string $username, int $status): void
    {
        $proposalCount = static::findUser(UserFixtures::USER)->getMeta()->getProposalCount();
        $showPath = $this->getShowPath(static::ID);
        $valueCount = \count($this->findValues($this->find(static::ID)));

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('delete', ['id' => static::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        if (302 !== $status) {
            self::assertNotNull($this->find(static::ID));

            return;
        }

        self::assertTrue($client->getResponse()->isRedirect($this->path('list')));
        static::getEntityManager()->clear();
        self::assertNull($this->find(static::ID));
        self::assertSame($proposalCount - $valueCount, static::findUser(UserFixtures::USER)->getMeta()->getProposalCount());
        self::assertFalse($this->isListed($showPath));

        $client = static::createClient();
        $client->request('GET', $this->path('show', ['id' => static::ID]));
        self::assertSame(410, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideDeleters(): iterable
    {
        yield 'author of its values' => [UserFixtures::USER, 404];
        yield 'other user' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 302];
    }

    /**
     * A value for an empty field gives it its data, and makes its author a contributor who
     * watches the knowledge.
     */
    public function testProposeValue(): void
    {
        [$field, $values, $data] = $this->getProposal();
        $client = static::createClientAs(UserFixtures::USER2);
        $knowledge = $this->find(static::ID);
        $contributorCount = $knowledge->getContributorCount();
        $proposalCount = static::findUser(UserFixtures::USER2)->getMeta()->getProposalCount();

        static::proposeValue($client, $knowledge, $field, $values);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $value = static::findLastValue();
        self::assertSame($field, $value->getParentEntityField());
        self::assertSame(static::ID, $value->getParentEntityId());
        self::assertSame(UserFixtures::USER2, $value->getUser()->getUsername());

        $knowledge = $this->find(static::ID);
        self::assertSame($data, PropertyAccess::createPropertyAccessor()->getValue($knowledge, $field));
        self::assertSame($contributorCount + 1, $knowledge->getContributorCount());
        self::assertSame($proposalCount + 1, static::findUser(UserFixtures::USER2)->getMeta()->getProposalCount());
        self::assertCount(1, static::getEntityManager()->getRepository(Contribute::class)->findBy(['value' => $value]));
        self::assertNotNull(static::getEntityManager()->getRepository(Watch::class)->findOneBy([
            'entityType' => $knowledge->getType(),
            'entityId' => static::ID,
            'user' => static::findUser(UserFixtures::USER2),
        ]));
    }

    protected function path(string $action, array $parameters = []): string
    {
        return static::generatePath(static::getRoutePrefix().'_'.$action, $parameters);
    }

    protected function find(int $id): ?AbstractKnowledge
    {
        return static::getEntityManager()->getRepository(static::getEntityClass())->find($id);
    }

    protected function findLast(): AbstractKnowledge
    {
        return static::getEntityManager()->getRepository(static::getEntityClass())->findOneBy([], ['id' => 'DESC']);
    }

    /**
     * @return list<BaseValue>
     */
    protected function findValues(AbstractKnowledge $knowledge): array
    {
        return static::getEntityManager()->getRepository(BaseValue::class)->findBy([
            'parentEntityType' => $knowledge->getType(),
            'parentEntityId' => $knowledge->getId(),
        ]);
    }

    /**
     * The page of a knowledge, with its slug.
     */
    protected function getShowPath(int $id): string
    {
        return $this->path('show', ['id' => $this->find($id)->getSluggedId()]);
    }

    /**
     * A picture of "user2", copied from the ones of the fixtures, for a picture value.
     *
     * @return string Its id
     */
    protected static function createPicture(string $name): string
    {
        $picture = static::$kernel->getContainer()->get('tests.fixture_factory')->picture(static::findUser(UserFixtures::USER2), 'wood-3.jpg', $name);
        static::getEntityManager()->flush();

        return (string) $picture->getId();
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

    private function getNewForm(KernelBrowser $client): Form
    {
        $crawler = $client->request('GET', $this->path('new'));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return $crawler->filter(\sprintf('form[action="%s"]', $this->path('create')))->form();
    }
}
