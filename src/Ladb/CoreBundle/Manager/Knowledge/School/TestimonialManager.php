<?php

namespace Ladb\CoreBundle\Manager\Knowledge\School;

use Ladb\CoreBundle\Entity\Knowledge\School\Testimonial;
use Ladb\CoreBundle\Manager\AbstractManager;
use Ladb\CoreBundle\Utils\ActivityUtils;

class TestimonialManager extends AbstractManager {

	const NAME = 'ladb_core.knowledge_school_testimonial_manager';

	private $activityUtils;

	public function __construct(ActivityUtils $activityUtils) {
		$this->activityUtils = $activityUtils;
	}

	/////

	public function delete(Testimonial $testimonial, $flush = true) {

		$school = $testimonial->getSchool();

		// Decrement user testimonial count
		$testimonial->getUser()->getMeta()->incrementTestimonialCount(-1);

		// Decrement school testimonial count
		$school->incrementTestimonialCount(-1);

		// Delete activities
		$this->activityUtils->deleteActivitiesByTestimonial($testimonial, false);

		parent::deleteEntity($testimonial, $flush);
	}

}