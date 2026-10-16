<?php
declare(strict_types=1);

$jobList = \OC::$server->getJobList();
$recoveryJob = \OCA\ApplePhotosConnector\BackgroundJob\MetadataCatalogRecoveryJob::class;
if (!$jobList->has($recoveryJob, null)) {
    $jobList->add($recoveryJob);
}
