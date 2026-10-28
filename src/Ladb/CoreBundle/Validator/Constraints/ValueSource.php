<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class ValueSource extends Constraint {

	public function validatedBy(): string {
		return 'ladb_core.value_source_validator';
	}

	public function getTargets(): string|array {
		return self::CLASS_CONSTRAINT;
	}

}