<?php

namespace Tests\Double;

use Ladb\CoreBundle\Manager\Core\PictureManager;
use Ladb\CoreBundle\Utils\WebScreenshotUtils;

/**
 * Screenshots of the test environment (config_test.yml): the capture of a page by Chromium is a
 * copy of a picture of the fixtures.
 */
final class OfflineWebScreenshotUtils extends WebScreenshotUtils
{
    public function captureToPicture($url, $width, $height, $clipWidth = 0, $clipHeight = 0, $waitBeforeCapture = 0)
    {
        $pictureManager = $this->get(PictureManager::NAME);
        $picture = $pictureManager->createEmpty('jpg', false);
        copy(OfflinePictureManager::PICTURE, $picture->getAbsoluteMasterPath());
        $pictureManager->computeSizes($picture);

        $om = $this->getDoctrine()->getManager();
        $om->persist($picture);
        $om->flush();

        return $picture;
    }
}
