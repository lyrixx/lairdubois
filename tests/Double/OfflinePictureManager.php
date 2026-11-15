<?php

namespace Tests\Double;

use Ladb\CoreBundle\Manager\Core\PictureManager;

/**
 * Picture manager of the test environment (config_test.yml): a picture "downloaded" from a URL
 * is a copy of a picture of the fixtures, without any request (ExternalUrlTest tests the real
 * download).
 */
final class OfflinePictureManager extends PictureManager
{
    public const PICTURE = __DIR__.'/../../src/Ladb/CoreBundle/Resources/fixtures/pictures/wood-1.jpg';

    /** @var list<string> */
    private array $urls = [];

    public function createFromUrl($url, $persist = true)
    {
        $this->urls[] = $url;

        $picture = $this->createEmpty('jpg', $persist);
        copy(self::PICTURE, $picture->getAbsolutePath());
        $this->computeSizes($picture);

        return $picture;
    }

    /**
     * The URLs of the pictures asked for, until the next reboot of the kernel.
     *
     * @return list<string>
     */
    public function getUrls(): array
    {
        return $this->urls;
    }
}
