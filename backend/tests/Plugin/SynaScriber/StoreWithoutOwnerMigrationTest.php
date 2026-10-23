<?php

declare(strict_types=1);

namespace App\Tests\Plugin\SynaScriber;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * migrations/002 against the real tables: an installation that stored its
 * sessions under the owner account keeps them, under user id 0.
 */
final class StoreWithoutOwnerMigrationTest extends KernelTestCase
{
    private const GROUP = 'P_synascriber';

    private Connection $db;
    private string $sql;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get('doctrine')->getConnection();
        $dir = static::getContainer()->getParameter('plugins_dir');
        self::assertIsString($dir);
        $this->sql = (string) file_get_contents($dir.'/synascriber/migrations/002_store_without_owner.sql');
        self::assertNotSame('', $this->sql);
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        parent::tearDown();
    }

    public function testSessionsOfTheOldOwnerMoveToTheInstallationAndTheSettingGoes(): void
    {
        $owner = random_int(900000, 999999);
        $this->db->executeStatement('DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = ? AND BSETTING = ?', [self::GROUP, 'owner_user_id']);
        $this->db->insert('BCONFIG', ['BOWNERID' => 0, 'BGROUP' => self::GROUP, 'BSETTING' => 'owner_user_id', 'BVALUE' => (string) $owner]);
        $this->row($owner, 'synascriber', 'session', 's_moved');
        $this->row($owner, 'other_plugin', 'session', 's_kept');

        $this->migrate($owner + 1);

        self::assertSame(0, $this->ownerOf('synascriber', 's_moved'));
        self::assertSame($owner, $this->ownerOf('other_plugin', 's_kept'), 'other plugins keep their rows');
        self::assertFalse($this->db->fetchOne('SELECT BVALUE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = ? AND BSETTING = ?', [self::GROUP, 'owner_user_id']));

        $this->row($owner, 'synascriber', 'session', 's_new_user_row');
        $this->migrate($owner + 2);

        self::assertSame($owner, $this->ownerOf('synascriber', 's_new_user_row'), 'without the setting a rerun changes nothing');
    }

    private function migrate(int $userId): void
    {
        $this->db->executeStatement($this->sql, ['userId' => $userId, 'group' => self::GROUP]);
    }

    private function row(int $userId, string $plugin, string $type, string $key): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->insert('plugin_data', [
            'user_id' => $userId,
            'plugin_name' => $plugin,
            'data_type' => $type,
            'data_key' => $key,
            'data' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ownerOf(string $plugin, string $key): int
    {
        return (int) $this->db->fetchOne('SELECT user_id FROM plugin_data WHERE plugin_name = ? AND data_key = ?', [$plugin, $key]);
    }
}
