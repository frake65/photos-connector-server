<?php
declare(strict_types=1);
namespace OCA\ApplePhotosConnector\BackgroundJob;

use OCA\ApplePhotosConnector\Service\MetadataCatalogService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Server;

/** Requeues catalogue jobs left queued or with an expired lease after a worker interruption. */
final class MetadataCatalogRecoveryJob extends TimedJob {
    public function __construct(ITimeFactory $time){parent::__construct($time);$this->setInterval(300);}
    protected function run($argument): void {Server::get(MetadataCatalogService::class)->recover();}
}
