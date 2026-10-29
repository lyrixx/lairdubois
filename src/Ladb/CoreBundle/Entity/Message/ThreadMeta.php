<?php

namespace Ladb\CoreBundle\Entity\Message;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table('tbl_message_thread_meta')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Message\ThreadMetaRepository::class)]
class ThreadMeta {

	const CLASS_NAME = 'LadbCoreBundle:Message\ThreadMeta';

	#[ORM\Column(name: 'id', type: 'integer')]
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'AUTO')]
	private $id;

	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Message\Thread::class, inversedBy: 'metas')]
	private $thread;

	#[ORM\JoinColumn(name: 'participant_user_id', referencedColumnName: 'id')]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\User::class)]
	private $participant;

	#[ORM\Column(type: 'boolean', name: 'is_deleted')]
	private $isDeleted = false;

	// Id /////

	public function getId() {
		return $this->id;
	}

	// Message /////

	public function getThread() {
		return $this->thread;
	}

	public function setThread(\Ladb\CoreBundle\Entity\Message\Thread $thread) {
		$this->thread = $thread;
		return $this;
	}

	// Participant /////

	public function getParticipant() {
		return $this->participant;
	}

	public function setParticipant(\Ladb\CoreBundle\Entity\Core\User $participant) {
		$this->participant = $participant;
		return $this;
	}

	// IsDeleted /////

	public function getIsDeleted() {
		return $this->isDeleted;
	}

	public function setIsDeleted($isDeleted) {
		$this->isDeleted = $isDeleted;
		return $this;
	}
}