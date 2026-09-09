<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Entity\Agent;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\AgentVersionRepository;
use App\Repository\MessageMetaRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\AgentAccess;
use App\Service\Agent\AgentRuntimeResolver;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\Agent\Policy\LegacyFlagToolPolicy;
use App\Service\ModelConfigService;
use App\Service\RAG\RagScopeResolver;
use PHPUnit\Framework\TestCase;

/**
 * AB26 / C6: an assistant's declared knowledge folders are searched only while
 * its owner may still use them, and the reader's own files stay out unless the
 * assistant opts in.
 */
final class AgentRuntimeResolverScopesTest extends TestCase
{
    private const OWNER = 7;

    public function testForeignFolderTheOwnerCanNoLongerUseIsDropped(): void
    {
        $rag = $this->createMock(RagScopeResolver::class);
        // Owner may still use 4:legal, but lost 9:secret.
        $rag->method('canUse')->willReturnCallback(
            static fn (int $userId, int $ownerId, string $groupKey): bool => self::OWNER === $userId && 4 === $ownerId && 'legal' === $groupKey
        );

        $profile = $this->resolve($rag, [
            'knowledge' => ['ownFolder' => true, 'folders' => ['4:legal', '9:secret']],
        ]);

        $scopeKeys = array_map(static fn (array $s): string => $s['ownerId'].':'.$s['groupKey'], $profile->ragScopes);
        self::assertContains('4:legal', $scopeKeys);
        self::assertContains(self::OWNER.':TASKPROMPT:agent:contract-review', $scopeKeys, 'own folder is always kept');
        self::assertNotContains('9:secret', $scopeKeys, 'a folder the owner lost access to is dropped');
        self::assertContains('scope_dropped:9:secret', $profile->notes);
        self::assertFalse($profile->includeUserFiles);
    }

    public function testIncludeUserFilesFlagIsCarried(): void
    {
        $rag = $this->createMock(RagScopeResolver::class);
        $rag->method('canUse')->willReturn(true);

        $profile = $this->resolve($rag, [
            'knowledge' => ['ownFolder' => true, 'includeUserFiles' => true],
        ]);

        self::assertTrue($profile->includeUserFiles);
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function resolve(RagScopeResolver $rag, array $draft): \App\Service\Runtime\RuntimeProfile
    {
        $agent = new Agent(self::OWNER, 55, 'contract-review', 'Contract review', $draft);

        $agents = $this->createMock(AgentRepository::class);
        $agents->method('find')->willReturn($agent);

        $prompts = $this->createMock(PromptRepository::class);
        $prompt = new Prompt();
        $prompt->setTopic('agent:contract-review');
        $prompt->setPrompt('Instructions');
        $prompts->method('find')->willReturn($prompt);

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn(1);

        $resolver = new AgentRuntimeResolver(
            $agents,
            $this->createMock(AgentVersionRepository::class),
            $prompts,
            $this->createMock(ModelRepository::class),
            $modelConfig,
            new AgentDefinitionValidator(),
            $this->createMock(AgentAccess::class),
            $this->createMock(MessageMetaRepository::class),
            $rag,
            new LegacyFlagToolPolicy(),
        );

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(self::OWNER);

        // Owner + draft path avoids the access/publish gates.
        return $resolver->resolve(1, $user, true);
    }
}
