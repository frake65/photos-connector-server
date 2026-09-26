<?php
declare(strict_types=1);
namespace OCA\ApplePhotosConnector\Service;

use OCA\ApplePhotosConnector\Db\InventoryRepository;
use OCA\ApplePhotosConnector\Db\ImportRun;

class InventoryService {
    public function __construct(
        private InventoryRepository $repository,
        private AssetIdentity $identity,
        private InventoryValidator $validator,
        private UploadedFileLocator $files,
    ) {}

    public function ingest(string $user, mixed $source, mixed $assets): array {
        if ($user === '') { throw new \InvalidArgumentException('Authenticated user required'); }
        $seen = is_array($assets) && array_is_list($assets) ? count($assets) : 0;
        $run = ImportRun::start($user, $this->validator->sourceId($source), $seen);
        // Commit the single running row independently so an inventory rollback cannot erase it.
        $this->repository->transaction(fn () => $this->repository->insertRun($run));
        try {
            if (!is_array($source) || !is_array($assets)) {
                throw new \InvalidArgumentException('source object and assets array required');
            }
            [$source, $assets] = $this->validator->validate($source, $assets);
            return $this->process($user, $source, $assets, $run);
        } catch (\Throwable $error) {
            $errorCode = $error instanceof \InvalidArgumentException ? 'validation_error'
                : ($error instanceof \OCP\DB\Exception ? 'database_error' : 'processing_error');
            try {
                $this->repository->transaction(fn () => $this->repository->failRun($user, $run->runId, $seen, $errorCode));
            } catch (\Throwable) {
                // DB outage/process failures can leave running behind. Keep the original cause.
                error_log('Apple Photos Connector: failed to finalize import run ' . $run->runId);
            }
            throw new ImportRunFailure($run->runId, $error);
        }
    }

    /** Inventory v2 is atomic: reservation, run, tickets, and replay response commit together. */
    public function ingestIdempotent(string $user, mixed $source, mixed $assets, string $key): array {
        if ($user === '') { throw new \InvalidArgumentException('Authenticated user required'); }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $key)) {
            throw new \InvalidArgumentException('Idempotency-Key must be a UUID');
        }
        if (!is_array($source) || !is_array($assets)) { throw new \InvalidArgumentException('source object and assets array required'); }
        [$normalizedSource, $normalizedAssets] = $this->validator->validate($source, $assets);
        $key = strtolower($key);
        $sourceId = $normalizedSource['source_id'];
        $fingerprint = hash('sha256', json_encode(
            ['source' => $normalizedSource, 'assets' => $normalizedAssets],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));

        try {
            return $this->repository->transaction(function () use ($user, $key, $sourceId, $fingerprint, $normalizedSource, $normalizedAssets): array {
                $existing = $this->repository->inventoryIdempotency($user, $key);
                if ($existing !== null) { return $this->replayResult($existing, $sourceId, $fingerprint); }

                $now = ImportRun::now();
                // The unique reservation serializes concurrent requests. It is invisible unless the
                // whole transaction, including its completed result, commits successfully.
                $this->repository->insertInventoryIdempotency($user, $key, $sourceId, $fingerprint, $now);
                $run = ImportRun::start($user, $sourceId, count($normalizedAssets));
                $this->repository->insertRun($run);
                $result = $this->processWithinTransaction($user, $normalizedSource, $normalizedAssets, $run);
                $this->repository->completeInventoryIdempotency($user, $key,
                    json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return $result;
            });
        } catch (\Throwable $error) {
            // A concurrent winner or an ambiguous commit may have completed the reservation.
            $existing = $this->repository->inventoryIdempotency($user, $key);
            if ($existing !== null) {
                try { return $this->replayResult($existing, $sourceId, $fingerprint); }
                catch (InventoryIdempotencyConflict $conflict) { throw $conflict; }
            }
            throw $error;
        }
    }

    private function replayResult(array $existing, string $sourceId, string $fingerprint): array {
        if (($existing['source_id'] ?? null) !== $sourceId || !hash_equals((string)($existing['fingerprint'] ?? ''), $fingerprint)) {
            throw new InventoryIdempotencyConflict('Idempotency key conflicts with a different request');
        }
        if (!is_string($existing['result_json'] ?? null)) {
            throw new \RuntimeException('Inventory idempotency result is incomplete');
        }
        $result = json_decode($existing['result_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result) || !isset($result['runId'], $result['summary'], $result['assets'])) {
            throw new \RuntimeException('Stored inventory idempotency result is invalid');
        }
        return $result;
    }

    private function process(string $user, array $source, array $assets, ImportRun $run): array {
        return $this->repository->transaction(fn () => $this->processWithinTransaction($user, $source, $assets, $run));
    }

    private function processWithinTransaction(string $user, array $source, array $assets, ImportRun $run): array {
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
            $sourceId = $source['source_id'];
            // A write precedes the asset read: concurrent inventories serialize on this source row.
            $this->repository->touchSource($user, $sourceId, $source['name'], $now);
            if ($this->repository->source($user, $sourceId) === null) {
                $this->repository->insertSource([
                    'user_id' => $user, 'source_id' => $sourceId, 'name' => $source['name'],
                    'created_at' => $source['created_at'] ?? $now, 'last_seen_at' => $now,
                ]);
            }
            $known = [];
            foreach ($this->repository->assets($user, $sourceId) as $row) {
                foreach ($this->identity->lookupKeys($row['cloud_identifier'], $row['local_identifier']) as $key) {
                    $known[$key] ??= $row;
                }
            }
            $response = [];
            $tickets = [];
            foreach ($assets as $asset) {
                $key = $this->identity->key($asset['cloud_identifier'], $asset['local_identifier']);
                $exists = array_key_exists($key, $known);
                if ($exists) {
                    $row = $known[$key];
                    $this->repository->touchAsset($user, $sourceId, (int)$row['id'], $now);
                } else {
                    $id = $this->repository->insertAsset($asset + [
                        'user_id' => $user, 'source_id' => $sourceId,
                        'first_seen_at' => $now, 'last_seen_at' => $now,
                    ]);
                    $row = $asset + ['id' => $id, 'nextcloud_file_id' => null];
                    foreach ($this->identity->lookupKeys($asset['cloud_identifier'], $asset['local_identifier']) as $lookupKey) {
                        if (!array_key_exists($lookupKey, $known)) { $known[$lookupKey] = $row; }
                    }
                }
                $current = $this->repository->getCurrentTarget($user, $sourceId, (int)$row['id']);
                // A persisted mapping is authoritative only while its file
                // still exists. Missing files are recoverable during a normal import.
                $retarget = $current !== null && !$this->files->exists($user, $current['path']);
                $uploaded = $current !== null && !$retarget;
                $upload = null;
                if (!$uploaded) {
                    $id = (int)$row['id'];
                    if (!isset($tickets[$id])) {
                        $uploadId = ImportRun::start($user, $sourceId, 0)->runId;
                        $this->repository->insertUpload([
                            'upload_id' => $uploadId, 'run_id' => $run->runId, 'source_id' => $sourceId,
                            'user_id' => $user, 'asset_id' => $id, 'filename' => $asset['filename'], 'status' => 'pending',
                            'created_at' => $now,
                            'retarget_allowed' => $retarget, 'base_target_id' => $current['id'] ?? null,
                        ]);
                        $tickets[$id] = ['uploadId' => $uploadId, 'assetId' => (string)$id];
                    }
                    $upload = $tickets[$id];
                }
                $response[] = ['cloudIdentifier' => $asset['cloud_identifier'], 'state' => $uploaded ? 'known' : 'new', 'upload' => $upload];
            }
            $counts = array_count_values(array_column($response, 'state'));
            $summary = ['seen' => count($response), 'new' => $counts['new'] ?? 0, 'known' => $counts['known'] ?? 0];
            // Completion and all source/asset writes belong to exactly the same transaction.
            $this->repository->completeRun($user, $run->runId, $summary['seen'], $summary['new'], $summary['known']);
            return ['runId' => $run->runId, 'summary' => $summary, 'assets' => $response];
    }
}
