<?php

namespace Tests\Double;

use Ladb\CoreBundle\Utils\VideoHostingUtils;

/**
 * Video hosting of the test environment (config_test.yml): the thumbnails the YouTube (playlist)
 * and Vimeo APIs give, the data of the videos for the sitemaps and for Youtook, without calling
 * them. The other hosts build the URL themselves.
 */
final class OfflineVideoHostingUtils extends VideoHostingUtils
{
    /**
     * The title getVideoSitemapData() gives to every video, followed by its identifier.
     */
    public const SITEMAP_TITLE = 'Vidéo hors ligne';

    public function getThumbnailUrl($kind, $embedIdentifier)
    {
        if (self::KIND_YOUTUBEPLAYLIST === $kind || self::KIND_VIMEO === $kind) {
            return 'https://video.example/'.$kind.'/'.$embedIdentifier.'.jpg';
        }

        return parent::getThumbnailUrl($kind, $embedIdentifier);
    }

    /**
     * The data of the YouTube, Vimeo and Dailymotion APIs for the sitemaps (ladb:cron:sitemaps),
     * without calling them. The parent knows no other host.
     */
    public function getVideoSitemapData($kind, $embedIdentifier)
    {
        if (!\in_array($kind, [self::KIND_YOUTUBE, self::KIND_VIMEO, self::KIND_DAILYMOTION], true)) {
            return parent::getVideoSitemapData($kind, $embedIdentifier);
        }

        return [
            'thumbnail_loc' => 'https://video.example/'.$kind.'/'.$embedIdentifier.'.jpg',
            'title' => self::SITEMAP_TITLE.' '.$embedIdentifier,
            'description' => 'Une vidéo de démonstration.',
            'content_loc' => null,
            'player_loc' => 'https://video.example/'.$kind.'/'.$embedIdentifier,
            'duration' => 60,
            'rating' => null,
            'view_count' => null,
            'publication_date' => null,
            'category' => null,
            'uploader' => null,
        ];
    }

    /**
     * The video and its channel as the YouTube API gives them to Youtook (NewTookType), for any
     * video; null for the other hosts, as the original.
     */
    public function getVideoGwData($kind, $embedIdentifier)
    {
        if (self::KIND_YOUTUBE !== $kind) {
            return null;
        }

        return [
            'videoData' => [
                'thumbnail_loc' => 'https://video.example/'.$kind.'/'.$embedIdentifier.'.jpg',
                'title' => 'Affûter un ciseau à bois ('.$embedIdentifier.')',
                'description' => 'Une pierre à eau et un guide d\'affûtage.',
            ],
            'channelData' => [
                'id' => 'UCvideoexample',
                'thumbnail_loc' => 'https://video.example/channel.jpg',
                'title' => 'Une chaîne d\'ébénisterie',
                'description' => 'Des vidéos sur le travail du bois.',
            ],
        ];
    }
}
