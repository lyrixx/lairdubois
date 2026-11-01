<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class OneThing extends Constraint {

	public $message = 'Une seule chose';

	#[HasNamedArguments]
	public function __construct(?string $message = null, ?array $groups = null, mixed $payload = null) {
		parent::__construct(null, $groups, $payload);

		$this->message = $message ?? $this->message;
	}

	public function validatedBy(): string {
		return 'ladb_core.one_thing_validator';
	}

	public function getTargets(): string|array {
		return self::PROPERTY_CONSTRAINT;
	}

}