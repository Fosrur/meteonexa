<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        dirname(__DIR__, 2) . '/api',
        dirname(__DIR__, 2) . '/install',
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
