<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Config\Runtime;

use App\Service\Config\Runtime\RuntimeConfigAssembler;
use App\Service\Config\Runtime\RuntimeConfigContext;
use App\Service\Config\Runtime\RuntimeConfigContributorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RuntimeConfigAssemblerTest extends TestCase
{
    public function testFeatureFlagsMergeAndOtherKeysStayDisjoint(): void
    {
        $assembler = new RuntimeConfigAssembler([
            $this->contributor(['billing' => ['enabled' => false], 'features' => ['help' => true]]),
            $this->contributor(['features' => ['savedTasks' => false], 'speech' => ['whisperEnabled' => false]]),
        ]);

        $payload = $assembler->assemble(Request::create('/api/v1/config/runtime'), null);

        self::assertFalse($payload['billing']['enabled']);
        self::assertTrue($payload['features']['help']);
        self::assertFalse($payload['features']['savedTasks']);
        self::assertFalse($payload['speech']['whisperEnabled']);
    }

    public function testARepeatedTopLevelKeyIsRejected(): void
    {
        $assembler = new RuntimeConfigAssembler([
            $this->contributor(['billing' => ['enabled' => true]]),
            $this->contributor(['billing' => ['enabled' => false]]),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('billing');

        $assembler->assemble(Request::create('/'), null);
    }

    public function testARepeatedFeatureFlagIsRejected(): void
    {
        $assembler = new RuntimeConfigAssembler([
            $this->contributor(['features' => ['help' => true]]),
            $this->contributor(['features' => ['help' => false]]),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('features.help');

        $assembler->assemble(Request::create('/'), null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function contributor(array $payload): RuntimeConfigContributorInterface
    {
        return new class($payload) implements RuntimeConfigContributorInterface {
            /**
             * @param array<string, mixed> $payload
             */
            public function __construct(private array $payload)
            {
            }

            public function contribute(RuntimeConfigContext $context): array
            {
                return $this->payload;
            }
        };
    }
}
