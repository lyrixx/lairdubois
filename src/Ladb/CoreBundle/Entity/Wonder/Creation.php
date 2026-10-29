<?php

namespace Ladb\CoreBundle\Entity\Wonder;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Ladb\CoreBundle\Validator\Constraints as LadbAssert;
use Ladb\CoreBundle\Model\BlockBodiedTrait;
use Ladb\CoreBundle\Model\InspirableInterface;
use Ladb\CoreBundle\Model\InspirableTrait;
use Ladb\CoreBundle\Model\BlockBodiedInterface;
use Ladb\CoreBundle\Model\FeedbackableInterface;
use Ladb\CoreBundle\Model\FeedbackableTrait;
use Ladb\CoreBundle\Model\SpotlightableInterface;
use Ladb\CoreBundle\Model\SpotlightableTrait;

#[LadbAssert\BodyBlocks]
#[ORM\Table('tbl_wonder_creation')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Wonder\CreationRepository::class)]
class Creation extends AbstractWonder implements BlockBodiedInterface, InspirableInterface, FeedbackableInterface, SpotlightableInterface {

	use BlockBodiedTrait;
	use InspirableTrait, FeedbackableTrait, SpotlightableTrait;

	const CLASS_NAME = self::class;
	const TYPE = 100;

	#[Assert\Count(min: 1, max: 5)]
	#[ORM\JoinTable(name: 'tbl_wonder_creation_picture')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Core\Picture::class, cascade: ['persist'])]
	#[ORM\OrderBy(['sortIndex' => 'ASC'])]
	protected $pictures;

	#[ORM\Column(type: 'string', length: 255, nullable: false)]
	private $bodyExtract;

	#[Assert\Count(min: 1)]
	#[ORM\JoinTable(name: 'tbl_wonder_creation_body_block')]
	#[ORM\InverseJoinColumn(name: 'block_id', referencedColumnName: 'id', unique: true, onDelete: 'cascade')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Core\Block\AbstractBlock::class, cascade: ['persist', 'remove'])]
	#[ORM\OrderBy(['sortIndex' => 'ASC'])]
	private $bodyBlocks;

	#[ORM\Column(type: 'integer', name: 'body_block_picture_count')]
	private $bodyBlockPictureCount = 0;

	#[ORM\Column(type: 'integer', name: 'body_block_video_count')]
	private $bodyBlockVideoCount = 0;

	#[ORM\JoinTable(name: 'tbl_wonder_creation_wood')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Input\Wood::class, cascade: ['persist'])]
	private $woods;

	#[ORM\JoinTable(name: 'tbl_wonder_creation_tool')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Input\Tool::class, cascade: ['persist'])]
	private $tools;

	#[ORM\JoinTable(name: 'tbl_wonder_creation_finish')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Input\Finish::class, cascade: ['persist'])]
	private $finishes;

	#[ORM\JoinTable(name: 'tbl_wonder_creation_hardware')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Input\Hardware::class, cascade: ['persist'])]
	private $hardwares;

	#[ORM\JoinColumn(name: 'spotlight_id', referencedColumnName: 'id')]
	#[ORM\OneToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\Spotlight::class, cascade: ['remove'])]
	private $spotlight = null;

	#[ORM\JoinTable(name: 'tbl_wonder_creation_tag')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Core\Tag::class, cascade: ['persist'])]
	protected $tags;

	#[ORM\JoinTable(name: 'tbl_wonder_creation_referral')]
	#[ORM\InverseJoinColumn(name: 'referral_id', referencedColumnName: 'id', unique: true)]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Core\Referer\Referral::class, cascade: ['persist', 'remove'])]
	#[ORM\OrderBy(['accessCount' => 'DESC'])]
	protected $referrals;

	#[ORM\Column(type: 'integer', name: 'feedback_count')]
	private $feedbackCount = 0;

	#[ORM\Column(type: 'integer', name: 'rebound_count')]
	private $reboundCount = 0;

	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Wonder\Creation::class, mappedBy: 'inspirations')]
	private $rebounds;

	#[ORM\Column(type: 'integer', name: 'inspiration_count')]
	private $inspirationCount = 0;

	#[Assert\Count(min: 0, max: 4)]
	#[ORM\JoinTable(name: 'tbl_wonder_creation_inspiration')]
	#[ORM\JoinColumn(name: 'creation_id', referencedColumnName: 'id')]
	#[ORM\InverseJoinColumn(name: 'rebound_creation_id', referencedColumnName: 'id')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Wonder\Creation::class, inversedBy: 'rebounds', cascade: ['persist'])]
	private $inspirations;

	#[ORM\Column(type: 'integer', name: 'question_count')]
	private $questionCount = 0;

	#[Assert\Count(min: 0, max: 4)]
	#[ORM\JoinTable(name: 'tbl_wonder_creation_question')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Qa\Question::class, inversedBy: 'creations', cascade: ['persist'])]
	private $questions;

	#[ORM\Column(type: 'integer', name: 'plan_count')]
	private $planCount = 0;

	#[Assert\Count(min: 0, max: 4)]
	#[ORM\JoinTable(name: 'tbl_wonder_creation_plan')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Wonder\Plan::class, inversedBy: 'creations', cascade: ['persist'])]
	private $plans;

	#[ORM\Column(type: 'integer', name: 'howto_count')]
	private $howtoCount = 0;

	#[Assert\Count(min: 0, max: 4)]
	#[ORM\JoinTable(name: 'tbl_wonder_creation_howto')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Howto\Howto::class, inversedBy: 'creations', cascade: ['persist'])]
	private $howtos;

	#[ORM\Column(type: 'integer', name: 'workflow_count')]
	private $workflowCount = 0;

	#[Assert\Count(min: 0, max: 4)]
	#[ORM\JoinTable(name: 'tbl_wonder_creation_workflow')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Workflow\Workflow::class, inversedBy: 'creations', cascade: ['persist'])]
	private $workflows;

	#[ORM\Column(type: 'integer', name: 'provider_count')]
	private $providerCount = 0;

	#[Assert\Count(min: 0, max: 10)]
	#[ORM\JoinTable(name: 'tbl_wonder_creation_provider')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Knowledge\Provider::class, inversedBy: 'creations', cascade: ['persist'])]
	private $providers;

	#[ORM\Column(type: 'integer', name: 'school_count')]
	private $schoolCount = 0;

	#[Assert\Count(min: 0, max: 4)]
	#[ORM\JoinTable(name: 'tbl_wonder_creation_school')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Knowledge\School::class, inversedBy: 'creations', cascade: ['persist'])]
	private $schools;

	/////

	public function __construct() {
		parent::__construct();
		$this->bodyBlocks = new \Doctrine\Common\Collections\ArrayCollection();
		$this->woods = new \Doctrine\Common\Collections\ArrayCollection();
		$this->tools = new \Doctrine\Common\Collections\ArrayCollection();
		$this->finishes = new \Doctrine\Common\Collections\ArrayCollection();
		$this->hardwares = new \Doctrine\Common\Collections\ArrayCollection();
		$this->inspirations = new \Doctrine\Common\Collections\ArrayCollection();
		$this->questions = new \Doctrine\Common\Collections\ArrayCollection();
		$this->plans = new \Doctrine\Common\Collections\ArrayCollection();
		$this->howtos = new \Doctrine\Common\Collections\ArrayCollection();
		$this->workflows = new \Doctrine\Common\Collections\ArrayCollection();
		$this->providers = new \Doctrine\Common\Collections\ArrayCollection();
		$this->schools = new \Doctrine\Common\Collections\ArrayCollection();
	}

	// Type /////

	public function getType() {
		return Creation::TYPE;
	}

	// Woods /////

	public function addWood(\Ladb\CoreBundle\Entity\Input\Wood $wood) {
        if (!$this->woods->contains($wood)) {
		    $this->woods[] = $wood;
        }
		return $this;
	}

	public function removeWood(\Ladb\CoreBundle\Entity\Input\Wood $wood) {
		$this->woods->removeElement($wood);
	}

	public function getWoods() {
		return $this->woods;
	}

	// Tools /////

	public function addTool(\Ladb\CoreBundle\Entity\Input\Tool $tool) {
		if (!$this->tools->contains($tool)) {
			$this->tools[] = $tool;
		}
		return $this;
	}

	public function removeTool(\Ladb\CoreBundle\Entity\Input\Tool $tool) {
		$this->tools->removeElement($tool);
	}

	public function getTools() {
		return $this->tools;
	}

	// Finishes /////

	public function addFinish(\Ladb\CoreBundle\Entity\Input\Finish $finish) {
		if (!$this->finishes->contains($finish)) {
			$this->finishes[] = $finish;
		}
		return $this;
	}

	public function removeFinish(\Ladb\CoreBundle\Entity\Input\Finish $finish) {
		$this->finishes->removeElement($finish);
	}

	public function getFinishes() {
		return $this->finishes;
	}

	// Hardwares /////

	public function addHardware(\Ladb\CoreBundle\Entity\Input\Hardware $hardware) {
		if (!$this->hardwares->contains($hardware)) {
			$this->hardwares[] = $hardware;
		}
		return $this;
	}

	public function removeHardware(\Ladb\CoreBundle\Entity\Input\Hardware $hardware) {
		$this->hardwares->removeElement($hardware);
	}

	public function getHardwares() {
		return $this->hardwares;
	}

	// LinkedEntities /////

	public function getLinkedEntities() {
		return array_merge(
			$this->inspirations->getValues(),
			$this->questions->getValues(),
			$this->plans->getValues(),
			$this->howtos->getValues(),
			$this->workflows->getValues(),
			$this->providers->getValues(),
			$this->schools->getValues()
		);
	}

	// QuestionCount /////

	public function incrementQuestionCount($by = 1) {
		return $this->questionCount += intval($by);
	}

	public function getQuestionCount() {
		return $this->questionCount;
	}

	// Questions /////

	public function addQuestion(\Ladb\CoreBundle\Entity\Qa\Question $question) {
		if (!$this->questions->contains($question)) {
			$this->questions[] = $question;
			$this->questionCount = count($this->questions);
			if (!$this->getIsDraft()) {
				$question->incrementCreationCount();
			}
		}
		return $this;
	}

	public function removeQuestion(\Ladb\CoreBundle\Entity\Qa\Question $question) {
		if ($this->questions->removeElement($question)) {
			$this->questionCount = count($this->questions);
			if (!$this->getIsDraft()) {
				$question->incrementCreationCount(-1);
			}
		}
	}

	public function getQuestions() {
		return $this->questions;
	}

	// PlanCount /////

	public function incrementPlanCount($by = 1) {
		return $this->planCount += intval($by);
	}

	public function getPlanCount() {
		return $this->planCount;
	}

	// Plans /////

	public function addPlan(\Ladb\CoreBundle\Entity\Wonder\Plan $plan) {
		if (!$this->plans->contains($plan)) {
			$this->plans[] = $plan;
			$this->planCount = count($this->plans);
			if (!$this->getIsDraft()) {
				$plan->incrementCreationCount();
			}
		}
		return $this;
	}

	public function removePlan(\Ladb\CoreBundle\Entity\Wonder\Plan $plan) {
		if ($this->plans->removeElement($plan)) {
			$this->planCount = count($this->plans);
			if (!$this->getIsDraft()) {
				$plan->incrementCreationCount(-1);
			}
		}
	}

	public function getPlans() {
		return $this->plans;
	}

	// HowtoCount /////

	public function incrementHowtoCount($by = 1) {
		return $this->howtoCount += intval($by);
	}

	public function getHowtoCount() {
		return $this->howtoCount;
	}

	// Howtos /////

	public function addHowto(\Ladb\CoreBundle\Entity\Howto\Howto $howto) {
		if (!$this->howtos->contains($howto)) {
			$this->howtos[] = $howto;
			$this->howtoCount = count($this->howtos);
			if (!$this->getIsDraft()) {
				$howto->incrementCreationCount();
			}
		}
		return $this;
	}

	public function removeHowto(\Ladb\CoreBundle\Entity\Howto\Howto $howto) {
		if ($this->howtos->removeElement($howto)) {
			$this->howtoCount = count($this->howtos);
			if (!$this->getIsDraft()) {
				$howto->incrementCreationCount(-1);
			}
		}
	}

	public function getHowtos() {
		return $this->howtos;
	}

	// WorkflowCount /////

	public function incrementWorkflowCount($by = 1) {
		return $this->workflowCount += intval($by);
	}

	public function getWorkflowCount() {
		return $this->workflowCount;
	}

	// Workflows /////

	public function addWorkflow(\Ladb\CoreBundle\Entity\Workflow\Workflow $workflow) {
		if (!$this->workflows->contains($workflow)) {
			$this->workflows[] = $workflow;
			$this->workflowCount = count($this->workflows);
			if (!$this->getIsDraft()) {
				$workflow->incrementCreationCount();
			}
		}
		return $this;
	}

	public function removeWorkflow(\Ladb\CoreBundle\Entity\Workflow\Workflow $workflow) {
		if ($this->workflows->removeElement($workflow)) {
			$this->workflowCount = count($this->workflows);
			if (!$this->getIsDraft()) {
				$workflow->incrementCreationCount(-1);
			}
		}
	}

	public function getWorkflows() {
		return $this->workflows;
	}

	// ProviderCount /////

	public function incrementProviderCount($by = 1) {
		return $this->providerCount += intval($by);
	}

	public function getProviderCount() {
		return $this->providerCount;
	}

	// Providers /////

	public function addProvider(\Ladb\CoreBundle\Entity\Knowledge\Provider $provider) {
		if (!$this->providers->contains($provider)) {
			$this->providers[] = $provider;
			$this->providerCount = count($this->providers);
			if (!$this->getIsDraft()) {
				$provider->incrementCreationCount();
			}
		}
		return $this;
	}

	public function removeProvider(\Ladb\CoreBundle\Entity\Knowledge\Provider $provider) {
		if ($this->providers->removeElement($provider)) {
			$this->providerCount = count($this->providers);
			if (!$this->getIsDraft()) {
				$provider->incrementCreationCount(-1);
			}
		}
	}

	public function getProviders() {
		return $this->providers;
	}

	// SchoolCount /////

	public function incrementSchoolCount($by = 1) {
		return $this->schoolCount += intval($by);
	}

	public function getSchoolCount() {
		return $this->schoolCount;
	}

	// Schools /////

	public function addSchool(\Ladb\CoreBundle\Entity\Knowledge\School $school) {
		if (!$this->schools->contains($school)) {
			$this->schools[] = $school;
			$this->schoolCount = count($this->schools);
			if (!$this->getIsDraft()) {
				$school->incrementCreationCount();
			}
		}
		return $this;
	}

	public function removeSchool(\Ladb\CoreBundle\Entity\Knowledge\School $school) {
		if ($this->schools->removeElement($school)) {
			$this->schoolCount = count($this->schools);
			if (!$this->getIsDraft()) {
				$school->incrementCreationCount(-1);
			}
		}
	}

	public function getSchools() {
		return $this->schools;
	}

}