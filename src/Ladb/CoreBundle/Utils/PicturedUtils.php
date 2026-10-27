<?php

namespace Ladb\CoreBundle\Utils;

use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Ladb\CoreBundle\Entity\Core\Block\Gallery;
use Ladb\CoreBundle\Entity\Core\Picture;
use Ladb\CoreBundle\Model\BlockBodiedInterface;
use Ladb\CoreBundle\Model\MultiPicturedInterface;

class PicturedUtils {

	const NAME = 'ladb_core.pictured_utils';

	private $cacheManager;

	public function __construct(CacheManager $cacheManager) {
		$this->cacheManager = $cacheManager;
	}

	/////

	public function resetPictures($entity) {
		if ($entity instanceof MultiPicturedInterface) {
			$entity->resetPictures();
		}
		if ($entity instanceof BlockBodiedInterface) {
			foreach ($entity->getBodyBlocks() as $block) {
				if ($block instanceof Gallery) {
					$block->resetPictures();
				}
			}
		}
	}

	public function getPictureSitemapData(Picture $picture = null) {
		if (is_null($picture)) {
			return null;
		}
		return array(
			'loc'     => $this->cacheManager->getBrowserPath($picture->getWebPath(), '1024x1024i'),
			'caption' => $picture->getLegend(),
		);
	}

}