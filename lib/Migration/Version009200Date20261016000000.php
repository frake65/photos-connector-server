<?php
declare(strict_types=1);
namespace OCA\ApplePhotosConnector\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Additive, metadata-only catalogue and durable background-run state. */
final class Version009200Date20261016000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if(!$schema->hasTable('apc_metadata_catalog')){
            $table=$schema->createTable('apc_metadata_catalog');
            $table->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);
            $table->addColumn('user_id',Types::STRING,['length'=>64,'notnull'=>true]);
            $table->addColumn('scope_folder_id',Types::BIGINT,['notnull'=>true]);
            $table->addColumn('file_id',Types::BIGINT,['notnull'=>true]);
            $table->addColumn('mime_type',Types::STRING,['length'=>255,'notnull'=>true]);
            $table->addColumn('bytes',Types::BIGINT,['notnull'=>true]);
            $table->addColumn('mtime',Types::BIGINT,['notnull'=>true]);
            $table->addColumn('etag',Types::STRING,['length'=>255,'notnull'=>true]);
            $table->addColumn('last_scan_id',Types::STRING,['length'=>36,'notnull'=>true]);
            $table->addColumn('indexed_at',Types::STRING,['length'=>32,'notnull'=>true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id','scope_folder_id','file_id'],'apc_catalog_node_scope');
            $table->addIndex(['user_id','scope_folder_id','last_scan_id'],'apc_catalog_scope_scan');
        }
        if(!$schema->hasTable('apc_catalog_seen')){
            $table=$schema->createTable('apc_catalog_seen');
            $table->addColumn('scan_id',Types::STRING,['length'=>36,'notnull'=>true]);
            $table->addColumn('file_id',Types::BIGINT,['notnull'=>true]);
            $table->addColumn('seen_at',Types::STRING,['length'=>32,'notnull'=>true]);
            $table->setPrimaryKey(['scan_id','file_id']);
        }
        if(!$schema->hasTable('apc_catalog_jobs')){
            $table=$schema->createTable('apc_catalog_jobs');
            $table->addColumn('scan_id',Types::STRING,['length'=>36,'notnull'=>true]);
            $table->addColumn('user_id',Types::STRING,['length'=>64,'notnull'=>true]);
            $table->addColumn('folder_id',Types::BIGINT,['notnull'=>true]);
            $table->addColumn('active_key',Types::STRING,['length'=>64,'notnull'=>false]);
            $table->addColumn('state',Types::STRING,['length'=>16,'notnull'=>true]);
            $table->addColumn('offset_value',Types::BIGINT,['notnull'=>true,'default'=>0]);
            $table->addColumn('folder_etag',Types::STRING,['length'=>255,'notnull'=>true]);
            $table->addColumn('etag_restarts',Types::INTEGER,['notnull'=>true,'default'=>0]);
            $table->addColumn('counters_json',Types::TEXT,['notnull'=>true]);
            $table->addColumn('created_at',Types::STRING,['length'=>32,'notnull'=>true]);
            $table->addColumn('updated_at',Types::STRING,['length'=>32,'notnull'=>true]);
            $table->addColumn('started_at',Types::STRING,['length'=>32,'notnull'=>false]);
            $table->addColumn('completed_at',Types::STRING,['length'=>32,'notnull'=>false]);
            $table->addColumn('lease_until',Types::STRING,['length'=>32,'notnull'=>false]);
            $table->addColumn('attempts',Types::INTEGER,['notnull'=>true,'default'=>0]);
            $table->addColumn('error_code',Types::STRING,['length'=>64,'notnull'=>false]);
            $table->setPrimaryKey(['scan_id']);
            $table->addUniqueIndex(['user_id','folder_id','active_key'],'apc_catalog_job_active');
            $table->addIndex(['state','lease_until'],'apc_catalog_job_lease');
        }
        return $schema;
    }
}
