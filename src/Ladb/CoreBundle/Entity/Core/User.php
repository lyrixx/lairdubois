<?php

namespace Ladb\CoreBundle\Entity\Core;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Ladb\CoreBundle\Validator\Constraints as LadbAssert;
use Ladb\CoreBundle\Model\LocalisableInterface;
use Ladb\CoreBundle\Model\IndexableInterface;
use Ladb\CoreBundle\Model\IndexableTrait;
use Ladb\CoreBundle\Model\LocalisableTrait;
use Ladb\CoreBundle\Model\SitemapableInterface;
use Ladb\CoreBundle\Model\SitemapableTrait;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @ORM\Table("tbl_core_user")
 * @ORM\Entity(repositoryClass="Ladb\CoreBundle\Repository\Core\UserRepository")
 * @ORM\HasLifecycleCallbacks
 * @UniqueEntity("email")
 * @LadbAssert\ValidUserUsername()
 * @LadbAssert\ValidUserDisplayname()
 * @LadbAssert\ValidUserEmail()
 * @UniqueEntity(fields="usernameCanonical", errorPath="username", message="fos_user.username.already_used", groups={"Registration", "Profile"})
 * @UniqueEntity(fields="emailCanonical", errorPath="email", message="fos_user.email.already_used", groups={"Registration", "Profile"})
 */
class User implements UserInterface, EquatableInterface, \Serializable, IndexableInterface, SitemapableInterface, LocalisableInterface {

	use IndexableTrait, SitemapableTrait, LocalisableTrait;

	const CLASS_NAME = 'LadbCoreBundle:Core\User';

	const ROLE_DEFAULT = 'ROLE_USER';
	const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

	const ACCOUNT_TYPE_NONE = 0;
	const ACCOUNT_TYPE_ASSO = 1;
	const ACCOUNT_TYPE_PRO = 2;
	const ACCOUNT_TYPE_HOBBYIST = 3;
	const ACCOUNT_TYPE_BRAND = 4;

	/**
	 * @ORM\Column(name="id", type="integer")
	 * @ORM\Id
	 * @ORM\GeneratedValue(strategy="AUTO")
	 */
	protected $id;

	/**
	 * @ORM\Column(name="created_at", type="datetime")
	 * @Gedmo\Timestampable(on="create")
	 */
	private $createdAt;

	/**
	 * @ORM\Column(name="updated_at", type="datetime")
	 * @Gedmo\Timestampable(on="update")
	 */
	private $updatedAt;

	/**
	 * @ORM\Column(type="boolean", nullable=true, name="email_confirmed")
	 */
	private $emailConfirmed = false;

	/**
	 * @ORM\Column(type="string", length=180)
	 * @Assert\Length(min=3, max=25, allowEmptyString=true)
	 */
	protected $username;

	/**
	 * @ORM\Column(type="string", length=180, unique=true, name="username_canonical")
	 */
	protected $usernameCanonical;

	/**
	 * @ORM\Column(type="string", length=180)
	 * @Assert\NotBlank(groups={"settings"})
	 * @Assert\Email(mode="strict", groups={"settings"})
	 */
	protected $email;

	/**
	 * @ORM\Column(type="string", length=180, unique=true, name="email_canonical")
	 */
	protected $emailCanonical;

	/**
	 * @ORM\Column(type="boolean")
	 */
	protected $enabled;

	/**
	 * The salt of the sha512 hash of FOSUserBundle, null once the password is hashed again (#24).
	 *
	 * @ORM\Column(type="string", nullable=true)
	 */
	protected $salt;

	/**
	 * @ORM\Column(type="string")
	 */
	protected $password;

	protected $plainPassword;

	/**
	 * @ORM\Column(type="datetime", nullable=true, name="last_login")
	 */
	protected $lastLogin;

	/**
	 * The token of the forgotten password and of the email confirmation.
	 *
	 * @ORM\Column(type="string", length=180, unique=true, nullable=true, name="confirmation_token")
	 */
	protected $confirmationToken;

	/**
	 * @ORM\Column(type="datetime", nullable=true, name="password_requested_at")
	 */
	protected $passwordRequestedAt;

	/**
	 * @ORM\Column(type="array")
	 */
	protected $roles;

	/**
	 * @ORM\Column(type="string", length=25, unique=true)
	 * @Assert\Length(min=3, max=25, allowEmptyString=true, groups={"settings"})
	 * @Assert\NotBlank(groups={"settings"})
	 * @Assert\Regex("/^[ a-zA-Z0-9ÀÁÂÃÄÅàáâãäåÒÓÔÕÖØòóôõöøÈÉÊËèéêëÇçÌÍÎÏìíîïÙÚÛÜùúûüÿÑñ'°’’-]+$/")
	 */
	private $displayname;

	/**
	 * @ORM\Column(type="string", length=25, name="displayname_canonical")
	 */
	private $displaynameCanonical;

	/**
	 * @ORM\Column(type="string", length=100, nullable=true)
	 * @Assert\Length(min=3, max=100, allowEmptyString=true, groups={"settings"})
	 * @Assert\Regex("/^[A-Za-z][ a-zA-Z0-9ÀÁÂÃÄÅàáâãäåÒÓÔÕÖØòóôõöøÈÉÊËèéêëÇçÌÍÎÏìíîïÙÚÛÜùúûüÿÑñ'°’’-]+$/")
	 */
	private $fullname;

	/**
	 * @ORM\ManyToOne(targetEntity="Ladb\CoreBundle\Entity\Core\Picture", cascade={"persist", "remove"})
	 * @ORM\JoinColumn(name="avatar_id", nullable=true)
	 * @Assert\Type(type="Ladb\CoreBundle\Entity\Core\Picture")
	 */
	private $avatar;

	/**
	 * @ORM\Column(type="string", length=100, nullable=true)
	 */
	private $location;

	/**
	 * @ORM\Column(type="float", nullable=true)
	 */
	private $latitude;

	/**
	 * @ORM\Column(type="float", nullable=true)
	 */
	private $longitude;

	/**
	 * @ORM\Column(type="smallint", name="account_type")
	 */
	private $accountType = User::ACCOUNT_TYPE_NONE;

	/**
	 * @ORM\OneToMany(targetEntity="Ladb\CoreBundle\Entity\Core\Follower", mappedBy="followingUser")
	 */
	private $followers;

	/**
	 * @ORM\ManyToOne(targetEntity="Ladb\CoreBundle\Entity\Core\UserMeta", cascade={"persist", "remove"})
	 * @ORM\JoinColumn(name="meta_id", nullable=true)
	 */
	private $meta = null;

	// Team /////

	/**
	 * @ORM\Column(type="boolean", name="is_team", nullable=false)
	 */
	private $isTeam = false;

	/////

	public function __construct() {
		$this->enabled = false;
		$this->roles = array();
		$this->skills = new \Doctrine\Common\Collections\ArrayCollection();
	}

	public function __toString() {
		return (string) $this->getUsername();
	}

	// Serialization in the session (token) /////

	public function __serialize(): array {
		return array(
			$this->password,
			$this->salt,
			$this->usernameCanonical,
			$this->username,
			$this->enabled,
			$this->id,
			$this->email,
			$this->emailCanonical,
			$this->roles,
		);
	}

	/**
	 * The roles are unknown in a session of FOSUserBundle (before #24): the 8 first values only.
	 */
	public function __unserialize(array $data): void {
		list(
			$this->password,
			$this->salt,
			$this->usernameCanonical,
			$this->username,
			$this->enabled,
			$this->id,
			$this->email,
			$this->emailCanonical
		) = $data;
		$this->roles = $data[8] ?? null;
	}

	/**
	 * Never called: PHP prefers __serialize().
	 */
	public function serialize() {
		return serialize($this->__serialize());
	}

	/**
	 * A user in the "C:" format of \Serializable: the sessions of FOSUserBundle (#24).
	 */
	public function unserialize($serialized) {
		$this->__unserialize(unserialize($serialized));
	}

	/**
	 * Whether the session of the user stays logged in, the user being reloaded at each request: what
	 * Symfony 4.4 compared of the User of FOSUserBundle (AbstractToken::hasUserChanged()), with the
	 * roles of the user in the session in place of those of its token.
	 */
	public function isEqualTo(UserInterface $user) {
		if (!$user instanceof self) {
			return false;
		}
		if ($this->password !== $user->getPassword()) {
			return false;
		}
		if ($this->salt !== $user->getSalt()) {
			return false;
		}
		// Unknown in a session of FOSUserBundle, whose token keeps them: they cannot have changed since
		// the last request before #24
		if (null !== $this->roles) {
			$roles = $this->getRoles();
			$userRoles = $user->getRoles();
			if (count($userRoles) !== count($roles) || count($userRoles) !== count(array_intersect($userRoles, $roles))) {
				return false;
			}
		}
		if ($this->username !== $user->getUsername()) {
			return false;
		}
		if ($this->enabled !== $user->isEnabled()) {
			return false;
		}
		return true;
	}

	// ID /////

	public function getId() {
		return $this->id;
	}

	// Username /////

	public function setUsername($username) {
		$this->username = $username;
		return $this;
	}

	/**
	 * The constraints of FOSUserBundle (#24), on the getter: validated after those of the property, as
	 * when they were on its parent class.
	 *
	 * @Assert\NotBlank(message="fos_user.username.blank", groups={"Registration", "Profile"})
	 * @Assert\Length(min=2, minMessage="fos_user.username.short", max=180, maxMessage="fos_user.username.long", groups={"Registration", "Profile"})
	 */
	public function getUsername() {
		return $this->username;
	}

	public function setUsernameCanonical($usernameCanonical) {
		$this->usernameCanonical = $usernameCanonical;
		return $this;
	}

	public function getUsernameCanonical() {
		return $this->usernameCanonical;
	}

	// Email /////

	public function setEmail($email) {
		if ($email != $this->email) {
			$this->setEmailConfirmed(false);
		}
		$this->email = $email;
		return $this;
	}

	/**
	 * The constraints of FOSUserBundle (#24), on the getter as those of the username.
	 *
	 * @Assert\NotBlank(message="fos_user.email.blank", groups={"Registration", "Profile"})
	 * @Assert\Length(min=2, minMessage="fos_user.email.short", max=180, maxMessage="fos_user.email.long", groups={"Registration", "Profile"})
	 * @Assert\Email(message="fos_user.email.invalid", groups={"Registration", "Profile"})
	 */
	public function getEmail() {
		return $this->email;
	}

	public function setEmailCanonical($emailCanonical) {
		$this->emailCanonical = $emailCanonical;
		return $this;
	}

	public function getEmailCanonical() {
		return $this->emailCanonical;
	}

	// Enabled /////

	/**
	 * False for an account that an admin disabled, or whose email address was never confirmed when the
	 * email confirmation of FOSUserBundle was on (#142).
	 */
	public function setEnabled($boolean) {
		$this->enabled = (bool) $boolean;
		return $this;
	}

	public function isEnabled() {
		return $this->enabled;
	}

	// Password /////

	public function setSalt($salt) {
		$this->salt = $salt;
		return $this;
	}

	public function getSalt() {
		return $this->salt;
	}

	public function setPassword($password) {
		$this->password = $password;
		return $this;
	}

	public function getPassword() {
		return $this->password;
	}

	public function setPlainPassword($password) {
		$this->plainPassword = $password;
		return $this;
	}

	/**
	 * The constraints of FOSUserBundle (#24), on the getter as those of the username.
	 *
	 * @Assert\NotBlank(message="fos_user.password.blank", groups={"Registration", "ResetPassword", "ChangePassword"})
	 * @Assert\Length(min=2, minMessage="fos_user.password.short", max=4096, groups={"Registration", "Profile", "ResetPassword", "ChangePassword"})
	 */
	public function getPlainPassword() {
		return $this->plainPassword;
	}

	public function eraseCredentials() {
		$this->plainPassword = null;
	}

	// LastLogin /////

	public function setLastLogin(?\DateTime $time = null) {
		$this->lastLogin = $time;
		return $this;
	}

	public function getLastLogin() {
		return $this->lastLogin;
	}

	// ConfirmationToken /////

	public function setConfirmationToken($confirmationToken) {
		$this->confirmationToken = $confirmationToken;
		return $this;
	}

	public function getConfirmationToken() {
		return $this->confirmationToken;
	}

	public function setPasswordRequestedAt(?\DateTime $date = null) {
		$this->passwordRequestedAt = $date;
		return $this;
	}

	public function getPasswordRequestedAt() {
		return $this->passwordRequestedAt;
	}

	public function isPasswordRequestNonExpired($ttl) {
		return $this->getPasswordRequestedAt() instanceof \DateTime &&
			$this->getPasswordRequestedAt()->getTimestamp() + $ttl > time();
	}

	// Roles /////

	public function addRole($role) {
		$role = strtoupper($role);
		if ($role === static::ROLE_DEFAULT) {
			return $this;
		}

		if (!in_array($role, $this->roles, true)) {
			$this->roles[] = $role;
		}

		return $this;
	}

	public function removeRole($role) {
		if (false !== $key = array_search(strtoupper($role), $this->roles, true)) {
			unset($this->roles[$key]);
			$this->roles = array_values($this->roles);
		}

		return $this;
	}

	public function setRoles(array $roles) {
		$this->roles = array();

		foreach ($roles as $role) {
			$this->addRole($role);
		}

		return $this;
	}

	public function getRoles() {
		$roles = $this->roles;

		// we need to make sure to have at least one role
		$roles[] = static::ROLE_DEFAULT;

		return array_values(array_unique($roles));
	}

	public function hasRole($role) {
		return in_array(strtoupper($role), $this->getRoles(), true);
	}

	public function setSuperAdmin($boolean) {
		if (true === $boolean) {
			$this->addRole(static::ROLE_SUPER_ADMIN);
		} else {
			$this->removeRole(static::ROLE_SUPER_ADMIN);
		}

		return $this;
	}

	public function isSuperAdmin() {
		return $this->hasRole(static::ROLE_SUPER_ADMIN);
	}

	// IsIndexable /////

	public function isIndexable() {
		return $this->isEnabled();
	}

	// EmailConfirmed /////

	public function setEmailConfirmed($emailConfirmed) {
		$this->emailConfirmed = $emailConfirmed;
		return $this;
	}

	public function getEmailConfirmed() {
		return $this->emailConfirmed;
	}

	// CreatedAt /////

	public function setCreatedAt($createdAt) {
		$this->createdAt = $createdAt;
		return $this;
	}

	public function getCreatedAt() {
		return $this->createdAt;
	}

	// UpdatedAt /////

	public function setUpdatedAt($updatedAt) {
		$this->updatedAt = $updatedAt;
		return $this;
	}

	public function getUpdatedAt() {
		return $this->updatedAt;
	}

	// Displayname /////

	public function setDisplayname($displayname) {
		$this->displayname = $displayname;
		return $this;
	}

	public function getDisplayname() {
		return $this->displayname;
	}

	public function getTitle() {
		return $this->getDisplayname();
	}

	// DisplaynameCanonical /////

	public function setDisplaynameCanonical($displaynameCanonical) {
		$this->displaynameCanonical = $displaynameCanonical;
		return $this;
	}

	public function getDisplaynameCanonical() {
		return $this->displaynameCanonical;
	}

	// Fullname /////

	public function setFullname($fullname) {
		$this->fullname = $fullname;
		return $this;
	}

	public function getFullname() {
		return $this->fullname;
	}

	public function isFullnameDisplayble() {
		return !is_null($this->fullname) && strlen($this->fullname) > 0 && $this->fullname != $this->displayname;
	}

	// Avatar /////

	public function setAvatar(\Ladb\CoreBundle\Entity\Core\Picture $avatar = null) {
		$this->avatar = $avatar;
		return $this;
	}

	public function getAvatar() {
		return $this->avatar;
	}

	public function getMainPicture() {
		return $this->getAvatar();
	}

	// AccountType /////

	public function setAccountType($accountType) {
		$this->accountType = $accountType;
		return $this;
	}

	public function getAccountType() {
		return $this->accountType;
	}

	// MarkerIcon /////

	public function getMarkerIcon() {
		switch ($this->accountType) {
			case User::ACCOUNT_TYPE_ASSO:
				return 'asso';
			case User::ACCOUNT_TYPE_PRO:
				return 'pro';
			case User::ACCOUNT_TYPE_HOBBYIST:
				return 'hobbyist';
			case User::ACCOUNT_TYPE_BRAND:
				return 'brand';
		}
		return 'none';
	}

	// Meta /////

	public function getMeta() {
		if (is_null($this->meta)) {
			$this->meta = new UserMeta();
		}
		return $this->meta;
	}
	
	// IsTeam /////

	public function setIsTeam($isTeam) {
		$this->isTeam = $isTeam;
		return $this;
	}

	public function getIsTeam() {
		return $this->isTeam;
	}

}