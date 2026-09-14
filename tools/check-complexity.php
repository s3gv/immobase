<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

/**
 * Prueft die Komplexitaetsgrenzen aus der Foundation-Spec, Abschnitt 6.3.
 *
 * Warum ein eigenes Werkzeug statt PHPMD: PHPMD haengt an PDepend, und PDepend
 * unterstuetzt Symfony 8 nicht (symfony/config nur bis ^7.0). Der Konflikt ist
 * hart, keine Versionsfrage. Dieses Skript prueft dafuer exakt die vier Zahlen
 * aus der Spec statt PHPMDs Naeherungen.
 *
 * Aufruf: php tools/check-complexity.php [pfad ...]
 */

require dirname(__DIR__).'/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

const MAX_COMPLEXITY = 8;
const MAX_METHOD_LINES = 30;
const MAX_CLASS_LINES = 200;
const MAX_NESTING = 3;

/**
 * Knoten, die einen zusaetzlichen Pfad durch den Code eroeffnen.
 */
const DECISION_NODES = [
    Node\Stmt\If_::class,
    Node\Stmt\ElseIf_::class,
    Node\Stmt\Catch_::class,
    Node\Stmt\For_::class,
    Node\Stmt\Foreach_::class,
    Node\Stmt\While_::class,
    Node\Stmt\Do_::class,
    Node\Expr\Ternary::class,
    Node\Expr\BinaryOp\BooleanAnd::class,
    Node\Expr\BinaryOp\BooleanOr::class,
    Node\Expr\BinaryOp\LogicalAnd::class,
    Node\Expr\BinaryOp\LogicalOr::class,
    Node\MatchArm::class,
];

/**
 * Knoten, die eine Verschachtelungsebene aufmachen.
 */
const NESTING_NODES = [
    Node\Stmt\If_::class,
    Node\Stmt\For_::class,
    Node\Stmt\Foreach_::class,
    Node\Stmt\While_::class,
    Node\Stmt\Do_::class,
    Node\Stmt\Switch_::class,
    Node\Stmt\TryCatch::class,
];

/**
 * @return list<string>
 */
function collectPhpFiles(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && 'php' === $file->getExtension()) {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

function cyclomaticComplexity(Node $function): int
{
    $finder = new NodeFinder();
    $count = 1;

    foreach (DECISION_NODES as $type) {
        $count += count($finder->findInstanceOf($function, $type));
    }

    return $count;
}

/**
 * Groesste Verschachtelungstiefe innerhalb einer Methode.
 *
 * Gemessen ueber die Elternkette statt ueber einen eigenen Baumlauf: das
 * vermeidet dynamischen Property-Zugriff und ist leichter nachzuvollziehen.
 *
 * else und elseif zaehlen bewusst nicht als eigene Ebene — ein else liegt auf
 * derselben Ebene wie sein if, nicht darunter.
 */
function nestingDepth(Node\Stmt\ClassMethod $method): int
{
    $traverser = new NodeTraverser(new ParentConnectingVisitor());
    $traverser->traverse([$method]);

    $finder = new NodeFinder();
    $deepest = 0;

    foreach (NESTING_NODES as $type) {
        foreach ($finder->findInstanceOf($method, $type) as $node) {
            $deepest = max($deepest, depthOf($node));
        }
    }

    return $deepest;
}

function depthOf(Node $node): int
{
    $depth = 0;
    $current = $node;

    while ($current instanceof Node) {
        if (in_array($current::class, NESTING_NODES, true)) {
            ++$depth;
        }

        $parent = $current->getAttribute('parent');
        $current = $parent instanceof Node ? $parent : null;
    }

    return $depth;
}

function lineCount(Node $node): int
{
    return $node->getEndLine() - $node->getStartLine() + 1;
}

/**
 * @return list<array{file: string, line: int, subject: string, rule: string, actual: int, limit: int}>
 */
function checkFile(string $path): array
{
    $parser = (new ParserFactory())->createForHostVersion();
    $ast = $parser->parse((string) file_get_contents($path));

    if (null === $ast) {
        return [];
    }

    $finder = new NodeFinder();
    $findings = [];

    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class) {
        $name = $class->name?->toString() ?? 'anonyme Klasse';
        $findings = array_merge($findings, checkClass($path, $name, $class));
    }

    return $findings;
}

/**
 * @return list<array{file: string, line: int, subject: string, rule: string, actual: int, limit: int}>
 */
function checkClass(string $path, string $className, Node\Stmt\ClassLike $class): array
{
    $findings = [];

    if (lineCount($class) > MAX_CLASS_LINES) {
        $findings[] = finding($path, $class->getStartLine(), $className, 'Klassenlänge', lineCount($class), MAX_CLASS_LINES);
    }

    foreach ((new NodeFinder())->findInstanceOf($class, Node\Stmt\ClassMethod::class) as $method) {
        $subject = $className.'::'.$method->name->toString().'()';
        $findings = array_merge($findings, checkMethod($path, $subject, $method));
    }

    return $findings;
}

/**
 * @return list<array{file: string, line: int, subject: string, rule: string, actual: int, limit: int}>
 */
function checkMethod(string $path, string $subject, Node\Stmt\ClassMethod $method): array
{
    $findings = [];
    $line = $method->getStartLine();

    $complexity = cyclomaticComplexity($method);
    if ($complexity > MAX_COMPLEXITY) {
        $findings[] = finding($path, $line, $subject, 'Zyklomatische Komplexität', $complexity, MAX_COMPLEXITY);
    }

    $lines = lineCount($method);
    if ($lines > MAX_METHOD_LINES) {
        $findings[] = finding($path, $line, $subject, 'Methodenlänge', $lines, MAX_METHOD_LINES);
    }

    $depth = nestingDepth($method);
    if ($depth > MAX_NESTING) {
        $findings[] = finding($path, $line, $subject, 'Verschachtelungstiefe', $depth, MAX_NESTING);
    }

    return $findings;
}

/**
 * @return array{file: string, line: int, subject: string, rule: string, actual: int, limit: int}
 */
function finding(string $file, int $line, string $subject, string $rule, int $actual, int $limit): array
{
    return ['file' => $file, 'line' => $line, 'subject' => $subject, 'rule' => $rule, 'actual' => $actual, 'limit' => $limit];
}

$root = dirname(__DIR__);
$argumentValues = $_SERVER['argv'] ?? [];
$arguments = [];

if (is_array($argumentValues)) {
    foreach (array_slice($argumentValues, 1) as $argument) {
        if (is_string($argument)) {
            $arguments[] = $argument;
        }
    }
}
$paths = [] === $arguments ? ['src', 'tools'] : $arguments;

$files = [];
foreach ($paths as $path) {
    $files = array_merge($files, collectPhpFiles($root.'/'.$path));
}

$findings = [];
foreach ($files as $file) {
    $findings = array_merge($findings, checkFile($file));
}

if ([] === $findings) {
    printf("Komplexität: %d Dateien geprüft, keine Überschreitungen.\n", count($files));

    exit(0);
}

printf("%d Überschreitung(en) der Komplexitätsgrenzen:\n\n", count($findings));

foreach ($findings as $item) {
    printf(
        "  %s:%d\n    %s — %s %d, erlaubt %d\n",
        substr($item['file'], strlen($root) + 1),
        $item['line'],
        $item['subject'],
        $item['rule'],
        $item['actual'],
        $item['limit'],
    );
}

printf("\nGrenzen: Komplexität %d, Methode %d Zeilen, Klasse %d Zeilen, Verschachtelung %d.\n",
    MAX_COMPLEXITY, MAX_METHOD_LINES, MAX_CLASS_LINES, MAX_NESTING);
printf("Umbauen, nicht unterdrücken. Siehe Foundation-Spec 6.3.\n");

exit(1);
