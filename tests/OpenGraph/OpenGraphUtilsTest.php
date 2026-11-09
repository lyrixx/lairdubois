<?php

namespace Tests\OpenGraph;

use Ladb\CoreBundle\Utils\OpenGraphUtils;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tests\Double\OfflineFacebookGraph;

/**
 * The scrape of a page by Facebook, which PublicationListener asks for at each publication
 * (without debug), on the Graph API (Tests\Double\OfflineFacebookGraph, which answers an error
 * to this request). The other tests use Tests\Double\OfflineOpenGraphUtils, which scrapes the
 * same way.
 */
final class OpenGraphUtilsTest extends TestCase
{
    /**
     * An error of the API is logged, the publication goes on.
     */
    public function testScrapeLogsTheErrorOfTheApi(): void
    {
        $logger = new class() extends AbstractLogger {
            /** @var list<array{mixed, string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
        OfflineFacebookGraph::popRequests();

        (new OpenGraphUtils('access-token', 'app-id', 'app-secret', $logger))->scrape(OfflineFacebookGraph::URL);

        self::assertSame(['https://graph.facebook.com/v5.0/'], OfflineFacebookGraph::popRequests());
        self::assertSame([['error', 'OpenGraphUtils failed to scrape. Facebook SDK returned an error: Unsupported request (Tests\Double\OfflineFacebookGraph).']], $logger->records);
    }
}
