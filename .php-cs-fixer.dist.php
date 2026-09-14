<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/tools'])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => false],
        'ordered_class_elements' => true,

        // Bewusst abgeschaltet: die Regel entfernt PHPDoc-Blöcke, die sie für
        // redundant hält — darunter verengende Typen wie non-empty-string oder
        // list<int>, die gegenüber dem nativen string bzw. array sehr wohl
        // Information tragen. PHPStan läuft hier auf Level max und braucht
        // diese Angaben. Ein Formatierer darf keine Typinformation löschen,
        // die der Analysator auswertet.
        'no_superfluous_phpdoc_tags' => false,

        // Aus demselben Grund abgeschaltet: die Regel wandelt PHPDoc-Blöcke,
        // die sie für rein beschreibend hält, in gewöhnliche Kommentare um —
        // darunter @var-Angaben, die PHPStan auswertet. Aus /** wird /*, und
        // die Typinformation ist weg.
        'phpdoc_to_comment' => false,
    ])
    ->setFinder($finder);
