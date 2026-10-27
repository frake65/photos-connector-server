<?php
declare(strict_types=1);
namespace OCA\ApplePhotosConnector\Service;

use OCA\ApplePhotosConnector\BackgroundJob\MetadataCatalogWorkerJob;
use OCA\ApplePhotosConnector\Db\MetadataCatalogRepository;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/** Metadata-only, resumable catalogue over an explicitly selected user-visible folder. */
final class MetadataCatalogService {
    public const MAX_BATCH_SIZE=100;
    private const LEASE_SECONDS=900;
    private const MAX_ETAG_RESTARTS=3;
    private const MAX_ATTEMPTS=8;
    private const SUPPORTED_MIME_TYPES=[
        'image/jpeg','image/png','image/heic','image/heif','image/gif','image/tiff','image/webp','image/bmp',
        'video/quicktime','video/mp4','video/x-m4v','video/mpeg','video/3gpp','video/3gpp2','video/x-msvideo','video/x-matroska',
    ];

    public function __construct(private MetadataCatalogRepository $jobs,private IRootFolder $root,
        private IJobList $jobList) {}

    /** Start only for a folder ID currently visible under this user's Files root. */
    public function start(string $user,int $folderId,int $batchSize=50): array {
        if($user===''||$folderId<1)throw new \InvalidArgumentException('invalid_folder');
        $folder=$this->resolveFolder($user,$folderId);
        if($folder===null||!$folder->isReadable())throw new \DomainException('folder_unavailable');
        $existing=$this->jobs->active($user,$folderId);
        if($existing!==null)return $this->present($existing);
        $batchSize=max(1,min(self::MAX_BATCH_SIZE,$batchSize));
        $id=self::uuid();$now=self::now();
        try {
            $this->jobs->create($id,$user,$folderId,MetadataCatalogRepository::activeKey($user,$folderId),
                (string)$folder->getEtag(),$now);
        } catch(\Throwable $e) {
            // Unique active scope is authoritative under concurrent starts; never create duplicate work.
            $existing=$this->jobs->active($user,$folderId);
            if($existing!==null)return $this->present($existing);
            throw $e;
        }
        $this->queue($id,$batchSize);
        return $this->get($user,$id)??throw new \RuntimeException('catalog_job_missing');
    }

    public function get(string $user,string $id): ?array { $row=$this->jobs->get($user,$id);return $row===null?null:$this->present($row); }

    /** One bounded search page per queued invocation; Nextcloud may expand rows into mount aliases. */
    public function process(string $id,int $batchSize=50): void {
        $batchStartedNs=hrtime(true);$now=self::now();$job=$this->jobs->claim($id,$now,self::after(self::LEASE_SECONDS));if($job===null)return;
        $user=(string)$job['user_id'];$folderId=(int)$job['folder_id'];
        $counters=json_decode((string)$job['counters_json'],true)?:MetadataCatalogRepository::emptyCounters();
        if((int)$job['attempts']>self::MAX_ATTEMPTS){$this->fail($job,'worker_attempt_limit',$counters,$batchStartedNs);return;}
        try {
            $folder=$this->resolveFolder($user,$folderId);
            if($folder===null||!$folder->isReadable()){$this->fail($job,'folder_unavailable',$counters,$batchStartedNs);return;}
            $currentFolderEtag=(string)$folder->getEtag();
            $recordedFolderEtag=(string)($job['folder_etag']??'');
            if($recordedFolderEtag!==''&&$currentFolderEtag!==$recordedFolderEtag){
                $restarts=(int)$job['etag_restarts']+1;
                if($restarts>self::MAX_ETAG_RESTARTS){$counters['etagRestarts']=$restarts;$counters['incomplete']=true;$this->finish($job,'incomplete',$counters,'folder_changed_too_often',$batchStartedNs);return;}
                $counters=$this->restartCounters($restarts);
                if($this->jobs->restart($id,$currentFolderEtag,$restarts,$counters,self::now(),(int)$job['lease_generation'],$batchStartedNs))$this->queue($id,$batchSize);return;
            }

            $limit=max(1,min(self::MAX_BATCH_SIZE,$batchSize));$offset=max(0,(int)$job['offset_value']);
            $nodes=$folder->search(new CatalogSearchQuery($limit,$offset));
            if(!is_array($nodes))throw new \UnexpectedValueException('search_page_invalid');
            $files=[];$processedFileIds=[];
            foreach($nodes as $node){
                if(!$node instanceof File)continue;
                try {
                    if(!$folder->isSubNode($node)||!$node->isReadable()){$counters['inaccessible']++;$counters['incomplete']=true;continue;}
                    $fileId=(int)$node->getId();
                    if(isset($processedFileIds[$fileId]))continue;
                    $before=['mimeType'=>(string)$node->getMimeType(),'bytes'=>(int)$node->getSize(),
                        'mtime'=>(int)$node->getMTime(),'etag'=>(string)$node->getEtag()];
                    if(!in_array(strtolower($before['mimeType']),self::SUPPORTED_MIME_TYPES,true)){$processedFileIds[$fileId]=true;$counters['unsupported']++;continue;}
                    // Re-read public node metadata before persisting; changed entries are omitted and flagged.
                    $after=['mimeType'=>(string)$node->getMimeType(),'bytes'=>(int)$node->getSize(),
                        'mtime'=>(int)$node->getMTime(),'etag'=>(string)$node->getEtag()];
                    if($before!==$after||!$folder->isSubNode($node)){$processedFileIds[$fileId]=true;$counters['changedDuringScan']++;$counters['incomplete']=true;continue;}
                    $files[]=['fileId'=>$fileId,'mimeType'=>$before['mimeType'],'bytes'=>$before['bytes'],
                        'mtime'=>$before['mtime'],'etag'=>$before['etag']];
                    $processedFileIds[$fileId]=true;
                } catch(\Throwable){$counters['inaccessible']++;$counters['incomplete']=true;}
            }
            $afterFolderEtag=(string)$folder->getEtag();
            $counters['pages']++;$counters['lastBatchItems']=count($nodes);
            // PHP exposes a process-wide high-water mark, not a per-batch peak. Label it honestly.
            $counters['processPeakMemoryBytes']=max((int)$counters['processPeakMemoryBytes'],memory_get_peak_usage(true));
            // Folder::search can return directories as well as files. Keep this explicitly a Node count.
            $counters['nodesVisited']+=count($nodes);
            // Nextcloud 35 limits cache rows before mapping/filtering them to visible Nodes.
            // In particular, the selected folder itself can consume one row and then be removed,
            // so a full query page may contain fewer Nodes than requested. Advance by the requested
            // search rows, never by the visible Node count (or aliases could also distort it).
            $newOffset=$offset+(count($nodes)>0?$limit:0);$changedDuringPage=$afterFolderEtag!==$currentFolderEtag;
            $restarts=(int)$job['etag_restarts'];
            if($changedDuringPage){
                $restarts++;
                if($restarts<=self::MAX_ETAG_RESTARTS)$counters=$this->restartCounters($restarts);
                else $counters['etagRestarts']=$restarts;
            }
            $tooManyChanges=$changedDuringPage&&$restarts>self::MAX_ETAG_RESTARTS;
            if($tooManyChanges)$counters['incomplete']=true;
            // A short page is not an end signal: Nextcloud can filter a row after applying LIMIT.
            // An empty visible result is the only verified end condition for the supported API.
            $complete=count($nodes)===0&&!$changedDuringPage;
            $state=$tooManyChanges?'incomplete':($complete?(!empty($counters['incomplete'])?'incomplete':'completed'):'queued');
            if($changedDuringPage&&!$tooManyChanges){
                if($this->jobs->restart($id,$afterFolderEtag,$restarts,$counters,self::now(),(int)$job['lease_generation'],$batchStartedNs))$this->queue($id,$limit);
            } elseif($this->jobs->savePage($id,$user,$folderId,$files,$newOffset,$counters,$state,self::now(),
                (int)$job['lease_generation'],$batchStartedNs,$tooManyChanges?'folder_changed_too_often':null)&&!$complete&&!$tooManyChanges)$this->queue($id,$limit);
        }catch(\Throwable){$this->fail($job,'catalog_scan_failed',$counters,$batchStartedNs);}
    }

    public function recover(): void { foreach($this->jobs->findRecoverable(self::now()) as $id)$this->queue((string)$id,50); }

    private function resolveFolder(string $user,int $folderId): ?Folder {
        try{$nodes=$this->root->getUserFolder($user)->getById($folderId);}catch(\Throwable){return null;}
        if(!is_array($nodes))$nodes=$nodes===null?[]:[$nodes];
        foreach($nodes as $node)if($node instanceof Folder){try{if($node->isReadable())return $node;}catch(\Throwable){}}
        return null;
    }
    private function queue(string $id,int $batchSize): void { $arg=['scanId'=>$id,'batchSize'=>max(1,min(self::MAX_BATCH_SIZE,$batchSize))];if(!$this->jobList->has(MetadataCatalogWorkerJob::class,$arg))$this->jobList->add(MetadataCatalogWorkerJob::class,$arg); }
    private function fail(array $job,string $code,array $counters,int $batchStartedNs): void { $this->finish($job,'failed',$counters,$code,$batchStartedNs); }
    private function finish(array $job,string $state,array $counters,?string $error,int $batchStartedNs): void {
        $this->jobs->savePage((string)$job['scan_id'],(string)$job['user_id'],(int)$job['folder_id'],[],(int)$job['offset_value'],
            $counters,$state,self::now(),(int)$job['lease_generation'],$batchStartedNs,$error);
    }
    private function restartCounters(int $restarts): array {
        $counters=MetadataCatalogRepository::emptyCounters();$counters['etagRestarts']=$restarts;return $counters;
    }
    private function present(array $row): array { return ['scanId'=>(string)$row['scan_id'],'folderId'=>(int)$row['folder_id'],
        'state'=>(string)$row['state'],'offset'=>(int)$row['offset_value'],'createdAt'=>(string)$row['created_at'],
        'updatedAt'=>(string)$row['updated_at'],'startedAt'=>$row['started_at'],'completedAt'=>$row['completed_at'],
        'counters'=>json_decode((string)$row['counters_json'],true)?:MetadataCatalogRepository::emptyCounters(),
        'errorCode'=>$row['error_code']]; }
    private static function now(): string{return gmdate('Y-m-d\TH:i:s\Z');}
    private static function after(int $seconds): string{return gmdate('Y-m-d\TH:i:s\Z',time()+$seconds);}
    private static function uuid(): string{$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
}
