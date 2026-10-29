<?php

namespace Ladb\CoreBundle\Entity\Core;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Ladb\CoreBundle\Model\IdentifiableInterface;

#[ORM\Table('tbl_core_member_request')]
#[ORM\UniqueConstraint(name: 'ENTITY_MEMBER_INVITATION_UNIQUE', columns: ['team_id', 'sender_id'])]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Core\MemberRequestRepository::class)]
class MemberRequest implements IdentifiableInterface {

	const CLASS_NAME = self::class;

	#[ORM\Column(name: 'id', type: 'integer')]
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'AUTO')]
	protected $id;

	#[ORM\Column(name: 'created_at', type: 'datetime')]
	#[Gedmo\Timestampable(on: 'create')]
	private $createdAt;

	#[ORM\JoinColumn(name: 'team_id', nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\User::class)]
	private $team;

	#[ORM\JoinColumn(nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\User::class)]
	private $sender;

	// ID /////

	public function getId() {
		return $this->id;
	}

	// CreatedAt /////

	public function getCreatedAt() {
		return $this->createdAt;
	}

	public function setCreatedAt($createdAt) {
		$this->createdAt = $createdAt;
		return $this;
	}

	// Team /////

	public function getTeam() {
		return $this->team;
	}

	public function setTeam($team) {
		$this->team = $team;
		return $this;
	}

	// Sender /////

	public function getSender() {
		return $this->sender;
	}

	public function setSender($sender) {
		$this->sender = $sender;
		return $this;
	}

}