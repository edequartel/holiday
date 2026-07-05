<?php
require_once __DIR__ . '/config.php';

$config = holiday_config();

try {
    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed. Check your private secrets file.');
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_to_trip(int $tripId): void
{
    header('Location: index.php?trip_id=' . $tripId);
    exit;
}

function ensure_map_points_table(PDO $pdo): void
{
    $columns = $pdo->query("SHOW COLUMNS FROM map_points LIKE 'show_on_map'")->fetchAll();
    if (!$columns) {
        $pdo->exec('ALTER TABLE map_points ADD COLUMN show_on_map TINYINT(1) NOT NULL DEFAULT 1 AFTER source');
    }
}

function ensure_itinerary_days_table(PDO $pdo): void
{
    $columns = $pdo->query("SHOW COLUMNS FROM itinerary_days LIKE 'url'")->fetchAll();
    if (!$columns) {
        $pdo->exec('ALTER TABLE itinerary_days ADD COLUMN url VARCHAR(1000) NULL AFTER hotel');
    }
}

function ensure_day_documents_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS day_documents (
          id INT AUTO_INCREMENT PRIMARY KEY,
          trip_id INT NOT NULL,
          day_id INT NOT NULL,
          original_name VARCHAR(255) NOT NULL,
          stored_name VARCHAR(255) NOT NULL,
          file_path VARCHAR(500) NOT NULL,
          mime_type VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
          file_size INT NOT NULL DEFAULT 0,
          file_hash CHAR(64) NULL,
          notes TEXT,
          extracted_json JSON NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
          FOREIGN KEY (day_id) REFERENCES itinerary_days(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM day_documents LIKE 'extracted_json'")->fetchAll();
    if (!$columns) {
        $pdo->exec('ALTER TABLE day_documents ADD COLUMN extracted_json JSON NULL AFTER notes');
    }

    $columns = $pdo->query("SHOW COLUMNS FROM day_documents LIKE 'file_hash'")->fetchAll();
    if (!$columns) {
        $pdo->exec('ALTER TABLE day_documents ADD COLUMN file_hash CHAR(64) NULL AFTER file_size');
    }
}

function ensure_day_links_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS day_links (
          id INT AUTO_INCREMENT PRIMARY KEY,
          trip_id INT NOT NULL,
          day_id INT NOT NULL,
          title VARCHAR(255) NOT NULL,
          url VARCHAR(1000) NOT NULL,
          notes TEXT,
          extracted_json JSON NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
          FOREIGN KEY (day_id) REFERENCES itinerary_days(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM day_links LIKE 'extracted_json'")->fetchAll();
    if (!$columns) {
        $pdo->exec('ALTER TABLE day_links ADD COLUMN extracted_json JSON NULL AFTER notes');
    }
}

function document_dedupe_key(array $document): string
{
    $hash = trim((string)($document['file_hash'] ?? ''));
    if ($hash !== '') {
        return 'hash:' . $hash;
    }

    return 'legacy:' . strtolower(trim((string)($document['original_name'] ?? ''))) .
        '|' . (int)($document['file_size'] ?? 0) .
        '|' . hash('sha256', (string)($document['extracted_json'] ?? ''));
}

function group_unique_documents_by_day(array $documents): array
{
    $grouped = [];
    $seen = [];
    foreach ($documents as $document) {
        $dayId = (int)($document['day_id'] ?? 0);
        $key = $dayId . '|' . document_dedupe_key($document);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $grouped[$dayId][] = $document;
    }

    return $grouped;
}

function count_unique_documents_by_day(array $documents): array
{
    $counts = [];
    foreach (group_unique_documents_by_day($documents) as $dayId => $items) {
        $counts[(int)$dayId] = count($items);
    }

    return $counts;
}
