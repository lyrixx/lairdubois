<?php

namespace Tests\Geocoding;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Utils\LocalisableUtils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;
use Tests\Double\OfflineLocalisableUtils;

/**
 * LocalisableUtils on answers of the Geocoding API of Google (responses/), served by a Guzzle
 * handler: what it fills on a localisable, what it sends and what it logs. The expected values are
 * those of willdurand/geocoder 3 for the same answers, unless a comment says otherwise. The other
 * tests use Tests\Double\OfflineLocalisableUtils.
 */
final class LocalisableUtilsTest extends TestCase
{
    private const API_KEY = 'API_KEY';

    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    // Value of the fields before the geocoding, to see the ones it leaves
    private const UNTOUCHED = 'untouched';

    private const FIELDS = ['latitude', 'longitude', 'postalCode', 'locality', 'country', 'geographicalAreas', 'formattedAddress'];

    /** @var list<RequestInterface> */
    private array $requests = [];

    private ?AbstractLogger $logger = null;

    /**
     * @dataProvider provideGeocodeLocation
     *
     * @param array<string, float|string|null> $fields
     * @param list<string>                     $logLevels
     */
    public function testGeocodeLocation(string $location, $response, bool $geocoded, array $fields, array $logLevels): void
    {
        $offer = new Offer();
        $offer->setLocation($location);
        foreach (self::FIELDS as $field) {
            $offer->{'set'.ucfirst($field)}(self::UNTOUCHED);
        }

        self::assertSame($geocoded, $this->createLocalisableUtils($response)->geocodeLocation($offer));

        $actualFields = [];
        foreach (self::FIELDS as $field) {
            $actualFields[$field] = $offer->{'get'.ucfirst($field)}();
        }
        self::assertSame($fields, $actualFields);
        self::assertSame($logLevels, $this->logger->levels);
        self::assertCount(1, $this->requests);
        self::assertSame(self::ENDPOINT.'?address='.rawurlencode($location).'&language=fr_FR&key='.self::API_KEY, (string) $this->requests[0]->getUri());
    }

    public function provideGeocodeLocation(): iterable
    {
        yield 'locality' => ['Bordeaux, France', $this->createResponse('bordeaux.json'), true, [
            'latitude' => 44.837789,
            'longitude' => -0.57918,
            'postalCode' => '33000',
            'locality' => 'Bordeaux',
            'country' => 'France',
            'geographicalAreas' => 'Bordeaux,Nouvelle-Aquitaine,Gironde,France',
            'formattedAddress' => "33000 Bordeaux\nFrance",
        ], []];
        yield 'first of several results, without postal code' => ['Saint-Denis', $this->createResponse('saint-denis.json'), true, [
            'latitude' => 48.936181,
            'longitude' => 2.357443,
            'postalCode' => null,
            'locality' => 'Saint-Denis',
            'country' => 'France',
            'geographicalAreas' => 'Saint-Denis,Île-de-France,Seine-Saint-Denis,France',
            'formattedAddress' => "Saint-Denis\nFrance",
        ], []];
        yield 'street address with accents' => ['12 rue de l\'Église, Besançon', $this->createResponse('besancon.json'), true, [
            'latitude' => 47.2465147,
            'longitude' => 6.0420493,
            'postalCode' => '25000',
            'locality' => 'Besançon',
            'country' => 'France',
            'geographicalAreas' => 'Besançon,Bourgogne-Franche-Comté,Doubs,France',
            'formattedAddress' => "12 Rue de l'Église\n25000 Besançon\nFrance",
        ], []];
        yield 'sublocality and administrative area' => ['4200 rue Saint-Denis, Montréal', $this->createResponse('montreal.json'), true, [
            'latitude' => 45.5215328,
            'longitude' => -73.5826459,
            'postalCode' => 'H2W 2M5',
            'locality' => 'Montréal',
            'country' => 'Canada',
            'geographicalAreas' => 'Montréal,Québec,Communauté-Urbaine-de-Montréal,Canada',
            'formattedAddress' => "4200 Rue Saint-Denis\nMontréal Québec H2W 2M5\nCanada",
        ], []];
        // willdurand/geocoder 3 ignored postal_town (United Kingdom, Sweden...): no locality
        yield 'postal town' => ['10 Downing Street, London', $this->createResponse('london.json'), true, [
            'latitude' => 51.5033635,
            'longitude' => -0.1276248,
            'postalCode' => 'SW1A 2AA',
            'locality' => 'London',
            'country' => 'Royaume-Uni',
            'geographicalAreas' => 'London,England,Greater London,Royaume-Uni',
            'formattedAddress' => "10 Downing Street\nLondon\nSW1A 2AA\nUnited Kingdom",
        ], []];
        // No country: the address cannot be formatted. willdurand/geocoder 3 gave an empty
        // country, hence empty geographical areas instead of none.
        yield 'without country' => ['Océan Atlantique', $this->createResponse('atlantic-ocean.json'), true, [
            'latitude' => -14.5994134,
            'longitude' => -28.6731465,
            'postalCode' => null,
            'locality' => null,
            'country' => null,
            'geographicalAreas' => null,
            'formattedAddress' => self::UNTOUCHED,
        ], []];

        $untouched = array_fill_keys(self::FIELDS, self::UNTOUCHED);
        // willdurand/geocoder 3 threw an exception, logged as an error
        yield 'no result' => ['Adresse inconnue', $this->createResponse('zero-results.json'), false, $untouched, ['warning']];
        yield 'invalid API key' => ['Bordeaux, France', $this->createResponse('request-denied.json'), false, $untouched, ['error']];
        yield 'API not authorized' => ['Bordeaux, France', $this->createResponse('request-denied-api.json'), false, $untouched, ['error']];
        yield 'quota exceeded' => ['Bordeaux, France', $this->createResponse('over-query-limit.json'), false, $untouched, ['error']];
        yield 'server error' => ['Bordeaux, France', new Response(500, [], '<html><body>Server Error</body></html>'), false, $untouched, ['error']];
        yield 'empty answer' => ['Bordeaux, France', new Response(200), false, $untouched, ['error']];
        yield 'connection failure' => ['Bordeaux, France', $this->createConnectionFailure(), false, $untouched, ['error']];
    }

    public function testGeocodeEmptyLocation(): void
    {
        $offer = new Offer();
        $offer->setLocation('');

        // An empty query is refused before any request (willdurand/geocoder 3 sent it)
        self::assertFalse($this->createLocalisableUtils()->geocodeLocation($offer));
        self::assertSame(['error'], $this->logger->levels);
        self::assertSame([], $this->requests);
    }

    public function testGeocodeWithoutLocation(): void
    {
        $offer = new Offer();
        foreach (self::FIELDS as $field) {
            $offer->{'set'.ucfirst($field)}(self::UNTOUCHED);
        }

        self::assertFalse($this->createLocalisableUtils()->geocodeLocation($offer));

        self::assertNull($offer->getLatitude());
        self::assertNull($offer->getLongitude());
        self::assertNull($offer->getPostalCode());
        self::assertNull($offer->getLocality());
        self::assertNull($offer->getGeographicalAreas());
        self::assertNull($offer->getFormattedAddress());
        self::assertSame(self::UNTOUCHED, $offer->getCountry());
        self::assertSame([], $this->requests);
    }

    /**
     * @dataProvider provideGetBoundsAndLocation
     *
     * @param array<string, string|list<string>>|null $boundsAndLocation
     * @param list<string>                            $logLevels
     */
    public function testGetBoundsAndLocation(string $address, $response, ?array $boundsAndLocation, array $logLevels): void
    {
        self::assertSame($boundsAndLocation, $this->createLocalisableUtils($response)->getBoundsAndLocation($address));
        self::assertSame($logLevels, $this->logger->levels);
        self::assertCount(1, $this->requests);
        self::assertSame(self::ENDPOINT.'?address='.urlencode($address).'&key='.self::API_KEY.'&language=fr&region=FR', (string) $this->requests[0]->getUri());
    }

    public function provideGetBoundsAndLocation(): iterable
    {
        yield 'bounds' => ['Bordeaux, France', $this->createResponse('bordeaux.json'), [
            'bounds' => ['44.9161806,-0.6386987', '44.8107826,-0.5328729'],
            'location' => '44.837789,-0.57918',
        ], []];
        yield 'first of several results' => ['Saint-Denis', $this->createResponse('saint-denis.json'), [
            'bounds' => ['48.9504679,2.3346131', '48.9013101,2.3871279'],
            'location' => '48.936181,2.357443',
        ], []];
        yield 'without bounds, with accents' => ['12 rue de l\'Église, Besançon', $this->createResponse('besancon.json'), [
            'location' => '47.2465147,6.0420493',
        ], []];
        yield 'no result' => ['Adresse inconnue', $this->createResponse('zero-results.json'), null, []];
        yield 'invalid API key' => ['Bordeaux, France', $this->createResponse('request-denied.json'), null, []];
        yield 'quota exceeded' => ['Bordeaux, France', $this->createResponse('over-query-limit.json'), null, []];
        // file_get_contents() gave a PHP warning, in both cases
        yield 'server error' => ['Bordeaux, France', new Response(500, [], '<html><body>Server Error</body></html>'), null, ['warning']];
        yield 'connection failure' => ['Bordeaux, France', $this->createConnectionFailure(), null, ['error']];
    }

    /**
     * Tests\Double\OfflineLocalisableUtils, used by the other tests, gives what the API would.
     */
    public function testOfflineLocalisableUtilsMatchesTheApi(): void
    {
        $address = OfflineLocalisableUtils::ADDRESSES['Bordeaux, France'];

        $offer = new Offer();
        $offer->setLocation('Bordeaux, France');
        self::assertTrue($this->createLocalisableUtils($this->createResponse('bordeaux.json'))->geocodeLocation($offer));
        foreach (self::FIELDS as $field) {
            self::assertSame($address[$field], $offer->{'get'.ucfirst($field)}(), $field);
        }

        self::assertSame([
            'bounds' => $address['bounds'],
            'location' => $address['latitude'].','.$address['longitude'],
        ], $this->createLocalisableUtils($this->createResponse('bordeaux.json'))->getBoundsAndLocation('Bordeaux, France'));
    }

    /**
     * @param Response|callable|null $response The answer to the only request expected
     */
    private function createLocalisableUtils($response = null): LocalisableUtils
    {
        $this->requests = [];
        $logger = new class() extends AbstractLogger {
            public $levels = [];

            public function log($level, $message, array $context = [])
            {
                $this->levels[] = $level;
            }
        };
        $this->logger = $logger;

        // An empty queue fails any request
        $handler = HandlerStack::create(new MockHandler(null === $response ? [] : [$response]));
        $handler->push(Middleware::tap(function (RequestInterface $request) {
            $this->requests[] = $request;
        }));

        return new LocalisableUtils(self::API_KEY, new Client(['handler' => $handler]), $logger);
    }

    private function createResponse(string $file): Response
    {
        return new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], file_get_contents(__DIR__.'/responses/'.$file));
    }

    private function createConnectionFailure(): callable
    {
        return static fn (RequestInterface $request) => new ConnectException('Connection timed out.', $request);
    }
}
