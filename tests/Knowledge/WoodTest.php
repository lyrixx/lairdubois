<?php

namespace Tests\Knowledge;

use Ladb\CoreBundle\Entity\Knowledge\Wood;
use Ladb\CoreBundle\Entity\Knowledge\Wood\Texture;

final class WoodTest extends KnowledgeTestCase
{
    protected static function getRoutePrefix(): string
    {
        return 'core_wood';
    }

    protected static function getEntityClass(): string
    {
        return Wood::class;
    }

    protected function getNewFormValues(): array
    {
        return [
            'ladb_knowledge_newwood[nameValue][data]' => 'Frêne',
            'ladb_knowledge_newwood[grainValue][data]' => static::createPicture('test-wood'),
        ];
    }

    protected function getProposal(): array
    {
        return [Wood::FIELD_SCIENTIFICNAME, ['ladb_knowledge_value_text[data]' => 'Quercus robur'], 'Quercus robur'];
    }

    /**
     * A grain picture makes a texture: the picture and a mosaic of it.
     */
    public function testCreateMakesATexture(): void
    {
        $this->testCreate();

        $textures = static::getEntityManager()->getRepository(Texture::class)->findBy(['wood' => $this->findLast()]);
        self::assertCount(1, $textures);
        self::assertNotNull($textures[0]->getMosaicPicture());
    }

    public function testDownloadTexture(): void
    {
        $client = static::createClient();
        $texture = static::getEntityManager()->getRepository(Texture::class)->findOneBy(['wood' => static::ID]);
        $downloadCount = $texture->getDownloadCount();

        $client->request('GET', $this->path('texture_download', ['id' => $texture->getId()]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringStartsWith('attachment;filename="lairdubois_texture_', $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith("PK\x03\x04", $client->getResponse()->getContent());
        self::assertSame($downloadCount + 1, static::getEntityManager()->getRepository(Texture::class)->find($texture->getId())->getDownloadCount());
    }
}
