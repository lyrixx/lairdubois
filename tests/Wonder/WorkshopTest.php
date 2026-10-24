<?php

namespace Tests\Wonder;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Wonder\Workshop;
use Tests\Double\OfflineLocalisableUtils;
use Tests\PublicationTestCase;

final class WorkshopTest extends PublicationTestCase
{
    protected static function getRoutePrefix(): string
    {
        return 'core_workshop';
    }

    protected static function getEntityClass(): string
    {
        return Workshop::class;
    }

    protected static function getCounterName(): string
    {
        return 'Workshop';
    }

    protected function getNewFormValues(string $owner): array
    {
        return [
            'ladb_wonder_workshop[title]' => 'mon atelier dans le garage',
            'ladb_wonder_workshop[pictures]' => static::createPicture($owner, 'test-workshop'),
            'ladb_wonder_workshop[bodyBlocks][0][body]' => 'Un atelier de **12 m²**, avec un établi.',
            'ladb_wonder_workshop[location]' => 'Bordeaux, France',
            'ladb_wonder_workshop[area]' => '12',
            'ladb_wonder_workshop[tags]' => 'atelier,garage',
        ];
    }

    public function testCreateGeocodesTheLocation(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->submitNewForm($client, UserFixtures::USER, $this->getNewFormValues(UserFixtures::USER));

        self::assertEqualsWithDelta(OfflineLocalisableUtils::ADDRESSES['Bordeaux, France']['latitude'], $this->findLast()->getLatitude(), 0.000001);
    }
}
