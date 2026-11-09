<?php

namespace Tests\Core;

use Ladb\CoreBundle\Manager\Core\PictureManager;
use Ladb\CoreBundle\Utils\ExternalUrlUtils;
use Ladb\CoreBundle\Utils\OpenGraphUtils;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Symfony\Bridge\PhpUnit\DnsMock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Double\OfflinePictureManager;
use Tests\WebTestCase;

/**
 * The requests to a URL which comes from outside the site (ExternalUrlUtils): the Open Graph
 * metas of the link of a find and their picture (OpenGraphUtils and PictureManager, which the
 * other tests replace by their doubles), the title of the page of a referer (ReferralTest). They
 * go through the client of the site to the MockHttpClient of config_test.yml, and the hosts
 * resolve by DnsMock.
 */
final class ExternalUrlTest extends WebTestCase
{
    private const PAGE_URL = 'https://bois.example/banc-sculpte.html';
    private const PICTURE_URL = 'https://bois.example/banc-sculpte.jpg';
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';

    private TestHandler $logs;

    /** @var list<string> */
    private array $requestedUrls = [];

    private ExternalUrlUtils $externalUrlUtils;

    protected function setUp(): void
    {
        static::bootKernel();
        $this->logs = new TestHandler(Level::Warning);
        static::getContainer()->get('logger')->pushHandler($this->logs);
        $this->externalUrlUtils = static::getContainer()->get(ExternalUrlUtils::class);
        DnsMock::withMockedHosts([
            'bois.example' => [['type' => 'A', 'ip' => '93.184.215.14']],
            'intranet.example' => [['type' => 'A', 'ip' => '10.0.0.1']],
        ]);
        $_SERVER['HTTP_USER_AGENT'] = self::USER_AGENT;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        DnsMock::withMockedHosts([]);
        parent::tearDown();
    }

    public function testPublicPageGivesItsMetasAndItsPicture(): void
    {
        $userAgents = [];
        $this->respond([
            self::PAGE_URL => new MockResponse('<html><head><meta property="og:title" content="Un banc"><meta property="og:image" content="'.self::PICTURE_URL.'"></head></html>'),
            self::PICTURE_URL => new MockResponse(file_get_contents(OfflinePictureManager::PICTURE)),
        ], $userAgents);

        $metas = $this->createOpenGraphUtils()->fetchMetas(self::PAGE_URL);
        $picture = $this->createPictureManager()->createFromUrl($metas['og:image'], false);

        self::assertSame(['og:title' => 'Un banc', 'og:image' => self::PICTURE_URL], $metas);
        self::assertNotNull($picture);
        self::assertSame(\array_slice(getimagesize(OfflinePictureManager::PICTURE), 0, 2), [$picture->getWidth(), $picture->getHeight()]);
        self::assertFileEquals(OfflinePictureManager::PICTURE, $picture->getAbsolutePath());
        // The Open Graph metas are read with the User-Agent of the member, as before
        self::assertSame([self::PAGE_URL, self::PICTURE_URL], $this->requestedUrls);
        self::assertSame([['User-Agent: '.self::USER_AGENT], []], $userAgents);
        unlink($picture->getAbsolutePath());
    }

    /**
     * @dataProvider provideRefusedUrls
     */
    public function testRefusedUrl(string $url): void
    {
        $this->respond([]);

        self::assertNull($this->externalUrlUtils->fetch($url));
        self::assertFalse($this->createOpenGraphUtils()->fetchMetas($url));
        self::assertNull($this->createPictureManager()->createFromUrl($url, false));
        self::assertSame([], $this->requestedUrls);
        self::assertCount(3, $this->logs->getRecords());
        self::assertTrue($this->logs->hasWarning('The request to an external URL failed.'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedUrls(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/banc-sculpte.jpg'];
        yield 'loopback in IPv6' => ['http://[::1]/banc-sculpte.jpg'];
        yield 'private network' => ['https://192.168.1.10/banc-sculpte.jpg'];
        yield 'link-local' => ['http://169.254.169.254/banc-sculpte.jpg'];
        yield 'host of a private network' => ['https://intranet.example/banc-sculpte.jpg'];
        yield 'local file' => ['file://'.OfflinePictureManager::PICTURE];
        yield 'ftp' => ['ftp://bois.example/banc-sculpte.jpg'];
        yield 'gopher' => ['gopher://bois.example/banc-sculpte.jpg'];
    }

    /**
     * @dataProvider provideRefusedRedirections
     */
    public function testRefusedRedirection(string $location): void
    {
        $this->respond([self::PAGE_URL => self::redirectTo($location)]);

        self::assertNull($this->externalUrlUtils->fetch(self::PAGE_URL));
        self::assertSame([self::PAGE_URL], $this->requestedUrls);
        self::assertTrue($this->logs->hasWarning('The request to an external URL failed.'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedRedirections(): iterable
    {
        yield 'loopback' => ['https://127.0.0.1/banc-sculpte.html'];
        yield 'host of a private network' => ['https://intranet.example/banc-sculpte.html'];
        yield 'from https to http' => ['http://bois.example/banc-sculpte.html'];
        yield 'local file' => ['file://'.OfflinePictureManager::PICTURE];
    }

    public function testRedirectionToPublicPage(): void
    {
        $this->respond([
            self::PAGE_URL => self::redirectTo('https://bois.example/trouvailles/banc-sculpte.html'),
            'https://bois.example/trouvailles/banc-sculpte.html' => new MockResponse('Un banc'),
        ]);

        self::assertSame('Un banc', $this->externalUrlUtils->fetch(self::PAGE_URL));
        self::assertSame([self::PAGE_URL, 'https://bois.example/trouvailles/banc-sculpte.html'], $this->requestedUrls);
    }

    /**
     * @dataProvider provideFailures
     */
    public function testFailure(MockResponse $response): void
    {
        $this->respond([self::PICTURE_URL => $response]);

        self::assertNull($this->externalUrlUtils->fetch(self::PICTURE_URL));
        self::assertTrue($this->logs->hasWarning('The request to an external URL failed.'));
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function provideFailures(): iterable
    {
        yield 'error status' => [new MockResponse('Introuvable', ['http_code' => 404])];
        yield 'timeout' => [new MockResponse([''])];
        // Read 1 Mio at a time: the request stops once the response is larger than a picture can be
        yield 'too large' => [new MockResponse((static function (): \Generator {
            for ($i = 0; $i < 100; ++$i) {
                yield str_repeat('a', 1024 * 1024);
            }
        })())];
        yield 'announced as too large' => [new MockResponse('a', ['response_headers' => ['Content-Length: 1000000000']])];
    }

    private function createOpenGraphUtils(): OpenGraphUtils
    {
        return new OpenGraphUtils('', '', '', static::getContainer()->get('logger'), $this->externalUrlUtils);
    }

    private function createPictureManager(): PictureManager
    {
        return new PictureManager($this->externalUrlUtils);
    }

    /**
     * @param array<string, MockResponse> $responses By URL
     * @param list<list<string>>          $userAgents The User-Agent headers of each request
     */
    private function respond(array $responses, array &$userAgents = []): void
    {
        /** @var MockHttpClient $httpClient */
        $httpClient = static::getContainer()->get('tests.external_url_http_client');
        $httpClient->setResponseFactory(function (string $method, string $url, array $options) use ($responses, &$userAgents): MockResponse {
            $this->requestedUrls[] = $url;
            $userAgents[] = $options['normalized_headers']['user-agent'] ?? [];

            return $responses[$url] ?? throw new \LogicException(\sprintf('No response for "%s".', $url));
        });
    }

    private static function redirectTo(string $location): MockResponse
    {
        return new MockResponse('', ['http_code' => 302, 'redirect_url' => $location, 'response_headers' => ['Location: '.$location]]);
    }
}
