<?php

// Set by castor qa:phpunit. Before the autoloader: some of the files it includes trigger
// deprecations of PHP.
if (false !== $deprecationsFile = getenv('PHPUNIT_DEPRECATIONS_FILE')) {
    require __DIR__.'/DeprecationCollector.php';
    Tests\DeprecationCollector::register($deprecationsFile);
}

require dirname(__DIR__).'/app/autoload.php';
