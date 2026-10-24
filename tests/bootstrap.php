<?php

// Set by castor qa:phpunit. Before the autoloader: some of the files it includes trigger
// deprecations of PHP.
if (false !== $deprecationsFile = getenv('PHPUNIT_DEPRECATIONS_FILE')) {
    require __DIR__.'/DeprecationCollector.php';
    Tests\DeprecationCollector::register($deprecationsFile);
}

require dirname(__DIR__).'/app/autoload.php';

// No call to the Graph API of Facebook: its SDK has no service to replace (see the double)
require __DIR__.'/Double/OfflineFacebookGraph.php';
