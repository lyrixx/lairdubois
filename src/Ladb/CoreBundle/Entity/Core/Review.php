<?php

namespace Ladb\CoreBundle\Entity\Core;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Ladb\CoreBundle\Validator\Constraints as LadbAssert;
use Ladb\CoreBundle\Model\TypableInterface;
use Ladb\CoreBundle\Model\AuthoredTrait;
use Ladb\CoreBundle\Model\TitledInterface;
use Ladb\CoreBundle\Model\TitledTrait;
use Ladb\CoreBundle\Model\HtmlBodiedTrait;
use Ladb\CoreBundle\Model\HtmlBodiedInterface;
use Ladb\CoreBundle\Model\AuthoredInterface;
use Ladb\CoreBundle\Model\BasicTimestampableInterface;
use Ladb\CoreBundle\Model\BasicTimestampableTrait;

#[ORM\Table('tbl_core_review')]
#[ORM\Index(name: 'IDX_REVIEW_ENTITY', columns: ['entity_type', 'entity_id'])]
#[ORM\UniqueConstraint(name: 'ENTITY_USER_UNIQUE', columns: ['entity_type', 'entity_id', 'user_id'])]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Core\ReviewRepository::class)]
class Review implements TypableInterface, BasicTimestampableInterface, AuthoredInterface, TitledInterface, HtmlBodiedInterface {

	use BasicTimestampableTrait;
	use AuthoredTrait, TitledTrait, HtmlBodiedTrait;

	const CLASS_NAME = 'LadbCoreBundle:Core\Review';
	const TYPE = 4;

	#[ORM\Column(name: 'id', type: 'integer')]
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'AUTO')]
	private $id;

	#[ORM\Column(name: 'entity_type', type: 'smallint', nullable: false)]
	private $entityType;

	#[ORM\Column(name: 'entity_id', type: 'integer', nullable: false)]
	private $entityId;

	#[ORM\Column(name: 'created_at', type: 'datetime')]
	#[Gedmo\Timestampable(on: 'create')]
	protected $createdAt;

	#[ORM\Column(name: 'updated_at', type: 'datetime')]
	#[Gedmo\Timestampable(on: 'update')]
	private $updatedAt;

	#[ORM\JoinColumn(nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\User::class)]
	private $user;

	#[Assert\NotBlank]
	#[Assert\Length(min: 2, max: 100)]
	#[ORM\Column(type: 'string', length: 100, nullable: false)]
	protected $title;

	#[Assert\NotBlank]
	#[Assert\Length(min: 5, max: 5000)]
	#[LadbAssert\NoMediaLink]
	#[ORM\Column(type: 'text', nullable: true)]
	protected $body;

	#[ORM\Column(name: 'html_body', type: 'text', nullable: true)]
	private $htmlBody;

	#[ORM\Column(type: 'integer', nullable: true)]
	private $rating;

	/////

	// Id /////

	public function getId() {
		return $this->id;
	}

	// Type /////

	public function getType() {
		return self::TYPE;
	}

	// EntityType /////

	public function setEntityType($entityType) {
		$this->entityType = $entityType;
	}

	public function getEntityType() {
		return $this->entityType;
	}

	// EntityId /////

	public function setEntityId($entityId) {
		$this->entityId = $entityId;
		return $this;
	}

	public function getEntityId() {
		return $this->entityId;
	}

	// Rating /////

	public function setRating($rating) {
		$this->rating = $rating;
		return $this;
	}

	public function getRating() {
		return $this->rating;
	}

}