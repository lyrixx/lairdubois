<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class ExcludeDomainsLink extends Constraint {

	public $excludedDomainPaterns = array();
	public $message = 'Ce domaine n\'est pas autorisé.';

	public function validatedBy(): string {
		return 'ladb_core.exclude_domains_link_validator';
	}

	public function getTargets(): string|array {
		return self::PROPERTY_CONSTRAINT;
	}

}