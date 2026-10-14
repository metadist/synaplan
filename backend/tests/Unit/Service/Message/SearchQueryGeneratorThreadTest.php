<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\Prompt;
use App\Repository\PromptRepository;
use App\Service\Message\SearchQueryGenerator;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SearchQueryGeneratorThreadTest extends TestCase
{
    private AiFacade&MockObject $ai;

    private SearchQueryGenerator $generator;

    protected function setUp(): void
    {
        $prompt = new Prompt();
        $prompt->setPrompt('return a query');

        $prompts = $this->createStub(PromptRepository::class);
        $prompts->method('findByTopic')->willReturn($prompt);

        $models = $this->createStub(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturn(1);
        $models->method('getProviderForModel')->willReturn('groq');
        $models->method('getModelName')->willReturn('test-model');

        $this->ai = $this->createMock(AiFacade::class);

        $this->generator = new SearchQueryGenerator(
            $this->ai,
            $prompts,
            $models,
            $this->createStub(RateLimitService::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LoggerInterface::class),
        );
    }

    public function testAFollowUpThatAsksToFetchTheTextIsResolvedAgainstThePreviousSubject(): void
    {
        $question = 'Ich erwarte, dass du dir den Text selber suchst';
        $thread = "User: Fasse mir folgendes Paper zusammen: The Narrative AI Advantage? A Field Experiment on AI-Augmented Evaluations\nAssistant: I would need the full text for the results.";

        $this->ai->expects($this->once())
            ->method('chat')
            ->with($this->callback(function (array $messages) use ($question): bool {
                $user = $messages[1]['content'] ?? '';

                return str_contains($user, 'The Narrative AI Advantage')
                    && str_contains($user, $question)
                    && str_contains($user, 'never for the instruction itself');
            }))
            ->willReturn(['content' => 'The Narrative AI Advantage field experiment full text', 'provider' => 'groq', 'model' => 'test-model']);

        $query = $this->generator->generate($question, null, null, $thread);

        $this->assertSame('The Narrative AI Advantage field experiment full text', $query);
    }

    public function testTheSameInstructionIsSearchedLiterallyWhenNothingCameBeforeIt(): void
    {
        $question = 'Ich erwarte, dass du dir den Text selber suchst';

        $this->ai->expects($this->never())->method('chat');

        $this->assertSame($question, $this->generator->generate($question));
    }

    public function testASelfContainedQuestionIgnoresTheThread(): void
    {
        $this->ai->expects($this->never())->method('chat');

        $query = $this->generator->generate('kebab price munich', null, null, "User: Summarize a paper about innovation\nAssistant: I need the full text.");

        $this->assertSame('kebab price munich', $query);
    }

    public function testResolvesAgainstThreadMatchesTheReportedFollowUpOnly(): void
    {
        $this->ai->expects($this->never())->method('chat');

        $this->assertTrue(SearchQueryGenerator::resolvesAgainstThread('Ich erwarte, dass du dir den Text selber suchst'));
        $this->assertTrue(SearchQueryGenerator::resolvesAgainstThread('look up the paper yourself'));
        $this->assertFalse(SearchQueryGenerator::resolvesAgainstThread('kebab price munich'));
        $this->assertFalse(SearchQueryGenerator::resolvesAgainstThread('The Narrative AI Advantage field experiment results'));
    }
}
