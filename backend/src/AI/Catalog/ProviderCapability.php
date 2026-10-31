<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * The capability keys of the provider registry: one `app.ai.<value>` tag per
 * case. `AiCapability` names calls (chat, chat_stream, embed_batch, …); this
 * names adapters, so a descriptor and a tag can never spell the same
 * capability two ways.
 */
enum ProviderCapability: string
{
    case Chat = 'chat';
    case Embedding = 'embedding';
    case Vision = 'vision';
    case ImageGeneration = 'image_generation';
    case VideoGeneration = 'video_generation';
    case SpeechToText = 'speech_to_text';
    case TextToSpeech = 'text_to_speech';
    case FileAnalysis = 'file_analysis';

    public function tag(): string
    {
        return 'app.ai.'.$this->value;
    }
}
