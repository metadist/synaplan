<?php

declare(strict_types=1);

/*
 * `make -C backend metrics`: src/ against the size limits of the ratchet,
 * with the largest offenders of each kind, then the controllers against the
 * thin-controller gate.
 */

use App\Tests\Architecture\Support\SizeOffenders;
use App\Tests\Architecture\Support\ThinControllerOffenders;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$measured = SizeOffenders::measure(dirname(__DIR__, 2));
$sections = [
    'fileLines' => sprintf('Files over %d lines', SizeOffenders::MAX_FILE_LINES),
    'constructorParams' => sprintf('Constructors over %d parameters', SizeOffenders::MAX_CONSTRUCTOR_PARAMS),
    'methodLines' => sprintf('Methods over %d lines', SizeOffenders::MAX_METHOD_LINES),
];

printf("Scanned %d files under src/.\n", $measured['scanned']);
foreach ($sections as $kind => $title) {
    $offenders = $measured[$kind];
    arsort($offenders);
    printf("\n%s: %d\n", $title, count($offenders));
    foreach (array_slice($offenders, 0, 5, true) as $where => $size) {
        printf("  %6d  %s\n", $size, $where);
    }
}

$controllers = ThinControllerOffenders::measure(dirname(__DIR__, 2));
printf(
    "\nControllers (%d): methods over %d lines %d, taking the entity manager %d, taking the AI facade %d\n",
    $controllers['scanned'],
    ThinControllerOffenders::MAX_METHOD_LINES,
    count($controllers['methodLines']),
    count($controllers['entityManager']),
    count($controllers['aiFacade']),
);
arsort($controllers['methodLines']);
foreach (array_slice($controllers['methodLines'], 0, 5, true) as $where => $size) {
    printf("  %6d  %s\n", $size, $where);
}
