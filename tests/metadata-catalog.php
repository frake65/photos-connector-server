<?php
declare(strict_types=1);

final class MetadataCatalogTestFile implements OCP\Files\File,OCP\Files\FileInfo {
    public int $etagReads=0;public int $contentReads=0;
    public function __construct(public int $id,public string $mime='image/jpeg',public int $bytes=123,
        public int $mtime=100,public string $etag='etag-1',public bool $readable=true,public bool $changeOnSecondEtagRead=false){}
    public function getId(): int{return $this->id;}
    public function getMimeType(): string{return $this->mime;}
    public function getSize(): int{return $this->bytes;}
    public function getMTime(): int{return $this->mtime;}
    public function getEtag(): string {
        $this->etagReads++;
        if($this->changeOnSecondEtagRead&&$this->etagReads===2){$this->mtime++;$this->etag='etag-mutated';}
        return $this->etag;
    }
    public function isReadable(): bool{return $this->readable;}
    public function fopen(string $mode): mixed{$this->contentReads++;throw new LogicException('Metadata catalog must not read file contents');}
}

final class MetadataCatalogTestFolder implements OCP\Files\Folder,OCP\Files\FileInfo {
    public int $maxReturned=0;public int $searchCalls=0;public bool $readable=true;public bool $duplicateAliases=false;
    public function __construct(public int $id,public array $children=[],public string $etag='folder-1'){}
    public function getId(): int{return $this->id;}
    public function getEtag(): string{return $this->etag;}
    public function isReadable(): bool{return $this->readable;}
    public function isSubNode(object $node): bool {foreach($this->allNodes() as $candidate)if($candidate===$node)return true;return false;}
    public function search(OCP\Files\Search\ISearchQuery $query): array {
        $this->searchCalls++;$this->assertAllNamesQuery($query);
        $all=$this->allNodes();usort($all,static fn(object $a,object $b):int=>$a->getId()<=>$b->getId());
        $page=array_slice($all,$query->getOffset(),$query->getLimit());$this->maxReturned=max($this->maxReturned,count($page));
        return $this->duplicateAliases?array_merge($page,$page):$page;
    }
    private function assertAllNamesQuery(OCP\Files\Search\ISearchQuery $query): void {
        $op=$query->getSearchOperation();
        if($query->getLimit()>100||$op->getField()!=='name'||$op->getValue()!=='%'
            ||count($query->getOrder())!==1||$query->getOrder()[0]->getField()!=='fileid')
            throw new LogicException('Search query must be bounded, match all names, and sort by file ID');
    }
    /** Fixture models the public folder-scoped recursive search result, never the implementation under test. */
    private function allNodes(): array {
        $nodes=[];foreach($this->children as $child){$nodes[]=$child;if($child instanceof self)array_push($nodes,...$child->allNodes());}return $nodes;
    }
    public function getById(int $id): array{$nodes=[];if($this->id===$id)$nodes[]=$this;foreach($this->allNodes() as $node)if($node->getId()===$id)$nodes[]=$node;return $nodes;}
}

final class MetadataCatalogTestRoot implements OCP\Files\IRootFolder {
    public function __construct(public array $users){}
    public function getUserFolder(string $user): MetadataCatalogTestFolder {if(!isset($this->users[$user]))throw new RuntimeException('unavailable user');return $this->users[$user];}
}

final class MetadataCatalogTestJobList implements OCP\BackgroundJob\IJobList {
    public array $jobs=[];
    public function add(string $jobClass,mixed $argument=null):void{$this->jobs[]=[$jobClass,$argument];}
    public function has(string $jobClass,mixed $argument):bool{foreach($this->jobs as $job)if($job==[$jobClass,$argument])return true;return false;}
}

function metadataCatalogScenarios(TestHarness\Connection $db,PDO $pdo): void {
    $repository=new OCA\ApplePhotosConnector\Db\MetadataCatalogRepository($db);
    $jobList=new MetadataCatalogTestJobList();
    $nestedImage=new MetadataCatalogTestFile(7002);
    $nestedVideo=new MetadataCatalogTestFile(7004,'video/mp4',2048,101,'video-etag');
    $unsupported=new MetadataCatalogTestFile(7005,'application/pdf');
    $deepFolder=new MetadataCatalogTestFolder(7003,[$nestedVideo]);
    $selected=new MetadataCatalogTestFolder(7001,[$nestedImage,$deepFolder,$unsupported]);
    $rootA=new MetadataCatalogTestFolder(1,[$selected]);
    $otherSelected=new MetadataCatalogTestFolder(7001,[new MetadataCatalogTestFile(8001)]);
    $rootB=new MetadataCatalogTestFolder(2,[$otherSelected]);
    $root=new MetadataCatalogTestRoot(['alice'=>$rootA,'bob'=>$rootB]);
    $service=new OCA\ApplePhotosConnector\Service\MetadataCatalogService($repository,$root,$jobList);
    $started=$service->start('alice',7001,2);$duplicate=$service->start('alice',7001,2);
    check($started['scanId']===$duplicate['scanId']&&count($jobList->jobs)===1,
        'MC1: parallel duplicate starts for one user/folder return the existing active job');
    $otherUser=$service->start('bob',7001,2);
    check($otherUser['scanId']!==$started['scanId'],'MC2: same folder ID in another user scope gets an isolated job');
    $service->process($started['scanId'],2);
    $progress=$service->get('alice',$started['scanId']);
    check($progress['state']==='queued'&&$progress['offset']===2&&$progress['counters']['lastBatchItems']===2,
        'MC3: first public search page stores bounded persistent progress');
    // A fresh service object models a worker process restart; cursor and counters are read from SQLite.
    $restartedWorker=new OCA\ApplePhotosConnector\Service\MetadataCatalogService($repository,$root,$jobList);
    $restartedWorker->process($started['scanId'],2);$restartedWorker->process($started['scanId'],2);
    $complete=$restartedWorker->get('alice',$started['scanId']);
    check($complete['state']==='completed'&&$complete['counters']['filesCatalogued']===2
        &&$complete['counters']['unsupported']===1&&$complete['counters']['pages']===3,
        'MC4: resumed nested-folder traversal indexes only supported image/video metadata');
    $rows=$pdo->query("SELECT user_id,scope_folder_id,file_id,mime_type,bytes,mtime,etag FROM apc_metadata_catalog WHERE last_scan_id='".$started['scanId']."' ORDER BY file_id")->fetchAll(PDO::FETCH_ASSOC);
    check(count($rows)===2&&$rows[0]['file_id']===$nestedImage->id&&$rows[1]['mime_type']==='video/mp4'
        &&(int)$rows[1]['bytes']===2048&&$rows[1]['etag']==='video-etag'&&$nestedImage->contentReads===0&&$nestedVideo->contentReads===0,
        'MC5: catalog stores only file ID, MIME, size, mtime and ETag without opening content');
    check($repository->get('bob',$started['scanId'])===null,'MC6: scan status is scoped to its owner');

    $claimFolder=new MetadataCatalogTestFolder(7050,[new MetadataCatalogTestFile(7051)]);
    $root->users['claim']=new MetadataCatalogTestFolder(9,[$claimFolder]);
    $claimScan=$service->start('claim',7050);
    $claimNow=gmdate('Y-m-d\TH:i:s\Z');$leaseUntil=gmdate('Y-m-d\TH:i:s\Z',time()+900);
    check($repository->claim($claimScan['scanId'],$claimNow,$leaseUntil)!==null
        &&$repository->claim($claimScan['scanId'],$claimNow,$leaseUntil)===null,
        'MC6a: only one worker can claim an active metadata-catalog job');

    $lockedFolder=new MetadataCatalogTestFolder(7100,[new MetadataCatalogTestFile(7101)]);$lockedFolder->readable=false;
    $root->users['blocked']=new MetadataCatalogTestFolder(3,[$lockedFolder]);
    try{$service->start('blocked',7100);check(false,'MC7: inaccessible folders must not start');}
    catch(DomainException){check(true,'MC7: inaccessible folders are rejected before a job is created');}

    $mutableFiles=[];for($i=0;$i<1250;$i++)$mutableFiles[]=new MetadataCatalogTestFile(20000+$i);
    $largeFolder=new MetadataCatalogTestFolder(7200,$mutableFiles);$root->users['large']=new MetadataCatalogTestFolder(4,[$largeFolder]);
    $large=$service->start('large',7200,100);$service->process($large['scanId'],100);
    $firstPage=$service->get('large',$large['scanId']);
    check($firstPage['state']==='queued'&&$firstPage['offset']===100&&$largeFolder->maxReturned===100
        &&$firstPage['counters']['lastBatchMilliseconds']>=0&&$firstPage['counters']['processPeakMemoryBytes']>0,
        'MC8: large flat folder returns at most one 100-node page and records runtime/memory measurements');
    for($i=0;$i<20&&$service->get('large',$large['scanId'])['state']==='queued';$i++)$service->process($large['scanId'],100);
    $largeComplete=$service->get('large',$large['scanId']);
    check($largeComplete['state']==='completed'&&$largeComplete['counters']['filesCatalogued']===1250
        &&$largeFolder->maxReturned<=100&&$largeComplete['counters']['pages']===13,
        'MC9: 1,250-entry flat directory completes through bounded, persistent pages');
    printf("METRIC metadata-catalog synthetic-flat: nodes=1250 pages=%d maxPage=%d maxBatchMs=%d processPeakBytes=%d\n",
        $largeComplete['counters']['pages'],$largeFolder->maxReturned,$largeComplete['counters']['maxBatchMilliseconds'],
        $largeComplete['counters']['processPeakMemoryBytes']);

    $aliasFolder=new MetadataCatalogTestFolder(7250,array_map(static fn(int $id)=>new MetadataCatalogTestFile($id),range(7251,7253)));
    $aliasFolder->duplicateAliases=true;$root->users['aliases']=new MetadataCatalogTestFolder(8,[$aliasFolder]);
    $aliasScan=$service->start('aliases',7250,2);
    for($i=0;$i<5&&$service->get('aliases',$aliasScan['scanId'])['state']==='queued';$i++)$service->process($aliasScan['scanId'],2);
    $aliasResult=$service->get('aliases',$aliasScan['scanId']);
    check($aliasResult['state']==='completed'&&$aliasResult['counters']['filesCatalogued']===3
        &&$aliasResult['counters']['nodesVisited']===6&&$aliasFolder->maxReturned===2,
        'MC9a: mounted aliases can expand returned Nodes while cursor and catalog remain deduplicated by file ID');

    $changingFile=new MetadataCatalogTestFile(7301,'image/jpeg',50,200,'before',true,true);
    $changingFolder=new MetadataCatalogTestFolder(7300,[$changingFile]);$root->users['change']=new MetadataCatalogTestFolder(5,[$changingFolder]);
    $changing=$service->start('change',7300,10);$service->process($changing['scanId'],10);
    $changedResult=$service->get('change',$changing['scanId']);
    check($changedResult['state']==='completed'&&$changedResult['counters']['changedDuringScan']===1
        &&$changedResult['counters']['incomplete']===true
        &&$pdo->query("SELECT COUNT(*) FROM apc_metadata_catalog WHERE last_scan_id='".$changing['scanId']."'")->fetchColumn()==0,
        'MC10: file metadata changing during observation is omitted and marks the scan incomplete');

    $restartFolder=new MetadataCatalogTestFolder(7400,array_map(static fn(int $id)=>new MetadataCatalogTestFile($id),range(7401,7405)));
    $root->users['restart']=new MetadataCatalogTestFolder(6,[$restartFolder]);
    $restartScan=$service->start('restart',7400,2);$service->process($restartScan['scanId'],2);
    array_shift($restartFolder->children);$restartFolder->children[]=new MetadataCatalogTestFile(7406);$restartFolder->etag='folder-2';
    $service->process($restartScan['scanId'],2);
    $afterChange=$service->get('restart',$restartScan['scanId']);
    check($afterChange['state']==='queued'&&$afterChange['offset']===0&&$afterChange['counters']['etagRestarts']===1,
        'MC11: folder changes between batches restart enumeration from a consistent beginning');
    for($i=0;$i<5&&$service->get('restart',$restartScan['scanId'])['state']==='queued';$i++)$service->process($restartScan['scanId'],2);
    $restartDone=$service->get('restart',$restartScan['scanId']);
    $currentScanFiles=$pdo->query("SELECT file_id FROM apc_metadata_catalog WHERE last_scan_id='".$restartScan['scanId']."' ORDER BY file_id")->fetchAll(PDO::FETCH_COLUMN);
    check($restartDone['state']==='completed'&&$restartDone['counters']['filesCatalogued']===5
        &&array_map('intval',$currentScanFiles)===[7402,7403,7404,7405,7406]
        &&$restartDone['counters']['nodesVisited']===5,
        'MC12: folder-change restart discards the old pass and catalogs only the new unique contents');
}
