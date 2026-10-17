<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\OpenAI;

use App\AI\Provider\OpenAI\OpenAIResponses;
use App\Tests\Characterization\Support\OpenAIFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OpenAIResponsesTest extends TestCase
{
    use OpenAIApiDouble;

    public function testReasoningModelsGetATierAndChatModelsNone(): void
    {
        $responses = new OpenAIResponses($this->api([OpenAIFixtures::model('gpt-5.5'), OpenAIFixtures::model('gpt-4o')]), new NullLogger());

        self::assertSame([true, false], [$responses->usesCompletionTokens('gpt-5.5'), $responses->usesCompletionTokens('gpt-4o')]);
        self::assertSame(['effort' => 'minimal'], $responses->resolveReasoningConfig('gpt-5', true, ['reasoning_effort' => 'off']));
        self::assertSame(['effort' => 'high', 'summary' => 'auto'], $responses->resolveReasoningConfig('gpt-5', true, ['reasoning_effort' => 'max']));
        self::assertNull($responses->resolveReasoningConfig('gpt-4o', false, ['reasoning_effort' => 'high']));
        self::assertSame(['GET /v1/models/gpt-5.5', 'GET /v1/models/gpt-4o'], $this->sdkCalls());
    }

    public function testARejectedTierIsRetriedWithoutItAndRemembered(): void
    {
        $responses = new OpenAIResponses($this->api([
            OpenAIFixtures::error(400, "Unsupported value: 'minimal' is not supported with the 'gpt-5' model."),
            OpenAIFixtures::response([OpenAIFixtures::message('One.')]),
            OpenAIFixtures::response([OpenAIFixtures::message('Two.')]),
        ]), new NullLogger());
        $request = ['model' => 'gpt-5', 'input' => [['role' => 'user', 'content' => 'Hi']], 'reasoning' => ['effort' => 'minimal']];

        $responses->create($request);
        $responses->create($request);

        $bodies = array_map(static fn (array $sent): array => $sent['body'], $this->sdk->requests());
        self::assertSame([true, false, false], array_map(static fn (array $body): bool => isset($body['reasoning']), $bodies));
    }

    public function testAnExpiredPreviousResponseIsRetriedWithTheWholeConversation(): void
    {
        $responses = new OpenAIResponses($this->api([
            OpenAIFixtures::error(400, "Previous response with id 'resp_gone' not found."),
            OpenAIFixtures::response([OpenAIFixtures::message('Fresh.')]),
        ]), new NullLogger());
        $input = [['role' => 'user', 'content' => 'First'], ['role' => 'assistant', 'content' => 'Reply'], ['role' => 'user', 'content' => 'Second']];

        $responses->create(['model' => 'gpt-4o', 'input' => $input, 'previous_response_id' => 'resp_gone']);

        [$reduced, $full] = array_map(static fn (array $sent): array => $sent['body'], $this->sdk->requests());
        self::assertSame([[['role' => 'user', 'content' => 'Second']], 'resp_gone'], [$reduced['input'], $reduced['previous_response_id']]);
        self::assertSame([$input, false], [$full['input'], isset($full['previous_response_id'])]);
    }
}
