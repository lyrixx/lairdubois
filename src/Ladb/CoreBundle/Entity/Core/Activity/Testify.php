<?php

namespace Ladb\CoreBundle\Entity\Core\Activity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table('tbl_core_activity_testify')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Core\Activity\TestifyRepository::class)]
class Testify extends AbstractActivity {

	const CLASS_NAME = 'LadbCoreBundle:Core\Activity\Testify';
	const STRIPPED_NAME = 'testify';

	#[ORM\JoinColumn(nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Knowledge\School\Testimonial::class)]
	private $testimonial;

	/////

	// StrippedName /////

	public function getStrippedName() {
		return self::STRIPPED_NAME;
	}

	// Testimonial /////

	public function setTestimonial(\Ladb\CoreBundle\Entity\Knowledge\School\Testimonial $testimonial) {
		$this->testimonial = $testimonial;
		return $this;
	}

	public function getTestimonial() {
		return $this->testimonial;
	}

}