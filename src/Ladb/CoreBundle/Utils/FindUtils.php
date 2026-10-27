<?php

namespace Ladb\CoreBundle\Utils;

use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Persistence\ManagerRegistry;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Entity\Core\Picture;
use Ladb\CoreBundle\Manager\Core\PictureManager;

class FindUtils {

	const NAME = 'ladb_core.find_utils';

	private $doctrine;
	private $openGraphUtils;
	private $pictureManager;
	private $videoHostingUtils;
	private $webScreenshotUtils;

	public function __construct(ManagerRegistry $doctrine, OpenGraphUtils $openGraphUtils, PictureManager $pictureManager, VideoHostingUtils $videoHostingUtils, WebScreenshotUtils $webScreenshotUtils) {
		$this->doctrine = $doctrine;
		$this->openGraphUtils = $openGraphUtils;
		$this->pictureManager = $pictureManager;
		$this->videoHostingUtils = $videoHostingUtils;
		$this->webScreenshotUtils = $webScreenshotUtils;
	}

	public function generateMainPicture(Find $find) {
		$om = $this->doctrine->getManager();

		switch ($find->getKind()) {

			case Find::KIND_WEBSITE:
				$website = $find->getContent();
				$mainPicture = null;

				$website->setHost(parse_url($website->getUrl(), PHP_URL_HOST));

				if (is_null($website->getThumbnail())) {

					// Try to fetch OpenGraph image, title and description
					$ogMetas = $this->openGraphUtils->fetchMetas($website->getUrl());
					if ($ogMetas) {

						if (isset($ogMetas['og:image'])) {
							$mainPicture = $this->pictureManager->createFromUrl($ogMetas['og:image'], false);
						}

						if (isset($ogMetas['og:title'])) {
							$ogTitle = substr($ogMetas['og:title'], 0, 255);
							$website->setTitle($ogTitle);
						}

						if (isset($ogMetas['og:description'])) {
							$ogDescription = substr($ogMetas['og:description'], 0, 255);
							$website->setDescription($ogDescription);
						}

					}

					// No picture detected capture a screenshot
					if (is_null($mainPicture)) {
                        // TODO : Capture no longer works
						// $mainPicture = $webScreenshotUtils->captureToPicture($website->getUrl(), 1280, 1024, 1280, 1024);
                        $mainPicture = $this->pictureManager->createEmpty();
					}

					$website->setThumbnail($mainPicture);

				} else {
					$mainPicture = $website->getThumbnail();
				}
				$find->setMainPicture($mainPicture);

				break;

			case Find::KIND_VIDEO:
				$video = $find->getContent();

				if (is_null($video->getThumbnail())) {
					$thumbnailUrl = $this->videoHostingUtils->getThumbnailUrl($video->getKind(), $video->getEmbedIdentifier());
					if (!is_null($thumbnailUrl)) {
						$mainPicture = $this->pictureManager->createFromUrl($thumbnailUrl, false);
					} else {
						$mainPicture = $this->webScreenshotUtils->captureToPicture($video->getUrl(), 1280, 1024, 1280, 1024);
					}
					$video->setThumbnail($mainPicture);
				} else {
					$mainPicture = $video->getThumbnail();
				}
				$find->setMainPicture($mainPicture);
				break;

			case Find::KIND_GALLERY:
				$gallery = $find->getContent();
				$find->setMainPicture($gallery->getPictures()->first());
				break;

		}

	}

}