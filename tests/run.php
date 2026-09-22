<?php

declare(strict_types=1);

/**
 * Lanceur de tests minimal : charge tests/**\/*Test.php, exécute chaque méthode test* des classes
 * étendant Atelier\Testing\TestCase, affiche un rapport et retourne un code de sortie non nul en cas d'échec.
 *
 *   php tests/run.php [filtre]
 */

$root = dirname(__DIR__);
require $root . '/src/Kernel/Autoloader.php';
$autoloader = new \Atelier\Kernel\Autoloader();
$autoloader->addNamespace('Atelier', $root . '/src');
$autoloader->addNamespace('Atelier\\Tests', $root . '/tests');
$autoloader->register();

$filter = $argv[1] ?? null;
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
        $files[] = $file->getPathname();
    }
}
sort($files);

$passed = 0;
$failed = 0;
$errors = [];
$assertions = 0;
$start = microtime(true);

foreach ($files as $file) {
    $before = get_declared_classes();
    require $file;
    $classes = array_diff(get_declared_classes(), $before);
    foreach ($classes as $class) {
        if (!is_subclass_of($class, \Atelier\Testing\TestCase::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'test')) {
                continue;
            }
            $label = $class . '::' . $method->getName();
            if ($filter !== null && stripos($label, $filter) === false) {
                continue;
            }
            /** @var \Atelier\Testing\TestCase $instance */
            $instance = new $class();
            try {
                $instance->setUp();
                $instance->{$method->getName()}();
                $instance->tearDown();
                $passed++;
                echo '.';
            } catch (\Atelier\Testing\AssertionFailed $e) {
                $failed++;
                $errors[] = [$label, 'ÉCHEC', $e->getMessage(), $e->getTrace()[0]['file'] ?? '', $e->getTrace()[0]['line'] ?? 0];
                echo 'F';
            } catch (Throwable $e) {
                $failed++;
                $errors[] = [$label, 'ERREUR', $e::class . ': ' . $e->getMessage(), $e->getFile(), $e->getLine()];
                echo 'E';
            }
            $assertions += $instance->assertionCount();
        }
    }
}

echo PHP_EOL, PHP_EOL;
foreach ($errors as [$label, $kind, $message, $file, $line]) {
    echo sprintf("%s  %s\n    %s\n    %s:%d\n\n", $kind, $label, $message, $file, $line);
}
echo sprintf(
    "%d tests, %d assertions, %d réussis, %d échoués (%.2f s)\n",
    $passed + $failed,
    $assertions,
    $passed,
    $failed,
    microtime(true) - $start
);
exit($failed === 0 ? 0 : 1);
