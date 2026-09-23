<?php

declare(strict_types=1);

namespace App\Tests\Integration\AI\Import;

use App\AI\Import\ModelImportApplier;
use App\AI\Import\UnknownImportSourceException;
use App\Entity\Model;
use App\Repository\ModelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * C6: the endpoint importer is idempotent and never overrides operator toggles.
 */
final class ModelImportApplierTest extends KernelTestCase
{
    private const PROVIDER_ID = 'itest-import/Qwen3-Test-32B';

    private EntityManagerInterface $em;
    private ModelRepository $models;
    private ModelImportApplier $applier;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine')->getManager();
        $this->models = $container->get(ModelRepository::class);
        $this->applier = $container->get(ModelImportApplier::class);
        $this->deleteTestRows();
    }

    protected function tearDown(): void
    {
        $this->deleteTestRows();
        parent::tearDown();
    }

    public function testApplyCreatesThenIsIdempotentAndPreservesToggles(): void
    {
        $source = 'openai_compatible:itest-endpoint';
        $rows = [['providerId' => self::PROVIDER_ID, 'name' => 'Qwen3 Test', 'tags' => ['chat', 'pic2text']]];

        $first = $this->applier->apply($source, $rows);
        self::assertSame(2, $first['created']);
        self::assertSame(0, $first['skipped']);

        $chat = $this->models->findOneBy(['service' => 'OpenAICompatible', 'tag' => 'chat', 'providerId' => self::PROVIDER_ID]);
        self::assertInstanceOf(Model::class, $chat);
        self::assertSame(1, $chat->getSelectable());
        self::assertSame(0, $chat->getIsDefault());
        self::assertSame('itest-endpoint', $chat->getJson()['endpoint'] ?? null);
        // #2110: imported rows are free by nature and must stay visible in the
        // model pickers despite their zero price.
        self::assertSame(1, $chat->getShowWhenFree());
        self::assertFalse($chat->isHiddenBecauseFree());
        $firstSeen = $chat->getJson()['meta']['import']['lastSeenAt'] ?? 0;
        self::assertGreaterThan(0, $firstSeen);

        // An operator makes it a non-selectable default and hides it when free.
        $chat->setSelectable(0)->setIsDefault(1)->setShowWhenFree(0);
        $this->em->flush();
        $this->em->clear();

        // Re-import a second later: nothing new, toggles survive, only lastSeenAt moves.
        sleep(1);
        $second = $this->applier->apply($source, $rows);
        self::assertSame(0, $second['created']);
        self::assertSame(2, $second['skipped']);

        $reloaded = $this->models->findOneBy(['service' => 'OpenAICompatible', 'tag' => 'chat', 'providerId' => self::PROVIDER_ID]);
        self::assertInstanceOf(Model::class, $reloaded);
        self::assertSame(0, $reloaded->getSelectable(), 'operator BSELECTABLE survives re-import');
        self::assertSame(1, $reloaded->getIsDefault(), 'operator BISDEFAULT survives re-import');
        self::assertSame(0, $reloaded->getShowWhenFree(), 'operator BSHOWWHENFREE survives re-import');
        self::assertGreaterThan($firstSeen, $reloaded->getJson()['meta']['import']['lastSeenAt'] ?? 0);
    }

    public function testImportedChatRowIsVisibleDespiteZeroPrice(): void
    {
        // #2110: a self-imported Ollama chat model carries no per-token price
        // and must still reach the chat dropdown and the Chat-Default picker.
        $result = $this->applier->apply('ollama', [
            ['providerId' => self::PROVIDER_ID, 'name' => 'Qwen3 Test', 'tags' => ['chat']],
        ]);

        self::assertSame(1, $result['created']);

        $chat = $this->models->findOneBy(['service' => 'ollama', 'tag' => 'chat', 'providerId' => self::PROVIDER_ID]);
        self::assertInstanceOf(Model::class, $chat);
        self::assertSame(0.0, $chat->getPriceIn());
        self::assertSame(0.0, $chat->getPriceOut());
        self::assertSame(1, $chat->getShowWhenFree());
        self::assertFalse($chat->isHiddenBecauseFree());
    }

    public function testUnknownTagsAreIgnored(): void
    {
        $result = $this->applier->apply('ollama', [
            ['providerId' => self::PROVIDER_ID, 'tags' => ['chat', 'not-a-real-tag', 'CHAT']],
        ]);

        self::assertSame(1, $result['created'], 'only the one valid, de-duplicated tag is created');
    }

    public function testCapabilityAliasTagsAreNotWritten(): void
    {
        // pic2pic / img2vid are features on a text2pic / text2vid row, not tags
        // capability→tag resolution looks up — importing them must be a no-op.
        $result = $this->applier->apply('ollama', [
            ['providerId' => self::PROVIDER_ID, 'tags' => ['pic2pic', 'img2vid']],
        ]);

        self::assertSame(0, $result['created']);
    }

    public function testEmptyOpenAiEndpointNameIsRejected(): void
    {
        $this->expectException(UnknownImportSourceException::class);
        $this->applier->apply('openai_compatible:', [
            ['providerId' => self::PROVIDER_ID, 'tags' => ['chat']],
        ]);
    }

    private function deleteTestRows(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Model m WHERE m.providerId = :pid')
            ->setParameter('pid', self::PROVIDER_ID)
            ->execute();
        $this->em->clear();
    }
}
