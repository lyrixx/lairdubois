<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Funding\Charge;
use Ladb\CoreBundle\Entity\Funding\Donation;
use Ladb\CoreBundle\Entity\Funding\Funding;
use Ladb\CoreBundle\Manager\Funding\FundingManager;

/**
 * The funding of the site: the previous month (id 1), with a recurrent charge and another one,
 * and the current month (id 2), where "user2" gives. Without a funding, /financement answers 404:
 * FundingManager::getOrCreateCurrent() only creates the months after the last one.
 */
final class FundingFixtures extends Fixture implements DependentFixtureInterface {

	// Cents, as stored
	public const RECURRENT_CHARGE_AMOUNT = 12000;
	public const CHARGE_AMOUNT = 3500;
	public const DONATION_AMOUNT = 2000;
	public const DONATION_FEE = 53;

	private $fundingManager;
	private $secret;

	public function __construct(
		FundingManager $fundingManager,
		string $secret
	) {
		$this->fundingManager = $fundingManager;
		$this->secret = $secret;
	}

	public function getDependencies() {
		return array( UserFixtures::class );
	}

	public function load(ObjectManager $manager): void {
		$user2 = $this->getReference(UserFixtures::USER2);

		// The previous month, as FundingController::chargeCreateAction

		$previousMonth = new \DateTime('first day of previous month');

		$funding = new Funding();
		$funding->setYear((int) $previousMonth->format('Y'));
		$funding->setMonth((int) $previousMonth->format('n'));
		$funding->addCharge($this->charge('Hébergement', self::RECURRENT_CHARGE_AMOUNT, true));
		$funding->addCharge($this->charge('Nom de domaine', self::CHARGE_AMOUNT, false));
		$funding->incrementChargeBalance(self::RECURRENT_CHARGE_AMOUNT + self::CHARGE_AMOUNT);
		$manager->persist($funding);
		$manager->flush();

		// The current month, with the recurrent charge

		$funding = $this->fundingManager->getOrCreateCurrent();

		// A donation, as FundingController::donationWebhookAction (without the emails)

		$donation = new Donation();
		$donation->setUser($user2);
		$donation->setAmount(self::DONATION_AMOUNT);
		$donation->setFee(self::DONATION_FEE);
		$donation->setStripeChargeId('pi_fixture');
		$manager->persist($donation);

		$funding->incrementDonationFeeBalance($donation->getFee());
		$funding->incrementDonationBalance($donation->getAmount());
		$funding->incrementDonationCount();

		$user2->getMeta()->incrementDonationCount();
		$user2->getMeta()->incrementDonationBalance($donation->getAmount());
		$user2->getMeta()->incrementDonationFeeBalance($donation->getFee());

		$manager->flush();

		$hashids = new \Hashids\Hashids($this->secret, 5, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890');
		$donation->setHashid($hashids->encode($donation->getId()));

		$manager->flush();
	}

	private function charge(string $label, int $amount, bool $isRecurrent): Charge {
		$charge = new Charge();
		$charge->setLabel($label);
		$charge->setDutyFreeAmount((int) round($amount / 1.2));
		$charge->setAmount($amount);
		$charge->setIsRecurrent($isRecurrent);

		return $charge;
	}

}
