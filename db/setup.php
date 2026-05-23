<?php

// CLI-only guard
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script can only be run from the command line.';
    exit(1);
}

// Required Autoloader
require_once('../vendor/autoload.php');

use Gallery\Core\DatabaseConnection;

// Create Database Connection
$db = DatabaseConnection::getInstance();

echo "Gallery - Database Creation Setup\n";
echo "==================================\n\n";

// Check if DB Exists and is Writable
$db_exists = file_exists('gallery.db');
$db_writeable = is_writable('gallery.db');

if (!$db_exists) {
    echo "[ERROR] Database file does not exist. Please create the gallery.db database file.\n";
    exit(1);
}

if (!$db_writeable) {
    echo "[ERROR] Database file is not writable. Please check the permissions.\n";
    exit(1);
}

echo "[OK] Database file exists and is writable.\n\n";

// Table definitions
$tables = [
    'media' => <<<SQL
    CREATE TABLE IF NOT EXISTS "media" (
        "media_id"	INTEGER NOT NULL UNIQUE,
        "media_type"	TEXT NOT NULL DEFAULT 'image',
        "file_name"	TEXT NOT NULL UNIQUE,
        "file_time"	INTEGER NOT NULL,
        "hash"	TEXT NOT NULL,
        "bits_fingerprint"	TEXT NOT NULL DEFAULT '',
        PRIMARY KEY("media_id" AUTOINCREMENT)
    )
    SQL,

    'tag_categories' => <<<SQL
    CREATE TABLE IF NOT EXISTS "tag_categories" (
        "category_id"	INTEGER NOT NULL,
        "category_name"	TEXT NOT NULL UNIQUE COLLATE NOCASE,
        "category_short"	TEXT NOT NULL UNIQUE COLLATE NOCASE,
        PRIMARY KEY("category_id" AUTOINCREMENT)
    )
    SQL,

    'tags' => <<<SQL
    CREATE TABLE IF NOT EXISTS "tags" (
        "tag_id"	INTEGER NOT NULL UNIQUE,
        "category_id"	INTEGER NOT NULL DEFAULT 1,
        "tag_name"	TEXT NOT NULL UNIQUE COLLATE NOCASE,
        PRIMARY KEY("tag_id" AUTOINCREMENT),
        CONSTRAINT "fk__tags__tag_categories" FOREIGN KEY("category_id") REFERENCES "tag_categories"("category_id")
    )
    SQL,

    'media_tags' => <<<SQL
    CREATE TABLE IF NOT EXISTS "media_tags" (
        "media_id"	INTEGER NOT NULL,
        "tag_id"	INTEGER NOT NULL,
        CONSTRAINT "PRIMARY" PRIMARY KEY("media_id","tag_id"),
        CONSTRAINT "FK__media_tags__media" FOREIGN KEY("media_id") REFERENCES "media"("media_id") ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT "FK__media_tags__tags" FOREIGN KEY("tag_id") REFERENCES "tags"("tag_id") ON DELETE CASCADE ON UPDATE CASCADE
    )
    SQL,

    'rate_limits' => <<<SQL
    CREATE TABLE IF NOT EXISTS "rate_limits" (
        "ip" TEXT NOT NULL,
        "requested_at" INTEGER NOT NULL
    )
    SQL,

    'auth_tokens' => <<<SQL
    CREATE TABLE IF NOT EXISTS "auth_tokens" (
        "token" TEXT NOT NULL PRIMARY KEY,
        "created_at" INTEGER NOT NULL
    )
    SQL,

    'tag_implications' => <<<SQL
    CREATE TABLE IF NOT EXISTS "tag_implications" (
        "tag_id" INTEGER NOT NULL,
        "implied_tag_id" INTEGER NOT NULL,
        PRIMARY KEY("tag_id", "implied_tag_id"),
        CONSTRAINT "FK__tag_implications__tags_trigger" FOREIGN KEY("tag_id") REFERENCES "tags"("tag_id") ON DELETE CASCADE,
        CONSTRAINT "FK__tag_implications__tags_implied" FOREIGN KEY("implied_tag_id") REFERENCES "tags"("tag_id") ON DELETE CASCADE
    )
    SQL,

    'dismissed_duplicates' => <<<SQL
    CREATE TABLE IF NOT EXISTS "dismissed_duplicates" (
        "media_id_1" INTEGER NOT NULL,
        "media_id_2" INTEGER NOT NULL,
        "dismissed_at" INTEGER NOT NULL,
        PRIMARY KEY("media_id_1", "media_id_2"),
        CONSTRAINT "FK__dismissed_dupes__media_1" FOREIGN KEY("media_id_1") REFERENCES "media"("media_id") ON DELETE CASCADE,
        CONSTRAINT "FK__dismissed_dupes__media_2" FOREIGN KEY("media_id_2") REFERENCES "media"("media_id") ON DELETE CASCADE
    )
    SQL,
];

// Create tables
foreach ($tables as $name => $sql) {
    echo "Creating table '$name'... ";
    $success = $db->exec($sql);
    if ($success !== false) {
        echo "[OK]\n";
    } else {
        echo "[ERROR] " . $db->errorInfo()[2] . "\n";
    }
}

// Insert default tag categories
echo "\nInserting default tag categories... ";
$sql = <<<SQL
INSERT OR IGNORE INTO "tag_categories" ("category_id", "category_name", "category_short")
    VALUES (1, 'General', 'g'),
           (2, 'Artist', 'a'),
           (3, 'Character', 'c'),
           (4, 'Source', 's'),
           (5, 'Personal List', 'p')
SQL;
$success = $db->exec($sql);
echo ($success !== false) ? "[OK]\n" : "[ERROR] " . $db->errorInfo()[2] . "\n";

// Create indexes
echo "\nCreating indexes...\n";
$indexes = [
    'idx_media_hash' => 'CREATE INDEX IF NOT EXISTS idx_media_hash ON media(hash)',
    'idx_media_file_time' => 'CREATE INDEX IF NOT EXISTS idx_media_file_time ON media(file_time DESC, media_id DESC)',
    'idx_media_type' => 'CREATE INDEX IF NOT EXISTS idx_media_type ON media(media_type)',
    'idx_rate_limits_ip_time' => 'CREATE INDEX IF NOT EXISTS idx_rate_limits_ip_time ON rate_limits(ip, requested_at)',
    // Reverse index on junction table for tag-based queries (search, exclude, counts, migration)
    'idx_media_tags_tag_media' => 'CREATE INDEX IF NOT EXISTS idx_media_tags_tag_media ON media_tags(tag_id, media_id)',
    // Tags category lookup for display page joins/sorts
    'idx_tags_category' => 'CREATE INDEX IF NOT EXISTS idx_tags_category ON tags(category_id)',
    // Auth token expiry cleanup
    'idx_auth_tokens_created' => 'CREATE INDEX IF NOT EXISTS idx_auth_tokens_created ON auth_tokens(created_at)',
];

foreach ($indexes as $name => $sql) {
    echo "  Index '$name'... ";
    $success = $db->exec($sql);
    echo ($success !== false) ? "[OK]\n" : "[ERROR] " . $db->errorInfo()[2] . "\n";
}

echo "\nSetup complete.\n";
