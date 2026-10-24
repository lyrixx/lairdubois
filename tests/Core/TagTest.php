<?php

namespace Tests\Core;

/**
 * The tags of a content: links to the list of its type, filtered by the tag. The search by tag
 * and the autocompletion of the tags are in tests/Search/SearchTest.php.
 */
final class TagTest extends InteractionTestCase
{
    public function testTagLinksToTheFilteredList(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', static::generatePath('core_creation_show', ['id' => self::CREATION_ID]));
        $showPath = static::generatePath('core_creation_show', ['id' => static::findCreation()->getSluggedId()]);

        $tags = $crawler->filter('a.ladb-tag');
        // Labels are normalized, without accent
        self::assertSame(['table', 'chene', 'salon'], $tags->each(fn ($tag) => $tag->text()));

        $crawler = $client->click($tags->last()->link());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('q=%40tag%3A%22salon%22', $client->getRequest()->getUri());
        self::assertNotCount(0, $crawler->filter(\sprintf('a[href$="%s"]', $showPath)));
    }

    /**
     * Only an admin highlights the usage of a tag, from no page of the site. For any user, the
     * action answers 500 (#44, in the known failures of the smoke test).
     *
     * @dataProvider provideHighlightRoutes
     */
    public function testHighlightSendsAnonymousToLogin(string $route): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath($route, ['id' => 1]));

        self::assertRedirectsToLogin($client);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideHighlightRoutes(): iterable
    {
        yield 'create' => ['core_tag_usage_highlight_create'];
        yield 'delete' => ['core_tag_usage_highlight_delete'];
    }
}
