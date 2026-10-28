<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class ValidUserDisplayname extends Constraint {

	public function validatedBy(): string {
		return 'ladb_core.valid_user_displayname_validator';
	}

	public function getTargets(): string|array {
		return self::CLASS_CONSTRAINT;
	}

}