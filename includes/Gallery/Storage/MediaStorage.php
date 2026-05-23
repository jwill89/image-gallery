<?php

namespace Gallery\Storage;

use PDO;
use Gallery\Core\DatabaseConnection;
use Gallery\Structure\Media;

/**
 * MediaStorage Class
 * Unified storage for all media items (images and videos) in the database.
 */
class MediaStorage
{
    private const string MAIN_TABLE = 'media';
    private const string TAGS_TABLE = 'media_tags';
    private const string OBJ_CLASS = Media::class;

    private PDO $db;

    public function __construct()
    {
        if (!isset($this->db)) {
            $this->db = DatabaseConnection::getInstance();
        }
    }

    /**
     * Retrieves a single media item by ID, or all items if no ID is given.
     */
    public function retrieve(?int $media_id = null): Media|array|null
    {
        $where = ($media_id !== null) ? " WHERE media_id = :media_id" : "";
        $sql = "SELECT * FROM " . self::MAIN_TABLE . "$where ORDER BY file_time DESC, media_id DESC";

        $stmt = $this->db->prepare($sql);

        if ($media_id !== null) {
            $stmt->bindParam(':media_id', $media_id, PDO::PARAM_INT);
        }

        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_CLASS, self::OBJ_CLASS);

        if ($media_id !== null) {
            return count($items) === 1 ? $items[0] : null;
        }

        return $items;
    }

    /**
     * Get media item by file name.
     */
    public function retrieveByFilename(string $file_name): ?Media
    {
        $sql = "SELECT * FROM " . self::MAIN_TABLE . " WHERE file_name = :file_name";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':file_name', $file_name, PDO::PARAM_STR);
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_CLASS, self::OBJ_CLASS);
        $item = $stmt->fetch();

        return $item instanceof Media ? $item : null;
    }

    /**
     * Retrieves paginated media filtered by tags (all must match).
     */
    public function retrieveWithTags(array $tag_ids, int $page_number, int $items_per_page): array
    {
        $tag_count = count($tag_ids);
        $offset = ($page_number - 1) * $items_per_page;

        $placeholders = implode(',', array_fill(0, $tag_count, '?'));
        $sql = "SELECT m.* FROM " . self::MAIN_TABLE . " m
                    LEFT JOIN " . self::TAGS_TABLE . " mt USING (media_id)
                    WHERE mt.tag_id IN ($placeholders)
                    GROUP BY m.media_id
                    HAVING COUNT(DISTINCT mt.tag_id) = ?
                    ORDER BY m.file_time DESC, m.media_id DESC
                    LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);

        $bind_index = 1;
        foreach ($tag_ids as $tid) {
            $stmt->bindValue($bind_index++, (int)$tid, PDO::PARAM_INT);
        }
        $stmt->bindValue($bind_index++, $tag_count, PDO::PARAM_INT);
        $stmt->bindValue($bind_index++, $items_per_page, PDO::PARAM_INT);
        $stmt->bindValue($bind_index, $offset, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_CLASS, self::OBJ_CLASS);
    }

    /**
     * Retrieves paginated media matching included tags and/or excluding excluded tags.
     */
    public function retrieveWithTagFilter(array $include_tag_ids, array $exclude_tag_ids, int $page_number, int $items_per_page): array
    {
        $offset = ($page_number - 1) * $items_per_page;
        $bind = [];

        $excludeClause = '';
        if (!empty($exclude_tag_ids)) {
            $exPlaceholders = implode(',', array_fill(0, count($exclude_tag_ids), '?'));
            $excludeClause = "AND m.media_id NOT IN (
                SELECT media_id FROM " . self::TAGS_TABLE . " WHERE tag_id IN ($exPlaceholders)
            )";
            foreach ($exclude_tag_ids as $tid) {
                $bind[] = (int)$tid;
            }
        }

        if (!empty($include_tag_ids)) {
            $incPlaceholders = implode(',', array_fill(0, count($include_tag_ids), '?'));
            $sql = "SELECT m.* FROM " . self::MAIN_TABLE . " m
                    LEFT JOIN " . self::TAGS_TABLE . " mt USING (media_id)
                    WHERE mt.tag_id IN ($incPlaceholders) $excludeClause
                    GROUP BY m.media_id
                    HAVING COUNT(DISTINCT mt.tag_id) = ?
                    ORDER BY m.file_time DESC, m.media_id DESC
                    LIMIT ? OFFSET ?";

            $incBind = array_map('intval', $include_tag_ids);
            $allBind = array_merge($incBind, $bind, [count($include_tag_ids), $items_per_page, $offset]);
        } else {
            $sql = "SELECT m.* FROM " . self::MAIN_TABLE . " m
                    WHERE 1=1 $excludeClause
                    ORDER BY m.file_time DESC, m.media_id DESC
                    LIMIT ? OFFSET ?";

            $allBind = array_merge($bind, [$items_per_page, $offset]);
        }

        $stmt = $this->db->prepare($sql);
        foreach ($allBind as $i => $val) {
            $stmt->bindValue($i + 1, $val, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_CLASS, self::OBJ_CLASS);
    }

    /**
     * Gets the total number of media matching included and/or excluded tags.
     */
    public function retrieveTotalWithTagFilterCount(array $include_tag_ids, array $exclude_tag_ids): int
    {
        $bind = [];

        $excludeClause = '';
        if (!empty($exclude_tag_ids)) {
            $exPlaceholders = implode(',', array_fill(0, count($exclude_tag_ids), '?'));
            $excludeClause = "AND m.media_id NOT IN (
                SELECT media_id FROM " . self::TAGS_TABLE . " WHERE tag_id IN ($exPlaceholders)
            )";
            foreach ($exclude_tag_ids as $tid) {
                $bind[] = (int)$tid;
            }
        }

        if (!empty($include_tag_ids)) {
            $incPlaceholders = implode(',', array_fill(0, count($include_tag_ids), '?'));
            $sql = "SELECT COUNT(*) FROM (
                SELECT m.media_id FROM " . self::MAIN_TABLE . " m
                LEFT JOIN " . self::TAGS_TABLE . " mt USING (media_id)
                WHERE mt.tag_id IN ($incPlaceholders) $excludeClause
                GROUP BY m.media_id
                HAVING COUNT(DISTINCT mt.tag_id) = ?
            )";

            $incBind = array_map('intval', $include_tag_ids);
            $allBind = array_merge($incBind, $bind, [count($include_tag_ids)]);
        } else {
            $sql = "SELECT COUNT(*) FROM " . self::MAIN_TABLE . " m
                    WHERE 1=1 $excludeClause";

            $allBind = $bind;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($allBind as $i => $val) {
            $stmt->bindValue($i + 1, $val, PDO::PARAM_INT);
        }
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * Retrieves paginated media items.
     */
    public function retrieveForPage(int $page_number, int $items_per_page): array
    {
        $offset = ($page_number - 1) * $items_per_page;
        $sql = "SELECT * FROM " . self::MAIN_TABLE . " ORDER BY file_time DESC, media_id DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_CLASS, self::OBJ_CLASS);
    }

    /**
     * Gets the total number of media items.
     */
    public function retrieveTotalCount(): int
    {
        $sql = "SELECT COUNT(*) FROM " . self::MAIN_TABLE;
        $stmt = $this->db->query($sql);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Retrieves paginated media that have no tags applied.
     */
    public function retrieveUntaggedForPage(int $page_number, int $items_per_page): array
    {
        $offset = ($page_number - 1) * $items_per_page;
        $sql = "SELECT m.* FROM " . self::MAIN_TABLE . " m
                LEFT JOIN " . self::TAGS_TABLE . " mt USING (media_id)
                WHERE mt.media_id IS NULL
                ORDER BY m.file_time DESC, m.media_id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_CLASS, self::OBJ_CLASS);
    }

    /**
     * Gets the total number of untagged media items.
     */
    public function retrieveTotalUntaggedCount(): int
    {
        $sql = "SELECT COUNT(*) FROM " . self::MAIN_TABLE . " m
                LEFT JOIN " . self::TAGS_TABLE . " mt USING (media_id)
                WHERE mt.media_id IS NULL";

        $stmt = $this->db->query($sql);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Gets the total number of media items with specific tags.
     */
    public function retrieveTotalWithTagsCount(array $tag_ids): int
    {
        $tag_count = count($tag_ids);
        $placeholders = implode(',', array_fill(0, $tag_count, '?'));
        $sql = "SELECT COUNT(*) FROM (SELECT m.media_id FROM " . self::MAIN_TABLE . " m
                    LEFT JOIN " . self::TAGS_TABLE . " mt USING (media_id)
                    WHERE mt.tag_id IN ($placeholders)
                    GROUP BY m.media_id
                    HAVING COUNT(DISTINCT mt.tag_id) = ?)";

        $stmt = $this->db->prepare($sql);

        $bind_index = 1;
        foreach ($tag_ids as $tid) {
            $stmt->bindValue($bind_index++, (int)$tid, PDO::PARAM_INT);
        }
        $stmt->bindValue($bind_index, $tag_count, PDO::PARAM_INT);

        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Retrieves a single random media item.
     */
    public function retrieveRandom(): ?Media
    {
        $sql = "SELECT * FROM " . self::MAIN_TABLE . " ORDER BY RANDOM() LIMIT 1";
        $stmt = $this->db->query($sql);
        $stmt->setFetchMode(PDO::FETCH_CLASS, self::OBJ_CLASS);
        $item = $stmt->fetch();

        return $item instanceof Media ? $item : null;
    }

    /**
     * Check if media exists by file name or hash.
     */
    public function mediaExistsInDatabase(string $file_name, string $hash): bool
    {
        $sql = "SELECT 1 FROM " . self::MAIN_TABLE . " WHERE file_name = :file_name OR hash = :hash LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':file_name', $file_name, PDO::PARAM_STR);
        $stmt->bindParam(':hash', $hash, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$stmt->fetchColumn() === 1;
    }

    /**
     * Finds an existing media item by its MD5 hash and returns its ID.
     */
    public function retrieveIdByHash(string $hash): ?int
    {
        $sql = "SELECT media_id FROM " . self::MAIN_TABLE . " WHERE hash = :hash LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':hash', $hash, PDO::PARAM_STR);
        $stmt->execute();

        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * Retrieves a lightweight summary of all media items.
     * Used by cron.php for orphan detection.
     */
    public function retrieveSummary(?string $media_type = null): array
    {
        $where = ($media_type !== null) ? " WHERE media_type = :media_type" : "";
        $sql = "SELECT media_id, media_type, file_name, hash FROM " . self::MAIN_TABLE . "$where ORDER BY file_time DESC, media_id DESC";

        $stmt = $this->db->prepare($sql);
        if ($media_type !== null) {
            $stmt->bindParam(':media_type', $media_type, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieves multiple media items by an array of IDs.
     * Returns items in file_time DESC order; missing IDs are silently skipped.
     *
     * @param int[] $mediaIds
     * @return Media[]
     */
    public function retrieveByIds(array $mediaIds): array
    {
        if (empty($mediaIds)) {
            return [];
        }

        // Build safe integer placeholders
        $ids = array_filter(array_map('intval', $mediaIds), fn($id) => $id > 0);
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM " . self::MAIN_TABLE . " WHERE media_id IN ({$placeholders}) ORDER BY file_time DESC, media_id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($ids));

        return $stmt->fetchAll(PDO::FETCH_CLASS, self::OBJ_CLASS);
    }

    /**
     * Saves a media item to the database.
     */
    public function store(Media $media): int
    {
        if (empty($media->getMediaId())) {
            $sql = "INSERT INTO " . self::MAIN_TABLE . " (media_type, file_name, file_time, hash, bits_fingerprint) VALUES (:media_type, :file_name, :file_time, :hash, :bits_fingerprint)";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':media_type', $media->getMediaType(), PDO::PARAM_STR);
            $stmt->bindValue(':file_name', $media->getFileName(), PDO::PARAM_STR);
            $stmt->bindValue(':file_time', $media->getFileTime(), PDO::PARAM_INT);
            $stmt->bindValue(':hash', $media->getHash(), PDO::PARAM_STR);
            $stmt->bindValue(':bits_fingerprint', $media->getBitsFingerprint(), PDO::PARAM_STR);

            $stmt->execute();
            $media->setMediaId((int)$this->db->lastInsertId());
        }

        return $media->getMediaId();
    }

    /**
     * Deletes a media item from the database.
     */
    public function delete(Media $media): bool
    {
        $sql = "DELETE FROM " . self::MAIN_TABLE . " WHERE media_id = :media_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':media_id', $media->getMediaId(), PDO::PARAM_INT);

        return $stmt->execute();
    }
}
