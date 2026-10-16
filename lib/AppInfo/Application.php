<?php
declare(strict_types=1);
namespace OCA\ApplePhotosConnector\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCA\ApplePhotosConnector\BackgroundJob\MetadataCatalogRecoveryJob;

class Application extends App implements IBootstrap {
    public const APP_ID = 'apple_photos_connector';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
    }

    public function boot(IBootContext $context): void {
        $jobList = $context->getAppContainer()->get(IJobList::class);
        if (!$jobList->has(MetadataCatalogRecoveryJob::class, null)) {
            $jobList->add(MetadataCatalogRecoveryJob::class);
        }
    }
}
