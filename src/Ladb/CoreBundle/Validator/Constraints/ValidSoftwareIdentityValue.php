<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class ValidSoftwareIdentityValue extends Constraint {

	public $message = 'Vous devez préciser le nom du logiciel hôte.';

	public function validatedBy(): string {
		return 'ladb_core.valid_software_identity_value_validator';
	}

	public function getTargets(): string|array {
		return self::CLASS_CONSTRAINT;
	}

}