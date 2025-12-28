<?php

declare(strict_types=1);

namespace App\Service\Plugin;

use App\Service\File\FileStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Manages plugin installation, symlinking, and configuration.
 */
final readonly class PluginManager
{
    private Filesystem $fs;

    public function __construct(
        private FileStorageService $fileStorageService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        #[Autowire('%plugins_dir%')]
        private string $pluginsDir,
    ) {
        $this->fs = new Filesystem();
    }

    /**
     * Lists all available plugins in the central repository.
     *
     * @return PluginManifest[]
     */
    public function listAvailablePlugins(): array
    {
        if (!is_dir($this->pluginsDir)) {
            $this->logger->warning("Central plugin repository not found at {$this->pluginsDir}");

            return [];
        }

        $plugins = [];
        $dirs = glob($this->pluginsDir.'/*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $manifestPath = $dir.'/manifest.json';
            if (file_exists($manifestPath)) {
                $content = file_get_contents($manifestPath);
                if ($content) {
                    $data = json_decode($content, true);
                    if ($data) {
                        $plugins[] = PluginManifest::fromArray($data);
                    }
                }
            }
        }

        return $plugins;
    }

    /**
     * Lists all plugins installed for a specific user.
     *
     * @return PluginManifest[]
     */
    public function listInstalledPlugins(int $userId): array
    {
        $userBaseDir = $this->fileStorageService->getUserBaseAbsolutePath($userId);
        $userPluginsDir = $userBaseDir.'/plugins';

        if (!is_dir($userPluginsDir)) {
            return [];
        }

        $plugins = [];
        $dirs = glob($userPluginsDir.'/*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $pluginName = basename($dir);
            // We read the manifest from the central repository to ensure we have full metadata
            $pluginPath = $this->pluginsDir.'/'.$pluginName;
            $manifestPath = $pluginPath.'/manifest.json';

            if (file_exists($manifestPath)) {
                $content = file_get_contents($manifestPath);
                if ($content) {
                    $data = json_decode($content, true);
                    if ($data) {
                        $plugins[] = PluginManifest::fromArray($data);
                    }
                }
            }
        }

        return $plugins;
    }

    /**
     * Installs (links) a plugin for a specific user.
     */
    public function installPlugin(int $userId, string $pluginName): void
    {
        $pluginPath = $this->pluginsDir.'/'.$pluginName;
        if (!is_dir($pluginPath)) {
            throw new \InvalidArgumentException("Plugin '$pluginName' not found in central repository.");
        }

        $manifestPath = $pluginPath.'/manifest.json';
        if (!file_exists($manifestPath)) {
            throw new \RuntimeException("Plugin '$pluginName' is missing manifest.json.");
        }

        $userBaseDir = $this->fileStorageService->getUserBaseAbsolutePath($userId);
        $userPluginsDir = $userBaseDir.'/plugins';
        $targetDir = $userPluginsDir.'/'.$pluginName;

        // Ensure user plugins directory exists
        if (!$this->fs->exists($userPluginsDir)) {
            $this->fs->mkdir($userPluginsDir);
        }

        // Clean up existing installation if any
        if ($this->fs->exists($targetDir)) {
            $this->logger->info("Plugin '$pluginName' already installed for user $userId. Refreshing symlinks.");
            $this->fs->remove($targetDir);
        }

        $this->fs->mkdir($targetDir);

        // 1. backend -> /plugins/{pluginName}/backend/
        if (is_dir($pluginPath.'/backend')) {
            $this->fs->symlink($pluginPath.'/backend', $targetDir.'/backend');
        }

        // 2. frontend -> /plugins/{pluginName}/frontend/
        if (is_dir($pluginPath.'/frontend')) {
            $this->fs->symlink($pluginPath.'/frontend', $targetDir.'/frontend');
        }

        // 3. up -> ../../../ (back to {userId}/ root)
        // Path from uploads/L1/L2/UserId/plugins/PluginName to uploads/L1/L2/UserId/ is ../../../
        $this->fs->symlink('../../../', $targetDir.'/up');

        $this->logger->info("Plugin '$pluginName' symlinked for user $userId.");

        // 4. Run migrations
        $this->runPluginMigrations($userId, $pluginName, $pluginPath);
    }

    /**
     * Uninstalls (removes links) a plugin for a specific user.
     */
    public function uninstallPlugin(int $userId, string $pluginName): void
    {
        $userBaseDir = $this->fileStorageService->getUserBaseAbsolutePath($userId);
        $targetDir = $userBaseDir.'/plugins/'.$pluginName;

        if ($this->fs->exists($targetDir)) {
            $this->fs->remove($targetDir);
            $this->logger->info("Plugin '$pluginName' uninstalled for user $userId.");
        }
    }

    /**
     * Runs plugin migrations from SQL files.
     */
    private function runPluginMigrations(int $userId, string $pluginName, string $pluginPath): void
    {
        $migrationsDir = $pluginPath.'/migrations';
        if (!is_dir($migrationsDir)) {
            return;
        }

        $pluginSlug = $this->slugify($pluginName);
        $group = 'P_'.substr($pluginSlug, 0, 62);

        $sqlFiles = glob($migrationsDir.'/*.sql');
        if (!$sqlFiles) {
            return;
        }
        sort($sqlFiles);

        $connection = $this->entityManager->getConnection();

        foreach ($sqlFiles as $file) {
            $sql = file_get_contents($file);
            if (!$sql) {
                continue;
            }

            $this->logger->info("Running migration $file for plugin '$pluginName' and user $userId");

            // Execute SQL with context placeholders
            // Plugins can use :userId and :group in their SQL migrations
            $connection->executeStatement($sql, [
                'userId' => $userId,
                'group' => $group,
            ]);
        }
    }

    /**
     * Helper to create a URL-friendly and BGROUP-safe slug.
     */
    private function slugify(string $text): string
    {
        // Replace non-letter or digits by underscores
        $text = preg_replace('~[^\pL\d]+~u', '_', $text);
        // Transliterate
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text) ?: $text;
        // Remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);
        // Trim underscores
        $text = trim($text, '_');
        // Lowercase
        $text = strtolower($text);

        return $text ?: 'n_a';
    }
}
