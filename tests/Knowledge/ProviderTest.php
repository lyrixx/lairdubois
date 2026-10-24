<?php

namespace Tests\Knowledge;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Knowledge\Provider;
use Tests\Double\OfflineLocalisableUtils;

final class ProviderTest extends KnowledgeTestCase
{
    protected static function getRoutePrefix(): string
    {
        return 'core_provider';
    }

    protected static function getEntityClass(): string
    {
        return Provider::class;
    }

    protected function getNewFormValues(): array
    {
        return [
            'ladb_knowledge_newprovider[signValue][brand]' => 'Bois et Compagnie',
            'ladb_knowledge_newprovider[signValue][store]' => 'Bordeaux',
            'ladb_knowledge_newprovider[logoValue][data]' => static::createPicture('test-provider'),
        ];
    }

    protected function getProposal(): array
    {
        // The data of a location is its address, as the geocoding formats it
        return [Provider::FIELD_ADDRESS, ['ladb_knowledge_value_location[location]' => 'Bordeaux, France'], OfflineLocalisableUtils::ADDRESSES['Bordeaux, France']['formattedAddress']];
    }

    /**
     * Its address is geocoded: the map of the provider shows it.
     */
    public function testAddressLocatesTheProvider(): void
    {
        [$field, $values] = $this->getProposal();
        $client = static::createClientAs(UserFixtures::USER2);

        static::proposeValue($client, $this->find(static::ID), $field, $values);

        $provider = $this->find(static::ID);
        self::assertEqualsWithDelta(OfflineLocalisableUtils::ADDRESSES['Bordeaux, France']['latitude'], $provider->getLatitude(), 0.000001);

        $client->request('GET', $this->path('location', ['id' => static::ID]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $geoJson = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $geoJson['features']);
    }
}
