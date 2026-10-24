<?php

namespace Tests\Promotion;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Resource;
use Ladb\CoreBundle\Entity\Promotion\Graphic;
use Tests\PublicationTestCase;

/**
 * A graphic is a file (PDF or SVG) to promote the site, with its thumbnail as main picture.
 */
final class GraphicTest extends PublicationTestCase
{
    protected static function getRoutePrefix(): string
    {
        return 'core_promotion_graphic';
    }

    protected static function getEntityClass(): string
    {
        return Graphic::class;
    }

    protected static function getCounterName(): string
    {
        return 'Graphic';
    }

    protected function getNewFormValues(string $owner): array
    {
        $user = static::findUser($owner);
        $factory = static::getFixtureFactory();
        $resource = $factory->resource($user, 'plan.svg', 'test-graphic', Resource::KIND_SVG, $factory->picture($user, 'wood-2.jpg', 'test-graphic'));
        static::getEntityManager()->flush();

        return [
            'ladb_promotion_graphic[title]' => 'affiche des portes ouvertes',
            'ladb_promotion_graphic[resource]' => (string) $resource->getId(),
            'ladb_promotion_graphic[body]' => 'Une affiche à imprimer pour l\'atelier.',
            'ladb_promotion_graphic[tags]' => 'affiche,atelier',
        ];
    }

    /**
     * The main picture of a graphic is the thumbnail of its file.
     */
    public function testCreateUsesTheThumbnail(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->submitNewForm($client, UserFixtures::USER, $this->getNewFormValues(UserFixtures::USER));

        $graphic = $this->findLast();
        self::assertSame($graphic->getResource()->getThumbnail()->getId(), $graphic->getMainPicture()->getId());
    }

    public function testDownload(): void
    {
        $client = static::createClient();
        $downloadCount = $this->find(static::ID)->getDownloadCount();

        $client->request('GET', $this->path('download', ['id' => static::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringStartsWith('attachment;filename="lairdubois_user_', $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith("PK\x03\x04", $client->getResponse()->getContent());
        self::assertSame($downloadCount + 1, $this->find(static::ID)->getDownloadCount());
    }

    /**
     * Like its page, the file of an unpublished graphic is gone for the others.
     */
    public function testDownloadDraft(): void
    {
        $this->unpublish();

        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', $this->path('download', ['id' => static::ID]));

        self::assertSame(410, $client->getResponse()->getStatusCode());
    }
}
