<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Voice;

use App\Entity\Message;
use App\Entity\Model;
use App\Service\Voice\VoiceReplyStamp;
use PHPUnit\Framework\TestCase;

final class VoiceReplyStampTest extends TestCase
{
    public function testASpokenReplyKeepsTheLiveModelWhenNothingWasDelegated(): void
    {
        $message = $this->message();
        $model = (new Model())->setService('OpenAI')->setProviderId('gpt-live')->setName('GPT Live');
        (new \ReflectionProperty(Model::class, 'id'))->setValue($model, 15);

        $stamp = new VoiceReplyStamp();
        $stamp->applyVoiceModel($message, $model);

        self::assertSame('OpenAI', $message->getMeta('voice_provider'));
        self::assertSame('GPT Live', $message->getMeta('voice_model_name'));
        self::assertSame('15', $message->getMeta('voice_model_id'));
        self::assertNull($message->getMeta('ai_chat_model'));
        self::assertSame('CHAT', $message->getTopic());
    }

    public function testADelegatedReplyNamesBothModelsAndTheRealTopic(): void
    {
        $message = $this->message();
        $stamp = new VoiceReplyStamp();
        $pending = $stamp->fromProcess([
            'response' => [
                'metadata' => ['provider' => 'Anthropic', 'model' => 'Claude Sonnet', 'model_id' => 5],
                'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7],
            ],
            'classification' => [
                'topic' => 'mediamaker',
                'language' => 'de',
                'sorting_provider' => 'Anthropic',
                'sorting_model_name' => 'Claude Sorter',
                'sorting_model_id' => 9,
            ],
        ], '0.030000', true);
        $stamp->apply($message, $pending);
        $stamp->mergeLiveUsage($message, [
            'promptTokens' => 100,
            'completionTokens' => 40,
            'totalTokens' => 140,
            'cost' => '0.010000',
            'modelKey' => 'openai:GPT Live',
            'kind' => 'LIVE',
            'priceKnown' => true,
        ]);

        self::assertSame('mediamaker', $message->getTopic());
        self::assertSame('de', $message->getLanguage());
        self::assertSame('Claude Sonnet', $message->getMeta('ai_chat_model'));
        self::assertSame('Claude Sorter', $message->getMeta('ai_sorting_model'));
        self::assertSame('0.030000', $message->getMeta('ai_chat_cost'));
        $extra = json_decode((string) $message->getMeta('ai_usage_extra'), true);
        self::assertIsArray($extra);
        self::assertSame('LIVE', $extra[0]['kind']);
        self::assertSame(140, $extra[0]['totalTokens']);
    }

    public function testALaterVoiceTickExtendsTheSameUsageRow(): void
    {
        $message = $this->message();
        $stamp = new VoiceReplyStamp();
        $entry = [
            'promptTokens' => 10,
            'completionTokens' => 4,
            'totalTokens' => 14,
            'cost' => '0.001000',
            'modelKey' => 'openai:GPT Live',
            'kind' => 'LIVE',
            'priceKnown' => true,
        ];
        $stamp->mergeLiveUsage($message, $entry);
        $stamp->mergeLiveUsage($message, $entry);

        $extra = json_decode((string) $message->getMeta('ai_usage_extra'), true);
        self::assertCount(1, $extra);
        self::assertSame(28, $extra[0]['totalTokens']);
        self::assertSame('0.002000', $extra[0]['cost']);
    }

    private function message(): Message
    {
        $message = (new Message())->setTopic('CHAT')->setDirection('OUT');
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, 7);

        return $message;
    }
}
