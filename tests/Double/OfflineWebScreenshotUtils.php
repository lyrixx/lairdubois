<?php

namespace Tests\Double;

use Ladb\CoreBundle\Utils\WebScreenshotUtils;

/**
 * Screenshots of the test environment (config_test.yml): the capture of a page by Chromium is a
 * copy of a picture of the fixtures.
 */
final class OfflineWebScreenshotUtils extends WebScreenshotUtils
{
    /** @var list<string> */
    private array $urls = [];

    public function captureToPicture($url, $width, $height, $clipWidth = 0, $clipHeight = 0, $waitBeforeCapture = 0)
    {
        $this->urls[] = $url;

        $picture = $this->pictureManager->createEmpty('jpg', false);
        copy(OfflinePictureManager::PICTURE, $picture->getAbsoluteMasterPath());
        $this->pictureManager->computeSizes($picture);

        $om = $this->doctrine->getManager();
        $om->persist($picture);
        $om->flush();

        return $picture;
    }

    /**
     * The URLs given to Chromium, until the next reboot of the kernel.
     *
     * @return list<string>
     */
    public function getUrls(): array
    {
        return $this->urls;
    }
}
