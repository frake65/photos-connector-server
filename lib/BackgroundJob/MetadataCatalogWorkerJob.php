<?php
declare(strict_types=1);
namespace OCA\ApplePhotosConnector\BackgroundJob;

use OCA\ApplePhotosConnector\Service\MetadataCatalogService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Server;

/** One bounded public Files-Node search page per invocation. */
final class MetadataCatalogWorkerJob extends QueuedJob {
    public function __construct(ITimeFactory $time){parent::__construct($time);$this->setAllowParallelRuns(true);}
    protected function run($argument): void {
        $id=is_array($argument)?($argument['scanId']??null):null;
        $size=is_array($argument)?($argument['batchSize']??50):50;
        if(!is_string($id)||!preg_match('/^[0-9a-f-]{36}$/iD',$id)||!is_int($size))return;
        Server::get(MetadataCatalogService::class)->process($id,$size);
    }
}
