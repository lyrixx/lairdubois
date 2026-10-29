<?php

namespace Ladb\CoreBundle\Entity\Core\Activity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table('tbl_core_activity_request')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Core\Activity\RequestRepository::class)]
class Request extends AbstractActivity {

	const CLASS_NAME = 'LadbCoreBundle:Core\Activity\Request';
	const STRIPPED_NAME = 'request';

	#[ORM\JoinColumn(nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\MemberRequest::class)]
	private $request;

	/////

	// StrippedName /////

	public function getStrippedName() {
		return self::STRIPPED_NAME;
	}

	// Request /////

	public function setRequest(\Ladb\CoreBundle\Entity\Core\MemberRequest $request) {
		$this->request = $request;
		return $this;
	}

	public function getRequest() {
		return $this->request;
	}

}