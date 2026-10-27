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
    public bool $filterFolderAfterLimit=false;public array $queryOffsets=[];public array $queryLimits=[];public bool $searchFails=false;public int $searchDelayMs=0;
    public function __construct(public int $id,public array $children=[],public string $etag='folder-1'){}
    public function getId(): int{return $this->id;}
    public function getEtag(): string{return $this->etag;}
    public function isReadable(): bool{return $this->readable;}
    public function isSubNode(object $node): bool {foreach($this->allNodes() as $candidate)if($candidate===$node)return true;return false;}
    public function search(OCP\Files\Search\ISearchQuery $query): array {
        if($this->searchDelayMs>0)usleep($this->searchDelayMs*1000);
        if($this->searchFails)throw new RuntimeException('synthetic_search_failure');
        $this->searchCalls++;$this->queryOffsets[]=$query->getOffset();$this->queryLimits[]=$query->getLimit();$this->assertAllNamesQuery($query);
        $all=$this->allNodes();if($this->filterFolderAfterLimit)$all[]=$this;
        usort($all,static fn(object $a,object $b):int=>$a->getId()<=>$b->getId());
        $page=array_slice($all,$query->getOffset(),$query->getLimit());
        // Nextcloud 35 can apply LIMIT before excluding the selected folder's own cache row.
        if($this->filterFolderAfterLimit)$page=array_values(array_filter($page,fn(object $node):bool=>$node!==$this));
        $this->maxReturned=max($this->maxReturned,count($page));
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
    $bootContext=new class($jobList) implements OCP\AppFramework\Bootstrap\IBootContext {
        private object $container;
        public function __construct(object $jobs) {
            $this->container=new class($jobs) {
                public function __construct(private object $jobs) {}
                public function get(string $service): object {
                    if($service!==OCP\BackgroundJob\IJobList::class)throw new LogicException('unexpected boot service');
                    return $this->jobs;
                }
            };
        }
        public function getAppContainer(): object{return $this->container;}
    };
    $application=new OCA\ApplePhotosConnector\AppInfo\Application();
    $application->boot($bootContext);$application->boot($bootContext);
    $recoveryClass=OCA\ApplePhotosConnector\BackgroundJob\MetadataCatalogRecoveryJob::class;
    $registeredRecovery=array_values(array_filter($jobList->jobs,static fn(array $job):bool=>$job[0]===$recoveryClass));
    $appInfo=file_get_contents(dirname(__DIR__).'/appinfo/info.xml');
    check(count($registeredRecovery)===1&&$registeredRecovery[0]===[$recoveryClass,null]
        &&str_contains($appInfo,'<job>OCA\\ApplePhotosConnector\\BackgroundJob\\MetadataCatalogRecoveryJob</job>')
        &&str_contains($appInfo,'<job>OCA\\ApplePhotosConnector\\BackgroundJob\\MetadataCatalogWorkerJob</job>')
        &&!is_file(dirname(__DIR__).'/appinfo/app.php'),
        'MC0: recovery and worker jobs use supported registration without appinfo/app.php');
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

    $staleFolder=new MetadataCatalogTestFolder(7060,[new MetadataCatalogTestFile(7061)]);
    $root->users['stale-worker']=new MetadataCatalogTestFolder(10,[$staleFolder]);
    $staleScan=$service->start('stale-worker',7060);$staleNow=gmdate('Y-m-d\\TH:i:s\\Z');
    $oldWorker=$repository->claim($staleScan['scanId'],$staleNow,gmdate('Y-m-d\\TH:i:s\\Z',time()+900));
    $pdo->prepare("UPDATE apc_catalog_jobs SET lease_until='2000-01-01T00:00:00Z' WHERE scan_id=?")->execute([$staleScan['scanId']]);
    $newNow=gmdate('Y-m-d\\TH:i:s\\Z');$newWorker=$repository->claim($staleScan['scanId'],$newNow,gmdate('Y-m-d\\TH:i:s\\Z',time()+900));
    $lateSave=$repository->savePage($staleScan['scanId'],'stale-worker',7060,
        [['fileId'=>7061,'mimeType'=>'image/jpeg','bytes'=>123,'mtime'=>100,'etag'=>'etag-1']],1,
        OCA\ApplePhotosConnector\Db\MetadataCatalogRepository::emptyCounters(),'queued',$newNow,
        (int)$oldWorker['lease_generation'],hrtime(true));
    $untouched=$pdo->prepare('SELECT state,offset_value,lease_generation FROM apc_catalog_jobs WHERE scan_id=?');
    $untouched->execute([$staleScan['scanId']]);$fencedJob=$untouched->fetch(PDO::FETCH_ASSOC);
    $staleRows=$pdo->prepare('SELECT COUNT(*) FROM apc_metadata_catalog WHERE last_scan_id=?');$staleRows->execute([$staleScan['scanId']]);
    check((int)$oldWorker['lease_generation']===1&&(int)$newWorker['lease_generation']===2&&!$lateSave
        &&(int)$staleRows->fetchColumn()===0&&$fencedJob['state']==='running'
        &&(int)$fencedJob['offset_value']===0&&(int)$fencedJob['lease_generation']===2,
        'MC6b: expired worker write rolls back after a newer lease generation takes ownership');
    check($repository->savePage($staleScan['scanId'],'stale-worker',7060,
        [['fileId'=>7061,'mimeType'=>'image/jpeg','bytes'=>123,'mtime'=>100,'etag'=>'etag-1']],1,
        OCA\ApplePhotosConnector\Db\MetadataCatalogRepository::emptyCounters(),'completed',$newNow,
        (int)$newWorker['lease_generation'],hrtime(true)),
        'MC6c: current worker may persist and complete after stale worker is fenced');

    $timedFolder=new MetadataCatalogTestFolder(7070,[new MetadataCatalogTestFile(7071)]);
    $timedFolder->searchDelayMs=15;$root->users['timed']=new MetadataCatalogTestFolder(11,[$timedFolder]);
    $timedScan=$service->start('timed',7070,10);$db->writeDelayMs=10;$service->process($timedScan['scanId'],10);$db->writeDelayMs=0;
    $timed=$service->get('timed',$timedScan['scanId']);
    check($timed['counters']['lastBatchMilliseconds']>=50&&$timed['counters']['maxBatchMilliseconds']>=50,
        'MC6d: batch timer includes delayed search, lease claim and catalog/status database writes');

    $brokenFolder=new MetadataCatalogTestFolder(7080);$brokenFolder->searchFails=true;
    $root->users['error']=new MetadataCatalogTestFolder(12,[$brokenFolder]);
    $errorScan=$service->start('error',7080);$service->process($errorScan['scanId']);
    check($service->get('error',$errorScan['scanId'])['state']==='failed'
        &&$service->get('error',$errorScan['scanId'])['errorCode']==='catalog_scan_failed',
        'MC6e: search failure is terminal failed, not completed or incomplete');

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
        &&$largeFolder->maxReturned<=100&&$largeComplete['counters']['pages']===14,
        'MC9: 1,250-entry flat directory completes through bounded, persistent pages');
    printf("METRIC metadata-catalog synthetic-flat: nodes=1250 pages=%d maxPage=%d maxBatchMs=%d processPeakBytes=%d\n",
        $largeComplete['counters']['pages'],$largeFolder->maxReturned,$largeComplete['counters']['maxBatchMilliseconds'],
        $largeComplete['counters']['processPeakMemoryBytes']);

    $filteredChildren=array_map(static fn(int $id)=>new MetadataCatalogTestFile($id),range(30001,31251));
    $filteredFolder=new MetadataCatalogTestFolder(30000,$filteredChildren);$filteredFolder->filterFolderAfterLimit=true;
    $root->users['filtered-limit']=new MetadataCatalogTestFolder(30,[$filteredFolder]);
    $filteredScan=$service->start('filtered-limit',30000,50);$service->process($filteredScan['scanId'],50);
    $shortFirstPage=$service->get('filtered-limit',$filteredScan['scanId']);
    check($shortFirstPage['state']==='queued'&&$shortFirstPage['offset']===50
        &&$shortFirstPage['counters']['lastBatchItems']===49&&$filteredFolder->queryOffsets===[0],
        'MC9b: a 49-node visible page after a 50-row query advances by requested rows and does not complete');
    for($i=0;$i<30&&$service->get('filtered-limit',$filteredScan['scanId'])['state']==='queued';$i++)
        $service->process($filteredScan['scanId'],50);
    $filteredDone=$service->get('filtered-limit',$filteredScan['scanId']);
    $filteredIds=$pdo->prepare('SELECT file_id FROM apc_metadata_catalog WHERE last_scan_id=? ORDER BY file_id');
    $filteredIds->execute([$filteredScan['scanId']]);$cataloguedIds=array_map('intval',$filteredIds->fetchAll(PDO::FETCH_COLUMN));
    check($filteredDone['state']==='completed'&&$filteredDone['counters']['filesCatalogued']===1251
        &&count($cataloguedIds)===1251&&$cataloguedIds===range(30001,31251)
        &&$filteredFolder->queryOffsets===range(0,1300,50)
        &&$filteredDone['counters']['pages']===27&&$filteredDone['counters']['incomplete']===false,
        'MC9c: filtered 1,251-file Nextcloud 35 page sequence catalogs all IDs and ends only on an empty result');
    $filteredAgain=$service->start('filtered-limit',30000,50);
    for($i=0;$i<30&&$service->get('filtered-limit',$filteredAgain['scanId'])['state']==='queued';$i++)
        $service->process($filteredAgain['scanId'],50);
    $repeatDone=$service->get('filtered-limit',$filteredAgain['scanId']);
    $repeatCounts=$pdo->prepare('SELECT COUNT(*),COUNT(DISTINCT file_id),SUM(CASE WHEN last_scan_id=? THEN 1 ELSE 0 END) FROM apc_metadata_catalog WHERE user_id=? AND scope_folder_id=?');
    $repeatCounts->execute([$filteredAgain['scanId'],'filtered-limit',30000]);$repeatRow=$repeatCounts->fetch(PDO::FETCH_NUM);
    check($repeatDone['state']==='completed'&&$repeatDone['counters']['filesCatalogued']===1251
        &&array_map('intval',$repeatRow)===[1251,1251,1251],
        'MC9f: a repeated PostgreSQL-safe catalog scan updates existing identities without duplicate rows');

    $smallFiltered=new MetadataCatalogTestFolder(31500,[new MetadataCatalogTestFile(31501)]);
    $smallFiltered->filterFolderAfterLimit=true;$root->users['small-filtered']=new MetadataCatalogTestFolder(31,[$smallFiltered]);
    $smallScan=$service->start('small-filtered',31500,50);
    for($i=0;$i<3&&$service->get('small-filtered',$smallScan['scanId'])['state']==='queued';$i++)$service->process($smallScan['scanId'],50);
    $smallDone=$service->get('small-filtered',$smallScan['scanId']);
    check($smallDone['state']==='completed'&&$smallDone['counters']['filesCatalogued']===1
        &&$smallFiltered->queryOffsets===[0,50],
        'MC9d: a non-empty folder smaller than one page is completed after the confirming empty query');

    $singleLimitFolder=new MetadataCatalogTestFolder(31700,[new MetadataCatalogTestFile(31701)]);
    $singleLimitFolder->filterFolderAfterLimit=true;$root->users['single-limit']=new MetadataCatalogTestFolder(33,[$singleLimitFolder]);
    $singleLimit=$service->start('single-limit',31700,1);
    $service->process($singleLimit['scanId'],1);
    $singleLimitFirst=$service->get('single-limit',$singleLimit['scanId']);
    $queuedWorkerArgs=array_values(array_filter($jobList->jobs,static fn(array $job):bool=>
        $job[0]===OCA\ApplePhotosConnector\BackgroundJob\MetadataCatalogWorkerJob::class
        &&($job[1]['scanId']??null)===$singleLimit['scanId']));
    check($singleLimitFirst['state']==='queued'&&$singleLimitFirst['offset']===2
        &&$singleLimitFirst['counters']['lastBatchItems']===1&&$singleLimitFolder->queryLimits===[2]
        &&count($queuedWorkerArgs)===1&&$queuedWorkerArgs[0][1]['batchSize']===2,
        'MC9g: a requested one-row page is clamped before Nextcloud filters the folder row, so a following file cannot look like end-of-scan');
    $service->process($singleLimit['scanId'],1);$singleLimitDone=$service->get('single-limit',$singleLimit['scanId']);
    check($singleLimitDone['state']==='completed'&&$singleLimitDone['counters']['filesCatalogued']===1
        &&$singleLimitFolder->queryOffsets===[0,2],
        'MC9h: the file behind a folder-filtered one-row boundary is persisted before the confirming empty page');

    $emptyFiltered=new MetadataCatalogTestFolder(31600);$emptyFiltered->filterFolderAfterLimit=true;
    $root->users['empty-filtered']=new MetadataCatalogTestFolder(32,[$emptyFiltered]);
    $emptyScan=$service->start('empty-filtered',31600,50);$service->process($emptyScan['scanId'],50);
    $emptyDone=$service->get('empty-filtered',$emptyScan['scanId']);
    check($emptyDone['state']==='completed'&&$emptyDone['counters']['filesCatalogued']===0
        &&$emptyFiltered->searchCalls===1&&$emptyDone['offset']===0,
        'MC9e: an empty folder terminates immediately without cursor drift or a queued loop');

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
    $changing=$service->start('change',7300,10);$service->process($changing['scanId'],10);$service->process($changing['scanId'],10);
    $changedResult=$service->get('change',$changing['scanId']);
    check($changedResult['state']==='incomplete'&&$changedResult['completedAt']!==null&&$changedResult['counters']['changedDuringScan']===1
        &&$changedResult['counters']['incomplete']===true
        &&$pdo->query("SELECT COUNT(*) FROM apc_metadata_catalog WHERE last_scan_id='".$changing['scanId']."'")->fetchColumn()==0,
        'MC10: file metadata changing during observation is omitted and marks the scan incomplete');
    $afterIncomplete=$service->start('change',7300,10);
    check($afterIncomplete['scanId']!==$changing['scanId']&&$afterIncomplete['state']==='queued',
        'MC10a: incomplete is terminal and a requested retry starts a new scan instead of relabeling it completed');

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
