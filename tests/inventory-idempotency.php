<?php
declare(strict_types=1);

function inventoryIdempotencyScenarios(PDO $pdo): void {
    $source = ['sourceId' => '550e8400-e29b-41d4-a716-446655440099', 'name' => 'Idempotency test'];
    $asset = ['localIdentifier' => 'local-idem', 'cloudIdentifier' => 'cloud-idem', 'mediaType' => 'image',
        'filename' => 'synthetic.jpg', 'creationDate' => null];
    $service = new OCA\ApplePhotosConnector\Service\InventoryService(
        new OCA\ApplePhotosConnector\Db\InventoryRepository(new TestHarness\Connection($pdo)),
        new OCA\ApplePhotosConnector\Service\AssetIdentity(), new OCA\ApplePhotosConnector\Service\InventoryValidator(), testUploadedFileLocator());
    $key = '550e8400-e29b-41d4-a716-446655440098';
    $beforeRuns = (int)$pdo->query("SELECT COUNT(*) FROM apc_import_runs WHERE user_id='idem-user'")->fetchColumn();
    $beforeTickets = (int)$pdo->query("SELECT COUNT(*) FROM apc_uploads WHERE user_id='idem-user'")->fetchColumn();
    $first = $service->ingestIdempotent('idem-user', $source, [$asset], $key);
    $replay = $service->ingestIdempotent('idem-user', $source, [$asset], $key);
    check($first === $replay, 'IV1: replay returns the exact run id and upload ticket');
    check((int)$pdo->query("SELECT COUNT(*) FROM apc_import_runs WHERE user_id='idem-user'")->fetchColumn() === $beforeRuns + 1, 'IV2: replay creates no second run');
    check((int)$pdo->query("SELECT COUNT(*) FROM apc_uploads WHERE user_id='idem-user'")->fetchColumn() === $beforeTickets + 1, 'IV3: replay creates no second upload ticket');

    $different = $asset; $different['filename'] = 'different.jpg';
    try { $service->ingestIdempotent('idem-user', $source, [$different], $key); throw new LogicException('Idempotency mismatch was accepted'); }
    catch (OCA\ApplePhotosConnector\Service\InventoryIdempotencyConflict) { check(true, 'IV4: same key with changed payload conflicts'); }
    check((int)$pdo->query("SELECT COUNT(*) FROM apc_import_runs WHERE user_id='idem-user'")->fetchColumn() === $beforeRuns + 1, 'IV5: conflicting payload is not processed');

    $otherUser = $service->ingestIdempotent('idem-user-2', $source, [$asset], $key);
    check($otherUser['runId'] !== $first['runId'], 'IV6: idempotency scope is isolated by user');
    try { $service->ingestIdempotent('idem-user', ['sourceId' => '550e8400-e29b-41d4-a716-446655440097', 'name' => 'Other'], [$asset], $key); throw new LogicException('Cross-source key reuse was accepted'); }
    catch (OCA\ApplePhotosConnector\Service\InventoryIdempotencyConflict) { check(true, 'IV7: same user key cannot cross source boundaries'); }

    $indices = $pdo->query("PRAGMA index_list(apc_inventory_idempotency)")->fetchAll(PDO::FETCH_ASSOC);
    $unique = array_filter($indices, static fn(array $item): bool => (int)$item['unique'] === 1);
    check(count($unique) === 1, 'IV8: database enforces unique user/key reservation for concurrent requests');

    $failingRepository = new class(new TestHarness\Connection($pdo)) extends OCA\ApplePhotosConnector\Db\InventoryRepository {
        public function completeInventoryIdempotency(string $user, string $key, string $result): void {
            throw new RuntimeException('injected result persistence failure');
        }
    };
    $failingService = new OCA\ApplePhotosConnector\Service\InventoryService($failingRepository,
        new OCA\ApplePhotosConnector\Service\AssetIdentity(), new OCA\ApplePhotosConnector\Service\InventoryValidator(), testUploadedFileLocator());
    try { $failingService->ingestIdempotent('idem-rollback', $source, [$asset], '550e8400-e29b-41d4-a716-446655440096'); throw new LogicException('Injected transaction failure was ignored'); }
    catch (RuntimeException $error) { check($error->getMessage() === 'injected result persistence failure', 'IV9: injected failure propagates'); }
    check((int)$pdo->query("SELECT COUNT(*) FROM apc_inventory_idempotency WHERE user_id='idem-rollback'")->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM apc_import_runs WHERE user_id='idem-rollback'")->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM apc_uploads WHERE user_id='idem-rollback'")->fetchColumn() === 0,
        'IV10: rollback leaves no run, ticket, asset, or successful idempotency record');
}

function inventoryIdempotencyConcurrencyScenario(): void {
    if (!function_exists('pcntl_fork')) { check(true, 'IV11: concurrent process test skipped (pcntl unavailable)'); return; }
    $directory = sys_get_temp_dir() . '/apc-idempotency-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $databasePath = $directory . '/inventory.sqlite';
    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $schema = new TestHarness\Schema();
    $base = new OCA\ApplePhotosConnector\Migration\Version008000Date20260910000000();
    $base->changeSchema(new class implements OCP\Migration\IOutput {}, fn () => $schema, []);
    $content = new OCA\ApplePhotosConnector\Migration\Version008600Date20260918000000(new TestHarness\Connection($pdo));
    $content->changeSchema(new class implements OCP\Migration\IOutput {}, fn () => $schema, []);
    $idempotent = new OCA\ApplePhotosConnector\Migration\Version008800Date20260925000000();
    $idempotent->changeSchema(new class implements OCP\Migration\IOutput {}, fn () => $schema, []);
    $schema->apply($pdo);
    $content->postSchemaChange(new class implements OCP\Migration\IOutput {}, fn () => $schema, []);
    $pdo = null;

    $source = ['sourceId' => '550e8400-e29b-41d4-a716-446655440089', 'name' => 'Concurrent test'];
    $asset = ['localIdentifier' => 'concurrent-local', 'cloudIdentifier' => 'concurrent-cloud', 'mediaType' => 'image', 'filename' => 'concurrent.jpg', 'creationDate' => null];
    $key = '550e8400-e29b-41d4-a716-446655440088';
    $barrier = $directory . '/start';
    $children = [];
    for ($index = 0; $index < 2; $index++) {
        $pid = pcntl_fork();
        if ($pid === -1) { throw new RuntimeException('Unable to fork idempotency test worker'); }
        if ($pid === 0) {
            while (!file_exists($barrier)) { usleep(1000); }
            usleep(random_int(0, 20_000));
            try {
                $childPdo = new PDO('sqlite:' . $databasePath);
                $childPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $childPdo->exec('PRAGMA busy_timeout=5000');
                $service = new OCA\ApplePhotosConnector\Service\InventoryService(
                    new OCA\ApplePhotosConnector\Db\InventoryRepository(new TestHarness\Connection($childPdo)),
                    new OCA\ApplePhotosConnector\Service\AssetIdentity(), new OCA\ApplePhotosConnector\Service\InventoryValidator(), testUploadedFileLocator());
                $result = $service->ingestIdempotent('concurrent-user', $source, [$asset], $key);
                file_put_contents($directory . '/result-' . $index, json_encode($result, JSON_THROW_ON_ERROR));
                exit(0);
            } catch (Throwable $error) {
                file_put_contents($directory . '/result-' . $index, 'ERROR:' . get_class($error));
                exit(1);
            }
        }
        $children[] = $pid;
    }
    touch($barrier);
    $statuses = [];
    foreach ($children as $pid) { pcntl_waitpid($pid, $status); $statuses[] = pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0; }
    $first = json_decode((string)file_get_contents($directory . '/result-0'), true);
    $second = json_decode((string)file_get_contents($directory . '/result-1'), true);
    $check = new PDO('sqlite:' . $databasePath);
    check($statuses === [true, true] && is_array($first) && $first === $second
        && (int)$check->query("SELECT COUNT(*) FROM apc_import_runs WHERE user_id='concurrent-user'")->fetchColumn() === 1
        && (int)$check->query("SELECT COUNT(*) FROM apc_uploads WHERE user_id='concurrent-user'")->fetchColumn() === 1,
        'IV11: concurrent same-key requests return one result and create one run/ticket');
    foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); }
    rmdir($directory);
}
