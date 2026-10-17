<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Support;

use App\AI\Service\AiFacade;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the thin-controller gate measures under a controller directory:
 * methods over 50 lines (constructors aside), and controllers that take the
 * entity manager or the AI facade in their constructor or an action. Shared
 * by ThinControllerTest and `make -C backend metrics`.
 */
final class ThinControllerOffenders
{
    public const MAX_METHOD_LINES = 50;

    /** @var array{entityManager: class-string, aiFacade: class-string} the parameter types a controller must not take */
    public const FORBIDDEN = [
        'entityManager' => EntityManagerInterface::class,
        'aiFacade' => AiFacade::class,
    ];

    /**
     * @param string $root      the backend directory
     * @param string $directory the controllers, relative to $root
     * @param string $namespace the namespace of $directory
     *
     * @return array{scanned: int, methodLines: array<string, int>, entityManager: list<string>, aiFacade: list<string>}
     */
    public static function measure(string $root, string $directory = 'src/Controller', string $namespace = 'App\\Controller'): array
    {
        $measured = ['scanned' => 0, 'methodLines' => [], 'entityManager' => [], 'aiFacade' => []];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            ++$measured['scanned'];
            $relative = substr($file->getPathname(), \strlen($root) + 1);

            foreach (SourceMetrics::ofFile($file->getPathname())->methodLines as $method => $lines) {
                if ($lines > self::MAX_METHOD_LINES && !str_ends_with($method, '::__construct')) {
                    $measured['methodLines'][$relative.'::'.$method] = $lines;
                }
            }

            $class = $namespace.'\\'.str_replace('/', '\\', substr($relative, \strlen($directory) + 1, -4));
            if (!class_exists($class)) {
                continue;
            }
            foreach (self::FORBIDDEN as $kind => $type) {
                if (self::takes($class, $type)) {
                    $measured[$kind][] = $relative;
                }
            }
        }

        ksort($measured['methodLines']);
        sort($measured['entityManager']);
        sort($measured['aiFacade']);

        return $measured;
    }

    /**
     * @param class-string $class
     * @param class-string $type
     */
    private static function takes(string $class, string $type): bool
    {
        $reflection = new \ReflectionClass($class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            if ($method->getDeclaringClass()->getName() !== $class && !$method->isConstructor()) {
                continue;
            }
            foreach ($method->getParameters() as $parameter) {
                foreach (self::typeNames($parameter->getType()) as $name) {
                    if (is_a($name, $type, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function typeNames(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            return array_merge(...array_map(self::typeNames(...), $type->getTypes()));
        }

        return [];
    }
}
