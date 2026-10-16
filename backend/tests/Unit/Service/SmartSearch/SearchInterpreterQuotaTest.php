<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SmartSearch;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Service\RateLimitService;
use App\Service\SmartSearch\Interpret\InterpretCandidate;
use App\Service\SmartSearch\Interpret\SearchInterpreter;
use App\Service\SmartSearch\SearchModelConfigService;
use App\Service\SmartSearch\SmartSearchConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SearchInterpreterQuotaTest extends TestCase
{
    public function testASpentMessageAllowanceStopsBeforeTheModelCall(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects(self::never())->method('chat');
        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->with(self::isInstanceOf(User::class), 'MESSAGES')->willReturn(['allowed' => false]);
        $rateLimits->expects(self::never())->method('recordUsage');

        $result = $this->interpreter($aiFacade, $rateLimits)->interpret(new User(), 'turn on groups', $this->candidates(), 'en');

        self::assertSame('limit_reached', $result->outcome);
    }

    public function testAnAnsweredQuestionIsBookedAsOneMessage(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('chat')->willReturn(['content' => '{"intent":"navigate","targetIds":["page:/files"],"answer":"Open Files."}']);
        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => true]);
        $rateLimits->expects(self::once())->method('recordUsage')->with(self::isInstanceOf(User::class), 'MESSAGES', self::anything());

        $result = $this->interpreter($aiFacade, $rateLimits)->interpret(new User(), 'where are my files', $this->candidates(), 'en');

        self::assertSame('ok', $result->outcome);
        self::assertSame(['page:/files'], $result->targetIds);
    }

    /**
     * @return list<InterpretCandidate>
     */
    private function candidates(): array
    {
        return [new InterpretCandidate('page:/files', 'page', 'Files')];
    }

    private function interpreter(AiFacade $aiFacade, RateLimitService $rateLimits): SearchInterpreter
    {
        return new SearchInterpreter(
            $this->createMock(SmartSearchConfig::class),
            $this->createMock(SearchModelConfigService::class),
            $aiFacade,
            $this->createMock(PromptRepository::class),
            $this->createMock(StructuredOutputConfig::class),
            $rateLimits,
            new NullLogger(),
        );
    }
}
