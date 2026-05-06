<?php

declare(strict_types=1);

namespace App\Runtime;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

/*
 * Inlined from runtime/frankenphp-symfony 1.0.0
 * (https://github.com/php-runtime/frankenphp-symfony, MIT, (c) Kévin Dunglas).
 * See FrankenPhpRuntime for the rationale.
 */

/**
 * A runner for FrankenPHP.
 */
final class FrankenPhpRunner implements RunnerInterface
{
    public function __construct(
        private HttpKernelInterface $kernel,
        private int $loopMax,
    ) {
    }

    public function run(): int
    {
        // Prevent worker script termination when a client connection is interrupted
        ignore_user_abort(true);

        $xdebugConnectToClient = function_exists('xdebug_connect_to_client');

        $server = array_filter($_SERVER, static fn (string $key) => !str_starts_with($key, 'HTTP_'), ARRAY_FILTER_USE_KEY);
        $server['APP_RUNTIME_MODE'] = 'web=1&worker=1';

        $sfRequest = null;
        $sfResponse = null;

        $handler = function () use ($server, &$sfRequest, &$sfResponse, $xdebugConnectToClient): void {
            if ($xdebugConnectToClient) {
                xdebug_connect_to_client();
            }

            // Merge env vars from DotEnv with the ones tied to the current request
            $_SERVER += $server;

            $sfRequest = Request::createFromGlobals();
            $sfResponse = $this->kernel->handle($sfRequest);

            $sfResponse->send();
        };

        $loops = 0;
        do {
            $ret = \frankenphp_handle_request($handler);

            if ($this->kernel instanceof TerminableInterface && null !== $sfRequest && null !== $sfResponse) {
                $this->kernel->terminate($sfRequest, $sfResponse);
            }

            gc_collect_cycles();
        } while ($ret && (-1 === $this->loopMax || ++$loops < $this->loopMax));

        return 0;
    }
}
