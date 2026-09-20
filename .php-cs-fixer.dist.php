<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/api',
        __DIR__ . '/install',
    ])
    ->name('*.php')
    ->exclude(['cache']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS2.0' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
    ])
    ->setFinder($finder);
