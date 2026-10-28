<?php

namespace Ladb\CoreBundle\Controller\Howto;

use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Controller\PublicationControllerTrait;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Utils\CollectionnableUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\EmbeddableUtils;
use Ladb\CoreBundle\Utils\ExplorableUtils;
use Ladb\CoreBundle\Utils\FollowerUtils;
use Ladb\CoreBundle\Utils\GlobalUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Symfony\Contracts\Service\Attribute\Required;

abstract class AbstractHowtoBasedController extends AbstractController {

	use PublicationControllerTrait;

	private $collectionnableUtils;
	private $commentableUtils;
	private $embeddableUtils;
	private $explorableUtils;
	private $followerUtils;
	private $globalUtils;
	private $likableUtils;
	private $watchableUtils;

	#[Required]
	public function setCollectionnableUtils(CollectionnableUtils $collectionnableUtils) {
		$this->collectionnableUtils = $collectionnableUtils;
	}

	#[Required]
	public function setCommentableUtils(CommentableUtils $commentableUtils) {
		$this->commentableUtils = $commentableUtils;
	}

	#[Required]
	public function setEmbeddableUtils(EmbeddableUtils $embeddableUtils) {
		$this->embeddableUtils = $embeddableUtils;
	}

	#[Required]
	public function setExplorableUtils(ExplorableUtils $explorableUtils) {
		$this->explorableUtils = $explorableUtils;
	}

	#[Required]
	public function setFollowerUtils(FollowerUtils $followerUtils) {
		$this->followerUtils = $followerUtils;
	}

	#[Required]
	public function setGlobalUtils(GlobalUtils $globalUtils) {
		$this->globalUtils = $globalUtils;
	}

	#[Required]
	public function setLikableUtils(LikableUtils $likableUtils) {
		$this->likableUtils = $likableUtils;
	}

	#[Required]
	public function setWatchableUtils(WatchableUtils $watchableUtils) {
		$this->watchableUtils = $watchableUtils;
	}

	protected function computeShowParameters(Howto $howto, $request) {
		$howtoRepository = $this->om->getRepository(Howto::CLASS_NAME);

		$userHowtos = $this->explorableUtils->getPreviousAndNextPublishedUserExplorables($howto, $howtoRepository, $howto->getUser()->getMeta()->getPublicHowtoCount());
		$similarHowtos = $this->explorableUtils->getSimilarExplorables($howto, 'fos_elastica.index.howto_howto', Howto::CLASS_NAME, $userHowtos);

		$user = $this->globalUtils->getUser();

		return array(
			'howto'             => $howto,
			'permissionContext' => $this->getPermissionContext($howto),
			'userHowtos'        => $userHowtos,
			'similarHowtos'     => $similarHowtos,
			'likeContext'       => $this->likableUtils->getLikeContext($howto, $user),
			'watchContext'      => $this->watchableUtils->getWatchContext($howto, $user),
			'commentContext'    => $this->commentableUtils->getCommentContext($howto),
			'collectionContext' => $this->collectionnableUtils->getCollectionContext($howto),
			'followerContext'   => $this->followerUtils->getFollowerContext($howto->getUser(), $user),
			'referral'          => $this->embeddableUtils->processReferer($howto, $request),
		);
	}

}