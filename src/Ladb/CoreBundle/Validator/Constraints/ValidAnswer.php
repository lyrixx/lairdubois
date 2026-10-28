<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class ValidAnswer extends Constraint {

	public $message = 'Vidéo introuvable.';

	public function validatedBy(): string {
		return 'ladb_core.valid_answer_validator';
	}

	public function getTargets(): string|array {
		return self::CLASS_CONSTRAINT;
	}

}