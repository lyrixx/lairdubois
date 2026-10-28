<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class UniqueWood extends Constraint {

	public $excludedId = 0;
	public $message = 'Cette essence existe déjà.';

	public function validatedBy(): string {
		return 'ladb_core.unique_wood_validator';
	}

	public function getTargets(): string|array {
		return self::PROPERTY_CONSTRAINT;
	}

}