<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class ValidUserUsername extends Constraint {

	public function validatedBy(): string {
		return 'ladb_core.valid_user_username_validator';
	}

	public function getTargets(): string|array {
		return self::CLASS_CONSTRAINT;
	}

}