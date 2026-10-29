<?php

namespace Ladb\CoreBundle\Entity\Promotion;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Ladb\CoreBundle\Validator\Constraints as LadbAssert;
use Ladb\CoreBundle\Entity\AbstractDraftableAuthoredPublication;
use Ladb\CoreBundle\Model\CollectionnableInterface;
use Ladb\CoreBundle\Model\CollectionnableTrait;
use Ladb\CoreBundle\Model\SluggedInterface;
use Ladb\CoreBundle\Model\SluggedTrait;
use Ladb\CoreBundle\Model\LicensedTrait;
use Ladb\CoreBundle\Model\HtmlBodiedInterface;
use Ladb\CoreBundle\Model\HtmlBodiedTrait;
use Ladb\CoreBundle\Model\PicturedInterface;
use Ladb\CoreBundle\Model\PicturedTrait;
use Ladb\CoreBundle\Model\CommentableTrait;
use Ladb\CoreBundle\Model\IndexableTrait;
use Ladb\CoreBundle\Model\LikableTrait;
use Ladb\CoreBundle\Model\ScrapableTrait;
use Ladb\CoreBundle\Model\SitemapableInterface;
use Ladb\CoreBundle\Model\SitemapableTrait;
use Ladb\CoreBundle\Model\TaggableTrait;
use Ladb\CoreBundle\Model\TitledTrait;
use Ladb\CoreBundle\Model\ViewableTrait;
use Ladb\CoreBundle\Model\WatchableTrait;
use Ladb\CoreBundle\Model\IndexableInterface;
use Ladb\CoreBundle\Model\TitledInterface;
use Ladb\CoreBundle\Model\ViewableInterface;
use Ladb\CoreBundle\Model\LikableInterface;
use Ladb\CoreBundle\Model\WatchableInterface;
use Ladb\CoreBundle\Model\CommentableInterface;
use Ladb\CoreBundle\Model\ReportableInterface;
use Ladb\CoreBundle\Model\TaggableInterface;
use Ladb\CoreBundle\Model\ExplorableInterface;
use Ladb\CoreBundle\Model\ScrapableInterface;

#[LadbAssert\BodyBlocks]
#[ORM\Table('tbl_promotion_graphic')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Promotion\GraphicRepository::class)]
class Graphic extends AbstractDraftableAuthoredPublication implements TitledInterface, SluggedInterface, PicturedInterface, HtmlBodiedInterface, IndexableInterface, SitemapableInterface, TaggableInterface, ViewableInterface, ScrapableInterface, LikableInterface, WatchableInterface, CommentableInterface, CollectionnableInterface, ReportableInterface, ExplorableInterface {

	use TitledTrait, SluggedTrait, PicturedTrait, HtmlBodiedTrait, LicensedTrait;
	use IndexableTrait, SitemapableTrait, TaggableTrait, ViewableTrait, ScrapableTrait, LikableTrait, WatchableTrait, CommentableTrait, CollectionnableTrait;

	const CLASS_NAME = 'LadbCoreBundle:Promotion\Graphic';
	const TYPE = 117;

	const ACCEPTED_FILE_TYPE = '/(\.|\/)(pdf|svg)$/i';

	#[Assert\NotBlank]
	#[Assert\Length(min: 4, max: 100)]
	#[ORM\Column(type: 'string', length: 100)]
	private $title;

	#[Gedmo\Slug(fields: ['title'], separator: '-')]
	#[ORM\Column(type: 'string', length: 100, unique: true)]
	private $slug;

	#[Assert\Type(type: 'Ladb\CoreBundle\Entity\Core\Picture')]
	#[ORM\JoinColumn(nullable: true, name: 'main_picture_id')]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\Picture::class, cascade: ['persist'])]
	private $mainPicture;

	#[ORM\Column(type: 'text', nullable: false)]
	private $body;

	#[ORM\Column(type: 'text', nullable: false)]
	private $htmlBody;

	#[ORM\JoinTable(name: 'tbl_promotion_graphic_tag')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Core\Tag::class, cascade: ['persist'])]
	private $tags;

	#[Assert\Type(type: 'Ladb\CoreBundle\Entity\Core\Resource')]
	#[Assert\NotNull]
	#[ORM\JoinColumn(nullable: false, name: 'resource_id')]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\Resource::class, cascade: ['persist'])]
	private $resource;

	#[Assert\Type(type: 'Ladb\CoreBundle\Entity\Core\License')]
	#[ORM\JoinColumn(nullable: true, name: 'license_id')]
	#[ORM\OneToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\License::class, cascade: ['persist', 'remove'])]
	private $license;

	#[ORM\Column(type: 'integer', name: 'zip_archive_size')]
	private $zipArchiveSize = 0;

	#[ORM\Column(type: 'integer', name: 'download_count')]
	private $downloadCount = 0;

	#[ORM\Column(type: 'integer', name: 'like_count')]
	private $likeCount = 0;

	#[ORM\Column(type: 'integer', name: 'watch_count')]
	private $watchCount = 0;

	#[ORM\Column(type: 'integer', name: 'comment_count')]
	private $commentCount = 0;

	#[ORM\Column(type: 'integer', name: 'private_collection_count')]
	private $privateCollectionCount = 0;

	#[ORM\Column(type: 'integer', name: 'public_collection_count')]
	private $publicCollectionCount = 0;

	#[ORM\Column(type: 'integer', name: 'view_count')]
	private $viewCount = 0;

	/////

	public function __construct() {
		$this->tags = new \Doctrine\Common\Collections\ArrayCollection();
	}

	// NotificationStrategy /////

	public function getNotificationStrategy() {
		return self::NOTIFICATION_STRATEGY_FOLLOWER;
	}

	// Type /////

	public function getType() {
		return Graphic::TYPE;
	}

	// Resources /////

	public function setResource(\Ladb\CoreBundle\Entity\Core\Resource $resource) {
		$this->resource = $resource;
		return $this;
	}

	public function getResource() {
		return $this->resource;
	}

	// ResourceSizeSum /////

	public function setZipArchiveSize($zipArchiveSize) {
		return $this->zipArchiveSize = $zipArchiveSize;
	}

	public function getZipArchiveSize() {
		return $this->zipArchiveSize;
	}

	// DownloadCount /////

	public function incrementDownloadCount($by = 1) {
		return $this->downloadCount += intval($by);
	}

	public function setDownloadCount($downloadCount) {
		$this->downloadCount = $downloadCount;
		return $this;
	}

	public function getDownloadCount() {
		return $this->downloadCount;
	}

}