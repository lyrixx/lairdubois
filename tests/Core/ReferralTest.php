<?php

namespace Tests\Core;

use Ladb\CoreBundle\Entity\Core\Referer\Referral;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Symfony\Bridge\PhpUnit\DnsMock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\WebTestCase;

/**
 * The links of the stickers, on other sites, end with "?referer=1": the page of the content
 * records the site and the page of the HTTP referer (Referer, Referral), with the titles of their
 * pages, and opens a warning, unless the link asks not to (displayRedirectionWarning=0). On the
 * creation of the fixtures.
 */
final class ReferralTest extends WebTestCase
{
    private const URL = 'https://example.org/forum/sujet-1';

    private const PAGES = [
        'https://example.org/' => '<html><head><title>Le forum</title></head></html>',
        self::URL => '<html><head><title>Le sujet</title></head></html>',
    ];

    private MockHttpClient $externalUrlHttpClient;

    /**
     * @dataProvider provideWarnings
     */
    public function testShowRecordsTheReferer(array $query, bool $warning): void
    {
        $crawler = $this->requestFromReferer('/creations/1.html?'.http_build_query(['referer' => 1] + $query));

        $referral = static::getEntityManager()->getRepository(Referral::class)->findOneBy(['url' => self::URL]);
        self::assertSame([Creation::TYPE, 1], [$referral->getEntityType(), $referral->getEntityId()]);
        self::assertSame('Le sujet', $referral->getTitle());
        self::assertSame('Le forum', $referral->getReferer()->getTitle());
        self::assertSame($warning, $referral->getDisplayRedirectionWarning());
        self::assertCount($warning ? 1 : 0, $crawler->filter('#referral_modal'));
    }

    /**
     * @return iterable<string, array{array<string, int>, bool}>
     */
    public static function provideWarnings(): iterable
    {
        yield 'warning' => [[], true];
        yield 'no warning' => [['displayRedirectionWarning' => 0], false];
    }

    public function testShowWithoutRefererParameter(): void
    {
        $crawler = $this->requestFromReferer('/creations/1.html');

        self::assertNull(static::getEntityManager()->getRepository(Referral::class)->findOneBy(['url' => self::URL]));
        self::assertSame(0, $this->externalUrlHttpClient->getRequestsCount());
        self::assertCount(0, $crawler->filter('#referral_modal'));
    }

    private function requestFromReferer(string $uri): Crawler
    {
        $client = static::createClient();
        $this->externalUrlHttpClient = $client->getContainer()->get('tests.external_url_http_client');
        $this->externalUrlHttpClient->setResponseFactory(static fn (string $method, string $url): MockResponse => new MockResponse(self::PAGES[$url]));
        DnsMock::withMockedHosts(['example.org' => [['type' => 'A', 'ip' => '93.184.215.14']]]);
        try {
            $crawler = $client->request('GET', $uri, [], [], ['HTTP_REFERER' => self::URL]);
        } finally {
            DnsMock::withMockedHosts([]);
        }
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return $crawler;
    }
}
