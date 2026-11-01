<?php

namespace Tests\Core;

use Ladb\CoreBundle\Entity\Core\Referer\Referral;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Double\OfflineHttpStream;
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
        self::assertSame([], OfflineHttpStream::getUrls());
        self::assertCount(0, $crawler->filter('#referral_modal'));
    }

    private function requestFromReferer(string $uri): Crawler
    {
        $client = static::createClient();
        OfflineHttpStream::register([
            'https://example.org' => '<html><head><title>Le forum</title></head></html>',
            self::URL => '<html><head><title>Le sujet</title></head></html>',
        ]);
        try {
            $crawler = $client->request('GET', $uri, [], [], ['HTTP_REFERER' => self::URL]);
        } finally {
            OfflineHttpStream::unregister();
        }
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return $crawler;
    }
}
