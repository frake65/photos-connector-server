<?php
declare(strict_types=1);
namespace OCA\ApplePhotosConnector\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Durable state and metadata rows for the metadata-only catalog spike. */
final class MetadataCatalogRepository {
    public function __construct(private IDBConnection $db) {}

    public function create(string $id, string $user, int $folderId, string $activeKey, string $folderEtag, string $now): void {
        $q = $this->db->getQueryBuilder();
        $q->insert('apc_catalog_jobs')->values([
            'scan_id'=>$q->createNamedParameter($id), 'user_id'=>$q->createNamedParameter($user),
            'folder_id'=>$q->createNamedParameter($folderId,IQueryBuilder::PARAM_INT),
            'active_key'=>$q->createNamedParameter($activeKey), 'state'=>$q->createNamedParameter('queued'),
            'offset_value'=>$q->createNamedParameter(0,IQueryBuilder::PARAM_INT),
            'folder_etag'=>$q->createNamedParameter($folderEtag), 'etag_restarts'=>$q->createNamedParameter(0,IQueryBuilder::PARAM_INT),
            'counters_json'=>$q->createNamedParameter(json_encode(self::emptyCounters(),JSON_THROW_ON_ERROR)),
            'created_at'=>$q->createNamedParameter($now), 'updated_at'=>$q->createNamedParameter($now),
            'started_at'=>$q->createNamedParameter(null), 'completed_at'=>$q->createNamedParameter(null),
            'lease_until'=>$q->createNamedParameter(null), 'attempts'=>$q->createNamedParameter(0,IQueryBuilder::PARAM_INT),
            'error_code'=>$q->createNamedParameter(null),
        ])->executeStatement();
    }

    public static function emptyCounters(): array {
        return ['nodesVisited'=>0,'filesCatalogued'=>0,'unsupported'=>0,'inaccessible'=>0,'changedDuringScan'=>0,
            'pages'=>0,'lastBatchItems'=>0,'lastBatchMilliseconds'=>0,'maxBatchMilliseconds'=>0,'processPeakMemoryBytes'=>0,
            'etagRestarts'=>0,'incomplete'=>false];
    }

    public function get(string $user,string $id): ?array {
        $q=$this->db->getQueryBuilder();
        $q->select('*')->from('apc_catalog_jobs')->where($q->expr()->eq('user_id',$q->createNamedParameter($user)),
            $q->expr()->eq('scan_id',$q->createNamedParameter($id)));
        $r=$q->executeQuery(); try{return $r->fetch()?:null;}finally{$r->closeCursor();}
    }

    public function active(string $user,int $folderId): ?array {
        $key=self::activeKey($user,$folderId); $q=$this->db->getQueryBuilder();
        $q->select('*')->from('apc_catalog_jobs')->where($q->expr()->eq('user_id',$q->createNamedParameter($user)),
            $q->expr()->eq('folder_id',$q->createNamedParameter($folderId,IQueryBuilder::PARAM_INT)),
            $q->expr()->eq('active_key',$q->createNamedParameter($key)))->setMaxResults(1);
        $r=$q->executeQuery(); try{return $r->fetch()?:null;}finally{$r->closeCursor();}
    }

    public static function activeKey(string $user,int $folderId): string { return hash('sha256',$user."\0".$folderId); }

    /** Claim one queued or expired job with a compare-and-set lease. */
    public function claim(string $id,string $now,string $leaseUntil): ?array {
        $row=$this->byId($id); if($row===null||!in_array($row['state'],['queued','running'],true))return null;
        if($row['state']==='running'&&(string)($row['lease_until']??'')>$now)return null;
        $q=$this->db->getQueryBuilder();
        $changed=$q->update('apc_catalog_jobs')->set('state',$q->createNamedParameter('running'))
            ->set('lease_until',$q->createNamedParameter($leaseUntil))
            ->set('started_at',$q->createNamedParameter($row['started_at']?:$now))
            ->set('updated_at',$q->createNamedParameter($now))
            ->set('attempts',$q->createNamedParameter((int)$row['attempts']+($row['state']==='running'?1:0),IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('scan_id',$q->createNamedParameter($id)),
                $q->expr()->eq('state',$q->createNamedParameter((string)$row['state'])),
                $q->expr()->eq('updated_at',$q->createNamedParameter((string)$row['updated_at'])))->executeStatement();
        return $changed===1?$this->byId($id):null;
    }

    /** Save bounded page results and the cursor atomically; replay after crash is idempotent. */
    public function savePage(string $scanId,string $user,int $folderId,array $files,int $offset,array $counters,
        string $state,string $now,?string $errorCode=null): void {
        $this->db->beginTransaction();
        try {
            foreach($files as $file) {
                $keys=['user_id'=>$user,'scope_folder_id'=>$folderId,'file_id'=>$file['fileId']];
                $values=['mime_type'=>$file['mimeType'],'bytes'=>$file['bytes'],'mtime'=>$file['mtime'],'etag'=>$file['etag'],
                    'last_scan_id'=>$scanId,'indexed_at'=>$now];
                $this->db->setValues('apc_metadata_catalog',$keys,$values);
                $this->db->setValues('apc_catalog_seen',['scan_id'=>$scanId,'file_id'=>$file['fileId']],['seen_at'=>$now]);
            }
            $qCount=$this->db->getQueryBuilder();
            $qCount->select($qCount->func()->count('file_id'))->from('apc_catalog_seen')
                ->where($qCount->expr()->eq('scan_id',$qCount->createNamedParameter($scanId)));
            $countResult=$qCount->executeQuery();
            try{$counters['filesCatalogued']=(int)$countResult->fetchOne();}finally{$countResult->closeCursor();}
            $q=$this->db->getQueryBuilder();
            $q->update('apc_catalog_jobs')->set('offset_value',$q->createNamedParameter($offset,IQueryBuilder::PARAM_INT))
                ->set('counters_json',$q->createNamedParameter(json_encode($counters,JSON_THROW_ON_ERROR)))
                ->set('state',$q->createNamedParameter($state))->set('updated_at',$q->createNamedParameter($now))
                ->set('completed_at',$q->createNamedParameter($state==='completed'||$state==='failed'?$now:null))
                ->set('active_key',$q->createNamedParameter($state==='completed'||$state==='failed'?null:self::activeKey($user,$folderId),$state==='completed'||$state==='failed'?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_STR))
                ->set('lease_until',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL))
                ->set('error_code',$q->createNamedParameter($errorCode,$errorCode===null?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_STR))
                ->where($q->expr()->eq('scan_id',$q->createNamedParameter($scanId)))->executeStatement();
            $this->db->commit();
        } catch(\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    public function restart(string $id,string $etag,int $restartCount,array $counters,string $now): void {
        $job=$this->byId($id);
        if($job===null)return;
        $this->db->beginTransaction();
        try {
            // Discard entries from the superseded enumeration pass so removals cannot survive a restart.
            $seen=$this->db->getQueryBuilder();
            $seen->delete('apc_catalog_seen')->where($seen->expr()->eq('scan_id',$seen->createNamedParameter($id)))->executeStatement();
            $catalog=$this->db->getQueryBuilder();
            $catalog->delete('apc_metadata_catalog')->where(
                $catalog->expr()->eq('user_id',$catalog->createNamedParameter((string)$job['user_id'])),
                $catalog->expr()->eq('scope_folder_id',$catalog->createNamedParameter((int)$job['folder_id'],IQueryBuilder::PARAM_INT)),
                $catalog->expr()->eq('last_scan_id',$catalog->createNamedParameter($id)))->executeStatement();
            $q=$this->db->getQueryBuilder();
            $q->update('apc_catalog_jobs')->set('offset_value',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->set('folder_etag',$q->createNamedParameter($etag))->set('etag_restarts',$q->createNamedParameter($restartCount,IQueryBuilder::PARAM_INT))
                ->set('counters_json',$q->createNamedParameter(json_encode($counters,JSON_THROW_ON_ERROR)))
                ->set('state',$q->createNamedParameter('queued'))->set('updated_at',$q->createNamedParameter($now))
                ->set('lease_until',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL))
                ->where($q->expr()->eq('scan_id',$q->createNamedParameter($id)))->executeStatement();
            $this->db->commit();
        } catch(\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    public function findRecoverable(string $now,int $limit=50): array {
        $q=$this->db->getQueryBuilder();
        $q->select('scan_id')->from('apc_catalog_jobs')->where($q->expr()->orX(
            $q->expr()->eq('state',$q->createNamedParameter('queued')),
            $q->expr()->andX($q->expr()->eq('state',$q->createNamedParameter('running')),$q->expr()->lt('lease_until',$q->createNamedParameter($now)))
        ))->orderBy('updated_at','ASC')->setMaxResults(max(1,min(100,$limit)));
        $r=$q->executeQuery();try{return array_column($r->fetchAllAssociative(),'scan_id');}finally{$r->closeCursor();}
    }

    private function byId(string $id): ?array {
        $q=$this->db->getQueryBuilder();$q->select('*')->from('apc_catalog_jobs')->where($q->expr()->eq('scan_id',$q->createNamedParameter($id)));
        $r=$q->executeQuery();try{return $r->fetch()?:null;}finally{$r->closeCursor();}
    }
}
