<?php

namespace Tests\Funding;

use Ladb\CoreBundle\DataFixtures\FundingFixtures;
use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Funding\Charge;
use Ladb\CoreBundle\Entity\Funding\Donation;
use Ladb\CoreBundle\Entity\Funding\Funding;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Component\DomCrawler\Form;
use Tests\Double\OfflineStripeClient;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The funding of the fixtures: the previous month, with a recurrent charge and another one, and
 * the current month, where "user2" gave. The payments go through Stripe, offline here
 * (Tests\Double\OfflineStripeClient); its webhook reads php://input, which the client of the
 * tests does not fill: it is not tested.
 */
final class FundingTest extends WebTestCase
{
    use XhrTrait;

    /**
     * @dataProvider provideUsers
     */
    public function testDashboard(?string $username): void
    {
        $client = $this->createClientFor($username);
        $client->request('GET', static::generatePath('core_funding_dashboard'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->findCurrent());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function provideUsers(): iterable
    {
        yield 'anonymous' => [null];
        yield 'user' => [UserFixtures::USER];
        yield 'admin' => [UserFixtures::ADMIN];
    }

    /**
     * Each month without a funding gets one, with the recurrent charges of the previous month.
     */
    public function testDashboardCreatesTheMissingMonths(): void
    {
        $em = static::getEntityManager();
        $em->remove($this->findCurrent());
        $em->flush();

        $client = static::createClient();
        $client->request('GET', static::generatePath('core_funding_dashboard'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $funding = $this->findCurrent();
        self::assertSame(FundingFixtures::RECURRENT_CHARGE_AMOUNT, $funding->getChargeBalance());
        self::assertSame(['Hébergement'], array_map(fn (Charge $charge) => $charge->getLabel(), $funding->getCharges()->toArray()));
    }

    public function testDashboardOfAMonth(): void
    {
        $previousMonth = new \DateTime('first day of previous month');

        $client = static::createClient();
        $client->request('GET', static::generatePath('core_funding_dashboard_year_month', ['year' => $previousMonth->format('Y'), 'month' => $previousMonth->format('n')]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testDashboardOfAMonthWithoutFunding(): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath('core_funding_dashboard_year_month', ['year' => 2000, 'month' => 1]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * The dashboard divides by the charges of the month.
     */
    public function testDashboardOfAMonthWithoutCharges(): void
    {
        $funding = $this->findCurrent();
        foreach ($funding->getCharges() as $charge) {
            $funding->removeCharge($charge);
            static::getEntityManager()->remove($charge);
        }
        $funding->setChargeBalance(0);
        static::getEntityManager()->flush();

        $client = static::createClient();
        try {
            $client->request('GET', static::generatePath('core_funding_dashboard'));
            $error = null;
        } catch (\DivisionByZeroError $error) {
        }

        self::assertNotNull($error, 'The page passes now: remove the known failure (#64).');
        self::markTestIncomplete('Known failure, see #64.');
    }

    /**
     * @dataProvider providePanels
     */
    public function testInfos(string $panel): void
    {
        $client = static::createClient();
        static::xhr($client, 'GET', $this->generateForCurrent('core_funding_infos', ['panel' => $panel]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePanels(): iterable
    {
        yield 'charge-balance' => ['charge-balance'];
        yield 'donation-fee-balance' => ['donation-fee-balance'];
        yield 'carried-forward-balance' => ['carried-forward-balance'];
        yield 'donation-balance' => ['donation-balance'];
    }

    public function testInfosOfAnUnknownPanel(): void
    {
        $client = static::createClient();
        static::xhr($client, 'GET', $this->generateForCurrent('core_funding_infos', ['panel' => 'inconnu']));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * The panel of the charges lists them, and links to their edition for the admins only.
     *
     * @dataProvider provideChargeEditors
     */
    public function testInfosOfTheCharges(string $username, int $editLinkCount): void
    {
        $client = static::createClientAs($username);
        $crawler = static::xhr($client, 'GET', $this->generateForCurrent('core_funding_infos', ['panel' => 'charge-balance']));

        self::assertStringContainsString('Hébergement', $crawler->filter('.ladb-charge-table')->text());
        self::assertCount($editLinkCount, $crawler->filter('.ladb-charge-row[data-href]'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideChargeEditors(): iterable
    {
        yield 'user' => [UserFixtures::USER, 0];
        yield 'admin' => [UserFixtures::ADMIN, 1];
    }

    public function testChargeCreate(): void
    {
        $chargeBalance = $this->findCurrent()->getChargeBalance();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $form = $this->getChargeForm($client, $this->generateForCurrent('core_funding_admin_charge_new'));
        $form->setValues([
            'ladb_funding_charge[label]' => 'Sauvegardes',
            'ladb_funding_charge[dutyFreeAmount]' => '1000',
            'ladb_funding_charge[amount]' => '1200',
        ]);
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $funding = $this->findCurrent();
        self::assertSame($chargeBalance + 1200, $funding->getChargeBalance());
        self::assertContains('Sauvegardes', array_map(fn (Charge $charge) => $charge->getLabel(), $funding->getCharges()->toArray()));
    }

    public function testChargeCreateInvalid(): void
    {
        $chargeCount = $this->findCurrent()->getCharges()->count();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $form = $this->getChargeForm($client, $this->generateForCurrent('core_funding_admin_charge_new'));
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($chargeCount, $this->findCurrent()->getCharges()->count());
    }

    public function testChargeUpdate(): void
    {
        $charge = $this->findCurrent()->getCharges()->first();
        $chargeBalance = $this->findCurrent()->getChargeBalance();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $form = $this->getChargeForm($client, static::generatePath('core_funding_admin_charge_edit', ['id' => $charge->getId()]));
        $form['ladb_funding_charge[amount]'] = (string) ($charge->getAmount() + 300);
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($chargeBalance + 300, $this->findCurrent()->getChargeBalance());
    }

    public function testChargeDelete(): void
    {
        $charge = $this->findCurrent()->getCharges()->first();
        [$id, $amount] = [$charge->getId(), $charge->getAmount()];
        $chargeBalance = $this->findCurrent()->getChargeBalance();

        $client = static::createClientAs(UserFixtures::ADMIN);
        static::xhr($client, 'GET', static::generatePath('core_funding_admin_charge_delete', ['id' => $id]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        self::assertNull(static::getEntityManager()->getRepository(Charge::class)->find($id));
        self::assertSame($chargeBalance - $amount, $this->findCurrent()->getChargeBalance());
    }

    /**
     * @dataProvider provideChargeActions
     */
    public function testChargeActionAsUser(string $method, string $route): void
    {
        $chargeBalance = $this->findCurrent()->getChargeBalance();

        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, $method, $this->getChargeActionPath($route), ['ladb_funding_charge' => ['label' => 'Sauvegardes', 'amount' => '1200']]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame($chargeBalance, $this->findCurrent()->getChargeBalance());
    }

    /**
     * @dataProvider provideChargeActions
     */
    public function testChargeActionAsAnonymous(string $method, string $route): void
    {
        $client = static::createClient();
        static::xhr($client, $method, $this->getChargeActionPath($route));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideChargeActions(): iterable
    {
        yield 'new' => ['GET', 'core_funding_admin_charge_new'];
        yield 'create' => ['POST', 'core_funding_admin_charge_create'];
        yield 'edit' => ['GET', 'core_funding_admin_charge_edit'];
        yield 'update' => ['POST', 'core_funding_admin_charge_update'];
        yield 'delete' => ['GET', 'core_funding_admin_charge_delete'];
    }

    /**
     * Out of an XHR, the form of a donation is the dashboard, which opens it.
     */
    public function testDonationNew(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', static::generatePath('core_funding_donation_new', ['amount_eur' => 10]));

        self::assertTrue($client->getResponse()->isRedirect(static::generatePath('core_funding_dashboard', ['amount_eur' => 10, 'auto_show' => true])));
    }

    /**
     * The form of a donation creates a payment intent of the amount on Stripe.
     */
    public function testDonationNewXhr(): void
    {
        $user = static::findUser(UserFixtures::USER);

        $client = static::createClientAs(UserFixtures::USER);
        $crawler = static::xhr($client, 'GET', static::generatePath('core_funding_donation_new', ['amount_eur' => 10]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $requests = $client->getContainer()->get('tests.stripe_client')->getRequests();
        self::assertCount(1, $requests);
        self::assertSame('/v1/payment_intents', $requests[0]['path']);
        self::assertSame(1000, $requests[0]['params']['amount']);
        self::assertSame($user->getEmailCanonical(), $requests[0]['params']['receipt_email']);
        self::assertSame($user->getId(), $requests[0]['params']['metadata']['user_id']);
        self::assertStringContainsString(OfflineStripeClient::PAYMENT_INTENT_ID.'_secret_offline', $crawler->html());
    }

    public function testDonationNewAsAnonymous(): void
    {
        $client = static::createClient();
        static::xhr($client, 'GET', static::generatePath('core_funding_donation_new'));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * Once paid, the page of the form thanks for the amount of the payment intent. The donation
     * is only recorded by the webhook.
     */
    public function testDonationConfirmed(): void
    {
        $donationCount = \count(static::getEntityManager()->getRepository(Donation::class)->findAll());

        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'POST', static::generatePath('core_funding_donation_confirmed'), ['intent_id' => OfflineStripeClient::PAYMENT_INTENT_ID]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $json = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($json['success']);
        self::assertStringContainsString((string) (OfflineStripeClient::PAYMENT_INTENT_AMOUNT / 100), $json['content']);
        self::assertSame($donationCount, \count(static::getEntityManager()->getRepository(Donation::class)->findAll()));
    }

    /**
     * @dataProvider provideInvalidConfirmations
     */
    public function testDonationConfirmedInvalid(array $parameters): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'POST', static::generatePath('core_funding_donation_confirmed'), $parameters);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function provideInvalidConfirmations(): iterable
    {
        yield 'without payment intent' => [[]];
        yield 'unknown payment intent' => [['intent_id' => 'pi_inconnu']];
    }

    /**
     * @dataProvider provideUsers
     */
    public function testDonors(?string $username): void
    {
        $client = $this->createClientFor($username);
        $crawler = $client->request('GET', static::generatePath('core_funding_donors'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $crawler->filter(\sprintf('a[href$="%s"]', static::generatePath('core_user_show', ['username' => UserFixtures::USER2]))));
        self::assertCount(0, $crawler->filter(\sprintf('a[href$="%s"]', static::generatePath('core_user_show', ['username' => UserFixtures::USER]))));
    }

    /**
     * @dataProvider provideDonors
     */
    public function testUserDonations(string $username, int $donationCount): void
    {
        $client = static::createClientAs($username);
        $crawler = $client->request('GET', static::generatePath('core_funding_user_donation_list'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount($donationCount, $crawler->filter('.ladb-ref'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideDonors(): iterable
    {
        yield 'donor' => [UserFixtures::USER2, 1];
        yield 'other user' => [UserFixtures::USER, 0];
    }

    public function testUserDonationsAsAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath('core_funding_user_donation_list'));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * @dataProvider provideDonationListReaders
     */
    public function testDonationList(?string $username, int $status): void
    {
        $client = $this->createClientFor($username);
        $crawler = $client->request('GET', static::generatePath('core_funding_admin_donation_list'));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        if (200 === $status) {
            self::assertSame([$this->findDonation()->getHashid()], $crawler->filter('.ladb-ref span')->each(fn ($node) => $node->text()));
        }
    }

    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function provideDonationListReaders(): iterable
    {
        yield 'anonymous' => [null, 404];
        yield 'user' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    private function getChargeForm(Client $client, string $path): Form
    {
        $crawler = static::xhr($client, 'GET', $path);
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return $crawler->filter('form')->form();
    }

    private function getChargeActionPath(string $route): string
    {
        if (str_contains($route, '_new') || str_contains($route, '_create')) {
            return $this->generateForCurrent($route);
        }

        return static::generatePath($route, ['id' => $this->findCurrent()->getCharges()->first()->getId()]);
    }

    private function createClientFor(?string $username): Client
    {
        return null === $username ? static::createClient() : static::createClientAs($username);
    }

    /**
     * A route of the current month.
     */
    private function generateForCurrent(string $route, array $parameters = []): string
    {
        return static::generatePath($route, ['year' => (int) date('Y'), 'month' => (int) date('n')] + $parameters);
    }


    private function findCurrent(): ?Funding
    {
        return static::getEntityManager()->getRepository(Funding::class)->findOneBy(['year' => (int) date('Y'), 'month' => (int) date('n')]);
    }

    private function findDonation(): Donation
    {
        return static::getEntityManager()->getRepository(Donation::class)->findOneBy(['user' => static::findUser(UserFixtures::USER2)]);
    }
}
