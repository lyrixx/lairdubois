<?php

namespace Ladb\CoreBundle\Controller;

use Ladb\CoreBundle\Entity\Core\UserWitness;
use Ladb\CoreBundle\Manager\Core\UserManager;

trait UserControllerTrait {

	private $userManager;

	/**
	 * @required
	 */
	public function setUserManager(UserManager $userManager) {
		$this->userManager = $userManager;
	}

	protected function retrieveUserByUsername($username) {
		$user = $this->userManager->findUserByUsername($username);
		if (is_null($user)) {

			// Try to load user witness
			$om = $this->getDoctrine()->getManager();
			$userWitnessRepository = $om->getRepository(UserWitness::class);
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