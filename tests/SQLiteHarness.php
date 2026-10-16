<?php
declare(strict_types=1);
// Minimal OCP compatibility surface for standalone SQLite tests, NOT a Nextcloud runtime.
namespace OCP {
    interface IDBConnection {}
    interface IUser { public function getUID(): string; }
    interface IRequest {
        public function getHeader(string $name): string;
        public function getParam(string $name): mixed;
        public function getParams(): array;
    }
    interface IUserSession { public function getUser(): mixed; }
}
namespace OCP\AppFramework {
    class App { public function __construct(string $appName, array $urlParams = []) {} }
    class Controller {
        public function __construct(string $name, protected \OCP\IRequest $request) {}
    }
}
namespace OCP\AppFramework\Bootstrap {
    interface IRegistrationContext {}
    interface IBootContext {}
    interface IBootstrap {
        public function register(IRegistrationContext $context): void;
        public function boot(IBootContext $context): void;
    }
}
namespace OCP\App {
    interface IAppManager { public function getAppVersion(string $app): string; }
}
namespace OCP\AppFramework\Http {
    class JSONResponse {
        public function __construct(private mixed $data, private int $status = 200) {}
        public function getStatus(): int { return $this->status; }
        public function getData(): mixed { return $this->data; }
    }
}
namespace OCP\DB {
    interface ISchemaWrapper {}
    class Types {
        public const BOOLEAN = 'boolean', BIGINT = 'bigint', INTEGER = 'integer', STRING = 'string', TEXT = 'text';
    }
}
namespace OCP\DB\QueryBuilder {
    interface IQueryBuilder {
        public const PARAM_BOOL = \PDO::PARAM_BOOL, PARAM_NULL = \PDO::PARAM_NULL, PARAM_STR = \PDO::PARAM_STR, PARAM_INT = \PDO::PARAM_INT;
    }
}
namespace OCP\Migration {
    interface IOutput {}
    class SimpleMigrationStep {}
}
namespace OCP\Files {
    interface FileInfo { public function getId(): int; }
    interface IRootFolder {}
    interface File {}
    interface Folder {}
}
namespace OCP\Files\Search {
    interface ISearchOperator { public function getQueryHint(string $name, $default); public function setQueryHint(string $name, $value): void; }
    interface ISearchComparison extends ISearchOperator {
        public const COMPARE_LIKE = 'like';
        public function getType(): string;
        public function getField(): string;
        public function getExtra(): string;
        public function getValue(): string|int|bool|\DateTime|array;
    }
    interface ISearchOrder {
        public const DIRECTION_ASCENDING = 'asc';
        public function getDirection(): string;
        public function getField(): string;
        public function getExtra(): string;
        public function sortFileInfo(\OCP\Files\FileInfo $a, \OCP\Files\FileInfo $b): int;
    }
    interface ISearchQuery {
        public function getSearchOperation(): ISearchOperator;
        public function getLimit(): int;
        public function getOffset(): int;
        public function getOrder(): array;
        public function getUser(): ?\OCP\IUser;
        public function limitToHome(): bool;
        public function getSelectFields(): array;
    }
}
namespace OCP\BackgroundJob {
    interface IJobList {
        public function add(string $jobClass, mixed $argument = null): void;
        public function has(string $jobClass, mixed $argument): bool;
    }
}
namespace OCA\Photos\Album {
    class AlbumInfo {
        public function __construct(private int $id, private string $userId) {}
        public function getId(): int { return $this->id; }
        public function getUserId(): string { return $this->userId; }
    }
    class AlbumFile {}
    interface AlbumMapper {
        public function create(string $userId, string $name, string $location = '', ?string $filters = null): AlbumInfo;
        public function get(int $id): ?AlbumInfo;
        public function getForAlbumIdAndFileId(int $albumId, int $fileId): ?AlbumFile;
        public function addFile(int $albumId, int $fileId, string $owner): void;
    }
}
namespace OCP\Lock {
    interface ILockingProvider { public const LOCK_SHARED = 1; }
}
namespace TestHarness {
    class Connection implements \OCP\IDBConnection {
        public function __construct(public \PDO $pdo) {
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        public function beginTransaction(): void { $this->pdo->beginTransaction(); }
        public function commit(): void { $this->pdo->commit(); }
        public function rollBack(): void { $this->pdo->rollBack(); }
        public function inTransaction(): bool { return $this->pdo->inTransaction(); }
        public function lastInsertId(string $table): string { return $this->pdo->lastInsertId(); }
        public function getQueryBuilder(): Query { return new Query($this->pdo); }
        public function setValues(string $table, array $keys, array $values, array $preconditions = []): int {
            $where = $keys + $preconditions;
            $whereSql = implode(' AND ', array_map(static fn(string $column): string => $column . ' = ?', array_keys($where)));
            $select = $this->pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . $whereSql . ' LIMIT 1');
            $select->execute(array_values($where));
            if ($select->fetchColumn() !== false) {
                if ($values === []) { return 0; }
                $setSql = implode(',', array_map(static fn(string $column): string => $column . ' = ?', array_keys($values)));
                $update = $this->pdo->prepare('UPDATE ' . $table . ' SET ' . $setSql . ' WHERE ' . $whereSql);
                $update->execute(array_merge(array_values($values), array_values($where)));
                return 0;
            }
            $insertValues = $keys + $values;
            $columns = array_keys($insertValues);
            $insert = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
            $insert->execute(array_values($insertValues));
            return 1;
        }
    }
    class Query {
        private string $operation = '';
        private string $table = '';
        private array $parameters = [], $values = [], $where = [], $joins = [];
        private string $columns = '*', $order = '';
        public function __construct(private \PDO $pdo) {}
        public function select(string ...$columns): self { $this->operation = 'select'; $this->columns=implode(',', $columns) ?: '*'; return $this; }
        public function from(string $table, ?string $alias = null): self { $this->table = $table . ($alias ? ' ' . $alias : ''); return $this; }
        public function insert(string $table): self { $this->operation = 'insert'; $this->table = $table; return $this; }
        public function update(string $table): self { $this->operation = 'update'; $this->table = $table; return $this; }
        public function delete(string $table): self { $this->operation = 'delete'; $this->table = $table; return $this; }
        public function values(array $values): self { $this->values = $values; return $this; }
        public function set(string $key, string $value): self { $this->values[$key] = $value; return $this; }
        public function where(string ...$where): self { $this->where = $where; return $this; }
        public function orderBy(string $column, string $direction = 'ASC'): self { $this->order = " ORDER BY $column $direction"; return $this; }
        public function setMaxResults(int $limit): self { $this->order .= ' LIMIT ' . max(1, $limit); return $this; }
        public function expr(): self { return $this; }
        public function func(): object { return new class {
            public function count(string $column = '', string $alias = ''): string {
                $expression = 'COUNT(' . ($column === '' ? '*' : $column) . ')';
                return $alias === '' ? $expression : $expression . ' AS ' . $alias;
            }
        }; }
        public function orX(string ...$where): string { return '(' . implode(' OR ', $where) . ')'; }
        public function eq(string $key, string $parameter): string { return "$key = $parameter"; }
        public function isNotNull(string $key): string { return "$key IS NOT NULL"; }
        public function lt(string $key, string $parameter): string { return "$key < $parameter"; }
        // Match Nextcloud's IExpressionBuilder: column comparisons use eq('a.id', 'm.asset_id'); there is no col().
        public function innerJoin(string $fromAlias,string $joinTable,string $alias,string $condition): self { $this->joins[]="INNER JOIN $joinTable $alias ON $condition"; return $this; }
        public function createNamedParameter(mixed $value, mixed $type = null): string {
            $key = ':p' . count($this->parameters);
            $this->parameters[$key] = $value;
            return $key;
        }
        private function statement(): \PDOStatement {
            $sql = match ($this->operation) {
                'select' => 'SELECT ' . ($this->columns ?? '*') . ' FROM ' . $this->table . ($this->joins ? ' ' . implode(' ', $this->joins) : ''),
                'insert' => 'INSERT INTO ' . $this->table . ' (' . implode(',', array_keys($this->values)) . ') VALUES (' . implode(',', $this->values) . ')',
                'update' => 'UPDATE ' . $this->table . ' SET ' . implode(',', array_map(fn ($key) => "$key = " . $this->values[$key], array_keys($this->values))),
                'delete' => 'DELETE FROM ' . $this->table,
            };
            if ($this->where) { $sql .= ' WHERE ' . implode(' AND ', $this->where); }
            $sql .= $this->order;
            $statement = $this->pdo->prepare($sql);
            $statement->execute($this->parameters);
            return $statement;
        }
        public function executeStatement(): int { return $this->statement()->rowCount(); }
        public function executeQuery(): Result { return new Result($this->statement()); }
    }
    class Result {
        public function __construct(private \PDOStatement $statement) {}
        public function fetchAllAssociative(): array { return $this->statement->fetchAll(\PDO::FETCH_ASSOC); }
        public function fetchAll(): array { return $this->fetchAllAssociative(); }
        public function fetch(): mixed { return $this->statement->fetch(\PDO::FETCH_ASSOC); }
        public function fetchOne(): mixed { return $this->statement->fetchColumn(); }
        public function closeCursor(): void { $this->statement->closeCursor(); }
    }
    class Schema implements \OCP\DB\ISchemaWrapper {
        public array $tables = [];
        public function hasTable(string $name): bool { return isset($this->tables[$name]); }
        public function getTable(string $name): Table { return $this->tables[$name]; }
        public function createTable(string $name): Table { return $this->tables[$name] = new Table($name); }
        public function apply(\PDO $pdo): void { foreach ($this->tables as $table) { $table->apply($pdo); } }
    }
    class Table {
        private array $columns = [], $indices = [];
        public function __construct(private string $name) {}
        public function hasColumn(string $name): bool { return isset($this->columns[$name]); }
        public function addColumn(string $name, string $type, array $options): void {
            $this->columns[$name] = !empty($options['autoincrement']) ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
                : (in_array($type, ['bigint', 'boolean'], true) ? 'INTEGER' : 'TEXT') . (!empty($options['notnull']) ? ' NOT NULL' : '')
                    . (array_key_exists('default', $options) ? ' DEFAULT ' . (int)$options['default'] : '');
        }
        public function setPrimaryKey(array $columns): void {}
        public function addUniqueIndex(array $columns, string $name): void { $this->indices[] = [$columns, $name, true]; }
        public function addIndex(array $columns, string $name): void { $this->indices[] = [$columns, $name, false]; }
        public function apply(\PDO $pdo): void {
            $columns = array_map(fn ($key) => "$key " . $this->columns[$key], array_keys($this->columns));
            $pdo->exec('CREATE TABLE ' . $this->name . ' (' . implode(',', $columns) . ')');
            foreach ($this->indices as [$keys, $name, $unique]) {
                $pdo->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX $name ON {$this->name} (" . implode(',', $keys) . ')');
            }
        }
    }
}
