<?php

namespace Tests\Double;

use Ladb\CoreBundle\Utils\VideoHostingUtils;

/**
 * Video hosting of the test environment (config_test.yml): the thumbnails the YouTube (playlist)
 * and Vimeo APIs give, without calling them. The other hosts build the URL themselves.
 */
final class OfflineVideoHostingUtils extends VideoHostingUtils
{
    public function getThumbnailUrl($kind, $embedIdentifier)
    {
        if (self::KIND_YOUTUBEPLAYLIST === $kind || self::KIND_VIMEO === $kind) {
            return 'https://video.example/'.$kind.'/'.$embedIdentifier.'.jpg';
        }

        return parent::getThumbnailUrl($kind, $embedIdentifier);
    }
}
