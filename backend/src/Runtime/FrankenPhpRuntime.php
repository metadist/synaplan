<?php

declare(strict_types=1);

namespace App\Runtime;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

/*
 * Inlined from runtime/frankenphp-symfony 1.0.0
 * (https://github.com/php-runtime/frankenphp-symfony, MIT, (c) Kévin Dunglas).
 *
 * Reason: the upstream package's composer.json constrains it to
 * symfony/* ^5.4 || ^6.0 || ^7.0, but synaplan is on Symfony 8.0.*. The
 * runtime code itself only touches stable Symfony interfaces unchanged in
 * Symfony 8, so we ship our own copy under App\Runtime\ and drop the
 * dependency. Revert to the package once upstream supports Symfony 8.
 */

/**
 * A runtime for FrankenPHP.
 */
final class FrankenPhpRuntime extends SymfonyRuntime
{
    /**
     * @param array{frankenphp_loop_max?: int} $options
     */
    public function __construct(array $options = [])
    {
        $options['frankenphp_loop_max'] = (int) ($options['frankenphp_loop_max'] ?? $_SERVER['FRANKENPHP_LOOP_MAX'] ?? $_ENV['FRANKENPHP_LOOP_MAX'] ?? 500);

        parent::__construct($options);
    }

    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof HttpKernelInterface && ($_SERVER['FRANKENPHP_WORKER'] ?? false)) {
            return new FrankenPhpRunner($application, $this->options['frankenphp_loop_max']);
        }

        return parent::getRunner($application);
    }
}
