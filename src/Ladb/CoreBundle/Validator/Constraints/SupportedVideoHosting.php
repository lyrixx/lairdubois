<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class SupportedVideoHosting extends Constraint {

	public function validatedBy(): string {
		return 'ladb_core.supported_video_hosting_validator';
	}

	public function getTargets(): string|array {
		return self::CLASS_CONSTRAINT;
	}

}