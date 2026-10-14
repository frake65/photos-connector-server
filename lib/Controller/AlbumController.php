<?php
declare(strict_types=1);
namespace OCA\ApplePhotosConnector\Controller;

use OCA\ApplePhotosConnector\AppInfo\Application;
use OCA\ApplePhotosConnector\Service\AlbumSyncDiagnosticException;
use OCA\ApplePhotosConnector\Service\AlbumSyncOrchestrator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IDBConnection;

class AlbumController extends Controller {
    public function __construct(IRequest $r, private IUserSession $session, private IDBConnection $db, private ?AlbumSyncOrchestrator $orchestrator = null) {
        parent::__construct(Application::APP_ID, $r);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function sync(): JSONResponse {
        $user = $this->session->getUser();
        if (!$user || !str_starts_with(strtolower($this->request->getHeader('Authorization')), 'basic ')) return new JSONResponse(['error' => 'Authentication required'], 401);
        $params = $this->request->getParams();
        $sourceId = $params['sourceId'] ?? null;
        if (!is_string($sourceId) || !$this->isUuid($sourceId) || !$this->orchestrator) return new JSONResponse(['error' => 'Invalid album sync request'], 400);
        $albumIds = $params['selectedAlbumIDs'] ?? null;
        $assetIds = $params['selectedAssetIDs'] ?? null;
        if (($albumIds !== null && !$this->isStringList($albumIds)) || ($assetIds !== null && !$this->isStringList($assetIds))) return new JSONResponse(['error' => 'Invalid album selection'], 400);
        $requestContext = [
            'selected_album_count' => count($albumIds ?? []),
            'selected_asset_count' => count($assetIds ?? []),
            'selected_asset_prefixes' => $this->identityPrefixCounts($assetIds ?? []),
        ];
        $this->debug('album.sync.request source='.strtolower($sourceId).' '.json_encode($requestContext, JSON_UNESCAPED_SLASHES));

        try {
            $result = $this->orchestrator->sync(strtolower($sourceId), $user->getUID(), $albumIds, $assetIds);
            return new JSONResponse(['status' => $result['errors'] ? 'partial' : 'completed', 'summary' => [
                'albumsSeen' => $result['albums_seen'], 'albumsCreated' => $result['albums_created'], 'albumsReused' => $result['albums_reused'],
                'foldersSkipped' => $result['folders_skipped'], 'membershipsSeen' => $result['memberships_seen'],
                'membershipsCreated' => $result['memberships_created'], 'membershipsReused' => $result['memberships_reused'],
                'membershipsSkippedNotImported' => $result['memberships_skipped_not_imported'], 'errors' => $result['errors'],
            ]]);
        } catch (AlbumSyncDiagnosticException $e) {
            return $this->syncFailure($e, $requestContext);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => 'Invalid album sync request'], 400);
        } catch (\Throwable $e) {
            return $this->syncFailure($e, $requestContext);
        }
    }

    private function syncFailure(\Throwable $error, array $requestContext): JSONResponse {
        $diagnostic = $error instanceof AlbumSyncDiagnosticException ? $error : null;
        $cause = $diagnostic?->getPrevious() ?? $error;
        if ($cause instanceof \InvalidArgumentException) return new JSONResponse(['error' => 'Invalid album sync request'], 400);

        $this->debug('album.sync.failure stage='.($diagnostic?->stage ?? 'album.sync.unclassified').' exception='.get_class($cause));
        $response = ['error' => 'Album sync failed'];
        if (defined('OC_DEBUG') && OC_DEBUG) {
            $response['diagnostic'] = [
                'stage' => $diagnostic?->stage ?? 'album.sync.unclassified',
                'exceptionClass' => get_class($cause),
                'context' => $diagnostic?->context ?? [],
                'request' => $requestContext,
            ];
        }
        return new JSONResponse($response, 500);
    }

    private function identityPrefixCounts(array $identities): array {
        $counts = ['cloud' => 0, 'local' => 0, 'other' => 0];
        foreach ($identities as $identity) {
            $prefix = str_starts_with($identity, 'cloud:') ? 'cloud' : (str_starts_with($identity, 'local:') ? 'local' : 'other');
            $counts[$prefix]++;
        }
        return $counts;
    }

    private function isStringList(mixed $value): bool {
        if (!is_array($value) || !array_is_list($value)) return false;
        foreach ($value as $item) { if (!is_string($item)) return false; }
        return true;
    }

    private function isUuid(mixed $value): bool {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1;
    }

    private function debug(string $message): void {
        if (defined('OC_DEBUG') && OC_DEBUG && class_exists('OC') && isset(\OC::$server)) {
            try { \OC::$server->getLogger()->debug('Apple Photos Connector: '.$message, ['app' => 'apple_photos_connector']); } catch (\Throwable) {}
        }
    }

 #[NoAdminRequired]
 #[NoCSRFRequired]
 public function inventory(): JSONResponse {
        $user = $this->session->getUser();
        if (!$user || !str_starts_with(strtolower($this->request->getHeader('Authorization')), 'basic ')) {
            return new JSONResponse(['error' => 'Authentication required'], 401);
        }

        $params = $this->request->getParams();
        $source = $params['source'] ?? null;
        $albums = $params['albums'] ?? null;
        if (!is_array($source) || !$this->isUuid($source['sourceId'] ?? null)
            || !is_string($source['name'] ?? null) || $source['name'] === ''
            || !is_array($albums) || !array_is_list($albums)) {
            return new JSONResponse(['error' => 'Invalid album inventory'], 400);
        }
        foreach ($albums as $album) {
            if (!is_array($album) || !is_string($album['localIdentifier'] ?? null) || $album['localIdentifier'] === ''
                || !is_string($album['name'] ?? null) || $album['name'] === ''
                || !in_array($album['kind'] ?? null, ['album', 'folder'], true)) {
                return new JSONResponse(['error' => 'Invalid album inventory'], 400);
            }
            foreach (['assets', 'assetIdentities'] as $field) {
                if (array_key_exists($field, $album) && !$this->isStringList($album[$field])) {
                    return new JSONResponse(['error' => 'Invalid album membership list'], 400);
                }
            }
            foreach (($album['assets'] ?? $album['assetIdentities'] ?? []) as $identity) {
                $prefixedValue = str_starts_with($identity, 'cloud:') || str_starts_with($identity, 'local:')
                    ? substr($identity, strpos($identity, ':') + 1) : $identity;
                if ($prefixedValue === '') return new JSONResponse(['error' => 'Invalid album membership list'], 400);
            }
            if (isset($album['cloudIdentifier']) && !is_string($album['cloudIdentifier']) && $album['cloudIdentifier'] !== null) {
                return new JSONResponse(['error' => 'Invalid album inventory'], 400);
            }
            if (isset($album['parentLocalIdentifier']) && !is_string($album['parentLocalIdentifier']) && $album['parentLocalIdentifier'] !== null) {
                return new JSONResponse(['error' => 'Invalid album inventory'], 400);
            }
            if (isset($album['assets'], $album['assetIdentities']) && $album['assets'] !== $album['assetIdentities']) {
                return new JSONResponse(['error' => 'Conflicting album membership lists'], 400);
            }
        }

        $userId = $user->getUID();
        $sourceId = strtolower($source['sourceId']);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.u\\Z');
        try {
            $this->db->beginTransaction();
            $query = $this->db->getQueryBuilder();
            $query->select('id')->from('apc_sources')->where(
                $query->expr()->eq('user_id', $query->createNamedParameter($userId)),
                $query->expr()->eq('source_id', $query->createNamedParameter($sourceId)),
            );
            $sourceResult = $query->executeQuery();
            try { $sourceExists = (bool)$sourceResult->fetch(); }
            finally { $sourceResult->closeCursor(); }
            if (!$sourceExists) {
                $this->rollbackTransaction();
                return new JSONResponse(['error' => 'Source must be registered first'], 400);
            }

            $albumIds = [];
            foreach ($albums as $album) {
                $query = $this->db->getQueryBuilder();
                $query->select('id')->from('apc_source_albums')->where(
                    $query->expr()->eq('user_id', $query->createNamedParameter($userId)),
                    $query->expr()->eq('source_id', $query->createNamedParameter($sourceId)),
                    $query->expr()->eq('local_identifier', $query->createNamedParameter($album['localIdentifier'])),
                );
                $existing = $query->executeQuery()->fetch();
                if ($existing) {
                    $albumId = (int)$existing['id'];
                    $query = $this->db->getQueryBuilder();
                    $query->update('apc_source_albums')
                        ->set('name', $query->createNamedParameter($album['name']))
                        ->set('cloud_identifier', $query->createNamedParameter($album['cloudIdentifier'] ?? null))
                        ->set('parent_local_identifier', $query->createNamedParameter($album['parentLocalIdentifier'] ?? null))
                        ->set('kind', $query->createNamedParameter($album['kind']))
                        ->set('last_seen_at', $query->createNamedParameter($now))
                        ->where($query->expr()->eq('id', $query->createNamedParameter($albumId)))
                        ->executeStatement();
                } else {
                    $query = $this->db->getQueryBuilder();
                    $query->insert('apc_source_albums')->values([
                        'user_id' => $query->createNamedParameter($userId),
                        'source_id' => $query->createNamedParameter($sourceId),
                        'local_identifier' => $query->createNamedParameter($album['localIdentifier']),
                        'cloud_identifier' => $query->createNamedParameter($album['cloudIdentifier'] ?? null),
                        'name' => $query->createNamedParameter($album['name']),
                        'parent_local_identifier' => $query->createNamedParameter($album['parentLocalIdentifier'] ?? null),
                        'kind' => $query->createNamedParameter($album['kind']),
                        'created_at' => $query->createNamedParameter($now),
                        'last_seen_at' => $query->createNamedParameter($now),
                    ])->executeStatement();
                    $albumId = (int)$this->db->lastInsertId('apc_source_albums');
                }
                $albumIds[] = $albumId;
            }

            $membershipsSkippedUnknown = 0;
            foreach ($albums as $index => $album) {
                foreach (($album['assets'] ?? $album['assetIdentities'] ?? []) as $identity) {
                    $query = $this->db->getQueryBuilder();
                    $column = null;
                    $value = $identity;
                    if (str_starts_with($identity, 'cloud:')) {
                        $column = 'cloud_identifier';
                        $value = substr($identity, 6);
                    } elseif (str_starts_with($identity, 'local:')) {
                        $column = 'local_identifier';
                        $value = substr($identity, 6);
                    }
                    $identityMatches = $column !== null
                        ? $query->expr()->eq($column, $query->createNamedParameter($value))
                        : $query->expr()->orX(
                            $query->expr()->eq('local_identifier', $query->createNamedParameter($value)),
                            $query->expr()->eq('cloud_identifier', $query->createNamedParameter($value)),
                        );
                    $query->select('id')->from('apc_assets')->where(
                        $query->expr()->eq('user_id', $query->createNamedParameter($userId)),
                        $query->expr()->eq('source_id', $query->createNamedParameter($sourceId)),
                        $identityMatches,
                    );
                    $matches = $query->executeQuery()->fetchAllAssociative();
                    if (!$matches) {
                        $membershipsSkippedUnknown++;
                        continue;
                    }

                    $assetId = (int)$matches[0]['id'];
                    $query = $this->db->getQueryBuilder();
                    $query->select('id')->from('apc_album_memberships')->where(
                        $query->expr()->eq('user_id', $query->createNamedParameter($userId)),
                        $query->expr()->eq('source_id', $query->createNamedParameter($sourceId)),
                        $query->expr()->eq('album_id', $query->createNamedParameter($albumIds[$index])),
                        $query->expr()->eq('asset_id', $query->createNamedParameter($assetId)),
                    );
                    if ($query->executeQuery()->fetch()) continue;

                    $query = $this->db->getQueryBuilder();
                    $query->insert('apc_album_memberships')->values([
                        'user_id' => $query->createNamedParameter($userId),
                        'source_id' => $query->createNamedParameter($sourceId),
                        'album_id' => $query->createNamedParameter($albumIds[$index]),
                        'asset_id' => $query->createNamedParameter($assetId),
                        'first_seen_at' => $query->createNamedParameter($now),
                        'last_seen_at' => $query->createNamedParameter($now),
                    ])->executeStatement();
                }
            }

            $this->db->commit();
            return new JSONResponse([
                'albums' => $albumIds,
                'count' => count($albumIds),
                'membershipsSkippedUnknown' => $membershipsSkippedUnknown,
            ]);
        } catch (\Throwable $error) {
            $this->rollbackTransaction();
            error_log('Apple Photos Connector: album inventory failed exception=' . get_class($error));
            return new JSONResponse(['error' => 'Album inventory failed'], 500);
        }
 }

 private function rollbackTransaction(): void {
     if (!$this->db->inTransaction()) return;
     try { $this->db->rollBack(); } catch (\Throwable) { error_log('Apple Photos Connector: album inventory rollback failed'); }
 }
}
