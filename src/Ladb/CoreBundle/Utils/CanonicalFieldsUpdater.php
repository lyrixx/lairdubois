<?php

namespace Ladb\CoreBundle\Utils;

use Ladb\CoreBundle\Entity\Core\User;

class CanonicalFieldsUpdater {

	const NAME = 'ladb_core.canonical_fields_updater';

	private $displaynameCanonicalizer;

	public function __construct(DisplaynameCanonicalizer $displaynameCanonicalizer) {
		$this->displaynameCanonicalizer = $displaynameCanonicalizer;
	}

	/**
	 * The canonical username or email: the Canonicalizer of FOSUserBundle (#24). Static for
	 * UserRepository::loadUserByIdentifier(), a repository being no service.
	 */
	public static function canonicalize($string) {
		if (null === $string) {
			return null;
		}

		$encoding = mb_detect_encoding($string);

		return $encoding ? mb_convert_case($string, MB_CASE_LOWER, $encoding) : mb_convert_case($string, MB_CASE_LOWER);
	}

	public function updateCanonicalFields(User $user) {
		$user->setUsernameCanonical(self::canonicalize($user->getUsername()));
		$user->setEmailCanonical(self::canonicalize($user->getEmail()));
		$user->setDisplaynameCanonical($this->displaynameCanonicalizer->canonicalize($user->getDisplayname()));
	}

}
