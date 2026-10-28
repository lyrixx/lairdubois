<?php

namespace Ladb\CoreBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Ladb\CoreBundle\Entity\Core\UserWitness;
use Ladb\CoreBundle\Manager\Core\UserManager;
use Symfony\Contracts\Service\Attribute\Required;

trait UserControllerTrait {

	// Protected: a controller that uses this trait under a parent that uses it too, or has its own setOm(), replaces
	// the setter of the parent, which would read its own private property, left null
	protected $om;
	private $userManager;

	#[Required]
	public function setOm(EntityManagerInterface $om) {
		$this->om = $om;
	}

	#[Required]
	public function setUserManager(UserManager $userManager) {
		$this->userManager = $userManager;
	}

	protected function retrieveUserByUsername($username) {
		$user = $this->userManager->findUserByUsername($username);
		if (is_null($user)) {

			// Try to load user witness
			$userWitnessRepository = $this->om->getRepository(UserWitness::class);
			$userWitness = $userWitnessRepository->findOneByUsername($username);
			if (is_null($userWitness) || is_null($userWitness->getUser())) {
				throw $this->createNotFoundException('User not found (username='.$username.')');
			}

			$user = $userWitness->getUser();

		}
		if (!$user->isEnabled() && !$this->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('User not enabled (username='.$username.')');
		}

		return $user;
	}

}