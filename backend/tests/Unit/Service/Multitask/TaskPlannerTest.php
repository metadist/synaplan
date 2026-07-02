<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\Prompt;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskPlanValidator;
use App\Service\Multitask\TaskPlanner;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\PromptService;
use App\Tests\Support\SkillCatalogFactory;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class TaskPlannerTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private PromptRepository&MockObject $promptRepository;
    private ModelConfigService&MockObject $modelConfigService;
    private TaskPlanner $planner;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);

        // Default: planner prompt present, topics available.
        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getPrompt')->willReturn('PLAN. Capabilities: [CAPABILITYLIST] Topics: [DYNAMICLIST] Keys: [KEYLIST]');
        $this->promptRepository->method('findByTopic')->with('tools:plan', 0)->willReturn($prompt);
        $this->promptRepository->method('getAllTopics')->willReturn(['general', 'officemaker']);
        $this->promptRepository->method('getTopicsWithDescriptions')->willReturn([
            ['topic' => 'general', 'description' => 'catch-all'],
        ]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(76);
        $this->modelConfigService->method('getProviderForModel')->willReturn('groq');
        $this->modelConfigService->method('getModelName')->willReturn('gpt-oss-120b');

        $this->planner = new TaskPlanner(
            $this->aiFacade,
            $this->promptRepository,
            $this->modelConfigService,
            new TaskPlanValidator(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(UserRepository::class),
            new TimeContextBuilder(),
            SkillCatalogFactory::real(),
            new PromptService(
                $this->createMock(PromptRepository::class),
                $this->createMock(PromptMetaRepository::class),
                $this->createMock(EntityManagerInterface::class),
                new NullLogger(),
            ),
        );
    }

    private function message(string $text = 'hello', string $lang = 'en'): Message&MockObject
    {
        $m = $this->createMock(Message::class);
        $m->method('getText')->willReturn($text);
        $m->method('getLanguage')->willReturn($lang);
        $m->method('getFileText')->willReturn('');
        $m->method('getFile')->willReturn(0);
        $m->method('getFiles')->willReturn(new ArrayCollection());

        return $m;
    }

    public function testHappyPathBuildsCanonicalChain(): void
    {
        $json = json_encode([
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n4',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'extract_text', 'inputs' => ['files' => '$message.files']],
                ['id' => 'n2', 'capability' => 'summarize', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text']],
                ['id' => 'n3', 'capability' => 'text2sound', 'depends_on' => ['n2'], 'params' => ['format' => 'mp3']],
                ['id' => 'n4', 'capability' => 'compose_reply', 'depends_on' => ['n2', 'n3']],
            ],
        ]);
        $this->aiFacade->method('chat')->willReturn(['content' => $json, 'provider' => 'groq', 'model' => 'gpt-oss-120b']);

        $result = $this->planner->plan($this->message(), [], 1);

        self::assertFalse($result->fallback);
        self::assertCount(4, $result->plan->nodes);
        self::assertSame(Capability::ExtractText, $result->plan->nodeById('n1')?->capability);
        self::assertSame('n4', $result->plan->replyNode);
        self::assertSame(76, $result->modelId);
    }

    public function testMarkdownFencedJsonIsParsed(): void
    {
        $json = "```json\n".json_encode([
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n1',
            'tasks' => [['id' => 'n1', 'capability' => 'chat']],
        ])."\n```";
        $this->aiFacade->method('chat')->willReturn(['content' => $json]);

        $result = $this->planner->plan($this->message(), [], 1);

        self::assertFalse($result->fallback);
        self::assertTrue($result->plan->isSingleNode());
    }

    public function testNonJsonOutputFallsBackToSingleChat(): void
    {
        $this->aiFacade->method('chat')->willReturn(['content' => 'I cannot do that, sorry.']);

        $result = $this->planner->plan($this->message(text: 'hi', lang: 'de'), [], 1);

        self::assertTrue($result->fallback);
        self::assertTrue($result->plan->isSingleNode());
        self::assertSame(Capability::Chat, $result->plan->nodes[0]->capability);
        self::assertSame('de', $result->plan->language);
        self::assertNotEmpty($result->errors);
    }

    public function testSchemaInvalidPlanFallsBack(): void
    {
        $json = json_encode([
            'version' => 1,
            'reply_node' => 'n1',
            'tasks' => [['id' => 'n1', 'capability' => 'launch_missiles']],
        ]);
        $this->aiFacade->method('chat')->willReturn(['content' => $json]);

        $result = $this->planner->plan($this->message(), [], 1);

        self::assertTrue($result->fallback);
        self::assertTrue($result->plan->isSingleNode());
    }

    public function testProviderErrorFallsBack(): void
    {
        $this->aiFacade->method('chat')->willThrowException(new \RuntimeException('provider down'));

        $result = $this->planner->plan($this->message(), [], 1);

        self::assertTrue($result->fallback);
        self::assertTrue($result->plan->isSingleNode());
    }
}
