<?php

namespace Tests\Find;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Utils\VideoHostingUtils;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Double\OfflineLocalisableUtils;
use Tests\Double\OfflineOpenGraphUtils;
use Tests\Double\OfflineVideoHostingUtils;
use Tests\DoUpTrait;
use Tests\PublicationTestCase;
use Tests\XhrTrait;

/**
 * A find is a link (website or video) or a gallery. The find of the fixtures is a gallery.
 */
final class FindTest extends PublicationTestCase
{
    use DoUpTrait;
    use XhrTrait;

    protected static function getRoutePrefix(): string
    {
        return 'core_find';
    }

    protected static function getEntityClass(): string
    {
        return Find::class;
    }

    protected static function getCounterName(): string
    {
        return 'Find';
    }

    protected function getNewFormValues(string $owner): array
    {
        return $this->getLinkFormValues(OfflineOpenGraphUtils::URL);
    }

    /**
     * The title, the description and the picture of a website come from its Open Graph metas.
     */
    public function testCreateWebsite(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->submitNewForm($client, UserFixtures::USER, $this->getLinkFormValues(OfflineOpenGraphUtils::URL));

        self::assertSame([OfflineOpenGraphUtils::METAS['og:image']], $client->getContainer()->get('tests.picture_manager')->getUrls());
        $find = $this->findLast();
        self::assertSame(Find::KIND_WEBSITE, $find->getKind());
        self::assertSame('bois.example', $find->getContent()->getHost());
        self::assertSame(OfflineOpenGraphUtils::METAS['og:title'], $find->getContent()->getTitle());
        self::assertSame(OfflineOpenGraphUtils::METAS['og:description'], $find->getContent()->getDescription());
        self::assertNotNull($find->getMainPicture()->getWidth());
    }

    /**
     * Without Open Graph metas, a website has no picture: the screenshot by Chromium is disabled.
     */
    public function testCreateWebsiteWithoutOpenGraph(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->submitNewForm($client, UserFixtures::USER, $this->getLinkFormValues('https://bois.example/sans-metas.html'));

        self::assertSame([], $client->getContainer()->get('tests.picture_manager')->getUrls());
        $find = $this->findLast();
        self::assertSame(Find::KIND_WEBSITE, $find->getKind());
        self::assertNull($find->getContent()->getTitle());
        // An empty picture, without file
        self::assertSame(0, $find->getMainPicture()->getWidth());

        $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * @dataProvider provideVideos
     */
    public function testCreateVideo(string $url, int $kind, string $embedIdentifier, string $thumbnailUrl): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->submitNewForm($client, UserFixtures::USER, $this->getLinkFormValues($url));

        self::assertSame([$thumbnailUrl], $client->getContainer()->get('tests.picture_manager')->getUrls());
        $find = $this->findLast();
        self::assertSame(Find::KIND_VIDEO, $find->getKind());
        self::assertSame($kind, $find->getContent()->getKind());
        self::assertSame($embedIdentifier, $find->getContent()->getEmbedIdentifier());
        self::assertNotNull($find->getMainPicture()->getWidth());
    }

    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function provideVideos(): iterable
    {
        yield 'YouTube' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', VideoHostingUtils::KIND_YOUTUBE, 'dQw4w9WgXcQ', 'http://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg'];
        // The thumbnail comes from the API of Vimeo: the one of the double
        yield 'Vimeo' => ['https://vimeo.com/76979871', VideoHostingUtils::KIND_VIMEO, '76979871', 'https://video.example/3/76979871.jpg'];
    }

    /**
     * Without a thumbnail from its host, a video gets a screenshot of its page.
     *
     * @dataProvider provideVideosWithoutThumbnail
     */
    public function testCreateVideoWithoutThumbnail(string $url, int $kind): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->submitNewForm($client, UserFixtures::USER, $this->getLinkFormValues($url));

        self::assertSame([$url], $client->getContainer()->get('tests.web_screenshot_utils')->getUrls());
        $find = $this->findLast();
        self::assertSame($kind, $find->getContent()->getKind());
        self::assertNotNull($find->getMainPicture()->getWidth());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideVideosWithoutThumbnail(): iterable
    {
        $video = OfflineVideoHostingUtils::VIMEO_WITHOUT_THUMBNAIL;
        $playlist = OfflineVideoHostingUtils::PLAYLIST_WITHOUT_THUMBNAIL;

        yield 'Vimeo' => ['https://vimeo.com/'.$video, VideoHostingUtils::KIND_VIMEO];
        yield 'Vimeo, http' => ['http://vimeo.com/'.$video, VideoHostingUtils::KIND_VIMEO];
        yield 'Vimeo, www' => ['https://www.vimeo.com/'.$video, VideoHostingUtils::KIND_VIMEO];
        yield 'Vimeo, unlisted' => ['https://vimeo.com/'.$video.'/0123456789', VideoHostingUtils::KIND_VIMEO];
        yield 'Vimeo, query' => ['https://vimeo.com/'.$video.'?share=copy&fl=sv', VideoHostingUtils::KIND_VIMEO];
        yield 'Vimeo, fragment' => ['https://vimeo.com/'.$video.'#t=30s', VideoHostingUtils::KIND_VIMEO];
        yield 'playlist' => ['https://www.youtube.com/playlist?list='.$playlist, VideoHostingUtils::KIND_YOUTUBEPLAYLIST];
        yield 'playlist, http' => ['http://www.youtube.com/playlist?list='.$playlist, VideoHostingUtils::KIND_YOUTUBEPLAYLIST];
        yield 'playlist, no subdomain' => ['https://youtube.com/playlist?list='.$playlist, VideoHostingUtils::KIND_YOUTUBEPLAYLIST];
        yield 'playlist, mobile' => ['https://m.youtube.com/playlist?list='.$playlist, VideoHostingUtils::KIND_YOUTUBEPLAYLIST];
        yield 'playlist, music' => ['https://music.youtube.com/playlist?list='.$playlist.'&si=abc', VideoHostingUtils::KIND_YOUTUBEPLAYLIST];
        yield 'playlist, embed' => ['https://www.youtube.com/embed/'.$playlist, VideoHostingUtils::KIND_YOUTUBEPLAYLIST];
        yield 'playlist, short' => ['https://youtu.be/'.$playlist, VideoHostingUtils::KIND_YOUTUBEPLAYLIST];
    }

    /**
     * Chromium only opens the page of a video on its host. Any other URL with a video in it stays
     * a video, without a thumbnail, as when a screenshot fails.
     *
     * @dataProvider provideVideosOutOfTheirHost
     */
    public function testCreateVideoOutOfItsHost(string $url, int $kind): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $logFile = static::$kernel->getLogDir().'/test.log';
        clearstatcache();
        $logOffset = file_exists($logFile) ? filesize($logFile) : 0;

        $this->submitNewForm($client, UserFixtures::USER, $this->getLinkFormValues($url));

        self::assertSame([], $client->getContainer()->get('tests.web_screenshot_utils')->getUrls());
        self::assertStringContainsString('app.WARNING: Screenshot refused: not the URL of a video on its host.', (string) file_get_contents($logFile, false, null, $logOffset));
        $find = $this->findLast();
        self::assertSame(Find::KIND_VIDEO, $find->getKind());
        self::assertSame($url, $find->getContent()->getUrl());
        self::assertSame($kind, $find->getContent()->getKind());
        self::assertNull($find->getContent()->getThumbnail());
        self::assertNull($find->getMainPicture());
        $embedIdentifier = $find->getContent()->getEmbedIdentifier();

        $crawler = $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString($embedIdentifier, $crawler->filter('.ladb-find iframe')->attr('src'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideVideosOutOfTheirHost(): iterable
    {
        $vimeo = 'vimeo.com/'.OfflineVideoHostingUtils::VIMEO_WITHOUT_THUMBNAIL;
        $playlist = 'youtu.be/'.OfflineVideoHostingUtils::PLAYLIST_WITHOUT_THUMBNAIL;

        yield 'other host' => ['https://example.test/'.$vimeo, VideoHostingUtils::KIND_VIMEO];
        yield 'other host, playlist' => ['https://example.test/'.$playlist, VideoHostingUtils::KIND_YOUTUBEPLAYLIST];
        yield 'other host, query' => ['https://example.test/?video='.$vimeo, VideoHostingUtils::KIND_VIMEO];
        yield 'other domain' => ['https://example'.$vimeo, VideoHostingUtils::KIND_VIMEO];
        yield 'user' => ['https://vimeo.com@example.test/'.$vimeo, VideoHostingUtils::KIND_VIMEO];
        yield 'loopback' => ['http://127.0.0.1/'.$vimeo, VideoHostingUtils::KIND_VIMEO];
        yield 'localhost' => ['http://localhost/'.$vimeo, VideoHostingUtils::KIND_VIMEO];
        yield 'loopback, IPv6' => ['http://[::1]/'.$vimeo, VideoHostingUtils::KIND_VIMEO];
        yield 'private address' => ['http://10.0.0.1/'.$vimeo, VideoHostingUtils::KIND_VIMEO];
        yield 'private address, 192.168' => ['http://192.168.0.1/'.$playlist, VideoHostingUtils::KIND_YOUTUBEPLAYLIST];
        yield 'name of a service' => ['http://nginx:8080/'.$vimeo, VideoHostingUtils::KIND_VIMEO];
    }

    /**
     * The pages of a video without a thumbnail, once published: the picture is not required.
     */
    public function testPagesOfAVideoWithoutThumbnail(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $this->submitNewForm($client, UserFixtures::USER, $this->getLinkFormValues('https://example.test/vimeo.com/'.OfflineVideoHostingUtils::VIMEO_WITHOUT_THUMBNAIL));
        $id = $this->findLast()->getId();

        $client->request('GET', $this->path('publish', ['id' => $id]));
        self::assertSame(302, $client->getResponse()->getStatusCode());

        foreach (['show', 'card', 'widget'] as $action) {
            static::xhr($client, 'GET', $this->path($action, ['id' => $id]));
            self::assertSame(200, $client->getResponse()->getStatusCode(), $action);
        }
        foreach ([$this->path('list'), '/@user/trouvailles'] as $path) {
            $client->request('GET', $path);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $path);
        }
    }

    public function testCreateGallery(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $pictureId = static::createPicture(UserFixtures::USER, 'test-find');

        $this->submitNewForm($client, UserFixtures::USER, [
            'ladb_find_find[title]' => 'une chaise d\'école',
            'ladb_find_find[contentType]' => (string) Find::CONTENT_TYPE_GALLERY,
            'ladb_find_find[gallery][pictures]' => $pictureId,
            'ladb_find_find[gallery][location]' => 'Bordeaux, France',
            'ladb_find_find[bodyBlocks][0][body]' => 'Vue dans une brocante, en hêtre courbé.',
            'ladb_find_find[tags]' => 'chaise,brocante',
        ]);

        $find = $this->findLast();
        self::assertSame(Find::KIND_GALLERY, $find->getKind());
        self::assertSame((int) $pictureId, $find->getMainPicture()->getId());
        self::assertEqualsWithDelta(OfflineLocalisableUtils::ADDRESSES['Bordeaux, France']['latitude'], $find->getContent()->getLatitude(), 0.000001);
    }

    /**
     * Without a link or a gallery chosen, the form keeps its type (Find::CONTENT_TYPE_NONE): refused,
     * with the message of the Range constraint.
     */
    public function testCreateWithoutContentType(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $form = $this->getNewForm($client, UserFixtures::USER);
        self::assertSame((string) Find::CONTENT_TYPE_NONE, $form->get('ladb_find_find[contentType]')->getValue());
        $values = $this->getLinkFormValues(OfflineOpenGraphUtils::URL);
        unset($values['ladb_find_find[contentType]']);

        $client->submit($form->setValues($values));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(['Vous devez sélectionner un type.'], $client->getCrawler()->filter('.alert-danger li')->each(fn (Crawler $node) => $node->text(null, false)));
    }

    /**
     * @return array<string, string>
     */
    private function getLinkFormValues(string $url): array
    {
        return [
            'ladb_find_find[title]' => 'un banc sculpté',
            'ladb_find_find[contentType]' => (string) Find::CONTENT_TYPE_LINK,
            'ladb_find_find[link][url]' => $url,
            'ladb_find_find[bodyBlocks][0][body]' => 'Un banc repéré sur le site d\'un sculpteur.',
            'ladb_find_find[tags]' => 'banc,sculpture',
        ];
    }
}
