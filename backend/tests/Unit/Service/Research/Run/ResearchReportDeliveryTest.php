<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\MessageMetaRepository;
use App\Repository\SearchResultRepository;
use App\Repository\UserRepository;
use App\Service\File\FileStorageService;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Research\Run\Ledger\EvidenceCard;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchOutcomeText;
use App\Service\Research\Run\ResearchReplyText;
use App\Service\Research\Run\ResearchReportDelivery;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\Stage\ResearchCallUsage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ResearchReportDeliveryTest extends ResearchUnitCase
{
    /** @var list<Message> */
    private array $posted = [];

    private int $registered = 0;

    private ?string $storedFile = null;

    /** @var list<array{title: string, url: string, host: string, description: string}> */
    private array $savedSources = [];

    public function testAFinishedRunPostsTheReportWithTheFileAttachedOnce(): void
    {
        $state = $this->finished(ResearchStatus::Complete, ResearchOutcome::COMPLETE, '# Prices'."\n\nThe price is 12.", 11);
        $delivery = $this->delivery();

        $delivery->deliver($state);
        $delivery->deliver($state);

        self::assertCount(1, $this->posted);
        self::assertSame("# Prices\n\nThe price is 12.", $this->posted[0]->getText());
        self::assertSame('de', $this->posted[0]->getLanguage());
        self::assertSame('OUT', $this->posted[0]->getDirection());
        self::assertCount(1, $this->posted[0]->getFiles());
        self::assertSame(1, $this->registered);
    }

    public function testAStoppedRunSaysSoAboveWhatWasFound(): void
    {
        $state = $this->finished(ResearchStatus::Stopped, ResearchOutcome::STOPPED_BY_USER, 'Partial findings.', 11);

        $this->delivery()->deliver($state);

        self::assertCount(1, $this->posted);
        self::assertSame("> de:research.outcome.stopped_by_user\n\nPartial findings.", $this->posted[0]->getText());
    }

    public function testARunWithoutAReportPostsOnlyTheReasonAndNoFile(): void
    {
        $state = $this->finished(ResearchStatus::Failed, ResearchOutcome::FAILED_INTERRUPTED, null, 11);

        $this->delivery()->deliver($state);

        self::assertCount(1, $this->posted);
        self::assertSame('de:research.outcome.failed_interrupted', $this->posted[0]->getText());
        self::assertCount(0, $this->posted[0]->getFiles());
        self::assertSame(0, $this->registered);
    }

    public function testTheReportReplacesTheReplyThatAnnouncedTheRun(): void
    {
        $state = $this->finished(ResearchStatus::Complete, ResearchOutcome::COMPLETE, '# Prices'."\n\nThe price is 12.", 11);
        $reply = (new Message())->setText('Looking into this in several sources.')->setStatus('complete');
        $reply->setMeta('research_run_id', $state->ref->id);

        $this->delivery($reply)->deliver($state);

        self::assertCount(1, $this->posted);
        self::assertSame($reply, $this->posted[0]);
        self::assertSame("# Prices\n\nThe price is 12.", $reply->getText());
        self::assertSame($state->ref->id, $reply->getMeta('research_run_id'));
        self::assertCount(1, $reply->getFiles());
    }

    public function testANewReplyCarriesTheRunIdSoTheStepsCanBeOpenedLater(): void
    {
        $state = $this->finished(ResearchStatus::Complete, ResearchOutcome::COMPLETE, 'Report.', 11);

        $this->delivery()->deliver($state);

        self::assertSame($state->ref->id, $this->posted[0]->getMeta('research_run_id'));
    }

    public function testThePersonReadsTheAnswerWithNumberedSourcesAndTheFileListsThem(): void
    {
        $state = $this->finished(ResearchStatus::Complete, ResearchOutcome::COMPLETE, null, 11);
        $state->ledger->addPoint('Costs');
        $source = $state->ledger->addSource(Source::web('https://pt.example/visa', 'Portugal'));
        $state->ledger->addEvidence(new EvidenceCard('', $source, 'p1', 'Fee 90 EUR.', ['§1'], []));
        $state->ledger->setReport("The fee is 90 EUR. [e1]\n\n## Nicht bestätigt\n\n- The tax rate.\n\n1 von 2 Aussagen sind durch eine Quelle belegt.");

        $this->delivery()->deliver($state);

        self::assertSame('The fee is 90 EUR. [1]', $this->posted[0]->getText());
        self::assertSame('1', $this->posted[0]->getMeta('web_search_results_count'));
        self::assertSame(['Portugal'], array_column($this->savedSources, 'title'));
        self::assertSame("The fee is 90 EUR. [1]\n\n## de:research.report.sources\n\n1. Portugal — https://pt.example/visa", $this->storedFile);
    }

    public function testTheReplyShowsTheRunsTokensAndCostNextToTheSorting(): void
    {
        $state = $this->finished(ResearchStatus::Complete, ResearchOutcome::COMPLETE, 'Report.', 11);
        $state->spend->add('reading', 'groq:llama', new ResearchCallUsage(900, 100, 1000, '0.010000'));
        $state->spend->add('writing', 'anthropic:claude', new ResearchCallUsage(2000, 500, 2500, '0.050000'));
        $reply = (new Message())->setText('Looking into this.')->setStatus('complete');
        $reply->setMeta('research_run_id', $state->ref->id);
        $reply->setMeta('ai_usage_extra', (string) json_encode([
            ['promptTokens' => 10, 'completionTokens' => 5, 'totalTokens' => 15, 'cost' => '0.000100', 'modelKey' => 'anthropic:sonnet', 'kind' => 'SORT', 'priceKnown' => true],
        ]));

        $delivery = $this->delivery($reply);
        $delivery->deliver($state);
        $delivery->deliver(ResearchRunState::fromStorage($state->toStorage()));

        $rows = json_decode((string) $reply->getMeta('ai_usage_extra'), true);
        self::assertIsArray($rows);
        self::assertSame(['SORT', 'READ', 'WRITE'], array_column($rows, 'kind'));
        self::assertSame([15, 1000, 2500], array_column($rows, 'totalTokens'));
        self::assertSame(['0.000100', '0.010000', '0.050000'], array_column($rows, 'cost'));
    }

    public function testNothingIsPostedForAnApiRunOrSomeoneElsesChat(): void
    {
        $this->delivery()->deliver($this->finished(ResearchStatus::Complete, ResearchOutcome::COMPLETE, 'Report.', null));
        $this->delivery()->deliver($this->finished(ResearchStatus::Complete, ResearchOutcome::COMPLETE, 'Report.', 99));

        self::assertSame([], $this->posted);
    }

    private function finished(ResearchStatus $status, string $code, ?string $report, ?int $chatId): ResearchRunState
    {
        $created = $this->clock->now();
        $budget = ResearchBudget::for(ResearchDepth::Quick, ResearchBudgetConfig::defaults(ResearchDepth::Quick), $created);
        $budget->at($created);
        $state = ResearchRunState::open(ResearchRunRef::start(7, ResearchDepth::Quick, 'What does one unit cost?', $chatId, $created), $budget);
        if (null !== $report) {
            $state->ledger->setReport($report);
        }
        $state->finish($status, new ResearchOutcome($code));
        $this->store->save($state);

        return $state;
    }

    private function delivery(?Message $announcing = null): ResearchReportDelivery
    {
        $metas = $this->createStub(MessageMetaRepository::class);
        $metas->method('findMessageInChatByMeta')->willReturn($announcing);
        $mine = $this->createStub(Chat::class);
        $mine->method('getUserId')->willReturn(7);
        $theirs = $this->createStub(Chat::class);
        $theirs->method('getUserId')->willReturn(8);
        $chats = $this->createStub(ChatRepository::class);
        $chats->method('find')->willReturnCallback(static fn (int $id): Chat => 11 === $id ? $mine : $theirs);

        $user = $this->createStub(User::class);
        $user->method('getLocale')->willReturn('de');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Message) {
                $this->posted[] = $entity;
            }
        });

        $storage = $this->createStub(FileStorageService::class);
        $storage->method('storeRawContent')->willReturnCallback(function (string $content): array {
            $this->storedFile = $content;

            return ['success' => true, 'path' => '7/2026/10/research.md', 'size' => 12, 'mime' => 'text/markdown', 'error' => null];
        });
        $sources = $this->createStub(SearchResultRepository::class);
        $sources->method('saveForReply')->willReturnCallback(function (Message $reply, array $sources): bool {
            $this->savedSources = $sources;

            return true;
        });
        $files = $this->createStub(GeneratedFileRegistrar::class);
        $files->method('register')->willReturnCallback(function (): File {
            ++$this->registered;

            return new File();
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string => $locale.':'.$id,
        );

        return new ResearchReportDelivery(
            $chats,
            $users,
            $em,
            $storage,
            $files,
            $this->store,
            new ResearchReplyText(new ResearchOutcomeText($translator), $translator),
            new NullLogger(),
            $metas,
            $sources,
        );
    }
}
