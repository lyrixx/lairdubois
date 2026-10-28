<?php

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;

class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = array(
            new Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new Symfony\Bundle\SecurityBundle\SecurityBundle(),
            new Symfony\Bundle\TwigBundle\TwigBundle(),
            new Symfony\Bundle\MonologBundle\MonologBundle(),
            new Doctrine\Bundle\DoctrineBundle\DoctrineBundle(),
			new Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle(),
			new Sensio\Bundle\FrameworkExtraBundle\SensioFrameworkExtraBundle(),

			new FOS\ElasticaBundle\FOSElasticaBundle(),
			new Stof\DoctrineExtensionsBundle\StofDoctrineExtensionsBundle(),
			new Liip\ImagineBundle\LiipImagineBundle(),

			new Symfony\Bundle\MercureBundle\MercureBundle(),

			new BenTools\WebPushBundle\WebPushBundle(),

            new Ladb\CoreBundle\LadbCoreBundle(),
        );

        if (in_array($this->getEnvironment(), array('dev', 'test'))) {
			$bundles[] = new Symfony\Bundle\DebugBundle\DebugBundle();
			$bundles[] = new Symfony\Bundle\WebProfilerBundle\WebProfilerBundle();
			$bundles[] = new Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle();
        }

        if ('test' === $this->getEnvironment()) {
			$bundles[] = new DAMA\DoctrineTestBundle\DAMADoctrineTestBundle();
        }

        return $bundles;
    }

	public function getCacheDir()
	{
		return dirname(__DIR__).'/var/cache/'.$this->getEnvironment();
	}

	public function getLogDir()
	{
		return dirname(__DIR__).'/var/logs';
	}

	public function registerContainerConfiguration(LoaderInterface $loader)
	{
		$loader->load($this->getProjectDir().'/app/config/config_'.$this->getEnvironment().'.yml');
	}

	protected function initializeContainer()
	{
		// While it includes the cached container, the kernel reports every error but E_WARNING
		// (error_reporting(E_ALL ^ E_WARNING)), before any handler of Symfony: PHP 8.4+ would display
		// the deprecations of the files it loads then (implicitly nullable parameters), at the top of
		// the first page. What the level of the caller (php.ini, bin/console) hides stays hidden.
		// Still needed with Symfony 5.4: the third-party bundles loaded with the debug container (#23).
		$errorReporting = error_reporting();
		$previousHandler = set_error_handler(static function ($type, $message, $file, $line) use ($errorReporting, &$previousHandler) {
			if (0 === ($errorReporting & $type)) {
				return true;
			}

			return $previousHandler ? $previousHandler($type, $message, $file, $line) : false;
		});

		try {
			parent::initializeContainer();
		} finally {
			restore_error_handler();
		}
	}
}
