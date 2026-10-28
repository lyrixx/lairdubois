<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NoMediaLink extends Constraint {

	public $message = 'Les liens du type "http://www.lairdubois.fr/media/cache..." ne sont pas permis.';

	public function validatedBy(): string {
		return 'ladb_core.no_media_link_validator';
	}

	public function getTargets(): string|array {
		return self::PROPERTY_CONSTRAINT;
	}

}