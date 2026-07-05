<?php
require __DIR__ . '/includes/db.php';
ensure_day_documents_table($pdo);

$documentId = (int)($_GET['id'] ?? 0);
if ($documentId <= 0) {
    http_response_code(404);
    exit('Document not found.');
}

$stmt = $pdo->prepare('SELECT * FROM day_documents WHERE id=?');
$stmt->execute([$documentId]);
$document = $stmt->fetch();

if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

$baseDir = realpath(__DIR__ . '/uploads/day-documents');
$filePath = realpath(__DIR__ . '/' . $document['file_path']);

$allowedPrefix = $baseDir ? $baseDir . DIRECTORY_SEPARATOR : '';
if (!$baseDir || !$filePath || strncmp($filePath, $allowedPrefix, strlen($allowedPrefix)) !== 0 || !is_file($filePath)) {
    http_response_code(404);
    exit('Document file not found.');
}

$filename = str_replace(['"', "\r", "\n"], '', $document['original_name']);

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
