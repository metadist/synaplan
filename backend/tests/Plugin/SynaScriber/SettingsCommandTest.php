<?php

declare(strict_types=1);

namespace App\Tests\Plugin\SynaScriber;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use Plugin\SynaScriber\Command\SettingsCommand;
use Plugin\SynaScriber\Service\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SettingsCommandTest extends PluginTestCase
{
    /** @var array<string, string> */
    private array $stored = [];

    private function tester(): CommandTester
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getValue')->willReturnCallback(
            fn (int $owner, string $group, string $setting): ?string => $this->stored[$setting] ?? null,
        );
        $repository->method('setValue')->willReturnCallback(
            function (int $owner, string $group, string $setting, string $value): Config {
                self::assertSame(0, $owner);
                self::assertSame(Settings::GROUP, $group);
                $this->stored[$setting] = $value;

                return $this->createStub(Config::class);
            },
        );

        return new CommandTester(new SettingsCommand(new Settings($repository)));
    }

    public function testSettingsFromStdinAreStoredAndSecretsAreNotPrinted(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['{"enabled": true, "transcriber_secret": "s3cret", "stt_url": "http://whisper:8080/"}']);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame(['enabled' => '1', 'transcriber_secret' => 's3cret', 'stt_url' => 'http://whisper:8080'], $this->stored);
        $printed = json_decode($tester->getDisplay(), true);
        self::assertIsArray($printed);
        self::assertTrue($printed['transcriber_secret']);
        self::assertStringNotContainsString('s3cret', $tester->getDisplay());
    }

    public function testAnInvalidValueStoresNothing(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['{"enabled": true, "default_language": "xx"}']);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame([], $this->stored);
        self::assertStringContainsString('Nothing was stored', $tester->getDisplay());
    }

    public function testASecretThatIsNotAStringStoresNothing(): void
    {
        foreach (['{"transcriber_secret": true}', '{"transcriber_secret": []}', '{"transcriber_secret": 1}', '{"prosody_secret": {"a": 1}}'] as $raw) {
            $tester = $this->tester();
            $tester->setInputs([$raw]);

            self::assertSame(Command::FAILURE, $tester->execute([]), $raw);
            self::assertSame([], $this->stored, $raw);
            self::assertStringContainsString('must be a string', $tester->getDisplay());
        }
    }

    public function testInputThatIsNotAnObjectIsRefused(): void
    {
        foreach (['', 'not json', '["enabled"]', '"enabled"'] as $raw) {
            $tester = $this->tester();
            $tester->setInputs([$raw]);

            self::assertSame(Command::INVALID, $tester->execute([]), $raw);
            self::assertSame([], $this->stored);
        }
    }

    public function testShowOnlyPrints(): void
    {
        $this->stored = ['enabled' => '1'];
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--show' => true]));
        $printed = json_decode($tester->getDisplay(), true);
        self::assertIsArray($printed);
        self::assertTrue($printed['enabled']);
        self::assertFalse($printed['transcriber_secret']);
    }
}
