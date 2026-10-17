<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin\SystemConfig;

use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use PHPUnit\Framework\TestCase;

final class FieldDefinitionTest extends TestCase
{
    public function testAnEnvironmentFieldCarriesNoStorageAttributes(): void
    {
        $field = new FieldDefinition('url', 'Ollama server URL', 'http://ollama:11434', tab: 'ai', section: 'ollama');

        self::assertFalse($field->isStoredInDatabase());
        self::assertSame([
            'tab' => 'ai',
            'section' => 'ollama',
            'type' => 'url',
            'sensitive' => false,
            'description' => 'Ollama server URL',
            'default' => 'http://ollama:11434',
        ], $field->toArray());
    }

    public function testADatabaseRowNamesItsGroupKeyAndEncryption(): void
    {
        $field = new FieldDefinition(
            'password',
            'Client secret',
            sensitive: true,
            row: new DatabaseRow('MICROSOFT', 'CLIENT_SECRET', encrypted: true),
            placeholder: 'from the app registration',
            tab: 'channels',
            section: 'm365',
        );

        self::assertSame(['MICROSOFT', 'CLIENT_SECRET', true], [$field->group(), $field->setting('M365_CLIENT_SECRET'), $field->isEncrypted()]);
        self::assertSame([
            'tab' => 'channels',
            'section' => 'm365',
            'type' => 'password',
            'sensitive' => true,
            'description' => 'Client secret',
            'default' => '',
            'source' => 'database',
            'dbGroup' => 'MICROSOFT',
            'dbKey' => 'CLIENT_SECRET',
            'encrypted' => true,
            'placeholder' => 'from the app registration',
        ], $field->toArray());
    }

    public function testARowWithoutGroupLivesInTheSearchGroupUnderTheFieldsName(): void
    {
        $field = new FieldDefinition('number', 'Minimum memory score', '0.35', row: new DatabaseRow(), tab: 'vectordb', section: 'qdrant_search');

        self::assertSame([DatabaseRow::DEFAULT_GROUP, 'MIN_CHAT_MEMORY_SCORE', false], [$field->group(), $field->setting('MIN_CHAT_MEMORY_SCORE'), $field->isEncrypted()]);
        self::assertSame(['source' => 'database'], array_diff_key($field->toArray(), array_flip(['tab', 'section', 'type', 'sensitive', 'description', 'default'])));
    }

    public function testASelectListsItsOptionsInOrder(): void
    {
        $field = new FieldDefinition('select', 'Read tools', 'auto', row: new DatabaseRow('TOOLS', 'POLICY_READ'), options: ['auto', 'ask', 'deny']);

        self::assertSame(['auto', 'ask', 'deny'], $field->toArray()['options'] ?? null);
    }

    public function testAFieldManagedElsewhereKeepsItsValueInTheDatabase(): void
    {
        $field = (new FieldDefinition('password', 'OpenAI API key', sensitive: true, tab: 'ai', section: 'cloud'))->managedIn('providers');

        self::assertTrue($field->isStoredInDatabase());
        self::assertSame(['source' => 'database', 'managedBy' => 'providers'], array_diff_key($field->toArray(), array_flip(['tab', 'section', 'type', 'sensitive', 'description', 'default'])));
    }
}
