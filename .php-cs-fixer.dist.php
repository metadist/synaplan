<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ ])
    ->exclude(['public/up', 'app/inc/ai/grpc-triton']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'single_quote' => true,
        'braces_position' => ['functions_opening_brace' => 'same_line'],
    ])
    ->setFinder($finder);
