<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stripe\Mock;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Checks outbound Stripe request params against the `$params` array shape that
 * the installed stripe-php SDK declares for the called method.
 *
 * stripe-php generates those shapes from the OpenAPI spec of the API version it
 * pins, so a parameter Stripe renamed or removed in a new SDK major shows up
 * here as an unknown key. PHPStan does not report it (array shapes accept
 * extra keys) and StripeMockHttpClient replays any body it is given.
 */
final class StripeParamShape
{
    private const GENERIC_ARRAY_TYPES = ['array', 'list', 'non-empty-array', 'non-empty-list', 'iterable'];
    private const PERMISSIVE_TYPES = ['array', 'mixed', 'iterable'];

    /** @var array<string, TypeNode> */
    private static array $cache = [];

    /**
     * @param class-string $class
     * @param array<mixed> $params
     *
     * @return list<string> dotted paths of keys the SDK shape does not declare
     */
    public static function unknownKeys(string $class, string $method, array $params): array
    {
        $cacheKey = $class.'::'.$method;
        if (!isset(self::$cache[$cacheKey])) {
            $docComment = (string) (new \ReflectionMethod($class, $method))->getDocComment();
            self::$cache[$cacheKey] = self::parseParamsType($docComment)
                ?? throw new \LogicException(sprintf('%s() declares no parsable @param array shape for $params, so outbound Stripe params cannot be checked against the SDK.', $cacheKey));
        }

        return self::unknownKeysForType(self::$cache[$cacheKey], $params);
    }

    /**
     * @param array<mixed> $params
     *
     * @return list<string>
     */
    public static function unknownKeysForType(TypeNode $type, array $params): array
    {
        return self::walk($type, $params, '');
    }

    public static function parseParamsType(string $docComment): ?TypeNode
    {
        $config = new ParserConfig([]);
        $constExprParser = new ConstExprParser($config);
        $parser = new PhpDocParser($config, new TypeParser($config, $constExprParser), $constExprParser);
        $phpDoc = $parser->parse(new TokenIterator((new Lexer($config))->tokenize($docComment)));

        foreach ($phpDoc->getParamTagValues() as $tag) {
            if ('$params' === $tag->parameterName) {
                return $tag->type;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function walk(TypeNode $type, mixed $value, string $path): array
    {
        if (!is_array($value)) {
            return [];
        }

        $shapes = [];
        $elementTypes = [];
        foreach (self::flatten($type) as $candidate) {
            if ($candidate instanceof ArrayShapeNode) {
                $shapes[] = $candidate;
            } elseif ($candidate instanceof ArrayTypeNode) {
                $elementTypes[] = $candidate->type;
            } elseif ($candidate instanceof GenericTypeNode && in_array($candidate->type->name, self::GENERIC_ARRAY_TYPES, true)) {
                $elementTypes[] = $candidate->genericTypes[count($candidate->genericTypes) - 1];
            } elseif ($candidate instanceof IdentifierTypeNode && in_array($candidate->name, self::PERMISSIVE_TYPES, true)) {
                return [];
            }
        }

        $best = null;
        foreach ($shapes as $shape) {
            $best = self::fewer($best, self::walkShape($shape, $value, $path));
        }
        foreach ($elementTypes as $elementType) {
            $unknown = [];
            foreach ($value as $key => $child) {
                array_push($unknown, ...self::walk($elementType, $child, self::join($path, $key)));
            }
            $best = self::fewer($best, $unknown);
        }

        return $best ?? [];
    }

    /**
     * @param array<mixed> $value
     *
     * @return list<string>
     */
    private static function walkShape(ArrayShapeNode $shape, array $value, string $path): array
    {
        $declared = [];
        foreach ($shape->items as $index => $item) {
            $declared[self::keyName($item, $index)] = $item->valueType;
        }

        $unknown = [];
        foreach ($value as $key => $child) {
            $childPath = self::join($path, $key);
            if (!isset($declared[(string) $key])) {
                if ($shape->sealed) {
                    $unknown[] = $childPath;
                }
                continue;
            }
            array_push($unknown, ...self::walk($declared[(string) $key], $child, $childPath));
        }

        return $unknown;
    }

    /**
     * @return list<TypeNode>
     */
    private static function flatten(TypeNode $type): array
    {
        if ($type instanceof UnionTypeNode || $type instanceof IntersectionTypeNode) {
            return array_merge(...array_map(self::flatten(...), $type->types));
        }
        if ($type instanceof NullableTypeNode) {
            return self::flatten($type->type);
        }

        return [$type];
    }

    private static function keyName(ArrayShapeItemNode $item, int $index): string
    {
        $key = $item->keyName;

        return match (true) {
            $key instanceof ConstExprStringNode => $key->value,
            $key instanceof ConstExprIntegerNode => $key->value,
            $key instanceof IdentifierTypeNode => $key->name,
            default => (string) $index,
        };
    }

    /**
     * @param list<string>|null $current
     * @param list<string>      $candidate
     *
     * @return list<string>
     */
    private static function fewer(?array $current, array $candidate): array
    {
        return null === $current || count($candidate) < count($current) ? $candidate : $current;
    }

    private static function join(string $path, int|string $key): string
    {
        return '' === $path ? (string) $key : $path.'.'.$key;
    }
}
