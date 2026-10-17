<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Classification;

use App\Entity\Agent;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentPinResolver;
use App\Service\Agent\AgentRuntimeResolver;
use App\Service\Agent\AgentService;
use App\Service\Message\Classification\AgentPinRoute;
use App\Service\Message\Classification\SorterInput;
use App\Service\Message\MessageSorter;
use App\Service\Runtime\RuntimeProfile;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AgentPinRouteTest extends TestCase
{
    private int $sorterCalls = 0;

    public function testWithoutAPinTheCascadeGoesOn(): void
    {
        self::assertNull($this->route(null)->pin($this->message('Hello'), [], []));
    }

    public function testAPinnedAssistantAnswersWithItsOwnTopicAndModel(): void
    {
        $pinned = $this->route($this->profile(internet: false))->pin($this->message('Where is my order?'), ['agentId' => 3], []);

        self::assertSame(['support-desk', 'agent', 3, 4, 7, 11, null], [$pinned['topic'] ?? null, $pinned['source'] ?? null, $pinned['agent_id'] ?? null, $pinned['agent_version_id'] ?? null, $pinned['prompt_id'] ?? null, $pinned['model_id'] ?? null, $pinned['web_search'] ?? null]);
        self::assertSame(0, $this->sorterCalls);
    }

    public function testTheSorterIsAskedOnlyWhetherAnAssistantWithInternetNeedsTheWeb(): void
    {
        $pinned = $this->route($this->profile(internet: true), ['web_search' => true, 'read_pages' => 2])->pin($this->message('What does the new tariff cost?'), [], []);

        self::assertSame([true, 2, 1], [$pinned['web_search'] ?? null, $pinned['read_pages'] ?? null, $this->sorterCalls]);
    }

    public function testAForcedSearchOrSmallTalkAsksTheSorterNothing(): void
    {
        $route = $this->route($this->profile(internet: true), ['web_search' => true]);

        self::assertNull($route->pin($this->message('What does the new tariff cost?'), [], [], searchAlreadyForced: true)['web_search'] ?? null);
        self::assertNull($route->pin($this->message('thanks!'), [], [])['web_search'] ?? null);
        self::assertSame(0, $this->sorterCalls);
    }

    public function testAPublishedAssistantTheSorterPickedGetsItsProfile(): void
    {
        $classification = $this->route(null, routable: true)->attachRoutableAgent(['topic' => Agent::TOPIC_PREFIX.'helpdesk', 'model_id' => 5], $this->message('Help'));

        self::assertSame([3, 4, 7, 11], [$classification['agent_id'] ?? null, $classification['agent_version_id'] ?? null, $classification['prompt_id'] ?? null, $classification['model_id'] ?? null]);
        self::assertSame(['topic' => 'general'], $this->route(null)->attachRoutableAgent(['topic' => 'general'], $this->message('Help')));
    }

    /**
     * @param array<string, mixed> $sorterAnswer
     */
    private function route(?RuntimeProfile $profile, array $sorterAnswer = [], bool $routable = false): AgentPinRoute
    {
        $resolver = $this->createStub(AgentPinResolver::class);
        $resolver->method('resolve')->willReturn($profile);
        $sorter = $this->createStub(MessageSorter::class);
        $sorter->method('classify')->willReturnCallback(function () use ($sorterAnswer): array {
            ++$this->sorterCalls;

            return $sorterAnswer;
        });

        if (!$routable) {
            return new AgentPinRoute($resolver, $sorter, new SorterInput(), new NullLogger());
        }

        $config = $this->createStub(AgentConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isRoutableEnabled')->willReturn(true);
        $agent = $this->createStub(Agent::class);
        $agent->method('getSlug')->willReturn('helpdesk');
        $agent->method('getId')->willReturn(3);
        $agents = $this->createStub(AgentService::class);
        $agents->method('routableForUser')->willReturn([$agent]);
        $runtime = $this->createStub(AgentRuntimeResolver::class);
        $runtime->method('resolve')->willReturn($this->profile(internet: false));
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($this->createStub(User::class));

        return new AgentPinRoute($resolver, $sorter, new SorterInput(), new NullLogger(), $config, $agents, $runtime, $users);
    }

    private function profile(bool $internet): RuntimeProfile
    {
        return new RuntimeProfile(7, 'support-desk', null, ['chat' => 11], [], ['tool_internet' => $internet], null, null, [], 3, 4);
    }

    private function message(string $text): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('en');
        $message->method('getUserId')->willReturn(10);
        $message->method('getChatId')->willReturn(0);

        return $message;
    }
}
