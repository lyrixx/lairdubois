<?php

namespace Tests\Wonder;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Tests\PublicationTestCase;

final class CreationTest extends PublicationTestCase
{
    protected static function getRoutePrefix(): string
    {
        return 'core_creation';
    }

    protected static function getEntityClass(): string
    {
        return Creation::class;
    }

    protected static function getCounterName(): string
    {
        return 'Creation';
    }

    protected function getNewFormValues(string $owner): array
    {
        return [
            'ladb_wonder_creation[title]' => 'banc de jardin',
            'ladb_wonder_creation[pictures]' => static::createPicture($owner, 'test-creation'),
            'ladb_wonder_creation[bodyBlocks][0][body]' => 'Un banc en **robinier**, pour le jardin.',
            'ladb_wonder_creation[tags]' => 'banc,jardin',
            'ladb_wonder_creation[woods]' => 'Robinier',
        ];
    }

    /**
     * The contents a creation links to (plan, howto, question...) count its publication: plan 1
     * of the fixtures.
     */
    public function testPublicationCountsOnTheLinkedContents(): void
    {
        $creationCount = $this->findPlan()->getCreationCount();

        $this->unpublish();
        self::assertSame($creationCount - 1, $this->findPlan()->getCreationCount());

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('publish', ['id' => static::ID]));
        self::assertSame($creationCount, $this->findPlan()->getCreationCount());
    }

    private function findPlan(): Plan
    {
        return static::getEntityManager()->getRepository(Plan::class)->find(static::ID);
    }
}
