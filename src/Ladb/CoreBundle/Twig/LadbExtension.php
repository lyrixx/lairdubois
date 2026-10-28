<?php

namespace Ladb\CoreBundle\Twig;

use Ladb\CoreBundle\Utils\UrlUtils;
use Ladb\CoreBundle\Utils\VideoHostingUtils;
use Ladb\CoreBundle\Parser\Markdown\LadbMarkdown;
use Ladb\CoreBundle\Utils\TypableUtils;
use Ladb\CoreBundle\Manager\Core\UserManager;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Extension\CoreExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

class LadbExtension extends AbstractExtension {

	private $router;
	private $serviceLocator;
	private $translator;
	private $urlUtils;

	// Twig creates its extensions with itself, at each request: TypableUtils, UserManager (the entity
	// manager) and VideoHostingUtils (Twig) are read from $serviceLocator when a filter or a function needs them
	public function __construct(RouterInterface $router, PsrContainerInterface $serviceLocator, TranslatorInterface $translator, UrlUtils $urlUtils) {
		$this->router = $router;
		$this->serviceLocator = $serviceLocator;
		$this->translator = $translator;
		$this->urlUtils = $urlUtils;
	}

	public function getFilters(): array {
		return array(
			new TwigFilter('ladb_file_size_format', array( $this, 'fileSizeFormatFilter' )),
			new TwigFilter('ladb_truncate_at', array( $this, 'truncateAtFilter' )),
			new TwigFilter('ladb_markdown', array( $this, 'markdownFilter' )),
			new TwigFilter('ladb_url_trim', array( $this, 'urlTrimFilter' )),
			new TwigFilter('ladb_url_truncate', array( $this, 'urlTruncateFilter' )),
			new TwigFilter('ladb_url_beautify', array( $this, 'urlBeautifyFilter' )),
			new TwigFilter('ladb_duration', array( $this, 'durationFilter' )),
			new TwigFilter('ladb_hours_minutes_duration', array( $this, 'hoursMinutesDurationFilter' )),

			// Filters of the abandoned twig/extensions, with the same code
			new TwigFilter('localizeddate', array( $this, 'localizedDateFilter' ), array( 'needs_environment' => true )),
			new TwigFilter('time_diff', array( $this, 'timeDiffFilter' ), array( 'needs_environment' => true )),
			new TwigFilter('truncate', array( $this, 'truncateFilter' ), array( 'needs_environment' => true )),
		);
	}

	public function getFunctions(): array {
		return array(
			new TwigFunction('ladb_entity', array( $this, 'entityFunction' )),
			new TwigFunction('ladb_entity_url_action', array( $this, 'entityUrlActionFunction' )),
			new TwigFunction('ladb_entity_type_stripped_name', array( $this, 'entityTypeStrippedNameFunction' )),
			new TwigFunction('ladb_entity_type_icon', array( $this, 'entityTypeIconFunction' )),
			new TwigFunction('ladb_value2json_tokens', array( $this, 'value2jsonTokensFunction' )),
			new TwigFunction('ladb_estimate_row_count', array( $this, 'estimateRowCountFunction' )),
			new TwigFunction('ladb_video_player_frame', array( $this, 'videoPlayerFrameFunction' )),
			new TwigFunction('ladb_video_icon_class', array( $this, 'videoIconClassFunction' )),
		);
	}

	public function getTests(): array {
		return array(
			new TwigTest('ladb_instanceof', array( $this, 'isInstanceof' )),
		);
	}

	// Filters /////

	public function fileSizeFormatFilter($fileSize, $decimals = 0) {
		$size = array( 'o', 'Ko', 'Mo', 'Go', 'To', 'Po', 'Eo', 'Zo', 'Yo');
		$factor = floor((strlen($fileSize) - 1) / 3);
		return sprintf("%.{$decimals}f", $fileSize / pow(1024, $factor)).' '.@$size[$factor];
	}

	public function truncateAtFilter($str, $needles = array( '{', '*', '[', '#' ), $separator = ' [...]') {
		$strLen = strlen($str);
		$pos = $strLen;
		foreach ($needles as $needle) {
			$needlePos = strpos($str, $needle);
			if ($needlePos) {
				$pos = min($pos, $needlePos);
			}
		}
		if ($pos < $strLen) {
			return substr($str, 0, $pos).$separator;
		}
		return $str;
	}

	public function markdownFilter($str) {
		$parser = new LadbMarkdown($this->serviceLocator->get(UserManager::NAME), $this->router, $this->urlUtils);
		return $parser->parse($str);
	}

	public function urlTrimFilter($str) {
		$str = preg_replace('#^https?://#', '', rtrim($str,'/'));
		return $str;
	}

	public function urlTruncateFilter($str, $removeProtocol = true, $lengthL = 14, $lengthR = 15, $separator = '...', $charset = 'UTF-8') {
		return $this->urlUtils->truncateUrl($str, $removeProtocol, $lengthL, $lengthR, $separator, $charset);
	}

	public function urlBeautifyFilter($str) {
		$components = parse_url($str);
		$str = '<span class="ladb-url-host">'.$components['host'].'</span>';
		if (isset($components['path'])) {
			$str .= '<span class="ladb-url-path">'.$components['path'].'</span>';
		}
		return '<span class="ladb-url">'.$str.'</span>';
	}

	public function durationFilter($duration, $long = false) {
		$d = new \DateTime();
		$d->add(new \DateInterval('PT'.$duration.'S'));
		$interval = $d->diff(new \DateTime());
		$translator = $this->translator;
		if ($interval->y > 0) {
			$str = $translator->trans('interval.'.($long ? 'long' : 'short').'.year', array('%count%' => $interval->y), 'date');
			if ($interval->m > 0) {
				$str .= ' '.$translator->trans('interval.'.($long ? 'long' : 'short').'.month', array('%count%' => $interval->m), 'date');
			}
		} else if ($interval->m > 0) {
			$str = $translator->trans('interval.'.($long ? 'long' : 'short').'.month', array('%count%' => $interval->m), 'date');
			if ($interval->d > 0) {
				$str .= ' '.$translator->trans('interval.'.($long ? 'long' : 'short').'.day', array('%count%' => $interval->d), 'date');
			}
		} else if ($interval->d > 0) {
			$str = $translator->trans('interval.'.($long ? 'long' : 'short').'.day', array('%count%' => $interval->d), 'date');
			if ($interval->h > 0) {
				$str .= ' '.$translator->trans('interval.'.($long ? 'long' : 'short').'.hour', array('%count%' => $interval->h), 'date');
			}
		} else if ($interval->h > 0) {
			$str = $translator->trans('interval.'.($long ? 'long' : 'short').'.hour', array('%count%' => $interval->h), 'date');
			if ($interval->i > 0) {
				$str .= ' '.$translator->trans('interval.'.($long ? 'long' : 'short').'.minute', array('%count%' => $interval->i), 'date');
			}
		} else if ($interval->i > 0) {
			$str = $translator->trans('interval.'.($long ? 'long' : 'short').'.minute', array('%count%' => $interval->i), 'date');
			if ($interval->s > 0) {
				$str .= ' '.$translator->trans('interval.'.($long ? 'long' : 'short').'.second', array('%count%' => $interval->s), 'date');
			}
		} else if ($interval->s > 0) {
			$str = $translator->trans('interval.'.($long ? 'long' : 'short').'.second', array('%count%' => $interval->s), 'date');
		} else {
			$str = '';
		}
		return $str;
	}

	public function hoursMinutesDurationFilter($duration, $long = false) {
		$str = '';
		if ($duration > 0) {
			$hours = floor($duration / 3600);
			$minutes = floor(($duration / 60) % 60);
			$translator = $this->translator;
			if ($hours > 0) {
				$str = $translator->trans('interval.'.($long ? 'long' : 'short').'.hour', array('%count%' => $hours), 'date');
				if ($minutes > 0) {
					$str .= ' '.$translator->trans('interval.'.($long ? 'long' : 'short').'.minute', array('%count%' => $minutes), 'date');
				}
			} else if ($minutes > 0) {
				$str = $translator->trans('interval.'.($long ? 'long' : 'short').'.minute', array('%count%' => $minutes), 'date');
			}
		}
		return $str;
	}

	// Copy of twig_localized_date_filter() of twig/extensions 1.5
	public function localizedDateFilter(Environment $env, $date, $dateFormat = 'medium', $timeFormat = 'medium', $locale = null, $timezone = null, $format = null, $calendar = 'gregorian') {
		$date = $env->getExtension(CoreExtension::class)->convertDate($date, $timezone);

		$formatValues = array(
			'none' => \IntlDateFormatter::NONE,
			'short' => \IntlDateFormatter::SHORT,
			'medium' => \IntlDateFormatter::MEDIUM,
			'long' => \IntlDateFormatter::LONG,
			'full' => \IntlDateFormatter::FULL,
		);

		$formatter = \IntlDateFormatter::create(
			$locale,
			$formatValues[$dateFormat],
			$formatValues[$timeFormat],
			\IntlTimeZone::createTimeZone($date->getTimezone()->getName()),
			'gregorian' === $calendar ? \IntlDateFormatter::GREGORIAN : \IntlDateFormatter::TRADITIONAL,
			$format
		);

		return $formatter->format($date->getTimestamp());
	}

	// Copy of Twig_Extensions_Extension_Date::diff() of twig/extensions 1.5
	public function timeDiffFilter(Environment $env, $date, $now = null) {
		$date = $env->getExtension(CoreExtension::class)->convertDate($date);
		$now = $env->getExtension(CoreExtension::class)->convertDate($now);

		$diff = $date->diff($now);

		$units = array(
			'y' => 'year',
			'm' => 'month',
			'd' => 'day',
			'h' => 'hour',
			'i' => 'minute',
			's' => 'second',
		);
		foreach ($units as $attribute => $unit) {
			$count = $diff->$attribute;
			if (0 !== $count) {
				$id = sprintf('diff.%s.%s', $diff->invert ? 'in' : 'ago', $unit);
				return $this->translator->trans($id, array( '%count%' => $count ), 'date');
			}
		}

		return '';
	}

	// Copy of twig_truncate_filter() of twig/extensions 1.5
	public function truncateFilter(Environment $env, $value, $length = 30, $preserve = false, $separator = '...') {
		if (mb_strlen($value, $env->getCharset()) > $length) {
			if ($preserve) {
				// If breakpoint is on the last word, return the value without separator.
				if (false === ($breakpoint = mb_strpos($value, ' ', $length, $env->getCharset()))) {
					return $value;
				}

				$length = $breakpoint;
			}

			return rtrim(mb_substr($value, 0, $length, $env->getCharset())).$separator;
		}

		return $value;
	}

	public function entityFunction($type, $id) {
		$typableUtils = $this->serviceLocator->get(TypableUtils::NAME);
		$typable = $typableUtils->findTypable($type, $id);
		return $typable;
	}

	public function entityUrlActionFunction($entity, $action = 'show', $absoluteUrl = true, $useSluggedId = true, $additionalParams = null) {
		$url = '';
		if ($entity instanceof \Ladb\CoreBundle\Model\TypableInterface) {
			$typableUtils = $this->serviceLocator->get(TypableUtils::NAME);
			$url = $typableUtils->getUrlAction($entity, $action, $absoluteUrl, $useSluggedId, $additionalParams);
		}
		return $url;
	}

    public function entityTypeStrippedNameFunction($entityOrType, $delimiter = '_', $capitalize = false) {
		$typableUtils = $this->serviceLocator->get(TypableUtils::NAME);
        if ($entityOrType instanceof \Ladb\CoreBundle\Model\TypableInterface) {
			return $typableUtils->getStrippedName($entityOrType, $delimiter, $capitalize);
        } else if (is_int($entityOrType) or is_string($entityOrType)) {
			return $typableUtils->getStrippedNameByType(intval($entityOrType), $delimiter, $capitalize);
		}
        return '';
    }

    public function entityTypeIconFunction($entityOrType) {
		$typableUtils = $this->serviceLocator->get(TypableUtils::NAME);
        if ($entityOrType instanceof \Ladb\CoreBundle\Model\TypableInterface) {
			return $typableUtils->getIcon($entityOrType);
        } else if (is_int($entityOrType) or is_string($entityOrType)) {
			return $typableUtils->getIconByType(intval($entityOrType));
		}
        return '';
    }

	public function value2jsonTokensFunction($value) {
        if (is_null($value) || strlen($value) == 0) {
            return '[]';
        }
        $tokens = explode(',', $value);
        $json = '';
        foreach ($tokens as $token) {
            $json .= ',{"id":"'.$token.'","name":"'.$token.'"}';
        }
        return '['.substr($json, 1).']';
    }

	public function estimateRowCountFunction($str, $maxCharPerRow = 100, $min = 2, $max = 40) {
		$rowCount = 0;
		$lines = explode("\n", $str);
		foreach ($lines as $line) {
			$strLen = strlen($line);
			if ($strLen == 0) {
				$rowCount++;
			} else {
				$rowCount += ceil(strlen($line) / $maxCharPerRow);
			}
		}
		return min($max, max($min, $rowCount));
	}

	public function videoPlayerFrameFunction($kind, $embedIdentifier, $width = '560', $height = '420', $styleClass = '', $autoPlay = false) {
		$videoHostingUtils = $this->serviceLocator->get(VideoHostingUtils::NAME);
		return $videoHostingUtils->getPlayerFrame($kind, $embedIdentifier, $width, $height, $styleClass, $autoPlay);
	}

	public function videoIconClassFunction($kind, $prefix = 'ladb-icon-') {
		$videoHostingUtils = $this->serviceLocator->get(VideoHostingUtils::NAME);
		return $videoHostingUtils->getIconClass($kind, $prefix);
	}

	// Tests /////

	public function isInstanceof($object, $class) {
		$reflectionClass = new \ReflectionClass($class);
		return $reflectionClass->isInstance($object);
	}

	public function getName() {
		return 'ladb_extension';
	}

}