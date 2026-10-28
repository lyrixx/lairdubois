<?php

namespace Ladb\CoreBundle\Imagine;

use Liip\ImagineBundle\Binary\BinaryInterface;
use Liip\ImagineBundle\Imagine\Filter\PostProcessor\PostProcessorInterface;
use Liip\ImagineBundle\Model\Binary;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ProcessBuilder;

class ImageOptimizerPostProcessor implements PostProcessorInterface {

	/**
	 * @param BinaryInterface $binary
	 *
	 * @throws ProcessFailedException
	 *
	 * @return BinaryInterface
	 *
	 * @see      Implementation taken from Assetic\Filter\JpegoptimFilter
	 */
	public function process(BinaryInterface $binary, array $options = []): BinaryInterface {

		$type = strtolower($binary->getMimeType());
		$isJPEG = in_array($type, array('image/jpeg', 'image/jpg'));
		$isPNG = in_array($type, array('image/png'));
		if ($isJPEG || $isPNG) {

			$input = tempnam(sys_get_temp_dir(), 'ladb_imageoptimizer');
			file_put_contents($input, $binary->getContent());

			$factory = new \ImageOptimizer\OptimizerFactory(array(
				'jpegoptim_options' => array('-m95', '--strip-all', '--all-progressive'),
				// The default of ps/image-optimizer 1.x. With the --skip-if-larger of 2.x, a PNG that
				// pngquant (lossy) would make larger goes to optipng (lossless) instead
				'pngquant_options' => array('--force'),
			));

			$optimizer = $factory->get($isJPEG ? 'jpegoptim' : 'png');
			$optimizer->optimize($input); //optimized file overwrites original one

			$result = new Binary(file_get_contents($input), $binary->getMimeType(), $binary->getFormat());

			unlink($input);

			return $result;
		}

		return $binary;
	}
}
