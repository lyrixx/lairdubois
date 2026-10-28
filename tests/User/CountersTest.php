<?php

namespace Tests\User;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The counters of the header (/@me/counters.json), that the JS of each page asks for once loaded.
 */
final class CountersTest extends WebTestCase
{
    use XhrTrait;

    /**
     * The sessions have no lock: written back by /@me/counters.json, a session would lose what a
     * concurrent request has just put in it, such as the CSRF token of a form loaded in XHR (#88).
     */
    public function testCountersDoNotWriteTheSession(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        // Last used a minute ago, under the threshold of its metadata (metadata_update_threshold)
        $file = $this->getSessionFile($client);
        $session = unserialize(file_get_contents($file));
        $session['_sf2_meta']['u'] = time() - 60;
        file_put_contents($file, serialize($session));

        static::xhr($client, 'GET', '/@me/counters.json');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($session, unserialize(file_get_contents($file)));
    }

    /**
     * A counter is computed again 3 to 5 minutes later, per user, even in another session.
     */
    public function testCountersAreNotComputedAgainBeforeTheirRefreshDate(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        static::xhr($client, 'GET', '/@me/counters.json');
        $count = json_decode($client->getResponse()->getContent(), true)['unlistedWonderCreationCount'];
        static::findUser(UserFixtures::USER2)->getMeta()->setUnlistedWonderCreationCount($count + 100);
        static::getEntityManager()->flush();

        $client = static::createClientAs(UserFixtures::USER2);
        static::xhr($client, 'GET', '/@me/counters.json');

        self::assertSame($count + 100, json_decode($client->getResponse()->getContent(), true)['unlistedWonderCreationCount']);
    }

    /**
     * In test, the session is a file (session.storage.mock_file of config_test.yml).
     */
    private function getSessionFile(KernelBrowser $client): string
    {
        $session = $client->getContainer()->get('session');

        return $client->getContainer()->getParameter('kernel.cache_dir').'/sessions/'.$client->getCookieJar()->get($session->getName())->getValue().'.mocksess';
    }
}
