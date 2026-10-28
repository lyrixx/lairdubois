<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class PlanResourcesMaxSize extends Constraint {

	public $maxSize = 62914560;	// 60 Mo
	public $message = 'La taille cumulée des fichiers ne doit pas dépasser 60Mo.';

	public function validatedBy(): string {
		return 'ladb_core.plan_resources_max_size_validator';
	}

	public function getTargets(): string|array {
		return self::CLASS_CONSTRAINT;
	}

}