<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Entity\Agent;
use App\Entity\AgentVersion;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\AgentVersionRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\AgentPublisher;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\Agent\Exception\AgentNothingChangedException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AgentPublisherTest extends TestCase
{
    private AgentVersionRepository&MockObject $versions;
    private AgentRepository&MockObject $agents;
    private PromptRepository&MockObject $prompts;
    private AgentPublisher $publisher;

    protected function setUp(): void
    {
        $this->versions = $this->createMock(AgentVersionRepository::class);
        $this->agents = $this->createMock(AgentRepository::class);
        $this->prompts = $this->createMock(PromptRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(
            static function (callable $callback): mixed {
                return $callback();
            }
        );
        $this->publisher = new AgentPublisher(
            new AgentDefinitionValidator(),
            $this->versions,
            $this->agents,
            $this->prompts,
            $em,
        );
    }

    public function testPublishIncrementsVersion(): void
    {
        $agent = $this->agent();
        $this->versions->method('findLatest')->willReturn(null);
        $this->versions->method('nextVersionNumber')->willReturn(1);
        $this->versions->expects(self::once())->method('save')->willReturnCallback(
            static function (AgentVersion $version): void {
                (new \ReflectionProperty(AgentVersion::class, 'id'))->setValue($version, 33);
            }
        );
        $this->agents->expects(self::once())->method('save');
        $this->prompts->method('find')->willReturn($this->prompt());

        $version = $this->publisher->publish($agent, $this->user(4), 'First cut');

        self::assertSame(1, $version->getVersion());
        self::assertSame(Agent::STATUS_PUBLISHED, $agent->getStatus());
        self::assertSame(33, $agent->getPublishedVersionId());
    }

    public function testIdenticalPublishIs409(): void
    {
        $payload = AgentDefinition::defaults()->toArray();
        $agent = $this->agent($payload);
        $latest = new AgentVersion(7, 1, $payload, 'Review NDAs.', 4, 'v1');
        $this->versions->method('findLatest')->willReturn($latest);
        $this->prompts->method('find')->willReturn($this->prompt());
        $this->versions->expects(self::never())->method('save');

        $this->expectException(AgentNothingChangedException::class);
        $this->publisher->publish($agent, $this->user(4), 'again');
    }

    /**
     * @param array<string, mixed>|null $draft
     */
    private function agent(?array $draft = null): Agent
    {
        $agent = new Agent(4, 20, 'contract-review', 'Contract review', $draft ?? AgentDefinition::defaults()->toArray());
        (new \ReflectionProperty(Agent::class, 'id'))->setValue($agent, 7);

        return $agent;
    }

    private function prompt(): Prompt
    {
        $prompt = new Prompt();
        $prompt->setOwnerId(4);
        $prompt->setTopic('agent:contract-review');
        $prompt->setPrompt('Review NDAs.');

        return $prompt;
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }
}
