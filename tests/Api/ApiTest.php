<?php

namespace Tests\Api;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Tests\Double\OfflineFacebookGraph;
use Tests\WebTestCase;

/**
 * The API of the site: the share count of a page on a social network, which the share buttons
 * show (Facebook only), from the Graph API of Facebook (Tests\Double\OfflineFacebookGraph).
 */
final class ApiTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        OfflineFacebookGraph::popRequests();
    }

    /**
     * @dataProvider provideUsers
     */
    public function testFacebookShareCount(?string $username): void
    {
        $client = null === $username ? static::createClient() : static::createClientAs($username);
        $client->request('GET', '/api/facebook/share.count.json', ['url' => OfflineFacebookGraph::URL]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame('{"url":"'.OfflineFacebookGraph::URL.'","network":"facebook","count":'.OfflineFacebookGraph::SHARE_COUNT.'}', $client->getResponse()->getContent());

        $requests = OfflineFacebookGraph::popRequests();
        self::assertCount(1, $requests);
        self::assertStringStartsWith('https://graph.facebook.com/v5.0/?', $requests[0]);
        parse_str(parse_url($requests[0], \PHP_URL_QUERY), $query);
        self::assertSame(['id' => OfflineFacebookGraph::URL, 'fields' => 'og_object{engagement}'], array_intersect_key($query, ['id' => true, 'fields' => true]));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function provideUsers(): iterable
    {
        yield 'anonymous' => [null];
        yield 'user' => [UserFixtures::USER];
    }

    /**
     * A page without Open Graph object on Facebook has no share.
     */
    public function testFacebookShareCountOfUnknownPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/facebook/share.count.json', ['url' => 'https://www.lairdubois.fr/creations/2.html']);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('{"url":"https://www.lairdubois.fr/creations/2.html","network":"facebook","count":0}', $client->getResponse()->getContent());
    }

    /**
     * Only the pages of the site, and only Facebook: 404 before any call to the network.
     *
     * @dataProvider provideInvalidRequests
     */
    public function testInvalidRequest(string $network, array $parameters): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/'.$network.'/share.count.json', $parameters);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame([], OfflineFacebookGraph::popRequests());
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function provideInvalidRequests(): iterable
    {
        yield 'no URL' => ['facebook', []];
        yield 'URL of another site' => ['facebook', ['url' => 'https://bois.example/']];
        yield 'URL of the site without HTTPS' => ['facebook', ['url' => 'http://www.lairdubois.fr/']];
        yield 'other network' => ['twitter', ['url' => OfflineFacebookGraph::URL]];
    }

    /**
     * An error of the Graph API (an expired token...) is a 404.
     */
    public function testFacebookError(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/facebook/share.count.json', ['url' => OfflineFacebookGraph::ERROR_URL]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertCount(1, OfflineFacebookGraph::popRequests());
    }
}
