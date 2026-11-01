<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Picture;

/**
 * The form of a picture of a gallery (legend, source, rotation), that the JS of the gallery loads
 * in XHR: the gallery (formSection) and whether its pictures are sortable go in the query of the
 * form, then of its action, and the updated thumbnail goes back to its gallery. On the main
 * picture of the creation of the fixtures, of "user".
 */
final class PictureTest extends InteractionTestCase
{
    /**
     * @dataProvider provideGalleries
     */
    public function testUpdateGoesBackToTheGallery(array $query, string $formSection, bool $sortable): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $id = static::findCreation()->getMainPicture()->getId();

        $crawler = static::xhr($client, 'GET', static::generatePath('core_picture_edit', ['id' => $id] + $query));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter(\sprintf('#edit_%s_%d_modal', $formSection, $id)));
        $form = $crawler->filter(\sprintf('form[action="%s"]', static::generatePath('core_picture_update', ['id' => $id, 'formSection' => $formSection, 'sortable' => $sortable ? 1 : 0])))->form([
            'ladb_editpicture[legend]' => 'Le banc fini',
        ]);

        $crawler = static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('Le banc fini', static::getEntityManager()->getRepository(Picture::class)->find($id)->getLegend());
        self::assertCount(1, $crawler->filter(\sprintf('li#picture_%d', $id)));
        self::assertSame($sortable, $crawler->filter(\sprintf('li#picture_%d.ladb-sortable', $id))->count() > 0);
        self::assertStringContainsString(\sprintf("pictureGalleryRemovePicture('%s', %d)", $formSection, $id), $client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{array<string, int|string>, string, bool}>
     */
    public static function provideGalleries(): iterable
    {
        yield 'gallery of the form' => [['formSection' => 'creation_pictures', 'sortable' => 1], 'creation_pictures', true];
        yield 'default' => [[], 'pictures', false];
    }
}
