<?php

namespace App\Tests\AI\Contract;

use App\AI\Interface\ChatProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Abstract Contract Test für ChatProviderInterface
 * 
 * Alle Provider-Implementierungen müssen diese Tests bestehen.
 */
abstract class ChatProviderContractTest extends TestCase
{
    /**
     * Zu testender Provider (von Subklasse implementiert)
     */
    abstract protected function getProvider(): ChatProviderInterface;

    /**
     * Test: sortingPrompt returns valid structure
     */
    public function testSortingPromptReturnsValidStructure(): void
    {
        $provider = $this->getProvider();
        
        $result = $provider->sortingPrompt(
            ['BTEXT' => 'Hello, how are you?', 'BUSERID' => 1],
            []
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('BTOPIC', $result);
        $this->assertArrayHasKey('BLANG', $result);
        
        // BTOPIC muss einer der erlaubten Werte sein
        $allowedTopics = ['CHAT', 'TOOLS', 'ANALYZE', 'IMAGE', 'CODE', 'GENERAL'];
        $this->assertContains($result['BTOPIC'], $allowedTopics);
        
        // BLANG muss 2-Buchstaben ISO-Code sein
        $this->assertMatchesRegularExpression('/^[a-z]{2}$/i', $result['BLANG']);
    }

    /**
     * Test: topicPrompt returns non-empty string
     */
    public function testTopicPromptReturnsString(): void
    {
        $provider = $this->getProvider();
        
        $result = $provider->topicPrompt(
            ['BTEXT' => 'What is artificial intelligence?', 'BTOPIC' => 'CHAT'],
            []
        );

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertGreaterThan(10, strlen($result));
    }

    /**
     * Test: simplePrompt returns non-empty string
     */
    public function testSimplePromptReturnsString(): void
    {
        $provider = $this->getProvider();
        
        $result = $provider->simplePrompt('Say hello');

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test: summarize returns shorter text
     */
    public function testSummarizeReturnsString(): void
    {
        $provider = $this->getProvider();
        
        $longText = str_repeat('This is a test sentence. ', 50);
        $result = $provider->summarize($longText);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertLessThan(strlen($longText), strlen($result));
    }

    /**
     * Test: translate returns translated text
     */
    public function testTranslateReturnsString(): void
    {
        $provider = $this->getProvider();
        
        $result = $provider->translate('Hello world', 'en', 'de');

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test: Provider Metadata
     */
    public function testProviderMetadata(): void
    {
        $provider = $this->getProvider();

        // getName
        $name = $provider->getName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);

        // getCapabilities
        $capabilities = $provider->getCapabilities();
        $this->assertIsArray($capabilities);
        $this->assertContains('chat', $capabilities);

        // getDefaultModels
        $models = $provider->getDefaultModels();
        $this->assertIsArray($models);
        $this->assertArrayHasKey('chat', $models);

        // isAvailable
        $available = $provider->isAvailable();
        $this->assertIsBool($available);

        // getStatus
        $status = $provider->getStatus();
        $this->assertIsArray($status);
        $this->assertArrayHasKey('healthy', $status);
    }

    /**
     * Test: Streaming callback is invoked
     */
    public function testTopicPromptStreamInvokesCallback(): void
    {
        $provider = $this->getProvider();
        $chunks = [];

        $provider->topicPromptStream(
            ['BTEXT' => 'Count to 3', 'BTOPIC' => 'CHAT'],
            [],
            function($chunk) use (&$chunks) {
                $chunks[] = $chunk;
            }
        );

        $this->assertNotEmpty($chunks);
        $this->assertIsArray($chunks);
    }
}

