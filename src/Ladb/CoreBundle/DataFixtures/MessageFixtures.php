<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Utils\MessageUtils;

/**
 * A thread (id 1) from "user2" to "user".
 */
final class MessageFixtures extends Fixture implements DependentFixtureInterface {

	private $factory;
	private $messageUtils;

	public function __construct(
		FixtureFactory $factory,
		MessageUtils $messageUtils
	) {
		$this->factory = $factory;
		$this->messageUtils = $messageUtils;
	}

	public function getDependencies() {
		return array( UserFixtures::class );
	}

	public function load(ObjectManager $manager): void {
		$user = $this->getReference(UserFixtures::USER);
		$user2 = $this->getReference(UserFixtures::USER2);

		$this->factory->actAs($user2);

		// Unread message count: composeThread() leaves it to the caller (notifyRecipientsForIncomingMessage() in the controller)
		$user->getMeta()->incrementUnreadMessageCount();
		$this->messageUtils->composeThread($user2, array( $user ), 'Ta table basse', 'Bonjour Jeanne, où as-tu acheté ton **chêne** ?');
	}

}
