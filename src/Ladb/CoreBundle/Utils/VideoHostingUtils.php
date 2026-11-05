<?php

namespace Ladb\CoreBundle\Utils;

use Twig\Environment;

class VideoHostingUtils {

	const NAME = 'ladb_core.video_hosting_utils';

	const KIND_UNKNOW = 0;
	const KIND_YOUTUBE = 1;
	const KIND_DAILYMOTION = 2;
	const KIND_VIMEO = 3;
	const KIND_YOUTUBEPLAYLIST = 4;
	const KIND_FACEBOOK = 5;

	// A video in a URL, from the domain of its host on. A playlist has a longer identifier than a
	// YouTube video: it comes first
	private const VIDEO_PATTERNS = array(
		VideoHostingUtils::KIND_YOUTUBEPLAYLIST => '(?:youtu\.be\/|youtube\.com(?:\/embed\/|\/v\/|\/watch\?v=|\/playlist\?list=|\/ytscreeningroom\?v=|\/feeds\/api\/videos\/))([\w\-]{12,})',
		VideoHostingUtils::KIND_YOUTUBE => '(?:youtu\.be\/|youtube\.com(?:\/embed\/|\/v\/|\/watch\?v=|\/ytscreeningroom\?v=|\/feeds\/api\/videos\/))([\w\-]{11})',
		VideoHostingUtils::KIND_DAILYMOTION => '(?:dailymotion\.com\/video|dai\.ly)\/([a-zA-Z0-9]{7}|[A-Za-z0-9]{6})',
		VideoHostingUtils::KIND_VIMEO => 'vimeo\.com\/([0-9]{1,10})',
	);

	private $googleApiKey;
	private $twig;

	public function __construct($googleApiKey, Environment $twig) {
		$this->googleApiKey = $googleApiKey;
		$this->twig = $twig;
	}

	/////

	public function getKindAndEmbedIdentifier($url) {

		$kind = VideoHostingUtils::KIND_UNKNOW;
		$embedIdentifier = "";

		if (!is_null($url)) {

			foreach (VideoHostingUtils::VIDEO_PATTERNS as $patternKind => $pattern) {
				if (preg_match('~'.$pattern.'~i', $url, $match)) {
					$kind = $patternKind;
					$embedIdentifier = $match[1];
					break;
				}
			}

			// Facebook
//			else if (preg_match('^http(?:s?):\/\/www\.facebook\.com\/(?:(?:video.php|watch\/))\?v=([a-zA-Z0-9]+)', $url, $match)) {
//				$kind = VideoHostingUtils::KIND_FACEBOOK;
//				$embedIdentifier = $match[1];
//			}

		}

		return array(
			'kind' => $kind,
			'embedIdentifier' => $embedIdentifier,
		);
	}

	/**
	 * Whether a URL is the one of a video on its host, in http or https, and nothing else:
	 * getKindAndEmbedIdentifier() finds a video anywhere in a URL, whatever its host. Chromium opens
	 * this URL (WebScreenshotUtils::captureVideoToPicture()) and reads it its own way: the pattern
	 * goes from its first character to its last, without \ (a / to Chromium) or anything out of
	 * printable ASCII, and without dot segments, that Chromium resolves.
	 */
	public function isHostedVideoUrl($url) {

		if (preg_match('~\/\.|%2e~i', $url)) {
			return false;
		}

		foreach (VideoHostingUtils::VIDEO_PATTERNS as $pattern) {
			if (preg_match('~^https?:\/\/(?:(?:www|m|music|touch)\.)?'.$pattern.'[\x21-\x5b\x5d-\x7e]*\z~i', $url)) {
				return true;
			}
		}

		return false;
	}

	public function getPlayerFrame($kind, $embedIdentifier, $width = '560', $height = '420', $styleClass = '', $autoplay = false, $format = '16by9' /* or 4by3 */, $itemprop = 'video') {
		$embedUrl = null;
		$playerTemplate = null;
		switch ($kind) {

			case VideoHostingUtils::KIND_YOUTUBE:
				$embedUrl = '//www.youtube.com/embed/'.$embedIdentifier.($autoplay ? '?autoplay=1' : '');
				$playerTemplate = '@LadbCore/Core/Video/_youtube-player.part.html.twig';
				break;

			case VideoHostingUtils::KIND_YOUTUBEPLAYLIST:
				$embedUrl = '//www.youtube.com/embed/videoseries?list='.$embedIdentifier.($autoplay ? '&autoplay=1' : '');
				$playerTemplate = '@LadbCore/Core/Video/_youtubeplaylist-player.part.html.twig';
				break;

			case VideoHostingUtils::KIND_DAILYMOTION:
				$embedUrl = '//www.dailymotion.com/embed/video/'.$embedIdentifier.($autoplay ? '?autoplay=1' : '');
				$playerTemplate = '@LadbCore/Core/Video/_youtube-player.part.html.twig';
				break;

			case VideoHostingUtils::KIND_VIMEO:
				$embedUrl = '//player.vimeo.com/video/'.$embedIdentifier.'?title=0&amp;byline=0&amp;portrait=0&amp;color=ffffff'.($autoplay ? '&autoplay=1' : '');
				$playerTemplate = '@LadbCore/Core/Video/_vimeo-player.part.html.twig';
				break;

			case VideoHostingUtils::KIND_FACEBOOK:
				$embedUrl = 'https://www.facebook.com/video.php?v='.$embedIdentifier;
				$playerTemplate = '@LadbCore/Core/Video/_facebook-player.part.html.twig';
				break;

		}

		if (!is_null($playerTemplate)) {

			$player = $this->twig->render($playerTemplate, array(
				'width'    => $width,
				'height'   => $height,
				'embedUrl' => $embedUrl,
				'autoPlay' => $autoplay,
			));

			$microdataScope = 'itemscope itemtype="http://schema.org/VideoObject"';
			if (!empty($itemprop)) {
				$microdataScope = 'itemprop="'.$itemprop.'" '.$microdataScope;
			}
			$microdataMetas = '<meta itemprop="embedUrl" content="'.$embedUrl.'"/>';
			$player = '<div class="embed-responsive embed-responsive-'.$format.'">'.$player.'</div>';

			return '<div class="'.$styleClass.'" '.$microdataScope.'>'.$player.$microdataMetas.'</div>';
		}
		return '';
	}

	public function getThumbnailUrl($kind, $embedIdentifier) {
		switch ($kind) {

			case VideoHostingUtils::KIND_YOUTUBE:
				return 'http://img.youtube.com/vi/'.$embedIdentifier.'/hqdefault.jpg';

			case VideoHostingUtils::KIND_YOUTUBEPLAYLIST:
				$hash = json_decode(file_get_contents('https://www.googleapis.com/youtube/v3/playlists?part=contentDetails%2Csnippet&id='.$embedIdentifier.'&key='.$this->googleApiKey), true);
				if ($hash && isset($hash['items']) && isset($hash['items'][0]) && isset($hash['items'][0]['snippet'])) {
					$snippet = $hash['items'][0]['snippet'];
					return $snippet['thumbnails']['high']['url'];
				}
				break;

			case VideoHostingUtils::KIND_VIMEO:
				$hash = unserialize(file_get_contents('http://vimeo.com/api/v2/video/'.$embedIdentifier.'.php'));
				if ($hash && isset($hash[0]) && isset($hash[0]['thumbnail_large'])) {
					return $hash[0]['thumbnail_large'];
				}
				break;

			case VideoHostingUtils::KIND_DAILYMOTION:
				return 'http://www.dailymotion.com/thumbnail/video/'.$embedIdentifier;

		}
		return null;
	}

	public function getIconClass($kind, $prefix = 'ladb-icon-') {
		switch ($kind) {

			case VideoHostingUtils::KIND_YOUTUBE:
			case VideoHostingUtils::KIND_YOUTUBEPLAYLIST:
				return $prefix.'youtube-square';

			case VideoHostingUtils::KIND_VIMEO:
				return $prefix.'vimeo-square';

			case VideoHostingUtils::KIND_DAILYMOTION:
				return $prefix.'dailymotion-square';

			case VideoHostingUtils::KIND_FACEBOOK:
				return $prefix.'facebook-square';

		}
		return $prefix.'link';
	}

	public function getVideoSitemapData($kind, $embedIdentifier) {
		switch ($kind) {

			case VideoHostingUtils::KIND_YOUTUBE:
				$hash = json_decode(file_get_contents('https://www.googleapis.com/youtube/v3/videos?part=contentDetails%2Csnippet%2Cstatistics&id='.$embedIdentifier.'&key='.$this->googleApiKey), true);
				if ($hash && isset($hash['items']) && isset($hash['items'][0]) && isset($hash['items'][0]['snippet']) && isset($hash['items'][0]['contentDetails'])) {
					$snippet = $hash['items'][0]['snippet'];
					$contentDetails = $hash['items'][0]['contentDetails'];
					$statistics = $hash['items'][0]['statistics'];
					return array(
						'thumbnail_loc' => $snippet['thumbnails']['high']['url'],
						'title' => $snippet['title'],
						'description' => $snippet['description'],
						'content_loc' => null,
						'player_loc' => 'http://www.youtube.com/embed/'.$embedIdentifier,
						'duration' => date_create('@0')->add(new \DateInterval($contentDetails['duration']))->getTimestamp(),
						'rating' => null,
						'view_count' => $statistics['viewCount'],
						'publication_date' => $snippet['publishedAt'],
						'category' => null,
						'uploader' => $snippet['channelTitle'],
					);
				}
				break;

			case VideoHostingUtils::KIND_VIMEO:
				$hash = json_decode(file_get_contents('http://vimeo.com/api/v2/video/'.$embedIdentifier.'.json'));
				if ($hash && isset($hash[0])) {
					$data = $hash[0];
					return array(
						'thumbnail_loc' => $data->thumbnail_large,
						'title' => $data->title,
						'description' => $data->description,
						'content_loc' => null,
						'player_loc' => $data->url,
						'duration' => $data->duration,
						'rating' => null,
						'view_count' => null,
						'publication_date' => null,
						'category' => null,
						'uploader' => $data->user_name,
					);
				}
				break;

			case VideoHostingUtils::KIND_DAILYMOTION:
				$hash = json_decode(file_get_contents('https://api.dailymotion.com/video/'.$embedIdentifier.'?fields=thumbnail_url,title,description,url,duration,views_total'));
				if ($hash) {
					return array(
						'thumbnail_loc' => $hash->thumbnail_url,
						'title' => $hash->title,
						'description' => $hash->description,
						'content_loc' => null,
						'player_loc' => $hash->url,
						'duration' => $hash->duration,
						'rating' => null,
						'view_count' => $hash->views_total,
						'publication_date' => null,
						'category' => null,
						'uploader' => null,
					);
				}
				break;

		}
		return null;
	}

	public function getVideoGwData($kind, $embedIdentifier) {
		switch ($kind) {

			case VideoHostingUtils::KIND_YOUTUBE:

				// Video data
				$hash = json_decode(file_get_contents('https://www.googleapis.com/youtube/v3/videos?part=snippet&id='.$embedIdentifier.'&key='.$this->googleApiKey), true);
				if ($hash && isset($hash['items']) && isset($hash['items'][0]) && isset($hash['items'][0]['snippet'])) {
					$snippet = $hash['items'][0]['snippet'];
					$videoData = array(
						'thumbnail_loc' => $snippet['thumbnails']['high']['url'],
						'title' => $snippet['title'],
						'description' => $snippet['description'],
					);

					// Channel data
					$hash = json_decode(file_get_contents('https://www.googleapis.com/youtube/v3/channels?part=snippet&&id='.$snippet['channelId'].'&key='.$this->googleApiKey), true);
					if ($hash && isset($hash['items']) && isset($hash['items'][0]) && isset($hash['items'][0]['snippet'])) {
						$id = $hash['items'][0]['id'];
						$snippet = $hash['items'][0]['snippet'];
						$channelData = array(
							'id' => $id,
							'thumbnail_loc' => $snippet['thumbnails']['high']['url'],
							'title' => $snippet['title'],
							'description' => $snippet['description'],
						);

						return array(
							'videoData' => $videoData,
							'channelData' => $channelData,
						);

					}

				}

				break;

		}
		return null;
	}

}