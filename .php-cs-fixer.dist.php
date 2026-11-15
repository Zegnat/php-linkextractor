<?php

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS3x0' => true,
        // PER lets a project skip what its PHP versions lack: trailing commas outside arrays need PHP 7.3.
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/src', __DIR__ . '/tests'])
            ->append([__DIR__ . '/castor.php'])
    );
