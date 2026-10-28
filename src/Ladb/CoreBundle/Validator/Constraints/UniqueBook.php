<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class UniqueBook extends Constraint {

	public $excludedId = 0;
	public $message = 'Ce livre existe déjà.';

	public function validatedBy(): string {
		return 'ladb_core.unique_book_validator';
	}

	public function getTargets(): string|array {
		return self::PROPERTY_CONSTRAINT;
	}

}