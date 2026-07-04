<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/app')
    ->in(__DIR__ . '/bootstrap')
    ->in(__DIR__ . '/config')
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return ChiefTools\PhpCsFixer\Config::make($finder);
