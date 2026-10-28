<?php

namespace Tests\Find;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Utils\VideoHostingUtils;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Double\OfflineLocalisableUtils;
use Tests\Double\OfflineOpenGraphUtils;
use Tests\PublicationTestCase;

/**
 * A find is a link (website or video) or a gallery. The find of the fixtures is a gallery.
 */
final class FindTest extends PublicationTestCase
{
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
