<?php

declare(strict_types=1);

use Ladb\Rector\ControllerGetToActionArgumentRector;
use Rector\Config\RectorConfig;

// The paths come from the command line (castor qa:rector <path>)
return RectorConfig::configure()
    ->withRules([
        ControllerGetToActionArgumentRector::class,
    ])
    ->withIndent("\t", 1)
    ->withCache(__DIR__.'/var')
;
