<?php

namespace Tests\Video;

use Ladb\CoreBundle\Utils\VideoHostingUtils;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * What the site reads in the URL of a video, and the URLs that Chromium may open
 * (WebScreenshotUtils::captureVideoToPicture()).
 */
final class VideoHostingUtilsTest extends TestCase
{
    private const VIDEO = 'dQw4w9WgXcQ';
    private const PLAYLIST = 'PLrAXtmErZgOeiKm4sgNOknGvNjby9efdf';
    private const DAILYMOTION = 'x7tgad0';
    private const VIMEO = '76979871';

    /**
     * @dataProvider provideUrls
     */
    public function testKindAndEmbedIdentifier(string $url, int $kind, string $embedIdentifier): void
    {
        self::assertSame(['kind' => $kind, 'embedIdentifier' => $embedIdentifier], $this->createUtils()->getKindAndEmbedIdentifier($url));
    }

    /**
     * @dataProvider provideUrls
     */
    public function testHostedVideoUrl(string $url): void
    {
        self::assertTrue($this->createUtils()->isHostedVideoUrl($url));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function provideUrls(): iterable
    {
        foreach (['https://www.youtube.com', 'http://www.youtube.com', 'https://youtube.com', 'https://m.youtube.com', 'https://music.youtube.com'] as $host) {
            yield $host.', watch' => [$host.'/watch?v='.self::VIDEO, VideoHostingUtils::KIND_YOUTUBE, self::VIDEO];
            yield $host.', playlist' => [$host.'/playlist?list='.self::PLAYLIST, VideoHostingUtils::KIND_YOUTUBEPLAYLIST, self::PLAYLIST];
        }
        yield 'YouTube, start' => ['https://www.youtube.com/watch?v='.self::VIDEO.'&t=42s', VideoHostingUtils::KIND_YOUTUBE, self::VIDEO];
        yield 'YouTube, in a playlist' => ['https://www.youtube.com/watch?v='.self::VIDEO.'&list='.self::PLAYLIST.'&index=2', VideoHostingUtils::KIND_YOUTUBE, self::VIDEO];
        yield 'YouTube, embed' => ['https://www.youtube.com/embed/'.self::VIDEO, VideoHostingUtils::KIND_YOUTUBE, self::VIDEO];
        yield 'YouTube, v' => ['https://www.youtube.com/v/'.self::VIDEO.'?version=3', VideoHostingUtils::KIND_YOUTUBE, self::VIDEO];
        yield 'YouTube, screening room' => ['https://www.youtube.com/ytscreeningroom?v='.self::VIDEO, VideoHostingUtils::KIND_YOUTUBE, self::VIDEO];
        yield 'YouTube, feed' => ['https://www.youtube.com/feeds/api/videos/'.self::VIDEO, VideoHostingUtils::KIND_YOUTUBE, self::VIDEO];
        yield 'youtu.be' => ['https://youtu.be/'.self::VIDEO, VideoHostingUtils::KIND_YOUTUBE, self::VIDEO];
        yield 'youtu.be, http' => ['http://youtu.be/'.self::VIDEO, VideoHostingUtils::KIND_YOUTUBE, self::VIDEO];
        yield 'youtu.be, share' => ['https://youtu.be/'.self::VIDEO.'?si=AbCdEfGh_123&t=10', VideoHostingUtils::KIND_YOUTUBE, self::VIDEO];
        yield 'playlist, embed' => ['https://www.youtube.com/embed/'.self::PLAYLIST, VideoHostingUtils::KIND_YOUTUBEPLAYLIST, self::PLAYLIST];
        yield 'playlist, youtu.be' => ['https://youtu.be/'.self::PLAYLIST, VideoHostingUtils::KIND_YOUTUBEPLAYLIST, self::PLAYLIST];
        yield 'playlist, share' => ['https://www.youtube.com/playlist?list='.self::PLAYLIST.'&si=AbCdEfGh_123', VideoHostingUtils::KIND_YOUTUBEPLAYLIST, self::PLAYLIST];

        foreach (['https://www.dailymotion.com', 'http://www.dailymotion.com', 'https://dailymotion.com', 'https://m.dailymotion.com', 'https://touch.dailymotion.com'] as $host) {
            yield $host => [$host.'/video/'.self::DAILYMOTION, VideoHostingUtils::KIND_DAILYMOTION, self::DAILYMOTION];
        }
        yield 'Dailymotion, title' => ['https://www.dailymotion.com/video/'.self::DAILYMOTION.'_un-banc-sculpte_creation', VideoHostingUtils::KIND_DAILYMOTION, self::DAILYMOTION];
        yield 'Dailymotion, playlist' => ['https://www.dailymotion.com/video/'.self::DAILYMOTION.'?playlist=x6lgtp', VideoHostingUtils::KIND_DAILYMOTION, self::DAILYMOTION];
        yield 'Dailymotion, 6 characters' => ['https://www.dailymotion.com/video/x2abc3', VideoHostingUtils::KIND_DAILYMOTION, 'x2abc3'];
        yield 'dai.ly' => ['https://dai.ly/'.self::DAILYMOTION, VideoHostingUtils::KIND_DAILYMOTION, self::DAILYMOTION];

        foreach (['https://vimeo.com', 'http://vimeo.com', 'https://www.vimeo.com'] as $host) {
            yield $host => [$host.'/'.self::VIMEO, VideoHostingUtils::KIND_VIMEO, self::VIMEO];
        }
        yield 'Vimeo, unlisted' => ['https://vimeo.com/'.self::VIMEO.'/0123456789', VideoHostingUtils::KIND_VIMEO, self::VIMEO];
        yield 'Vimeo, query' => ['https://vimeo.com/'.self::VIMEO.'?share=copy&fl=sv', VideoHostingUtils::KIND_VIMEO, self::VIMEO];
        yield 'Vimeo, fragment' => ['https://vimeo.com/'.self::VIMEO.'#t=30s', VideoHostingUtils::KIND_VIMEO, self::VIMEO];
        yield 'capital letters' => ['HTTPS://VIMEO.COM/'.self::VIMEO, VideoHostingUtils::KIND_VIMEO, self::VIMEO];
    }

    /**
     * @dataProvider provideOtherUrls
     */
    public function testOtherUrl(string $url): void
    {
        self::assertFalse($this->createUtils()->isHostedVideoUrl($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOtherUrls(): iterable
    {
        $vimeo = 'vimeo.com/'.self::VIMEO;

        yield 'other host' => ['https://example.test/'.$vimeo];
        yield 'other host, query' => ['https://example.test/?video='.$vimeo];
        yield 'other host, fragment' => ['https://example.test/#'.$vimeo];
        yield 'other domain' => ['https://example'.$vimeo];
        yield 'subdomain of another host' => ['https://vimeo.com.example.test/'.self::VIMEO];
        yield 'loopback' => ['http://127.0.0.1/'.$vimeo];
        yield 'localhost' => ['http://localhost/'.$vimeo];
        yield 'loopback, IPv6' => ['http://[::1]/'.$vimeo];
        yield 'private address' => ['http://10.0.0.1/'.$vimeo];
        yield 'other subdomain' => ['https://example.vimeo.com/'.self::VIMEO];
        yield 'other page of the host' => ['https://vimeo.com/channels/'.$vimeo];
        yield 'user' => ['https://example@'.$vimeo];
        yield 'host as a user' => ['https://vimeo.com@example.test/'.$vimeo];
        yield 'backslash' => ['https://example.test\\@'.$vimeo];
        yield 'port' => ['https://vimeo.com:8080/'.self::VIMEO];
        yield 'no scheme' => [$vimeo];
        yield 'relative scheme' => ['//'.$vimeo];
        yield 'other scheme' => ['ftp://'.$vimeo];
        yield 'file' => ['file:///'.$vimeo];
        yield 'space before' => [' https://'.$vimeo];
        yield 'new line after' => ['https://'.$vimeo."\n"];
        yield 'new line in the host' => ["https://example.test\n.".$vimeo];
        yield 'tab' => ["https://vimeo.com/\t".self::VIMEO];
        yield 'dot segments' => ['https://'.$vimeo.'/../other'];
        yield 'encoded dot segments' => ['https://'.$vimeo.'/%2E%2e/other'];
        yield 'backslash in the path' => ['https://'.$vimeo.'\\..\\other'];
        yield 'not a video' => ['https://vimeo.com/'];
        yield 'empty' => [''];
    }

    private function createUtils(): VideoHostingUtils
    {
        return new VideoHostingUtils('', new Environment(new ArrayLoader()));
    }
}
