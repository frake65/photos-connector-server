<?php
declare(strict_types=1);
namespace OCA\ApplePhotosConnector\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add monotonic fencing generations to the metadata catalog worker lease. */
final class Version009300Date20261027000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if($schema->hasTable('apc_catalog_jobs')){
            $jobs=$schema->getTable('apc_catalog_jobs');
            if(!$jobs->hasColumn('lease_generation')){
                $jobs->addColumn('lease_generation',Types::BIGINT,['notnull'=>true,'default'=>0]);
            }
        }
        return $schema;
    }
}
