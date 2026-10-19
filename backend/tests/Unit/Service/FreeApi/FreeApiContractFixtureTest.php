<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FreeApi;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures under Fixtures/freeapi-contract/v1 are a byte-for-byte copy of
 * synaplan-freeapi contract/v1. A failure here means the contract changed:
 * copy the new files and update CHECKSUMS.sha256, never edit a fixture by hand.
 */
final class FreeApiContractFixtureTest extends TestCase
{
    private const DIR = __DIR__.'/../../../Fixtures/freeapi-contract';

    public function testChecksumsMatchCommittedLedger(): void
    {
        foreach ($this->ledger() as $name => $hash) {
            $path = self::DIR.'/'.$name;
            $this->assertFileExists($path, $name);
            $this->assertSame($hash, hash_file('sha256', $path), $name);
        }
    }

    public function testEveryVendoredFileIsListedInTheLedger(): void
    {
        $files = glob(self::DIR.'/v1/*');
        $this->assertNotFalse($files);
        $this->assertNotEmpty($files);

        $listed = array_keys($this->ledger());
        foreach ($files as $file) {
            $this->assertContains('v1/'.basename($file), $listed, basename($file));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function contractFiles(): iterable
    {
        foreach (['aliases', 'errors', 'headers', 'issuance', 'limits', 'routes'] as $name) {
            yield $name => [$name.'.json'];
        }
    }

    #[DataProvider('contractFiles')]
    public function testContractFileParsesAsJsonObject(string $file): void
    {
        $this->assertIsArray($this->decode($file));
    }

    public function testErrorCodesAreNamespacedAndComplete(): void
    {
        $errors = $this->decode('errors.json');
        $this->assertNotEmpty($errors);

        foreach ($errors as $code => $error) {
            $this->assertIsString($code);
            $this->assertStringStartsWith('freeapi_', $code);
            $this->assertIsArray($error, $code);
            $this->assertIsInt($error['http'] ?? null, $code);
            $this->assertIsString($error['type'] ?? null, $code);
            $this->assertIsString($error['message'] ?? null, $code);
            $this->assertNotSame('', $error['message'], $code);
        }
    }

    public function testAliasesDeclareProtocolOneAndRequiredFields(): void
    {
        $aliases = $this->decode('aliases.json');
        $this->assertSame(1, $aliases['protocol'] ?? null);
        $this->assertIsArray($aliases['aliases'] ?? null);
        $this->assertNotEmpty($aliases['aliases']);

        foreach ($aliases['aliases'] as $index => $alias) {
            $this->assertIsArray($alias, (string) $index);
            foreach (['id', 'capability', 'route', 'label'] as $field) {
                $this->assertIsString($alias[$field] ?? null, $index.'.'.$field);
                $this->assertNotSame('', $alias[$field], $index.'.'.$field);
            }
        }
    }

    /**
     * @return array<string, string> file name relative to the fixture root => sha256
     */
    private function ledger(): array
    {
        $lines = file(self::DIR.'/CHECKSUMS.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($lines);
        $this->assertNotEmpty($lines);

        $ledger = [];
        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  v1\/\S+$/', $line);
            [$hash, $name] = explode('  ', $line, 2);
            $ledger[$name] = $hash;
        }

        return $ledger;
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $file): array
    {
        $json = file_get_contents(self::DIR.'/v1/'.$file);
        $this->assertNotFalse($json, $file);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, $file);

        return $decoded;
    }
}
