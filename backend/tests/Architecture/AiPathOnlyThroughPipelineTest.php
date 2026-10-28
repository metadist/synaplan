<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\FileAnalysisProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\ProviderMetadataInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\SupportsAsyncVideo;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\VideoGenerationProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Messages\MessagesTranslatorInterface;
use App\AI\Messages\PipelineMessagesTranslator;
use App\AI\Service\AiEmbeddingExecutor;
use App\AI\Service\AiFacade;
use App\AI\Service\AiVisionExecutor;
use PHPUnit\Framework\TestCase;

/**
 * Architecture gate for S1 AU12: no class outside `App\AI\Pipeline\`,
 * `App\AI\Provider\`, the facade transport classes (`AiFacade`,
 * `AiEmbeddingExecutor`, `AiVisionExecutor`) and the Messages translators
 * (`App\AI\Messages\Translator\`, `PipelineMessagesTranslator`) may inject or
 * call a provider's capability method or a `MessagesTranslatorInterface`
 * directly.
 */
final class AiPathOnlyThroughPipelineTest extends TestCase
{
    /** @var list<class-string> */
    private const CAPABILITY_INTERFACES = [
        ChatProviderInterface::class,
        EmbeddingProviderInterface::class,
        VisionProviderInterface::class,
        ImageGenerationProviderInterface::class,
        VideoGenerationProviderInterface::class,
        SupportsAsyncVideo::class,
        SpeechToTextProviderInterface::class,
        TextToSpeechProviderInterface::class,
        FileAnalysisProviderInterface::class,
        MessagesTranslatorInterface::class,
    ];

    /** @var list<class-string> */
    private const ALLOWED_TRANSPORT_CLASSES = [
        AiFacade::class,
        AiEmbeddingExecutor::class,
        AiVisionExecutor::class,
        PipelineMessagesTranslator::class,
    ];

    /**
     * Shrink-only baseline of legacy `MessagesTranslatorInterface` collaborators
     * (`GatewayToolLoop` receives the `PipelineMessagesTranslator` wrapper from
     * `MessagesGateway`).
     *
     * @var list<string>
     */
    private const BASELINE = [
        'App\AI\Messages\MessagesGateway',
        'App\AI\Messages\Tools\GatewayToolLoop',
    ];

    public function testNoClassOutsidePipelineAndProvidersReferencesProviderCapabilityTypesDirectly(): void
    {
        $violations = $this->scanSrc(\dirname(__DIR__, 2).'/src');

        self::assertSame([], $violations, sprintf(
            "Direct provider capability or translator reference(s) outside the pipeline:\n  %s",
            implode("\n  ", $violations),
        ));
    }

    public function testDetectorFlagsDirectProviderInjectionInDisallowedClass(): void
    {
        $ref = new \ReflectionClass(new class {
            public function callDirectly(ChatProviderInterface $provider): array
            {
                return $provider->chat([]);
            }
        });

        self::assertNotEmpty($this->inspectClass($ref));
    }

    /**
     * @return list<string>
     */
    private function scanSrc(string $srcDir): array
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($srcDir) + 1);
            if ('Kernel.php' === $relative) {
                continue;
            }

            $class = 'App\\'.str_replace('/', '\\', substr($relative, 0, -4));
            if ($this->isAllowedNamespaceOrClass($class)) {
                continue;
            }
            if (!class_exists($class) && !interface_exists($class)) {
                continue;
            }

            $ref = new \ReflectionClass($class);
            if ($ref->isInterface()) {
                continue;
            }

            foreach ($this->inspectClass($ref) as $violation) {
                $violations[] = $violation;
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * @template T of object
     *
     * @param \ReflectionClass<T> $ref
     *
     * @return list<string>
     */
    private function inspectClass(\ReflectionClass $ref): array
    {
        $className = $ref->getName();
        $found = [];

        foreach ($ref->getProperties() as $prop) {
            if ($prop->getDeclaringClass()->getName() !== $className) {
                continue;
            }
            foreach ($this->extractNamedTypes($prop->getType()) as $typeName) {
                if ($this->isForbiddenProviderType($typeName)) {
                    $found[] = sprintf('%s::$%s references %s', $className, $prop->getName(), $typeName);
                }
            }
        }

        foreach ($ref->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }
            foreach ($method->getParameters() as $param) {
                foreach ($this->extractNamedTypes($param->getType()) as $typeName) {
                    if ($this->isForbiddenProviderType($typeName)) {
                        $found[] = sprintf('%s::%s($%s) references %s', $className, $method->getName(), $param->getName(), $typeName);
                    }
                }
            }
        }

        return $found;
    }

    private function isAllowedNamespaceOrClass(string $class): bool
    {
        if (\in_array($class, self::ALLOWED_TRANSPORT_CLASSES, true) || \in_array($class, self::BASELINE, true)) {
            return true;
        }

        foreach (['App\\AI\\Pipeline\\', 'App\\AI\\Provider\\', 'App\\AI\\Messages\\Translator\\', 'App\\AI\\Interface\\'] as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isForbiddenProviderType(string $typeName): bool
    {
        if (str_starts_with($typeName, 'App\\AI\\Provider\\')) {
            return true;
        }

        foreach (self::CAPABILITY_INTERFACES as $interface) {
            if ($typeName === $interface || is_subclass_of($typeName, $interface)) {
                return true;
            }
        }

        return is_subclass_of($typeName, ProviderMetadataInterface::class) && !interface_exists($typeName);
    }

    /**
     * @return list<string>
     */
    private function extractNamedTypes(?\ReflectionType $type): array
    {
        if (null === $type) {
            return [];
        }
        if ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $inner) {
                $names = array_merge($names, $this->extractNamedTypes($inner));
            }

            return $names;
        }

        return [];
    }
}
