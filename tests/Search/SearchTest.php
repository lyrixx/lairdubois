<?php

namespace Tests\Search;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Collection\Collection;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Knowledge\Book;
use Ladb\CoreBundle\Entity\Knowledge\Provider;
use Ladb\CoreBundle\Entity\Knowledge\School;
use Ladb\CoreBundle\Entity\Knowledge\Software;
use Ladb\CoreBundle\Entity\Knowledge\Tool;
use Ladb\CoreBundle\Entity\Knowledge\Wood;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Entity\Wonder\Workshop;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Ladb\CoreBundle\Utils\TypableUtils;
use Symfony\Bundle\FrameworkBundle\Client;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The search, in the index of the test environment: the search box (which searches the
 * creations), the query of the lists with their facets ("@tag:salon"...), and the
 * autocompletions of the forms. On the contents of id 1 of the fixtures, which nothing changes
 * here.
 */
final class SearchTest extends WebTestCase
{
    use XhrTrait;

    /**
     * @dataProvider provideSearchRoutes
     */
    public function testSearchBoxSearchesTheCreations(string $route): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath($route, ['q' => 'table']));

        self::assertTrue($client->getResponse()->isRedirect(static::generatePath('core_creation_list', ['q' => 'table'])));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideSearchRoutes(): iterable
    {
        yield ['core_search'];
        yield ['core_search_creations'];
    }

    /**
     * A word of its title finds the content in the list of its type, a word of nothing finds
     * nothing.
     *
     * @param class-string $entityClass
     *
     * @dataProvider provideLists
     */
    public function testSearchList(string $route, string $entityClass, string $query): void
    {
        $client = static::createClient();
        $entity = static::getEntityManager()->getRepository($entityClass)->find(1);

        self::assertTrue($this->isListed($client, $route, $query, $entity));
        self::assertFalse($this->isListed($client, $route, 'introuvable', $entity));
    }

    /**
     * @return iterable<string, array{string, class-string, string}>
     */
    public static function provideLists(): iterable
    {
        yield 'creation' => ['core_creation_list', Creation::class, 'basse'];
        yield 'plan' => ['core_plan_list', Plan::class, 'basse'];
        yield 'workshop' => ['core_workshop_list', Workshop::class, 'jardin'];
        yield 'howto' => ['core_howto_list', Howto::class, 'aronde'];
        yield 'find' => ['core_find_list', Find::class, 'banc'];
        yield 'workflow' => ['core_workflow_list', Workflow::class, 'basse'];
        yield 'collection' => ['core_collection_list', Collection::class, 'salon'];
        yield 'wood' => ['core_wood_list', Wood::class, 'Chêne'];
        yield 'provider' => ['core_provider_list', Provider::class, 'Scierie'];
        yield 'school' => ['core_school_list', School::class, 'Bois'];
        yield 'tool' => ['core_tool_list', Tool::class, 'Rabot'];
        yield 'book' => ['core_book_list', Book::class, 'menuisier'];
        yield 'software' => ['core_software_list', Software::class, 'OpenCutList'];
    }

    /**
     * The facets of the list of the creations: the creation of the fixtures, by "user", has the
     * tags "table, chêne, salon", the wood "Chêne", the tools "Rabot, Scie à ruban", a feedback
     * and comments, and is linked to the plan of the fixtures.
     *
     * @dataProvider provideCreationFacets
     */
    public function testCreationFacet(string $query, bool $listed): void
    {
        $client = static::createClient();

        self::assertSame($listed, $this->isListed($client, 'core_creation_list', $query, $this->findCreation()));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideCreationFacets(): iterable
    {
        yield 'tag' => ['@tag:salon', true];
        yield 'other tag' => ['@tag:cuisine', false];
        yield 'wood' => ['@woods:Chêne', true];
        yield 'other wood' => ['@woods:Robinier', false];
        yield 'tool' => ['@tools:Rabot', true];
        yield 'author' => ['@author:user', true];
        yield 'other author' => ['@author:admin', false];
        yield 'with plans' => ['@content-plans', true];
        yield 'with videos' => ['@content-videos', false];
        yield 'with feedback' => ['@with-feedback', true];
        yield 'facet and text' => ['@tag:salon basse', true];
        yield 'sort by likes' => ['@sort-popular-likes', true];
        yield 'sort by comments' => ['@sort-popular-comments', true];
        yield 'sort by views' => ['@sort-popular-views', true];
    }

    /**
     * "@mine" keeps the contents of the user; it does nothing for anonymous.
     */
    public function testMineAsAnonymous(): void
    {
        $client = static::createClient();

        self::assertTrue($this->isListed($client, 'core_creation_list', '@mine', $this->findCreation()));
    }

    /**
     * "@mine" without value keeps the contents of the user.
     *
     * @dataProvider provideMine
     */
    public function testMine(string $username, bool $listed): void
    {
        $client = static::createClientAs($username);

        self::assertSame($listed, $this->isListed($client, 'core_creation_list', '@mine', $this->findCreation()));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideMine(): iterable
    {
        yield 'author' => [UserFixtures::USER, true];
        yield 'other user' => [UserFixtures::USER2, false];
    }

    /**
     * @dataProvider provideMineLists
     */
    public function testMineInEachList(string $route): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', static::generatePath($route, ['q' => '@mine']));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * The lists that know "@mine:draft".
     *
     * @return iterable<string, array{string}>
     */
    public static function provideMineLists(): iterable
    {
        foreach (['core_blog_post_list', 'core_creation_list', 'core_event_list', 'core_faq_question_list', 'core_find_list', 'core_howto_list', 'core_offer_list', 'core_plan_list', 'core_promotion_graphic_list', 'core_qa_question_list', 'core_workshop_list'] as $route) {
            yield $route => [$route];
        }
    }

    /**
     * The creation of the fixtures is no draft.
     */
    public function testMineDraft(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        self::assertFalse($this->isListed($client, 'core_creation_list', '@mine:draft', $this->findCreation()));
    }

    /**
     * @dataProvider provideTypeaheads
     */
    public function testTypeahead(string $route, array $parameters, string $expected): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath($route, $parameters));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $suggestions = json_decode($client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['suggestions'];
        self::assertContains($expected, array_map(fn ($suggestion) => $suggestion['username'] ?? $suggestion, $suggestions));
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function provideTypeaheads(): iterable
    {
        yield 'user' => ['core_search_typeahead_users_json', ['q' => 'user'], UserFixtures::USER2];
        yield 'team' => ['core_search_typeahead_users_json', ['q' => 'Atelier', 'family' => 'teams'], UserFixtures::TEAM];
        yield 'tag' => ['core_search_typeahead_tags_json', ['q' => 'salon'], 'salon'];
        yield 'wood' => ['core_search_typeahead_input_woods_json', ['q' => 'Chêne'], 'Chêne'];
        yield 'tool' => ['core_search_typeahead_input_tools_json', ['q' => 'Rabot'], 'Rabot'];
        yield 'software' => ['core_search_typeahead_softwares_json', ['q' => 'OpenCutList'], 'OpenCutList'];
    }

    /**
     * Teams are no users.
     */
    public function testTypeaheadUsersOnly(): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath('core_search_typeahead_users_json', ['q' => 'Atelier', 'family' => 'users']));

        self::assertSame(['suggestions' => ['']], json_decode($client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    /**
     * Whether the list of $route, searched with $query, links to the page of $entity.
     */
    private function isListed(Client $client, string $route, string $query, object $entity): bool
    {
        $showPath = static::$container->get(TypableUtils::NAME)->getUrlAction($entity, 'show', false);
        $crawler = $client->request('GET', static::generatePath($route, ['q' => $query]));
        self::assertSame(200, $client->getResponse()->getStatusCode(), \sprintf('Search of "%s" in %s.', $query, $route));

        return 0 < $crawler->filter(\sprintf('a[href$="%s"]', $showPath))->count();
    }

    private function findCreation(): Creation
    {
        return static::getEntityManager()->getRepository(Creation::class)->find(1);
    }
}
