<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class ValidToolIdentityValue extends Constraint {

	public $message = 'Vous devez préciser le nom du produit.';

	public function validatedBy(): string {
		return 'ladb_core.valid_tool_identity_value_validator';
	}

	public function getTargets(): string|array {
		return self::CLASS_CONSTRAINT;
	}

}