<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Core\Biography;
use Ladb\CoreBundle\Entity\Core\Registration;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Manager\Core\UserManager;
use Ladb\CoreBundle\Manager\Core\MemberInvitationManager;
use Ladb\CoreBundle\Manager\Core\MemberManager;

/**
 * The accounts: an admin, two members ("Personne") and a team ("Collectif") of the two members,
 * which invites the admin.
 * The password of each account that can log in is its username.
 */
final class UserFixtures extends Fixture {

	// Ids 1 to 4, in this order
	public const ADMIN = 'admin';
	public const USER = 'user';
	public const USER2 = 'user2';
	public const TEAM = 'team';

	private $factory;
	private $memberInvitationManager;
	private $memberManager;
	private $userManager;

	public function __construct(
		FixtureFactory $factory,
		MemberInvitationManager $memberInvitationManager,
		MemberManager $memberManager,
		UserManager $userManager
	) {
		$this->factory = $factory;
		$this->memberInvitationManager = $memberInvitationManager;
		$this->memberManager = $memberManager;
		$this->userManager = $userManager;
	}

	public function load(ObjectManager $manager) {
		$this->factory->copyPlaceholders();

		// The admin, also the sender of the welcome messages (parameter admin_username)
		$admin = $this->createUser($manager, self::ADMIN, 'Admin', 'Administrateur de L\'Air du Bois', User::ACCOUNT_TYPE_NONE);
		$admin->addRole('ROLE_ADMIN');
		$this->userManager->updateUser($admin);

		$user = $this->createUser($manager, self::USER, 'Jeanne Martin', 'Jeanne Martin', User::ACCOUNT_TYPE_HOBBYIST);
		$user->setLocation('Lyon, France');
		$user->setLatitude(45.764043);
		$user->setLongitude(4.835659);
		$user->getMeta()->setWebsite('https://www.example.com');
		foreach ($this->factory->skills('Ébénisterie,Tournage') as $skill) {
			$user->getMeta()->addSkill($skill);
		}
		$biography = new Biography();
		$biography->setBody('Menuisière amatrice, je travaille surtout le **chêne** et le *noyer*.');
		$this->factory->preprocess($biography);
		$user->getMeta()->setBiography($biography);
		$this->userManager->updateUser($user);

		$user2 = $this->createUser($manager, self::USER2, 'Paul Bernard', 'Paul Bernard', User::ACCOUNT_TYPE_PRO);
		$user2->setLocation('Nantes, France');
		$user2->setLatitude(47.218371);
		$user2->setLongitude(-1.553621);
		$this->userManager->updateUser($user2);

		// As UserController::teamCreateAction: no email, a random password, the creator as first member
		$team = $this->userManager->createUser();
		$team->setUsername(self::TEAM);
		$team->setDisplayname('Atelier Collectif');
		$team->setEmail(self::TEAM.'@lairdubois.test');
		$team->setEmailCanonical($team->getEmail());
		$team->setPlainPassword(bin2hex(random_bytes(20)));
		$team->setEnabled(true);
		$team->setIsTeam(true);
		$team->addRole('ROLE_TEAM');
		$team->setAvatar($this->factory->picture($team, 'avatar-team.png', 'avatar-team'));
		$team->getMeta()->setRequestEnabled(true);
		$this->userManager->updateUser($team);
		$this->register($manager, $team, $user);

		$this->memberManager->create($team, $user);
		$this->memberManager->create($team, $user2);
		$this->memberInvitationManager->create($team, $user, $admin);

		$this->factory->follow($user2, $user);
		$this->factory->follow($user, $team);

		$manager->flush();

		$this->addReference(self::ADMIN, $admin);
		$this->addReference(self::USER, $user);
		$this->addReference(self::USER2, $user2);
		$this->addReference(self::TEAM, $team);
	}

	private function createUser(ObjectManager $manager, string $username, string $displayname, string $fullname, int $accountType): User {
		$user = $this->userManager->createUser();
		$user->setUsername($username);
		$user->setDisplayname($displayname);
		$user->setFullname($fullname);
		$user->setEmail($username.'@lairdubois.test');
		$user->setEmailConfirmed(true);
		$user->setPlainPassword($username);
		$user->setEnabled(true);
		$user->setAccountType($accountType);
		$user->setAvatar($this->factory->picture($user, 'avatar-'.$username.'.png', 'avatar-'.$username));

		// Hashes the password with the encoder of security.yml (native), as the registration does
		$this->userManager->updateUser($user);
		$this->register($manager, $user);

		return $user;
	}

	private function register(ObjectManager $manager, User $user, ?User $creator = null): void {
		$registration = new Registration();
		$registration->setUser($user);
		if (null !== $creator) {
			$registration->setCreator($creator);
		}
		$registration->setClientIp4('127.0.0.1');
		$manager->persist($registration);
	}

}
