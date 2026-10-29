<?php

namespace Tests\Core;

use Ladb\CoreBundle\Entity\Core\Spotlight;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Tests\WebTestCase;

/**
 * The homepage shows the content in the spotlight (ladb:cron:spotlight) in its header, with the
 * template of its type (#196).
 */
final class SpotlightTest extends WebTestCase
{
    /**
     * @dataProvider provideSpotlightables
     */
    public function testHomepage(string $entityClass, string $pathPrefix): void
    {
        $entity = static::getEntityManager()->find($entityClass, 1);
        $spotlight = new Spotlight();
        $spotlight->setEntityType($entity->getType());
        $spotlight->setEntityId($entity->getId());
        $entity->setSpotlight($spotlight);
        static::getEntityManager()->persist($spotlight);
        static::getEntityManager()->flush();
        $title = $entity->getTitle();

        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $header = $crawler->filter('.ladb-header-spotlight');
        self::assertCount(1, $header);
        self::assertSame($title, trim($header->filter('.ladb-heading')->text()));
        self::assertStringContainsString($pathPrefix, $header->attr('onclick'));
    }

    public static function provideSpotlightables(): iterable
    {
        yield 'creation' => [Creation::class, '/creations/1-'];
        yield 'howto' => [Howto::class, '/pas-a-pas/1-'];
    }
}
