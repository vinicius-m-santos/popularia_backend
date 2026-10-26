<?php

// Estilo de código: vendor/bin/php-cs-fixer fix (ou composer cs para só conferir)
$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['var', 'vendor'])
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        // "/** @var Tipo */" antes de um return informa o tipo ao PHPStan.
        'phpdoc_to_comment' => ['allow_before_return_statement' => true],
    ])
    ->setFinder($finder)
;
