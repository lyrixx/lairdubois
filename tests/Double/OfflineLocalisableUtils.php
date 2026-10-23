<?php

namespace Tests\Double;

use Ladb\CoreBundle\Model\LocalisableExtendedInterface;
use Ladb\CoreBundle\Model\LocalisableInterface;
use Ladb\CoreBundle\Utils\LocalisableUtils;

/**
 * LocalisableUtils of the test environment (config_test.yml): geocodes from ADDRESSES instead of
 * calling the API of Google. Any other location has no result, like an unknown address.
 */
final class OfflineLocalisableUtils extends LocalisableUtils
{
    public const ADDRESSES = [
        'Bordeaux, France' => [
            'latitude' => 44.837789,
            'longitude' => -0.57918,
            'postalCode' => '33000',
            'locality' => 'Bordeaux',
            'country' => 'France',
            'geographicalAreas' => 'Bordeaux,Gironde,Nouvelle-Aquitaine,France',
            'formattedAddress' => "33000 Bordeaux\nFrance",
            // Elasticsearch ready bounds [top_left, bottom_right]
            'bounds' => ['44.9161806,-0.6386987', '44.8107826,-0.5328729'],
        ],
    ];

    public function geocodeLocation(LocalisableInterface $localisable)
    {
        if (null === $localisable->getLocation()) {
            // No call to Google without a location
            return parent::geocodeLocation($localisable);
        }

        $address = self::ADDRESSES[$localisable->getLocation()] ?? null;
        if (null === $address) {
            return false;
        }

        $localisable->setLatitude($address['latitude']);
        $localisable->setLongitude($address['longitude']);
        if ($localisable instanceof LocalisableExtendedInterface) {
            $localisable->setPostalCode($address['postalCode']);
            $localisable->setLocality($address['locality']);
            $localisable->setCountry($address['country']);
            $localisable->setGeographicalAreas($address['geographicalAreas']);
            $localisable->setFormattedAddress($address['formattedAddress']);
        }

        return true;
    }

    public function getBoundsAndLocation($address)
    {
        $address = self::ADDRESSES[$address] ?? null;
        if (null === $address) {
            return null;
        }

        return [
            'bounds' => $address['bounds'],
            'location' => $address['latitude'].','.$address['longitude'],
        ];
    }
}
