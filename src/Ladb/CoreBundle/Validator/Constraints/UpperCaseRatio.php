<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class UpperCaseRatio extends Constraint {

	public $message = 'Trop de majuscules.';
	public $maxRatio = 0.35;

	public function validatedBy(): string {
		return 'ladb_core.upper_case_ratio_validator';
	}

	public function getTargets(): string|array {
		return self::PROPERTY_CONSTRAINT;
	}

}