<?php

declare(strict_types=1);

/**
 * Offline checks of the reference client: the five signed-notification
 * vectors every cloud app must pass, the id shapes of both profiles, and the
 * path rules of the file store. Exit 1 on the first failure.
 *
 *   docker run --rm -v "$PWD":/repo -w /repo php:8.4-cli php _devextras/testing/cloud-apps/fixture-app/selftest.php
 */

use CloudFixture\FixtureException;
use CloudFixture\Notification;
use CloudFixture\Profile;
use CloudFixture\Store;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'CloudFixture\\')) {
        require __DIR__.'/src/'.substr($class, strlen('CloudFixture\\')).'.php';
    }
});

$failed = 0;
$check = static function (string $name, bool $ok) use (&$failed): void {
    printf("%s  %s\n", $ok ? 'PASS' : 'FAIL', $name);
    $failed += $ok ? 0 : 1;
};

$vectors = json_decode((string) file_get_contents(__DIR__.'/../../../../backend/tests/Fixtures/cloud-apps-contract/notify-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
$keys = [['publicKey' => $vectors['public_key'], 'kid' => '']];
foreach ($vectors['vectors'] as $vector) {
    $outcome = Notification::verify($vector['body'], $vectors['receiver_instance_id'], $keys, $vectors['now'], $vectors['window_seconds']);
    $check('notify vector '.$vector['name'].' → '.$vector['expect'], $outcome === $vector['expect']);
}
$unsigned = $vectors['vectors'][0]['body'];
unset($unsigned['sig']);
$check('an unsigned notification is refused', Notification::BAD_SIGNATURE === Notification::verify($unsigned, $vectors['receiver_instance_id'], $keys, $vectors['now']));

$check('keyed ids are numeric', 1 === preg_match('/^\d+$/', Profile::remoteId('keyed', 'ada', 'Invoices/a.pdf')));
$check('keyed versions are bare', !str_contains(Profile::version('keyed', 'x'), '"'));
$check('sso ids are storage$space!node', 1 === preg_match('/^[^$!]+\$[0-9a-f]+![0-9a-f]+$/', Profile::remoteId('sso', 'ada', 'Invoices/a.pdf')));
$check('sso versions are quoted etags', 1 === preg_match('/^"[0-9a-f]{32}"$/', Profile::version('sso', 'x')));
$check('an id is stable for the same file', Profile::remoteId('sso', 'ada', 'a.pdf') === Profile::remoteId('sso', 'ada', 'a.pdf'));

$check('a path is cleaned', 'Invoices/2026/a.pdf' === Store::cleanPath('/Invoices//2026/./a.pdf'));
$refused = static function (callable $call): bool {
    try {
        $call();
    } catch (FixtureException) {
        return true;
    }

    return false;
};
$check('a path may not leave the person\'s files', $refused(static fn () => Store::cleanPath('Invoices/../../etc/passwd')));
$check('a person id may not carry a path', $refused(static fn () => Store::person('../ada')));

$root = sys_get_temp_dir().'/cloud-fixture-selftest-'.bin2hex(random_bytes(4));
$store = new Store($root);
$store->put('ada', 'Invoices/inv-0101.txt', 'Vendor: Nordwind GmbH');
$store->put('ada', 'Invoices/2026/inv-0102.txt', 'Vendor: Ostsee Logistik');
$check('a folder lists its files', ['Invoices/inv-0101.txt'] === array_column($store->files('ada', 'Invoices', false), 'path'));
$check('a recursive folder lists every file', 2 === count($store->files('ada', 'Invoices')));
$store->reset();
rmdir($root.'/files');
rmdir($root);

printf("\n%s\n", 0 === $failed ? 'All checks passed.' : $failed.' check(s) failed.');
exit(0 === $failed ? 0 : 1);
