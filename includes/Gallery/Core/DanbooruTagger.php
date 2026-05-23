<?php

namespace Gallery\Core;

use PDO;

/**
 * DanbooruTagger
 *
 * Looks up an image by MD5 hash on the Danbooru API, then creates/resolves
 * gallery tags and links them to the image. Reusable by both the cron
 * script and the upload controller.
 *
 * Only supports images — Danbooru does not index videos by hash.
 */
class DanbooruTagger
{
    private const string API_BASE = 'https://danbooru.donmai.us';

    /**
     * Maps Danbooru category IDs to gallery category names.
     */
    private const array CATEGORY_MAP = [
        0 => 'General',     // General -> General
        1 => 'Artist',      // Artist -> Artist
        3 => 'Source',       // Copyright -> Source
        4 => 'Character',   // Character -> Character
    ];

    /**
     * Renames certain Danbooru tags to more readable gallery names.
     */
    private const array TAG_NAME_MAP = [
        '1boy'           => 'one man',
        '1girl'          => 'one woman',
        'multiple_boys'  => 'multiple men',
        'multiple_girls' => 'multiple women',
        '2boys'          => 'two men',
        '2girls'         => 'two women',
    ];

    private PDO $db;
    private string $login;
    private string $apiKey;

    /** @var array<string, int> category_name => category_id */
    private array $categoryCache = [];

    /** @var array<string, int> lowercase tag_name => tag_id */
    private array $tagCache = [];

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
        $this->login = Configuration::getDanbooruLogin();
        $this->apiKey = Configuration::getDanbooruApiKey();

        $this->loadCaches();
    }

    /**
     * Warm the category and tag caches from the database.
     */
    private function loadCaches(): void
    {
        if (empty($this->categoryCache)) {
            $stmt = $this->db->query('SELECT category_id, category_name FROM tag_categories');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->categoryCache[$row['category_name']] = (int)$row['category_id'];
            }
        }

        if (empty($this->tagCache)) {
            $stmt = $this->db->query('SELECT tag_id, tag_name FROM tags');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->tagCache[strtolower($row['tag_name'])] = (int)$row['tag_id'];
            }
        }
    }

    /**
     * Look up the given MD5 hash on Danbooru and apply any found tags to the media item.
     *
     * @param int    $mediaId The gallery media_id.
     * @param string $md5     The MD5 hash of the image file.
     *
     * @return array{found: bool, tags_created: int, tags_applied: int}
     */
    public function importTagsForMedia(int $mediaId, string $md5): array
    {
        $stats = ['found' => false, 'tags_created' => 0, 'tags_applied' => 0];

        $posts = $this->apiGet('/posts.json?tags=md5:' . urlencode($md5));

        if (empty($posts)) {
            return $stats;
        }

        $post = $posts[0];
        $stats['found'] = true;

        // Build categorized tag map: danbooru_tag => danbooru_category_id
        $categorizedTags = [];
        $fieldMap = [
            'tag_string_artist'    => 1,
            'tag_string_character' => 4,
            'tag_string_copyright' => 3,
            'tag_string_general'   => 0,
        ];

        foreach ($fieldMap as $field => $catId) {
            if (!empty($post[$field])) {
                foreach (explode(' ', $post[$field]) as $t) {
                    if ($t !== '') {
                        $categorizedTags[$t] = $catId;
                    }
                }
            }
        }

        // Prepared statement for linking tags to media
        $linkStmt = $this->db->prepare(
            'INSERT OR IGNORE INTO media_tags (media_id, tag_id) VALUES (:iid, :tid)'
        );

        foreach ($categorizedTags as $danbooruTag => $danbooruCategory) {
            if (!isset(self::CATEGORY_MAP[$danbooruCategory])) {
                continue;
            }

            $ourCategoryName = self::CATEGORY_MAP[$danbooruCategory];
            $ourCategoryId = $this->categoryCache[$ourCategoryName] ?? null;

            if ($ourCategoryId === null) {
                continue;
            }

            // Map tag name
            $galleryTagName = self::TAG_NAME_MAP[$danbooruTag] ?? str_replace('_', ' ', $danbooruTag);

            // Get or create tag in DB
            $key = strtolower($galleryTagName);

            if (isset($this->tagCache[$key])) {
                $tagId = $this->tagCache[$key];
            } else {
                $stmt = $this->db->prepare('INSERT OR IGNORE INTO tags (tag_name, category_id) VALUES (:name, :cat)');
                $stmt->execute([':name' => $galleryTagName, ':cat' => $ourCategoryId]);

                if ($this->db->lastInsertId() > 0) {
                    $tagId = (int)$this->db->lastInsertId();
                    $stats['tags_created']++;
                } else {
                    $stmt = $this->db->prepare('SELECT tag_id FROM tags WHERE tag_name = :name COLLATE NOCASE');
                    $stmt->execute([':name' => $galleryTagName]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$row) {
                        continue;
                    }

                    $tagId = (int)$row['tag_id'];
                }

                $this->tagCache[$key] = $tagId;
            }

            $linkStmt->execute([':iid' => $mediaId, ':tid' => $tagId]);
            $stats['tags_applied']++;
        }

        return $stats;
    }

    /**
     * Check whether Danbooru credentials are configured.
     */
    public static function isConfigured(): bool
    {
        return Configuration::getDanbooruLogin() !== ''
            && Configuration::getDanbooruApiKey() !== '';
    }

    /**
     * Make an authenticated GET request to the Danbooru API.
     */
    private function apiGet(string $path): ?array
    {
        $url = self::API_BASE . $path;
        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator . 'login=' . urlencode($this->login) . '&api_key=' . urlencode($this->apiKey);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'GalleryTagImporter/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            return json_decode($response, true);
        }

        return null;
    }
}
