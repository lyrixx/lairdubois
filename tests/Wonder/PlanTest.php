<?php

namespace Tests\Wonder;

use Ladb\CoreBundle\Entity\Core\Resource;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Tests\PublicationTestCase;

final class PlanTest extends PublicationTestCase
{
    protected static function getRoutePrefix(): string
    {
        return 'core_plan';
    }

    protected static function getEntityClass(): string
    {
        return Plan::class;
    }

    protected static function getCounterName(): string
    {
        return 'Plan';
    }

    protected function getNewFormValues(string $owner): array
    {
        $resource = static::getFixtureFactory()->resource(static::findUser($owner), 'plan.svg', 'test-plan', Resource::KIND_SVG);
        static::getEntityManager()->flush();

        return [
            'ladb_wonder_plan[title]' => 'plan d\'un banc de jardin',
            'ladb_wonder_plan[resources]' => (string) $resource->getId(),
            'ladb_wonder_plan[pictures]' => static::createPicture($owner, 'test-plan'),
            'ladb_wonder_plan[body]' => 'Les cotes du banc, au format SVG.',
            'ladb_wonder_plan[tags]' => 'banc,plan',
        ];
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
}
