<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Model;

use App\Entity\Model;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\Embedding\EmbeddingModelChangeGuard;
use App\Service\Embedding\Exception\PremiumRequiredException;
use App\Service\Model\Exception\InvalidPromptModelException;
use App\Service\Model\PromptModelEligibilityValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the prompt-side counterpart to ConfigController's premium
 * gate (issue #891). The validator MUST:
 *  - Skip when no aiModel is provided / it's the sentinel "no override"
 *  - Reject unknown / inactive models with 400-equivalent
 *  - Delegate to EmbeddingModelChangeGuard for VECTORIZE/EMBEDDING tags
 *  - Pass everything else through untouched, including non-numeric junk.
 */
final class PromptModelEligibilityValidatorTest extends TestCase
{
    private ModelRepository&MockObject $modelRepository;
    private EmbeddingModelChangeGuard&MockObject $embeddingChangeGuard;
    private PromptModelEligibilityValidator $validator;

    protected function setUp(): void
    {
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->embeddingChangeGuard = $this->createMock(EmbeddingModelChangeGuard::class);
        $this->validator = new PromptModelEligibilityValidator(
            $this->modelRepository,
            $this->embeddingChangeGuard,
        );
    }

    public function testSkipsWhenAiModelKeyAbsent(): void
    {
        $this->modelRepository->expects(self::never())->method('find');
        $this->embeddingChangeGuard->expects(self::never())->method('assertCanChange');

        $this->validator->assertMetadataAllowed($this->makeUser('NEW'), ['tool_internet' => true]);
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function noOverrideValuesProvider(): iterable
    {
        // -1 is the documented "use default" sentinel in PromptService.
        yield 'sentinel -1' => [-1];
        yield 'zero' => [0];
        yield 'negative' => [-42];
        yield 'string -1' => ['-1'];
        yield 'null' => [null];
    }

    #[DataProvider('noOverrideValuesProvider')]
    public function testSkipsWhenAiModelMeansNoOverride(mixed $value): void
    {
        $this->modelRepository->expects(self::never())->method('find');
        $this->embeddingChangeGuard->expects(self::never())->method('assertCanChange');

        $this->validator->assertMetadataAllowed(
            $this->makeUser('NEW'),
            ['aiModel' => $value],
        );
        $this->addToAssertionCount(1);
    }

    public function testSkipsWhenAiModelIsNonNumericGarbage(): void
    {
        // Matches PromptService::loadMetadataForPrompt's `(int) $value`
        // coercion which would also silently turn this into 0.
        $this->modelRepository->expects(self::never())->method('find');
        $this->embeddingChangeGuard->expects(self::never())->method('assertCanChange');

        $this->validator->assertMetadataAllowed(
            $this->makeUser('NEW'),
            ['aiModel' => 'not-a-number'],
        );
        $this->addToAssertionCount(1);
    }

    public function testThrowsWhenModelDoesNotExist(): void
    {
        $this->modelRepository->method('find')->with(999)->willReturn(null);
        $this->embeddingChangeGuard->expects(self::never())->method('assertCanChange');

        $this->expectException(InvalidPromptModelException::class);

        $this->validator->assertMetadataAllowed(
            $this->makeUser('PRO'),
            ['aiModel' => 999],
        );
    }

    public function testThrowsWhenModelIsInactive(): void
    {
        $this->modelRepository->method('find')->willReturn($this->makeModel(tag: 'chat', active: 0));
        $this->embeddingChangeGuard->expects(self::never())->method('assertCanChange');

        $this->expectException(InvalidPromptModelException::class);

        $this->validator->assertMetadataAllowed(
            $this->makeUser('PRO'),
            ['aiModel' => 7],
        );
    }

    public function testAllowsActiveChatModelForFreeUser(): void
    {
        $this->modelRepository->method('find')->willReturn($this->makeModel(tag: 'chat', active: 1));
        $this->embeddingChangeGuard->expects(self::never())->method('assertCanChange');

        $this->validator->assertMetadataAllowed(
            $this->makeUser('NEW'),
            ['aiModel' => 7],
        );
        $this->addToAssertionCount(1);
    }

    public function testAppliesEmbeddingGuardForVectorizeTag(): void
    {
        $this->modelRepository->method('find')->willReturn($this->makeModel(tag: 'vectorize', active: 1));
        $this->embeddingChangeGuard
            ->expects(self::once())
            ->method('assertCanChange')
            ->willThrowException(new PremiumRequiredException('NEW'));

        $this->expectException(PremiumRequiredException::class);

        $this->validator->assertMetadataAllowed(
            $this->makeUser('NEW'),
            ['aiModel' => 42],
        );
    }

    public function testAppliesEmbeddingGuardForEmbeddingTagCaseInsensitive(): void
    {
        // The Model entity stores the tag in lowercase historically, but
        // ConfigController upper-cases on read. Cover both.
        $this->modelRepository->method('find')->willReturn($this->makeModel(tag: 'Embedding', active: 1));
        $this->embeddingChangeGuard
            ->expects(self::once())
            ->method('assertCanChange');

        $this->validator->assertMetadataAllowed(
            $this->makeUser('PRO'),
            ['aiModel' => 42],
        );
        $this->addToAssertionCount(1);
    }

    public function testAllowsPremiumUserToPinEmbeddingModel(): void
    {
        $this->modelRepository->method('find')->willReturn($this->makeModel(tag: 'vectorize', active: 1));
        $this->embeddingChangeGuard
            ->expects(self::once())
            ->method('assertCanChange'); // returns void without throwing

        $this->validator->assertMetadataAllowed(
            $this->makeUser('PRO'),
            ['aiModel' => 42],
        );
        $this->addToAssertionCount(1);
    }

    private function makeUser(string $level): User
    {
        $user = new User();
        $user->setUserLevel($level);

        return $user;
    }

    private function makeModel(string $tag, int $active): Model
    {
        $model = new Model();
        $model->setTag($tag);
        $model->setActive($active);

        return $model;
    }
}
