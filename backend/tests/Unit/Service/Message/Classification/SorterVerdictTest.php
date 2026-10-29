<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Classification;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Agent\AgentPinResolver;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\Classification\AgentPinRoute;
use App\Service\Message\Classification\AttachmentIntent;
use App\Service\Message\Classification\SorterInput;
use App\Service\Message\Classification\SorterVerdict;
use App\Service\Message\MessageSorter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SorterVerdictTest extends TestCase
{
    public function testTheSortersVerdictWithItsVotes(): void
    {
        $route = $this->verdict([
            'topic' => 'general', 'language' => 'de', 'web_search' => true, 'read_pages' => 2, 'multi_step' => false,
            'sorting_model_id' => 7, 'sorting_provider' => 'groq', 'sorting_model_name' => 'sorter', 'sorting_usage' => ['tokens' => 12],
        ])->classify($this->message('Wie wird das Wetter?'), 'Wie wird das Wetter?', [], null, false, false);

        self::assertSame([
            'topic' => 'general', 'language' => 'de', 'web_search' => true, 'read_pages' => 2, 'multi_step' => false,
            'source' => 'ai_sorting', 'skip_sorting' => false, 'intent' => 'chat',
            'model_id' => 7, 'provider' => 'groq', 'model_name' => 'sorter', 'sorting_usage' => ['tokens' => 12],
            'effort' => 'answer', 'effort_why' => 'simple', 'effort_voted' => false,
            'routing_confidence' => 1.0, 'routing_fallback_reason' => null, 'routing_discarded_alternatives' => [],
        ], $route);
    }

    public function testTheEffortRatingIsReadFromTheRawSorterReply(): void
    {
        $raw = '{"BTOPIC":"general","BLANG":"de","BWEBSEARCH":true,"BREADPAGES":3,"BMULTI":false,"BEFFORT":"research","BEFFORT_WHY":"procedure"}';
        $route = $this->verdict(['topic' => 'general', 'language' => 'de', 'web_search' => true, 'raw_response' => $raw])
            ->classify($this->message('Welche Behörden brauche ich?'), 'Welche Behörden brauche ich?', [], null, false, false);

        self::assertSame(['research', 'procedure', true], [$route['effort'], $route['effort_why'], $route['effort_voted']]);
    }

    public function testAnUnparsableReplyIsNoEffortVote(): void
    {
        $route = $this->verdict(['topic' => 'general', 'language' => 'en', 'raw_response' => 'not json at all'])
            ->classify($this->message('hi'), 'hi', [], null, false, false);

        self::assertSame(['answer', 'simple', false], [$route['effort'], $route['effort_why'], $route['effort_voted']]);
    }

    public function testThePassThroughsAndTheForcedSearchLandOnTheVerdict(): void
    {
        $route = $this->verdict(['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'video', 'duration' => 8, 'resolution' => '720p', 'input_mode' => 'reference_images'])
            ->classify($this->message('a clip of the sea'), 'a clip of the sea', [], 42, true, false);

        self::assertSame(
            [42, 'video', 8, '720p', 'reference_images', true],
            [$route['override_model_id'], $route['media_type'], $route['duration'], $route['resolution'], $route['input_mode'], $route['force_web_search']],
        );
    }

    public function testAudioAboutAnAttachedPictureIsDescribedThroughThePlanner(): void
    {
        $route = $this->verdict(['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'audio'])
            ->classify($this->message('beschreibe in einem audio was hier zu sehen ist', ['photo.png']), 'beschreibe in einem audio was hier zu sehen ist', [], null, false, true);

        self::assertSame(['general', 'chat', true], [$route['topic'], $route['intent'], $route['multi_step']]);
        self::assertArrayNotHasKey('media_type', $route);
    }

    public function testCodeOnFilesAndASpokenAnswerGoThroughThePlanner(): void
    {
        $code = $this->verdict(['topic' => 'general', 'language' => 'en', 'multi_step' => false])
            ->classify($this->message('run a python script on this csv', ['data.csv']), 'run a python script on this csv', [], null, false, false);
        $spoken = $this->verdict(['topic' => 'general', 'language' => 'en', 'multi_step' => false])
            ->classify($this->message('read the poem aloud'), 'read the poem aloud', [], null, false, true);
        $speechModel = $this->verdict(['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'audio'])
            ->classify($this->message('read the poem aloud'), 'read the poem aloud', [], null, false, true);

        self::assertSame([true, true, null], [$code['multi_step'], $spoken['multi_step'], $speechModel['multi_step']]);
    }

    /**
     * @param array<string, mixed> $sorterResult
     */
    private function verdict(array $sorterResult): SorterVerdict
    {
        $sorter = $this->createStub(MessageSorter::class);
        $sorter->method('classify')->willReturn($sorterResult);
        $sorterInput = new SorterInput();
        $logger = new NullLogger();

        return new SorterVerdict(
            $sorter,
            $sorterInput,
            new TopicIntentRule(new SystemCapabilityRegistry()),
            new AgentPinRoute($this->createStub(AgentPinResolver::class), $sorter, $sorterInput, $logger),
            new AttachmentIntent($this->createStub(EntityManagerInterface::class), $logger),
            $logger,
        );
    }

    /**
     * @param list<string> $files
     */
    private function message(string $text, array $files = []): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getText')->willReturn($text);
        $message->method('getUserId')->willReturn(10);
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection(array_map(function (string $name): File {
            $file = $this->createStub(File::class);
            $file->method('getFileName')->willReturn($name);
            $file->method('getFileType')->willReturn(pathinfo($name, \PATHINFO_EXTENSION));

            return $file;
        }, $files)));

        return $message;
    }
}
