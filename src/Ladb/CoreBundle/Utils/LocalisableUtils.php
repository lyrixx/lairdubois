<?php

namespace Ladb\CoreBundle\Utils;

use CommerceGuys\Addressing\Address;
use CommerceGuys\Addressing\AddressFormat\AddressFormatRepository;
use CommerceGuys\Addressing\Country\CountryRepository;
use CommerceGuys\Addressing\Formatter\DefaultFormatter;
use CommerceGuys\Addressing\Subdivision\SubdivisionRepository;
use Geocoder\Provider\GoogleMaps\GoogleMaps;
use Geocoder\Query\GeocodeQuery;
use GuzzleHttp\Psr7\Request;
use Ladb\CoreBundle\Model\LocalisableExtendedInterface;
use Ladb\CoreBundle\Model\LocalisableInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

class LocalisableUtils {

	const NAME = 'ladb_core.localisable_utils';

	private $googleApiKey;
	private $httpClient;
	private $logger;

	public function __construct($googleApiKey, ClientInterface $httpClient, LoggerInterface $logger) {
		$this->googleApiKey = $googleApiKey;
		$this->httpClient = $httpClient;
		$this->logger = $logger;
	}

	/////

	public function geocodeLocation(LocalisableInterface $localisable) {
		if (!is_null($localisable->getLocation())) {

			$geocoder = new GoogleMaps($this->httpClient, null, $this->googleApiKey);

			try {
				// GeocodeQuery refuses an empty location: logged like an error of the API
				$response = $geocoder->geocodeQuery(GeocodeQuery::create($localisable->getLocation())->withLocale('fr_FR'));
			} catch (\Exception $e) {
				$this->logger->error($e);
				return false;
			}

			if ($response->count() > 0) {

				$address = $response->first();

				// Location
				$localisable->setLatitude($address->getCoordinates()->getLatitude());
				$localisable->setLongitude($address->getCoordinates()->getLongitude());

				if ($localisable instanceof LocalisableExtendedInterface) {

					// PostalCode /////

					$postalCode = $address->getPostalCode();
					if ($postalCode) {
						$localisable->setPostalCode($postalCode);
					} else {
						$localisable->setPostalCode(null);
					}

					// Locality /////

					$locality = $address->getLocality();
					if ($locality) {
						$localisable->setLocality($locality);
					} else {
						$localisable->setLocality(null);
					}

					// Country /////

					$country = $address->getCountry();
					if ($country) {
						$localisable->setCountry($country->getName());
					} else {
						$localisable->setCountry(null);
					}

					// GeographicalAreas /////

					$geographicalAreaParts = array();

					if ($locality) {
						$geographicalAreaParts[] = $locality;
					}
					$adminLevels = $address->getAdminLevels();
					foreach ($adminLevels as $adminLevel) {
						$geographicalAreaParts[] = $adminLevel->getName();
					}
					if ($country) {
						$geographicalAreaParts[] = $country->getName();
					}

					if (!empty($geographicalAreaParts)) {
						$localisable->setGeographicalAreas(implode(',', $geographicalAreaParts));
					} else {
						$localisable->setGeographicalAreas(null);
					}

					// FormattedAddress /////

					$addressFormatRepository = new AddressFormatRepository();
					$countryRepository = new CountryRepository();
					$subdivisionRepository = new SubdivisionRepository();
					$formatter = new DefaultFormatter($addressFormatRepository, $countryRepository, $subdivisionRepository, array( 'locale' => '\'fr_FR\'', 'html' => false ));

					$a = new Address();

					$a = $a->withCountryCode($country ? $country->getCode() : null);
					if ($address->getStreetNumber() && $address->getStreetName()) {
						$a = $a->withAddressLine1($address->getStreetNumber().' '.$address->getStreetName());
					} else if ($address->getStreetName()) {
						$a = $a->withAddressLine1($address->getStreetName());
					}
					if ($postalCode) {
						$a = $a->withPostalCode($postalCode);
					}
					if ($locality) {
						$a = $a->withLocality($locality);
					}
					if ($address->getSubLocality()) {
						$a = $a->withDependentLocality($address->getSubLocality());
					}
					if ($adminLevels->count() > 0) {
						$a = $a->withAdministrativeArea($adminLevels->first()->getName());
					}

					try {
						$localisable->setFormattedAddress($formatter->format($a));
					} catch (\Exception $e) {}

				}

				return true;
			}

			$this->logger->warning('No geocoding result for the location.', array( 'location' => $localisable->getLocation() ));

		} else {
			$localisable->setLatitude(null);
			$localisable->setLongitude(null);
			if ($localisable instanceof LocalisableExtendedInterface) {
				$localisable->setGeographicalAreas(null);
				$localisable->setPostalCode(null);
				$localisable->setLocality(null);
				$localisable->setFormattedAddress($localisable->getLocation());
			}
		}

		return false;
	}

	/////

	public function getBoundsAndLocation($address) {

		try {

			$url = 'https://maps.googleapis.com/maps/api/geocode/json?address='.urlencode($address).'&key='.$this->googleApiKey.'&language=fr&region=FR';
			$response = $this->httpClient->sendRequest(new Request('GET', $url));
			if (200 !== $response->getStatusCode()) {
				$this->logger->warning('The geocoding API answered an HTTP error.', array( 'status' => $response->getStatusCode() ));
				return null;
			}
			$hash = json_decode((string) $response->getBody(), true);

			if ($hash && isset($hash['results']) && is_array($hash['results'])) {

				foreach ($hash['results'] as $result) {

					if (isset($result['geometry'])) {

						$boundsAndLocation = array();

						if (isset($result['geometry']['bounds'])) {

							$bounds = $result['geometry']['bounds'];

							// Returns an Elasticsearch ready bounds array [ top_left, bottom_right ]
							$boundsAndLocation['bounds'] = array(
								$bounds['northeast']['lat'].','.$bounds['southwest']['lng'],
								$bounds['southwest']['lat'].','.$bounds['northeast']['lng'],
							);
						}

						if (isset($result['geometry']['location'])) {

							$location = $result['geometry']['location'];

							// Returns an Elasticsearch ready geopoint string "lat,lng"
							$boundsAndLocation['location'] = $location['lat'].','.$location['lng'];
						}

						return $boundsAndLocation;

					}

				}

			}

		} catch (\Exception $e) {
			$this->logger->error($e);
		}

		return null;
	}

}