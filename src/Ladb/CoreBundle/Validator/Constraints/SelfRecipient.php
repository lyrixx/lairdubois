<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class SelfRecipient extends Constraint {

	public $message = 'Vous ne pouvez pas être le destinataire de votre propre message';

	public function validatedBy(): string {
		return 'ladb_core.self_recipient_validator';
	}

	public function getTargets(): string|array {
		return self::PROPERTY_CONSTRAINT;
	}

}