<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\AI\Catalog\ProviderCatalog;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\Scaffold\ProviderScaffolder;
use App\Command\ProviderScaffoldCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `app:provider:scaffold` against a temporary project directory: the class
 * and test it writes compile and describe the provider, the snippets name
 * the model row and the five locales, and the refusals are sentences.
 */
final class ProviderScaffoldCommandTest extends TestCase
{
    private static ?ProviderCatalog $catalog = null;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/syn_scaffold_'.bin2hex(random_bytes(4));
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testItWritesTheProviderAndItsTestAndPrintsWhatToPaste(): void
    {
        $name = 'zz'.bin2hex(random_bytes(3));
        $tester = $this->tester();
        $tester->execute([
            'name' => $name,
            '--openai-compatible' => true,
            '--display-name' => 'Zed Zero',
            '--base-url' => 'https://api.zed.example/v1/',
            '--console-url' => 'https://console.zed.example/keys',
            '--model' => 'zed-1',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $class = ucfirst($name).'Provider';
        $providerFile = $this->projectDir.'/src/AI/Provider/'.$class.'.php';
        $testFile = $this->projectDir.'/tests/Unit/AI/Provider/'.$class.'Test.php';
        self::assertFileExists($providerFile);
        self::assertFileExists($testFile);

        require $providerFile;
        $fqcn = 'App\\AI\\Provider\\'.$class;
        self::assertTrue(class_exists($fqcn, false), 'the generated class compiles');
        $descriptor = $fqcn::descriptor();
        self::assertInstanceOf(ProviderDescriptor::class, $descriptor);
        self::assertSame($name, $descriptor->name);
        self::assertSame('Zed Zero', $descriptor->displayName);
        self::assertNotNull($descriptor->key);
        self::assertSame(strtoupper($name).'_API_KEY', $descriptor->key->envVar);
        self::assertSame('https://api.zed.example/v1/chat/completions', $descriptor->chatCompletionsUrl(), 'the trailing slash is dropped');
        self::assertSame('https://console.zed.example/keys', $descriptor->key->consoleUrl);
        self::assertSame($name, $descriptor->branding->helpKey);
        self::assertSame($name.':zed-1:chat', $descriptor->defaults->bindings['CHAT'] ?? null);

        // Read through PHPUnit's BypassFinals stream wrapper, which strips `final`.
        $testSource = (string) file_get_contents($testFile);
        self::assertStringContainsString('class '.$class.'Test extends TestCase', $testSource);
        self::assertStringContainsString("ProviderCatalog::builtIn()->has('".$name."')", $testSource);

        $display = $tester->getDisplay();
        self::assertStringContainsString("'service' => '".ucfirst($name)."'", $display, 'the model row names the service whose lowercase is the provider');
        self::assertStringContainsString("'providerId' => 'zed-1'", $display);
        self::assertStringContainsString("'showWhenFree' => 1", $display, 'a row with placeholder prices stays visible in the pickers');
        foreach (ProviderScaffolder::LOCALES as $locale) {
            self::assertStringContainsString($locale.'/admin.json → "providerHelp": { "'.$name.'"', $display);
        }
        self::assertStringContainsString('Admin → AI infrastructure → Providers & keys', $display);
    }

    public function testDryRunWritesNothing(): void
    {
        $tester = $this->tester();
        $tester->execute(['name' => 'zzdry', '--openai-compatible' => true, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($this->projectDir.'/src');
        self::assertStringContainsString('class ZzdryProvider extends AbstractChatCompletionsCloudProvider', $tester->getDisplay());
    }

    public function testAnExistingProviderNameIsRefused(): void
    {
        $tester = $this->tester();
        $tester->execute(['name' => 'groq', '--openai-compatible' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('A provider named "groq" exists (Groq).', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->projectDir.'/src');
    }

    public function testWithoutTheTemplateFlagItPointsToTheDocs(): void
    {
        $tester = $this->tester();
        $tester->execute(['name' => 'zzflag']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('docs/PROVIDERS.md', $tester->getDisplay());
    }

    public function testABadNameOrUrlIsOneSentence(): void
    {
        $tester = $this->tester();
        $tester->execute(['name' => 'Acme-AI', '--openai-compatible' => true]);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('A provider name is 2–32 lowercase letters and digits', $tester->getDisplay());

        $tester->execute(['name' => 'zzurl', '--openai-compatible' => true, '--base-url' => 'ftp://x']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('The base URL must be http(s)', $tester->getDisplay());
    }

    public function testAnExistingFileNeedsForce(): void
    {
        $tester = $this->tester();
        $tester->execute(['name' => 'zzforce', '--openai-compatible' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $tester->execute(['name' => 'zzforce', '--openai-compatible' => true]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('src/AI/Provider/ZzforceProvider.php exists; pass --force', $tester->getDisplay());

        $tester->execute(['name' => 'zzforce', '--openai-compatible' => true, '--force' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    private function tester(): CommandTester
    {
        self::$catalog ??= ProviderCatalog::builtIn();

        return new CommandTester(new ProviderScaffoldCommand(self::$catalog, new ProviderScaffolder($this->projectDir)));
    }
}
