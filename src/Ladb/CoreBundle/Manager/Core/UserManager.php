<?php

namespace Ladb\CoreBundle\Manager\Core;

use Doctrine\ORM\EntityManagerInterface;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Utils\CanonicalFieldsUpdater;
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;

/**
 * The user manager of FOSUserBundle (#24).
 */
class UserManager {

	const NAME = 'ladb_core.core_user_manager';

	private $canonicalFieldsUpdater;
	private $encoderFactory;
	private $om;

	public function __construct(CanonicalFieldsUpdater $canonicalFieldsUpdater, EncoderFactoryInterface $encoderFactory, EntityManagerInterface $om) {
		$this->canonicalFieldsUpdater = $canonicalFieldsUpdater;
		$this->encoderFactory = $encoderFactory;
		$this->om = $om;
	}

	/////

	public function createUser() {
		return new User();
	}

	public function findUserBy(array $criteria) {
		return $this->om->getRepository(User::class)->findOneBy($criteria);
	}

	public function findUserByUsername($username) {
		return $this->findUserBy(array( 'usernameCanonical' => CanonicalFieldsUpdater::canonicalize($username) ));
	}

	public function findUserByEmail($email) {
		return $this->findUserBy(array( 'emailCanonical' => CanonicalFieldsUpdater::canonicalize($email) ));
	}

	public function findUserByUsernameOrEmail($usernameOrEmail) {
		return $this->om->getRepository(User::class)->loadUserByUsername($usernameOrEmail);
	}

	public function findUserByConfirmationToken($token) {
		return $this->findUserBy(array( 'confirmationToken' => $token ));
	}

	public function findUserByDisplayname($displayname) {
		return $this->findUserBy(array( 'displaynameCanonical' => CanonicalFieldsUpdater::canonicalize($displayname) ));
	}

	public function updateUser(User $user, $andFlush = true) {

		// Populate displayname if null
		if (is_null($user->getDisplayname())) {
			$user->setDisplayname($user->getUsername());
		}

		$this->canonicalFieldsUpdater->updateCanonicalFields($user);
		$this->hashPassword($user);

		$this->om->persist($user);
		if ($andFlush) {
			$this->om->flush();
		}
	}

	/////

	/**
	 * The encoder of security.yml (native) salts the hash itself.
	 */
	private function hashPassword(User $user) {
		$plainPassword = $user->getPlainPassword();
		if (null === $plainPassword || '' === $plainPassword) {
			return;
		}

		$user->setSalt(null);
		$user->setPassword($this->encoderFactory->getEncoder($user)->encodePassword($plainPassword, null));
		$user->eraseCredentials();
	}

}
