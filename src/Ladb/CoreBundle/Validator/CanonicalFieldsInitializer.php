<?php

namespace Ladb\CoreBundle\Validator;

use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Utils\CanonicalFieldsUpdater;
use Symfony\Component\Validator\ObjectInitializerInterface;

/**
 * The canonical username and email of a user before its validation, for the UniqueEntity constraints
 * on them: the Initializer of FOSUserBundle (#24), which left the displayname alone.
 */
final class CanonicalFieldsInitializer implements ObjectInitializerInterface {

	public function initialize($object) {
		if ($object instanceof User) {
			$object->setUsernameCanonical(CanonicalFieldsUpdater::canonicalize($object->getUsername()));
			$object->setEmailCanonical(CanonicalFieldsUpdater::canonicalize($object->getEmail()));
		}
	}

}
