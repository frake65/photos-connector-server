<?php
declare(strict_types=1);
namespace OCA\ApplePhotosConnector\Service;

use OCP\Files\FileInfo;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOrder;
use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchQuery;

/** Public Files-Node search query. Nextcloud applies LIMIT/OFFSET before returning Nodes. */
final class CatalogSearchQuery implements ISearchQuery {
    public function __construct(private int $limit, private int $offset) {
        $this->limit = max(1, min(MetadataCatalogService::MAX_BATCH_SIZE, $limit));
        $this->offset = max(0, $offset);
    }

    public function getSearchOperation(): ISearchOperator { return new CatalogMatchAllNames(); }
    public function getLimit(): int { return $this->limit; }
    public function getOffset(): int { return $this->offset; }
    public function getOrder(): array { return [new CatalogFileIdOrder()]; }
    public function getUser(): ?\OCP\IUser { return null; }
    public function limitToHome(): bool { return false; }
    public function getSelectFields(): array { return []; }
}

/** LIKE '%' enumerates entries in the selected Folder's scope via the public Folder::search API. */
final class CatalogMatchAllNames implements ISearchComparison {
    public function getQueryHint(string $name, $default): mixed { return $default; }
    public function setQueryHint(string $name, $value): void {}
    public function getType(): string { return self::COMPARE_LIKE; }
    public function getField(): string { return 'name'; }
    public function getExtra(): string { return ''; }
    public function getValue(): string { return '%'; }
}

/** Deterministic ordering makes the persistent offset reproducible while the folder is unchanged. */
final class CatalogFileIdOrder implements ISearchOrder {
    public function getDirection(): string { return self::DIRECTION_ASCENDING; }
    public function getField(): string { return 'fileid'; }
    public function getExtra(): string { return ''; }
    public function sortFileInfo(FileInfo $a, FileInfo $b): int { return $a->getId() <=> $b->getId(); }
}
