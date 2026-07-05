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
          notes TEXT,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
          FOREIGN KEY (day_id) REFERENCES itinerary_days(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
