<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class UniqueFind extends Constraint {

	public $message = 'Cette trouvaille existe déjà.';

	public function validatedBy(): string {
		return 'ladb_core.unique_find_validator';
	}

	public function getTargets(): string|array {
		return self::CLASS_CONSTRAINT;
	}

}