<?php

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/app', __DIR__.'/routes', __DIR__.'/tests'])
    ->withPhpSets()
    ->withPreparedSets(deadCode: true);
