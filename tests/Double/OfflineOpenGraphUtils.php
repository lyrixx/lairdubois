<?php

namespace Tests\Double;

use Ladb\CoreBundle\Utils\OpenGraphUtils;

/**
 * Open Graph of the test environment (config_test.yml): only URL has metas, any other page has
 * none, as an unreachable one.
 */
final class OfflineOpenGraphUtils extends OpenGraphUtils
{
    public const URL = 'https://bois.example/banc-sculpte.html';

    public const METAS = [
        'og:title' => 'Un banc sculpté',
        'og:description' => 'Un banc en châtaignier, sculpté à la main.',
        'og:image' => 'https://bois.example/banc-sculpte.jpg',
    ];

    public function fetchMetas($uri)
    {
        return self::URL === $uri ? self::METAS : false;
    }
}
