<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Classification;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\Message\Classification\FastPathClassifier;
use App\Service\Message\Classification\SelfAwareQuestion;
use App\Service\SelfAware\SelfAwareConfig;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FastPathClassifierTest extends TestCase
{
    public function testSwitchedOffEveryMessageGoesToTheSorter(): void
    {
        self::assertNull($this->fastPath(global: null)->classify($this->message('Hello, how are you today?'), 'Hello, how are you today?', [], null, false));
    }

    public function testSwitchedOnAPlainChatSkipsTheSorterInItsLanguage(): void
    {
        $route = $this->fastPath()->classify($this->message('Hello, how are you today?'), 'Hello, how are you today?', [], null, true);

        self::assertNotNull($route);
        self::assertSame(['general', 'chat', 'en', 'fast_path_heuristic', true, true], [$route['topic'], $route['intent'], $route['language'], $route['source'], $route['skip_sorting'], $route['force_web_search'] ?? null]);
        self::assertArrayHasKey('web_search', $route);
        self::assertNull($route['web_search'], 'No sorter, no web search vote.');
    }

    public function testTheAccountsSettingWinsOverTheGlobalOne(): void
    {
        self::assertNull($this->fastPath(global: '1', account: '0')->classify($this->message('Hello, how are you today?'), 'Hello, how are you today?', [], null, false));
        self::assertNotNull($this->fastPath(global: '0', account: '1')->classify($this->message('Hello, how are you today?'), 'Hello, how are you today?', [], null, false));
    }

    #[DataProvider('ambiguousTurns')]
    public function testAnythingAmbiguousGoesToTheSorter(string $text, string $why): void
    {
        self::assertNull($this->fastPath(selfAware: true)->classify($this->message($text), $text, [], null, false), $why);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ambiguousTurns(): iterable
    {
        yield 'a command' => ['/pic a cat', 'a tool prefix'];
        yield 'a long message' => [str_repeat('please tell me more ', 20), 'over 280 characters'];
        yield 'an office format' => ['mach eine excel tabelle', 'the sorter may pick officemaker'];
        yield 'a PDF while the office engine runs' => ['write it as pdf', 'PDF only with the office engine'];
        yield 'media words' => ['hätte ich gerne das bild einer katze', 'a declarative media request'];
        yield 'audio words' => ['read it aloud please', 'speech needs the planner'];
        yield 'a data source' => ['what is in my documents about leave', 'the knowledge base'];
        yield 'a question about the product' => ['what can you do?', 'only the sorter picks the synaplan topic'];
    }

    public function testAnAttachmentAPickedModelOrAnUnknownLanguageGoToTheSorter(): void
    {
        self::assertNull($this->fastPath()->classify($this->message('look at this', ['photo.png']), 'look at this', [], null, false));
        self::assertNull($this->fastPath()->classify($this->message('Hello, how are you today?'), 'Hello, how are you today?', [], 42, false));
        self::assertNull($this->fastPath()->classify($this->message('ok', language: 'NN'), 'ok', [], null, false));
    }

    public function testAFollowUpOnGeneratedWorkGoesToTheSorter(): void
    {
        $file = $this->turn('__FILE_GENERATED__:report.docx');
        $picture = $this->turn('__IMAGE_GENERATED__', '7/2026/10/1_openai.png');

        self::assertNull($this->fastPath()->classify($this->message('mach den Titel fett'), 'mach den Titel fett', [$file], null, false));
        self::assertNull($this->fastPath()->classify($this->message('mach es blau'), 'mach es blau', [$picture], null, false));
        self::assertNotNull($this->fastPath()->classify($this->message('danke dir'), 'danke dir', [$picture, $this->turn('Gern!')], null, false));
    }

    private function fastPath(?string $global = '1', ?string $account = null, bool $selfAware = false): FastPathClassifier
    {
        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturnCallback(static fn (int $owner, string $group, string $setting): ?string => match (true) {
            'CLASSIFIER' === $group && 0 === $owner => $global,
            'CLASSIFIER' === $group => $account,
            default => null,
        });
        $converter = $this->createStub(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(true);

        return new FastPathClassifier($config, new SelfAwareQuestion($selfAware ? new SelfAwareConfig($config) : null), new NullLogger(), $converter);
    }

    /**
     * @param list<string> $files
     */
    private function message(string $text, array $files = [], string $language = 'en'): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getText')->willReturn($text);
        $message->method('getUserId')->willReturn(10);
        $message->method('getLanguage')->willReturn($language);
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new ArrayCollection(array_map(fn (string $name): File => $this->createStub(File::class), $files)));

        return $message;
    }

    private function turn(string $text, string $filePath = ''): Message
    {
        $turn = $this->createStub(Message::class);
        $turn->method('getDirection')->willReturn('OUT');
        $turn->method('getText')->willReturn($text);
        $turn->method('getFilePath')->willReturn($filePath);

        return $turn;
    }
}
