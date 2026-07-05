<?php
session_start();

require __DIR__ . '/includes/db.php';
ensure_itinerary_days_table($pdo);
ensure_map_points_table($pdo);
ensure_day_documents_table($pdo);
ensure_day_links_table($pdo);

const HOLIDAY_GIT_REMOTE = 'https://github.com/edequartel/holiday.git';

$action = $_POST['action'] ?? null;
$gitPullResult = $_SESSION['git_pull_result'] ?? null;
$documentImportResult = $_SESSION['document_import_result'] ?? null;
unset($_SESSION['git_pull_result']);
unset($_SESSION['document_import_result']);

if ($action === 'git_pull') {
    $_SESSION['git_pull_result'] = run_git_pull();

    $tripIdPost = (int)($_POST['trip_id'] ?? 0);
    if ($tripIdPost > 0) {
        redirect_to_trip($tripIdPost);
    }

    header('Location: index.php');
    exit;
}

if ($action === 'create_trip') {
    $stmt = $pdo->prepare('INSERT INTO trips (title, destination, start_date, end_date, notes) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$_POST['title'], $_POST['destination'], $_POST['start_date'] ?: null, $_POST['end_date'] ?: null, $_POST['notes']]);
    redirect_to_trip((int)$pdo->lastInsertId());
}

$tripIdPost = (int)($_POST['trip_id'] ?? 0);

if ($action === 'update_trip') {
    $stmt = $pdo->prepare('UPDATE trips SET title=?, destination=?, start_date=?, end_date=?, notes=? WHERE id=?');
    $stmt->execute([
        $_POST['title'],
        $_POST['destination'],
        $_POST['start_date'] ?: null,
        $_POST['end_date'] ?: null,
        $_POST['notes'],
        $tripIdPost,
    ]);
    redirect_to_trip($tripIdPost);
}

if ($action === 'import_day_pdf') {
    $_SESSION['document_import_result'] = import_day_pdf($pdo, $tripIdPost);
    redirect_to_trip($tripIdPost);
}

if ($action === 'cleanup_duplicate_documents') {
    $_SESSION['document_import_result'] = cleanup_duplicate_documents($pdo, $tripIdPost);
    redirect_to_trip($tripIdPost);
}

if ($action === 'add_day') {
    $stmt = $pdo->prepare('INSERT INTO itinerary_days (trip_id, day_date, location, title, details, transport, hotel, url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$tripIdPost, $_POST['day_date'], $_POST['location'], $_POST['title'], $_POST['details'], $_POST['transport'], $_POST['hotel'], $_POST['url']]);
    redirect_to_trip($tripIdPost);
}

if ($action === 'add_day_link') {
    add_day_link($pdo, $tripIdPost);
    redirect_to_trip($tripIdPost);
}

if ($action === 'add_flight') {
    $stmt = $pdo->prepare('INSERT INTO flights (trip_id, flight_date, airline, flight_number, departure_airport, arrival_airport, departure_time, arrival_time, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$tripIdPost, $_POST['flight_date'] ?: null, $_POST['airline'], $_POST['flight_number'], $_POST['departure_airport'], $_POST['arrival_airport'], $_POST['departure_time'] ?: null, $_POST['arrival_time'] ?: null, $_POST['notes']]);
    redirect_to_trip($tripIdPost);
}

if ($action === 'add_item') {
    $stmt = $pdo->prepare('INSERT INTO packing_items (trip_id, category, item, quantity) VALUES (?, ?, ?, ?)');
    $stmt->execute([$tripIdPost, $_POST['category'], $_POST['item'], $_POST['quantity']]);
    redirect_to_trip($tripIdPost);
}

if ($action === 'toggle_packed') {
    $stmt = $pdo->prepare('UPDATE packing_items SET packed = IF(packed=1,0,1) WHERE id=? AND trip_id=?');
    $stmt->execute([(int)$_POST['item_id'], $tripIdPost]);
    redirect_to_trip($tripIdPost);
}

if ($action === 'add_point') {
    $stmt = $pdo->prepare('INSERT INTO map_points (trip_id, point_type, name, address, city, latitude, longitude, notes, source, show_on_map) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$tripIdPost, $_POST['point_type'], $_POST['name'], $_POST['address'], $_POST['city'], $_POST['latitude'], $_POST['longitude'], $_POST['notes'], 'manual', isset($_POST['show_on_map']) ? 1 : 0]);
    redirect_to_trip($tripIdPost);
}

if ($action === 'save_ai_point') {
    $stmt = $pdo->prepare('INSERT INTO map_points (trip_id, point_type, name, city, latitude, longitude, notes, source, show_on_map) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$tripIdPost, $_POST['point_type'], $_POST['name'], $_POST['city'], $_POST['latitude'], $_POST['longitude'], $_POST['notes'], 'openai', 1]);
    redirect_to_trip($tripIdPost);
}

if ($action === 'toggle_point_map') {
    $stmt = $pdo->prepare('UPDATE map_points SET show_on_map=? WHERE id=? AND trip_id=?');
    $stmt->execute([isset($_POST['show_on_map']) ? 1 : 0, (int)$_POST['point_id'], $tripIdPost]);
    redirect_to_trip($tripIdPost);
}

if ($action === 'delete_day') {
    delete_day_with_documents($pdo, (int)$_POST['day_id'], $tripIdPost);
    redirect_to_trip($tripIdPost);
}

if (in_array($action, ['delete_flight','delete_item','delete_point','delete_link'], true)) {
    $map = [
        'delete_flight' => ['flights', 'flight_id'],
        'delete_item' => ['packing_items', 'item_id'],
        'delete_point' => ['map_points', 'point_id'],
        'delete_link' => ['day_links', 'link_id'],
    ];
    [$table, $field] = $map[$action];
    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id=? AND trip_id=?");
    $stmt->execute([(int)$_POST[$field], $tripIdPost]);
    redirect_to_trip($tripIdPost);
}

$trips = $pdo->query('SELECT * FROM trips ORDER BY start_date DESC, id DESC')->fetchAll();
$tripId = isset($_GET['trip_id']) ? (int)$_GET['trip_id'] : (int)($trips[0]['id'] ?? 0);

$trip = null; $days = []; $flights = []; $items = []; $points = []; $documentsByDay = []; $linksByDay = [];
if ($tripId) {
    $stmt = $pdo->prepare('SELECT * FROM trips WHERE id=?');
    $stmt->execute([$tripId]);
    $trip = $stmt->fetch();

    foreach ([
        'days' => 'SELECT * FROM itinerary_days WHERE trip_id=? ORDER BY day_date ASC, id ASC',
        'flights' => 'SELECT * FROM flights WHERE trip_id=? ORDER BY flight_date ASC, departure_time ASC',
        'items' => 'SELECT * FROM packing_items WHERE trip_id=? ORDER BY category ASC, item ASC',
        'points' => 'SELECT * FROM map_points WHERE trip_id=? ORDER BY point_type ASC, name ASC',
    ] as $var => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tripId]);
        $$var = $stmt->fetchAll();
    }

    $stmt = $pdo->prepare('SELECT * FROM day_documents WHERE trip_id=? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$tripId]);
    $documentsByDay = group_unique_documents_by_day($stmt->fetchAll());

    $stmt = $pdo->prepare('SELECT * FROM day_links WHERE trip_id=? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$tripId]);
    foreach ($stmt->fetchAll() as $link) {
        $linksByDay[(int)$link['day_id']][] = $link;
    }

    usort($points, 'compare_points_by_date');
}
$totalItems = count($items);
$packedItems = count(array_filter($items, fn($i) => (int)$i['packed'] === 1));
$packedPercent = $totalItems ? round(($packedItems / $totalItems) * 100) : 0;
$visiblePoints = array_values(array_filter($points, fn($point) => (int)($point['show_on_map'] ?? 1) === 1));
$mapJson = json_encode($visiblePoints, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

function run_git_pull(): array
{
    if (!function_exists('exec')) {
        return [
            'type' => 'danger',
            'message' => 'Git pull failed: PHP exec() is disabled.',
        ];
    }

    $repoDir = __DIR__;
    $branchOutput = [];
    exec('git -C ' . escapeshellarg($repoDir) . ' branch --show-current 2>&1', $branchOutput, $branchCode);

    $branch = trim(implode("\n", $branchOutput));
    if ($branchCode !== 0 || $branch === '') {
        return [
            'type' => 'danger',
            'message' => "Git pull failed: couldn't determine the current branch.\n" . implode("\n", $branchOutput),
        ];
    }

    $output = [];
    exec(
        'git -C ' . escapeshellarg($repoDir) . ' pull --ff-only ' . escapeshellarg(HOLIDAY_GIT_REMOTE) . ' ' . escapeshellarg($branch) . ' 2>&1',
        $output,
        $code
    );

    return [
        'type' => $code === 0 ? 'success' : 'danger',
        'message' => implode("\n", $output),
    ];
}

function delete_day_with_documents(PDO $pdo, int $dayId, int $tripId): void
{
    $stmt = $pdo->prepare('SELECT file_path FROM day_documents WHERE day_id=? AND trip_id=?');
    $stmt->execute([$dayId, $tripId]);
    foreach ($stmt->fetchAll() as $document) {
        delete_uploaded_document_file($pdo, $document['file_path'] ?? '', $dayId);
    }

    $stmt = $pdo->prepare('DELETE FROM itinerary_days WHERE id=? AND trip_id=?');
    $stmt->execute([$dayId, $tripId]);
}

function compare_points_by_date(array $a, array $b): int
{
    $dateA = point_sort_date($a);
    $dateB = point_sort_date($b);

    if ($dateA === $dateB) {
        return strcmp((string)$a['name'], (string)$b['name']);
    }

    return strcmp($dateA, $dateB);
}

function point_sort_date(array $point): string
{
    return point_itinerary_date($point) ?: '9999-12-31';
}

function point_itinerary_date(array $point): string
{
    $notes = (string)($point['notes'] ?? '');
    if (preg_match('/\d{4}-\d{2}-\d{2}/', $notes, $matches)) {
        return $matches[0];
    }

    return '';
}

function delete_uploaded_document_file(PDO $pdo, string $relativePath, int $deletedDayId): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM day_documents WHERE file_path=? AND day_id<>?');
    $stmt->execute([$relativePath, $deletedDayId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $baseDir = realpath(__DIR__ . '/uploads/day-documents');
    $filePath = realpath(__DIR__ . '/' . $relativePath);
    $allowedPrefix = $baseDir ? $baseDir . DIRECTORY_SEPARATOR : '';

    if ($baseDir && $filePath && strncmp($filePath, $allowedPrefix, strlen($allowedPrefix)) === 0 && is_file($filePath)) {
        @unlink($filePath);
    }
}

function delete_uploaded_document_file_if_unused(PDO $pdo, string $relativePath): void
{
    if (trim($relativePath) === '') {
        return;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM day_documents WHERE file_path=?');
    $stmt->execute([$relativePath]);
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $baseDir = realpath(__DIR__ . '/uploads/day-documents');
    $filePath = realpath(__DIR__ . '/' . $relativePath);
    $allowedPrefix = $baseDir ? $baseDir . DIRECTORY_SEPARATOR : '';

    if ($baseDir && $filePath && strncmp($filePath, $allowedPrefix, strlen($allowedPrefix)) === 0 && is_file($filePath)) {
        @unlink($filePath);
    }
}

function cleanup_duplicate_documents(PDO $pdo, int $tripId): array
{
    if ($tripId <= 0) {
        return ['type' => 'danger', 'message' => 'Choose a trip before cleaning uploaded documents.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM day_documents WHERE trip_id=? ORDER BY day_id ASC, created_at ASC, id ASC');
    $stmt->execute([$tripId]);
    $documents = $stmt->fetchAll();

    $seen = [];
    $duplicates = [];
    foreach ($documents as $document) {
        $key = (int)$document['day_id'] . '|' . document_dedupe_key($document);
        if (!isset($seen[$key])) {
            $seen[$key] = (int)$document['id'];
            continue;
        }
        $duplicates[] = $document;
    }

    if (!$duplicates) {
        return ['type' => 'success', 'message' => 'No duplicate uploaded documents were found.'];
    }

    $deletedRows = 0;
    $deletedFiles = 0;
    foreach ($duplicates as $duplicate) {
        $relativePath = (string)($duplicate['file_path'] ?? '');
        $stmt = $pdo->prepare('DELETE FROM day_documents WHERE id=? AND trip_id=?');
        $stmt->execute([(int)$duplicate['id'], $tripId]);
        if ($stmt->rowCount() < 1) {
            continue;
        }

        $deletedRows++;
        $beforeExists = $relativePath !== '' && is_file(__DIR__ . '/' . $relativePath);
        delete_uploaded_document_file_if_unused($pdo, $relativePath);
        if ($beforeExists && !is_file(__DIR__ . '/' . $relativePath)) {
            $deletedFiles++;
        }
    }

    return [
        'type' => 'success',
        'message' => "Removed {$deletedRows} duplicate uploaded document entr" . ($deletedRows === 1 ? 'y' : 'ies') . " and {$deletedFiles} unused PDF file" . ($deletedFiles === 1 ? '' : 's') . '.',
    ];
}

function add_day_link(PDO $pdo, int $tripId): void
{
    $dayId = (int)($_POST['day_id'] ?? 0);
    $url = normalize_day_link_url(trim($_POST['url'] ?? ''));
    if ($tripId <= 0 || $dayId <= 0 || $url === '') {
        return;
    }

    $stmt = $pdo->prepare('SELECT * FROM itinerary_days WHERE id=? AND trip_id=?');
    $stmt->execute([$dayId, $tripId]);
    $day = $stmt->fetch();
    if (!$day) {
        return;
    }

    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        $host = parse_url($url, PHP_URL_HOST);
        $title = $host ?: $url;
    }

    $notes = trim($_POST['notes'] ?? '');
    $extracted = [];

    try {
        $stmt = $pdo->prepare('SELECT * FROM trips WHERE id=?');
        $stmt->execute([$tripId]);
        $trip = $stmt->fetch() ?: [];

        $pageText = fetch_link_text($url);
        if ($pageText !== '') {
            $extracted = analyze_link_for_day($url, $title, $notes, $pageText, $trip, $day);
            update_day_from_import($pdo, $tripId, $dayId, $extracted);
            save_imported_map_point($pdo, $tripId, $extracted, (string)$day['day_date']);
        }
    } catch (Throwable $e) {
        $extracted = [];
    }

    $stmt = $pdo->prepare('INSERT INTO day_links (trip_id, day_id, title, url, notes, extracted_json) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $tripId,
        $dayId,
        $title,
        $url,
        $notes,
        $extracted ? json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function normalize_day_link_url(string $url): string
{
    if ($url === '') {
        return '';
    }

    if (!preg_match('/^https?:\/\//i', $url)) {
        $url = 'https://' . $url;
    }

    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

function fetch_link_text(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'HolidayPlanner/1.0',
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if (!is_string($response) || $status < 200 || $status >= 300 || stripos((string)$contentType, 'text/html') === false) {
        return '';
    }

    $response = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $response);
    $response = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $response);
    $text = html_entity_decode(strip_tags($response), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);

    return trim(substr((string)$text, 0, 18000));
}

function analyze_link_for_day(string $url, string $title, string $notes, string $pageText, array $trip, array $day): array
{
    $config = holiday_config();
    $apiKey = $config['openai_api_key'] ?? '';
    $model = $config['openai_model'] ?? 'gpt-5.5';

    if ($apiKey === '' || $apiKey === 'YOUR_OPENAI_API_KEY') {
        return [];
    }

    $prompt = "Analyze this website content for a travel itinerary day. Extract practical information that should be added to the day.\n" .
        "URL: {$url}\nTitle: {$title}\nUser notes: {$notes}\n" .
        "Trip: " . ($trip['title'] ?? '') . "\nDestination: " . ($trip['destination'] ?? '') . "\n" .
        "Day date: " . ($day['day_date'] ?? '') . "\nExisting day title: " . ($day['title'] ?? '') . "\nExisting location: " . ($day['location'] ?? '') . "\nExisting hotel: " . ($day['hotel'] ?? '') . "\n\n" .
        "Website text:\n{$pageText}\n\n" .
        "Return strict JSON only, no markdown. Format:\n" .
        "{\"title\":\"short activity/place title\",\"location\":\"city or place\",\"hotel\":\"hotel/accommodation name only if relevant\",\"transport\":\"transport details if relevant\",\"details\":\"concise useful summary\",\"address\":\"full address if present\",\"latitude\":\"decimal latitude if identifiable\",\"longitude\":\"decimal longitude if identifiable\",\"opening_hours\":\"opening days/times\",\"activity_time\":\"specific time, duration, or schedule\",\"price\":\"ticket/entry/price info\",\"booking\":\"booking/reservation/ticket instructions\",\"contact\":\"phone/email/contact details\",\"website\":\"best official URL\",\"important_notes\":[\"meeting point, entry rules, accessibility, what to bring, closures, warnings, tips\"]}\n" .
        "Only include information supported by the website text or clearly inferable from the URL/title. If a field is missing, use an empty string or empty array.";

    $payload = [
        'model' => $model,
        'input' => $prompt,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 80,
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($response) || $status < 200 || $status >= 300) {
        return [];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return [];
    }

    $text = trim(response_output_text($data));
    $text = preg_replace('/^```json\s*|\s*```$/', '', $text);
    $decoded = json_decode($text, true);

    return is_array($decoded) ? $decoded : [];
}

function import_day_pdf(PDO $pdo, int $tripId): array
{
    if ($tripId <= 0) {
        return ['type' => 'danger', 'message' => 'Choose a trip before uploading a booking PDF.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM trips WHERE id=?');
    $stmt->execute([$tripId]);
    $trip = $stmt->fetch();
    if (!$trip) {
        return ['type' => 'danger', 'message' => 'Trip not found.'];
    }

    $existingDayId = (int)($_POST['day_id'] ?? 0);
    $existingDay = null;
    if ($existingDayId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM itinerary_days WHERE id=? AND trip_id=?');
        $stmt->execute([$existingDayId, $tripId]);
        $existingDay = $stmt->fetch();
        if (!$existingDay) {
            return ['type' => 'danger', 'message' => 'Selected itinerary day was not found.'];
        }
    }

    $dayDate = trim($_POST['day_date'] ?? '');
    if ($dayDate === '' && $existingDay) {
        $dayDate = (string)$existingDay['day_date'];
    }

    $upload = $_FILES['booking_pdf'] ?? null;
    if (!$upload || (int)$upload['error'] !== UPLOAD_ERR_OK) {
        return ['type' => 'danger', 'message' => 'PDF upload failed.'];
    }

    if ((int)$upload['size'] > 20 * 1024 * 1024) {
        return ['type' => 'danger', 'message' => 'PDF is too large. Maximum size is 20 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($upload['tmp_name']) ?: '';
    if ($mimeType !== 'application/pdf') {
        return ['type' => 'danger', 'message' => 'Only PDF files can be uploaded.'];
    }

    $originalName = basename((string)$upload['name']);
    $fileSize = (int)$upload['size'];
    $fileHash = hash_file('sha256', $upload['tmp_name']);
    if (!is_string($fileHash) || $fileHash === '') {
        return ['type' => 'danger', 'message' => 'Could not fingerprint the uploaded PDF.'];
    }

    if (find_duplicate_imported_document($pdo, $tripId, $originalName, $fileSize, $fileHash)) {
        return ['type' => 'warning', 'message' => 'This PDF was already imported for this trip. No duplicate document or OpenAI import was added.'];
    }

    $uploadDir = __DIR__ . '/uploads/day-documents';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        return ['type' => 'danger', 'message' => 'Could not create the upload folder.'];
    }

    $storedName = bin2hex(random_bytes(16)) . '.pdf';
    $relativePath = 'uploads/day-documents/' . $storedName;
    $storedPath = __DIR__ . '/' . $relativePath;

    if (!move_uploaded_file($upload['tmp_name'], $storedPath)) {
        return ['type' => 'danger', 'message' => 'Could not store the uploaded PDF.'];
    }

    $extraDetails = trim($_POST['extra_details'] ?? '');
    try {
        $analysis = extract_itinerary_from_pdf($storedPath, $originalName, $trip, $dayDate, $extraDetails, $existingDay);
        $fallbackDate = $dayDate ?: (string)($trip['start_date'] ?? '') ?: date('Y-m-d');
        $importedDays = normalize_imported_days($analysis, $fallbackDate, $existingDay);
        $attachedCount = 0;

        foreach ($importedDays as $itinerary) {
            $dayId = find_or_create_imported_day($pdo, $tripId, $itinerary, $existingDay);
            update_day_from_import($pdo, $tripId, $dayId, $itinerary);
            save_imported_map_point($pdo, $tripId, $itinerary, (string)($itinerary['day_date'] ?? $dayDate));
            if (!day_document_exists($pdo, $tripId, $dayId, $originalName, $fileSize, $fileHash)) {
                insert_day_document($pdo, $tripId, $dayId, $originalName, $storedName, $relativePath, $mimeType, $fileSize, $fileHash, $extraDetails, $itinerary);
                $attachedCount++;
            }
        }

        if ($attachedCount === 0) {
            @unlink($storedPath);
            return ['type' => 'warning', 'message' => 'OpenAI analysed the PDF, but all matching document entries already existed. No duplicate information was added.'];
        }

        return ['type' => 'success', 'message' => "PDF analysed and divided over {$attachedCount} day(s)."];
    } catch (Throwable $e) {
        @unlink($storedPath);
        return ['type' => 'danger', 'message' => 'OpenAI could not read this PDF: ' . $e->getMessage()];
    }
}

function update_day_from_import(PDO $pdo, int $tripId, int $dayId, array $itinerary): void
{
    $stmt = $pdo->prepare('SELECT * FROM itinerary_days WHERE id=? AND trip_id=?');
    $stmt->execute([$dayId, $tripId]);
    $day = $stmt->fetch();
    if (!$day) {
        return;
    }

    $title = trim((string)$day['title']) ?: trim((string)($itinerary['title'] ?? ''));
    $location = trim((string)$day['location']) ?: trim((string)($itinerary['location'] ?? ''));
    $transport = trim((string)$day['transport']) ?: trim((string)($itinerary['transport'] ?? ''));
    $hotel = trim((string)$day['hotel']) ?: trim((string)($itinerary['hotel'] ?? ''));
    $url = trim((string)($day['url'] ?? '')) ?: trim((string)($itinerary['url'] ?? $itinerary['website'] ?? ''));

    $stmt = $pdo->prepare('UPDATE itinerary_days SET title=?, location=?, transport=?, hotel=?, url=? WHERE id=? AND trip_id=?');
    $stmt->execute([$title, $location, $transport, $hotel, $url, $dayId, $tripId]);
}

function normalize_imported_days(array $analysis, string $fallbackDate, ?array $existingDay): array
{
    $items = isset($analysis['days']) && is_array($analysis['days']) ? $analysis['days'] : [$analysis];
    $days = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $dayDate = normalize_imported_day_date(trim((string)($item['day_date'] ?? '')));
        if ($dayDate === '') {
            $dayDate = normalize_imported_day_date(trim((string)($item['arrival_date'] ?? '')));
        }
        if ($dayDate === '') {
            $dayDate = $existingDay ? (string)$existingDay['day_date'] : $fallbackDate;
        }

        $item['day_date'] = $dayDate;
        $item['title'] = trim((string)($item['title'] ?? '')) ?: 'Imported booking';
        $days[] = $item;
    }

    return $days ?: [[
        'day_date' => $existingDay ? (string)$existingDay['day_date'] : $fallbackDate,
        'title' => 'Imported booking',
    ]];
}

function normalize_imported_day_date(string $value): string
{
    if (preg_match('/\d{4}-\d{2}-\d{2}/', $value, $matches)) {
        return $matches[0];
    }

    return '';
}

function find_or_create_imported_day(PDO $pdo, int $tripId, array $itinerary, ?array $existingDay): int
{
    $dayDate = trim((string)($itinerary['day_date'] ?? ''));
    if ($existingDay && ($dayDate === '' || $dayDate === (string)$existingDay['day_date'])) {
        return (int)$existingDay['id'];
    }

    if ($dayDate !== '') {
        $stmt = $pdo->prepare('SELECT id FROM itinerary_days WHERE trip_id=? AND day_date=? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$tripId, $dayDate]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    }

    $stmt = $pdo->prepare('INSERT INTO itinerary_days (trip_id, day_date, location, title, details, transport, hotel, url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $tripId,
        $dayDate ?: date('Y-m-d'),
        $itinerary['location'] ?? '',
        $itinerary['title'] ?? 'Imported booking',
        '',
        $itinerary['transport'] ?? '',
        $itinerary['hotel'] ?? '',
        $itinerary['url'] ?? $itinerary['website'] ?? '',
    ]);

    return (int)$pdo->lastInsertId();
}

function find_duplicate_imported_document(PDO $pdo, int $tripId, string $originalName, int $fileSize, string $fileHash): bool
{
    $stmt = $pdo->prepare('SELECT id FROM day_documents WHERE trip_id=? AND (file_hash=? OR (file_hash IS NULL AND original_name=? AND file_size=?)) LIMIT 1');
    $stmt->execute([$tripId, $fileHash, $originalName, $fileSize]);

    return (bool)$stmt->fetchColumn();
}

function day_document_exists(PDO $pdo, int $tripId, int $dayId, string $originalName, int $fileSize, string $fileHash): bool
{
    $stmt = $pdo->prepare('SELECT id FROM day_documents WHERE trip_id=? AND day_id=? AND (file_hash=? OR (file_hash IS NULL AND original_name=? AND file_size=?)) LIMIT 1');
    $stmt->execute([$tripId, $dayId, $fileHash, $originalName, $fileSize]);

    return (bool)$stmt->fetchColumn();
}

function insert_day_document(PDO $pdo, int $tripId, int $dayId, string $originalName, string $storedName, string $relativePath, string $mimeType, int $fileSize, string $fileHash, string $notes, array $itinerary): void
{
    $stmt = $pdo->prepare('INSERT INTO day_documents (trip_id, day_id, original_name, stored_name, file_path, mime_type, file_size, file_hash, notes, extracted_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $tripId,
        $dayId,
        $originalName,
        $storedName,
        $relativePath,
        $mimeType,
        $fileSize,
        $fileHash,
        $notes,
        json_encode($itinerary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function extract_itinerary_from_pdf(string $filePath, string $filename, array $trip, string $dayDate, string $extraDetails, ?array $existingDay = null): array
{
    $config = holiday_config();
    $apiKey = $config['openai_api_key'] ?? '';
    $model = $config['openai_model'] ?? 'gpt-5.5';

    if ($apiKey === '' || $apiKey === 'YOUR_OPENAI_API_KEY') {
        throw new RuntimeException('OpenAI API key is missing in the private secrets file.');
    }

    $fileData = file_get_contents($filePath);
    if ($fileData === false) {
        throw new RuntimeException('Could not read the uploaded PDF.');
    }

    $prompt = "Read this booking, activity, ticket, restaurant, transport, or travel-detail PDF carefully. It may contain multiple dates, hotels, transfers, or activities. Split it into separate itinerary-day items.\n" .
        "Trip: " . ($trip['title'] ?? '') . "\n" .
        "Destination: " . ($trip['destination'] ?? '') . "\n" .
        "Trip travel dates: " . ($trip['start_date'] ?? '') . " to " . ($trip['end_date'] ?? '') . "\n" .
        "Selected day date: " . ($dayDate !== '' ? $dayDate : 'none supplied; infer the date or dates from the document') . "\n" .
        ($existingDay ? "Existing itinerary day title: " . ($existingDay['title'] ?? '') . "\nExisting location: " . ($existingDay['location'] ?? '') . "\nExisting hotel: " . ($existingDay['hotel'] ?? '') . "\n" : '') .
        "Extra details from user: {$extraDetails}\n\n" .
        "Return strict JSON only, no markdown. Format:\n" .
        "{\"days\":[{\"day_date\":\"YYYY-MM-DD\",\"title\":\"short day title\",\"location\":\"city or place\",\"hotel\":\"hotel or accommodation name if present\",\"url\":\"official hotel, booking, ticket, restaurant, or activity URL if present\",\"transport\":\"transport summary if relevant\",\"details\":\"one short sentence only\",\"arrival_date\":\"YYYY-MM-DD or original text\",\"departure_date\":\"YYYY-MM-DD or original text\",\"nights\":\"number or text\",\"check_in\":\"short time/window\",\"check_out\":\"short time/window\",\"breakfast\":\"short included/not included/time\",\"address\":\"full address\",\"latitude\":\"decimal latitude for the main location\",\"longitude\":\"decimal longitude for the main location\",\"confirmation\":\"booking/ticket number\",\"guest_name\":\"name if present\",\"room\":\"room, seat, ticket category, or unit\",\"payment\":\"short total/due/tax detail only if essential\",\"cancellation\":\"short deadline only if essential\",\"contact\":\"phone/email/contact\",\"parking\":\"short parking/access note\",\"important_notes\":[\"maximum 4 short essential notes\"]}]}\n" .
        "Create one days[] item per hotel stay, activity date, transfer date, or ticket date. Keep all fields concise. Do not include long policy text. Add real approximate latitude and longitude for the hotel, accommodation, activity venue, restaurant, station, airport, or main booked location when you can identify it. If a field is missing, use an empty string or empty array.";

    $payload = [
        'model' => $model,
        'input' => [[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'input_file',
                    'filename' => $filename,
                    'file_data' => 'data:application/pdf;base64,' . base64_encode($fileData),
                ],
                [
                    'type' => 'input_text',
                    'text' => $prompt,
                ],
            ],
        ]],
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($error ?: (string)$response);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('OpenAI returned an unreadable response.');
    }

    $text = response_output_text($data);
    if (!$text) {
        throw new RuntimeException('No text returned by OpenAI.');
    }

    $text = trim($text);
    $text = preg_replace('/^```json\s*|\s*```$/', '', $text);
    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('OpenAI did not return valid JSON.');
    }

    return $decoded;
}

function save_imported_map_point(PDO $pdo, int $tripId, array $itinerary, string $dayDate): void
{
    $latitude = filter_var($itinerary['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($itinerary['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($latitude === false || $longitude === false) {
        return;
    }

    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return;
    }

    $name = trim((string)($itinerary['hotel'] ?? '')) ?: trim((string)($itinerary['title'] ?? 'Booking location'));
    $address = trim((string)($itinerary['address'] ?? ''));
    $city = trim((string)($itinerary['location'] ?? ''));
    $pointType = trim((string)($itinerary['hotel'] ?? '')) !== '' ? 'hotel' : 'poi';
    $notes = trim(implode(' ', array_filter([
        $dayDate ? 'Imported from booking PDF for ' . $dayDate . '.' : '',
        trim((string)($itinerary['check_in'] ?? '')) ? 'Check-in: ' . trim((string)$itinerary['check_in']) . '.' : '',
        trim((string)($itinerary['confirmation'] ?? '')) ? 'Confirmation: ' . trim((string)$itinerary['confirmation']) . '.' : '',
    ])));

    $stmt = $pdo->prepare('SELECT id FROM map_points WHERE trip_id=? AND name=? AND ABS(latitude - ?) < 0.0001 AND ABS(longitude - ?) < 0.0001 LIMIT 1');
    $stmt->execute([$tripId, $name, $latitude, $longitude]);
    if ($stmt->fetch()) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO map_points (trip_id, point_type, name, address, city, latitude, longitude, notes, source, show_on_map) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$tripId, $pointType, $name, $address, $city, $latitude, $longitude, $notes, 'openai-pdf', 1]);
}

function response_output_text(array $data): string
{
    if (isset($data['output_text']) && is_string($data['output_text'])) {
        return $data['output_text'];
    }

    $parts = [];
    foreach ($data['output'] ?? [] as $output) {
        foreach ($output['content'] ?? [] as $content) {
            if (isset($content['text']) && is_string($content['text'])) {
                $parts[] = $content['text'];
            }
        }
    }

    return trim(implode("\n", $parts));
}

function format_imported_itinerary_details(array $itinerary): string
{
    $lines = [];

    if (!empty($itinerary['details'])) {
        $lines[] = trim((string)$itinerary['details']);
    }

    $fields = [
        'arrival_date' => 'Arrival',
        'departure_date' => 'Departure',
        'nights' => 'Stay',
        'check_in' => 'Check-in',
        'check_out' => 'Check-out',
        'breakfast' => 'Breakfast',
        'address' => 'Address',
        'confirmation' => 'Confirmation',
        'guest_name' => 'Guest',
        'room' => 'Room',
        'payment' => 'Payment',
        'cancellation' => 'Cancellation',
        'contact' => 'Contact',
        'parking' => 'Parking',
    ];

    foreach ($fields as $key => $label) {
        $value = trim((string)($itinerary[$key] ?? ''));
        if ($value !== '') {
            $lines[] = "{$label}: {$value}";
        }
    }

    $notes = $itinerary['important_notes'] ?? [];
    if (is_string($notes) && trim($notes) !== '') {
        $notes = [trim($notes)];
    }
    if (is_array($notes)) {
        foreach ($notes as $note) {
            $note = trim((string)$note);
            if ($note !== '') {
                $lines[] = 'Note: ' . $note;
            }
        }
    }

    return implode("\n", array_values(array_unique($lines)));
}

function booking_detail_groups(array $itinerary): array
{
    return [
        'Essentials' => [
            'Hotel' => $itinerary['hotel'] ?? '',
            'Address' => $itinerary['address'] ?? '',
            'Room' => $itinerary['room'] ?? '',
            'URL' => $itinerary['url'] ?? $itinerary['website'] ?? '',
        ],
        'Dates' => [
            'Arrival' => $itinerary['arrival_date'] ?? '',
            'Departure' => $itinerary['departure_date'] ?? '',
            'Nights' => $itinerary['nights'] ?? '',
        ],
        'Arrival' => [
            'Check-in' => $itinerary['check_in'] ?? '',
            'Check-out' => $itinerary['check_out'] ?? '',
            'Transport' => $itinerary['transport'] ?? '',
            'Parking' => $itinerary['parking'] ?? '',
        ],
        'Meals' => [
            'Breakfast' => $itinerary['breakfast'] ?? '',
        ],
        'Booking' => [
            'Confirmation' => $itinerary['confirmation'] ?? '',
            'Contact' => $itinerary['contact'] ?? '',
        ],
    ];
}

function link_detail_groups(array $details): array
{
    return [
        'Place' => [
            'Title' => $details['title'] ?? '',
            'Location' => $details['location'] ?? '',
            'Address' => $details['address'] ?? '',
        ],
        'Visit' => [
            'Opening hours' => $details['opening_hours'] ?? '',
            'Activity time' => $details['activity_time'] ?? '',
            'Price' => $details['price'] ?? '',
        ],
        'Booking' => [
            'Booking' => $details['booking'] ?? '',
            'Transport' => $details['transport'] ?? '',
            'Contact' => $details['contact'] ?? '',
            'Website' => $details['website'] ?? '',
        ],
    ];
}

function decoded_booking_details(array $document): array
{
    $json = $document['extracted_json'] ?? '';
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function decoded_link_details(array $link): array
{
    $json = $link['extracted_json'] ?? '';
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function has_group_values(array $fields): bool
{
    foreach ($fields as $value) {
        if (trim((string)$value) !== '') {
            return true;
        }
    }

    return false;
}

function short_display_value($value, int $limit = 220): string
{
    $value = trim(preg_replace('/\s+/', ' ', (string)$value));
    if (strlen($value) <= $limit) {
        return $value;
    }

    return substr($value, 0, $limit - 3) . '...';
}

function essential_notes($notes, int $max = 4): array
{
    if (is_string($notes) && trim($notes) !== '') {
        $notes = [trim($notes)];
    }
    if (!is_array($notes)) {
        return [];
    }

    $clean = [];
    foreach ($notes as $note) {
        $note = short_display_value($note, 180);
        if ($note !== '') {
            $clean[] = $note;
        }
    }

    return array_slice($clean, 0, $max);
}

function day_summary_fields(array $day): array
{
    return [
        'Date' => $day['day_date'] ?? '',
        'Day title' => $day['title'] ?? '',
        'Location' => $day['location'] ?? '',
        'Hotel' => $day['hotel'] ?? '',
        'Transport' => $day['transport'] ?? '',
        'URL' => $day['url'] ?? '',
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Holiday Planner</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link href="assets/app.css" rel="stylesheet">
</head>
<body>
<div id="openaiThinking" class="openai-thinking" aria-live="polite" aria-hidden="true">
    <div class="openai-thinking-box">
        <div class="spinner-border text-primary" role="status"></div>
        <div>
            <strong>OpenAI is thinking</strong>
            <div class="text-secondary">Analysing your travel information...</div>
        </div>
    </div>
</div>
<script>
window.showOpenAiThinking = function(message = 'Analysing your travel information...') {
    const overlay = document.getElementById('openaiThinking');
    if (!overlay) return;
    const text = overlay.querySelector('.text-secondary');
    if (text) text.textContent = message;
    overlay.classList.add('is-visible');
    overlay.setAttribute('aria-hidden', 'false');
};

document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    const action = form.querySelector('input[name="action"]')?.value;
    if (!['import_day_pdf', 'add_day_link', 'cleanup_duplicate_documents'].includes(action)) return;
    if (form.dataset.openaiSubmitting === '1') return;

    event.preventDefault();
    const messages = {
        import_day_pdf: 'Reading the document and dividing details by day...',
        add_day_link: 'Reading the website and extracting useful day details...',
        cleanup_duplicate_documents: 'Checking uploaded documents and removing duplicates...'
    };
    window.showOpenAiThinking(messages[action] || 'Analysing your travel information...');
    form.dataset.openaiSubmitting = '1';
    form.querySelectorAll('button').forEach(button => button.disabled = true);
    requestAnimationFrame(() => window.setTimeout(() => form.submit(), 120));
}, true);
</script>
<div class="page"><div class="page-wrapper">
    <div class="page-header d-print-none"><div class="container-xl"><div class="row g-2 align-items-center">
        <div class="col-12 col-md"><h2 class="page-title"><i class="ti ti-plane-departure me-2"></i>Holiday Planner</h2><div class="text-secondary">Trips · flights · itinerary · map · OpenAI POI suggestions</div></div>
        <div class="col-12 col-md-auto d-flex flex-wrap gap-2 app-header-actions">
            <?php if ($trip): ?>
                <a class="btn btn-outline-primary" href="calendar.php?trip_id=<?= (int)$tripId ?>"><i class="ti ti-calendar-event me-1"></i>Calendar</a>
                <a class="btn btn-outline-primary" href="itinerary-pdf.php?trip_id=<?= (int)$tripId ?>" target="_blank" rel="noopener"><i class="ti ti-file-type-pdf me-1"></i>Itinerary PDF</a>
                <form method="post" class="m-0">
                    <input type="hidden" name="action" value="cleanup_duplicate_documents">
                    <input type="hidden" name="trip_id" value="<?= (int)$tripId ?>">
                    <button class="btn btn-outline-warning"><i class="ti ti-copy-off me-1"></i>Analyse duplicates</button>
                </form>
            <?php endif; ?>
            <form method="post" class="m-0">
                <input type="hidden" name="action" value="git_pull">
                <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                <button class="btn btn-outline-secondary"><i class="ti ti-git-pull-request me-1"></i>Git pull</button>
            </form>
        </div>
    </div></div></div>

    <div class="page-body"><div class="container-xl"><div class="row row-cards">
        <?php if ($gitPullResult): ?>
            <div class="col-12 no-print"><div class="alert alert-<?= h($gitPullResult['type']) ?> mb-0">
                <strong>Git pull</strong>
                <pre class="mb-0 mt-2"><?= h($gitPullResult['message'] ?: 'No output returned.') ?></pre>
            </div></div>
        <?php endif; ?>
        <?php if ($documentImportResult): ?>
            <div class="col-12 no-print"><div class="alert alert-<?= h($documentImportResult['type']) ?> mb-0">
                <strong>PDF itinerary import</strong>
                <div class="mt-2"><?= nl2br(h($documentImportResult['message'])) ?></div>
            </div></div>
        <?php endif; ?>
        <div class="col-lg-3 no-print app-sidebar">
            <div class="card"><div class="card-header"><h3 class="card-title">Trips</h3></div><div class="list-group list-group-flush">
                <?php foreach ($trips as $t): ?><a href="?trip_id=<?= (int)$t['id'] ?>" class="list-group-item <?= $tripId === (int)$t['id'] ? 'active' : '' ?>"><strong><?= h($t['title']) ?></strong><br><small><?= h($t['destination']) ?></small></a><?php endforeach; ?>
            </div></div>
            <div class="card mt-3"><div class="card-header"><h3 class="card-title">New trip</h3></div><form method="post" class="card-body">
                <input type="hidden" name="action" value="create_trip">
                <input name="title" class="form-control mb-2" required placeholder="Taiwan 2026">
                <input name="destination" class="form-control mb-2" placeholder="Taiwan">
                <div class="row g-2"><div class="col"><input name="start_date" type="date" class="form-control"></div><div class="col"><input name="end_date" type="date" class="form-control"></div></div>
                <textarea name="notes" class="form-control mt-2" rows="3" placeholder="Trip notes"></textarea>
                <button class="btn btn-primary mt-3 w-100"><i class="ti ti-plus me-1"></i>Create trip</button>
            </form></div>
            <?php if ($trip && $days): ?>
                <div class="card mt-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-calendar-event me-2"></i>Calendar</h3></div><div class="list-group list-group-flush">
                    <?php foreach ($days as $d): ?>
                        <a href="#day-<?= (int)$d['id'] ?>" class="list-group-item">
                            <strong><?= h($d['day_date']) ?></strong><br>
                            <small><?= h($d['title'] ?: $d['location']) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div></div>
                <div class="card mt-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-list-check me-2"></i>Planning</h3></div><div class="list-group list-group-flush">
                    <?php foreach ($days as $d): ?>
                        <a href="#day-<?= (int)$d['id'] ?>" class="list-group-item">
                            <strong><?= h($d['title'] ?: 'Untitled day') ?></strong>
                            <div class="text-secondary small"><?= h($d['location'] ?: $d['hotel']) ?></div>
                            <?php if ($d['hotel']): ?><span class="badge bg-green-lt mt-1"><?= h($d['hotel']) ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div></div>
            <?php endif; ?>
        </div>

        <div class="col-lg-9">
        <?php if (!$trip): ?>
            <div class="empty"><div class="empty-icon"><i class="ti ti-map-2"></i></div><p class="empty-title">No trip yet</p><p class="empty-subtitle text-secondary">Create your first holiday plan.</p></div>
        <?php else: ?>
            <div class="card mb-3"><div class="card-body">
                <div class="row g-3 align-items-start">
                    <div class="col-12 col-md"><h2 class="h2 mb-1"><?= h($trip['title']) ?></h2><div class="text-secondary"><?= h($trip['destination']) ?> · <?= h($trip['start_date']) ?> to <?= h($trip['end_date']) ?></div><p class="mt-2"><?= nl2br(h($trip['notes'])) ?></p></div>
                    <div class="col-12 col-md-4"><div class="text-secondary mb-1">Packing progress</div><div class="progress progress-lg"><div class="progress-bar bg-success" style="width: <?= $packedPercent ?>%"><?= $packedPercent ?>%</div></div><div class="text-secondary mt-1"><?= $packedItems ?> / <?= $totalItems ?> packed</div></div>
                </div>
                <form method="post" class="row g-2 align-items-end no-print mt-3">
                    <input type="hidden" name="action" value="update_trip">
                    <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                    <div class="col-md-3"><label class="form-label">Trip title</label><input name="title" class="form-control" required value="<?= h($trip['title']) ?>"></div>
                    <div class="col-md-3"><label class="form-label">Destination</label><input name="destination" class="form-control" value="<?= h($trip['destination']) ?>"></div>
                    <div class="col-md-2"><label class="form-label">Travel from</label><input name="start_date" type="date" class="form-control" value="<?= h($trip['start_date']) ?>"></div>
                    <div class="col-md-2"><label class="form-label">Travel to</label><input name="end_date" type="date" class="form-control" value="<?= h($trip['end_date']) ?>"></div>
                    <div class="col-12 col-md-2"><button class="btn btn-outline-primary w-100"><i class="ti ti-device-floppy me-1"></i>Save trip</button></div>
                    <div class="col-12"><textarea name="notes" class="form-control" rows="2" placeholder="Trip notes"><?= h($trip['notes']) ?></textarea></div>
                </form>
            </div></div>

            <div class="card mb-3 no-print">
                <div class="card-header"><h3 class="card-title"><i class="ti ti-file-import me-2"></i>Import travel document</h3></div>
                <form method="post" enctype="multipart/form-data" class="card-body row g-2 align-items-end">
                    <input type="hidden" name="action" value="import_day_pdf">
                    <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                    <div class="col-md-5"><label class="form-label">Booking, hotel, ticket or activity PDF</label><input name="booking_pdf" type="file" accept="application/pdf,.pdf" class="form-control" required></div>
                    <div class="col-md-5"><label class="form-label">Extra details for OpenAI</label><input name="extra_details" class="form-control" placeholder="Room preferences, arrival time, meeting point"></div>
                    <div class="col-12 col-md-2"><button class="btn btn-outline-primary w-100"><i class="ti ti-sparkles me-1"></i>Import</button></div>
                    <div class="col-12 text-secondary small">OpenAI will find the date or dates in the document and divide the information over the right itinerary days.</div>
                </form>
            </div>

            <div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-map-pin me-2"></i>Map: hotels, parking and POI</h3></div>
                <div class="px-3 py-2 border-bottom bg-light text-secondary small">
                    <?php if (count($visiblePoints) > 1): ?>
                        <i class="ti ti-route me-1"></i>Route shown for <?= count($visiblePoints) ?> checked locations, sorted by date.
                    <?php elseif (count($visiblePoints) === 1): ?>
                        <i class="ti ti-route me-1"></i>Check at least one more location to show the route line.
                    <?php else: ?>
                        <i class="ti ti-route me-1"></i>No checked locations are visible on the map.
                    <?php endif; ?>
                </div>
                <div id="map"></div>
                <div class="card-body no-print"><div class="row g-2">
                    <form method="post" class="row g-2 col-12">
                        <input type="hidden" name="action" value="add_point"><input type="hidden" name="trip_id" value="<?= $tripId ?>">
                        <div class="col-md-2"><select name="point_type" class="form-select"><option value="hotel">Hotel</option><option value="parking">Parking</option><option value="poi">POI</option><option value="restaurant">Restaurant</option><option value="transport">Transport</option><option value="other">Other</option></select></div>
                        <div class="col-md-3"><input name="name" class="form-control" required placeholder="Name"></div>
                        <div class="col-md-2"><input name="city" class="form-control" placeholder="City"></div>
                        <div class="col-md-2"><input name="latitude" class="form-control" required placeholder="Latitude"></div>
                        <div class="col-md-2"><input name="longitude" class="form-control" required placeholder="Longitude"></div>
                        <div class="col-12 col-md-1"><button class="btn btn-primary w-100">Add</button></div>
                        <div class="col-md-6"><input name="address" class="form-control" placeholder="Address"></div>
                        <div class="col-md-4"><input name="notes" class="form-control" placeholder="Notes"></div>
                        <div class="col-md-2"><label class="form-check mt-2"><input name="show_on_map" class="form-check-input" type="checkbox" checked><span class="form-check-label">Show on map</span></label></div>
                    </form>
                    <?php if ($points): ?>
                        <div class="col-12 mt-3">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                <h4 class="mb-0">All locations</h4>
                                <span class="text-secondary"><?= count($points) ?> saved</span>
                            </div>
                            <div class="list-group">
                                <?php foreach ($points as $point): ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-center g-2">
                                            <div class="col">
                                                <div class="d-flex flex-wrap align-items-center gap-2">
                                                    <strong><?= h($point['name']) ?></strong>
                                                    <?php if (point_itinerary_date($point)): ?><span class="badge bg-orange-lt"><?= h(point_itinerary_date($point)) ?></span><?php endif; ?>
                                                    <span class="badge bg-blue-lt"><?= h($point['point_type']) ?></span>
                                                    <span class="badge <?= (int)($point['show_on_map'] ?? 1) === 1 ? 'bg-green-lt' : 'bg-secondary-lt' ?>"><?= (int)($point['show_on_map'] ?? 1) === 1 ? 'map' : 'hidden' ?></span>
                                                    <?php if ($point['source']): ?><span class="badge bg-green-lt"><?= h($point['source']) ?></span><?php endif; ?>
                                                </div>
                                                <div class="text-secondary"><?= h($point['address'] ?: $point['city']) ?></div>
                                                <?php if ($point['notes']): ?><div class="small mt-1"><?= h($point['notes']) ?></div><?php endif; ?>
                                            </div>
                                            <div class="col-12 col-sm-auto d-flex flex-wrap gap-2 app-item-actions">
                                                <form method="post" class="d-flex align-items-center">
                                                    <input type="hidden" name="action" value="toggle_point_map">
                                                    <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                                                    <input type="hidden" name="point_id" value="<?= (int)$point['id'] ?>">
                                                    <label class="form-check mb-0"><input name="show_on_map" class="form-check-input" type="checkbox" <?= (int)($point['show_on_map'] ?? 1) === 1 ? 'checked' : '' ?> onchange="this.form.submit()"><span class="form-check-label">Map</span></label>
                                                </form>
                                                <?php if ((int)($point['show_on_map'] ?? 1) === 1): ?><button type="button" class="btn btn-sm btn-outline-primary" onclick="focusLocation(<?= (int)$point['id'] ?>)"><i class="ti ti-map-pin me-1"></i>View</button><?php endif; ?>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="delete_point">
                                                    <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                                                    <input type="hidden" name="point_id" value="<?= (int)$point['id'] ?>">
                                                    <button class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div></div>
            </div>

            <div class="card mb-3 no-print"><div class="card-header"><h3 class="card-title"><i class="ti ti-sparkles me-2"></i>OpenAI POI suggestions</h3></div><div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4"><label class="form-label">Destination</label><input id="aiDestination" class="form-control" value="<?= h($trip['destination']) ?>"></div>
                    <div class="col-md-5"><label class="form-label">Interests</label><input id="aiInterests" class="form-control" value="culture, food, nature, accessible travel, birdwatching"></div>
                    <div class="col-md-2"><label class="form-label">Days</label><input id="aiDays" type="number" class="form-control" value="<?= max(1, count($days)) ?>"></div>
                    <div class="col-12 col-md-1"><button type="button" onclick="suggestPoi()" class="btn btn-primary w-100">Go</button></div>
                </div>
                <div id="aiResults" class="mt-3"></div>
            </div></div>

            <div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-plane me-2"></i>Flights</h3></div><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>Date</th><th>Flight</th><th>Route</th><th>Time</th><th class="no-print"></th></tr></thead><tbody>
                <?php foreach ($flights as $f): ?><tr><td><?= h($f['flight_date']) ?></td><td><strong><?= h($f['airline']) ?></strong><br><span class="text-secondary"><?= h($f['flight_number']) ?></span></td><td><?= h($f['departure_airport']) ?> → <?= h($f['arrival_airport']) ?></td><td><?= h($f['departure_time']) ?> - <?= h($f['arrival_time']) ?></td><td class="no-print"><form method="post"><input type="hidden" name="action" value="delete_flight"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><input type="hidden" name="flight_id" value="<?= (int)$f['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button></form></td></tr><?php endforeach; ?>
            </tbody></table></div><form method="post" class="card-body no-print row g-2"><input type="hidden" name="action" value="add_flight"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><div class="col-md-2"><input name="flight_date" type="date" class="form-control"></div><div class="col-md-2"><input name="airline" class="form-control" placeholder="Airline"></div><div class="col-md-2"><input name="flight_number" class="form-control" placeholder="Flight no."></div><div class="col-md-2"><input name="departure_airport" class="form-control" placeholder="From"></div><div class="col-md-2"><input name="arrival_airport" class="form-control" placeholder="To"></div><div class="col-md-1"><input name="departure_time" type="time" class="form-control"></div><div class="col-md-1"><input name="arrival_time" type="time" class="form-control"></div><div class="col-12 col-md-10"><input name="notes" class="form-control" placeholder="Notes"></div><div class="col-12 col-md-2"><button class="btn btn-primary w-100">Add flight</button></div></form></div>

            <div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-calendar-event me-2"></i>Itinerary</h3></div><div class="list-group list-group-flush">
                <?php foreach ($days as $d): $hasDocuments = !empty($documentsByDay[(int)$d['id']]); ?><div id="day-<?= (int)$d['id'] ?>" class="list-group-item itinerary-day"><div class="row g-2"><div class="col-12 col-md"><strong><?= h($d['day_date']) ?> · <?= h($d['title']) ?></strong><div class="text-secondary"><?= h($d['location']) ?></div><?php if (!$hasDocuments && trim((string)$d['details']) !== ''): ?><p class="mt-2"><?= nl2br(h($d['details'])) ?></p><?php endif; ?><span class="badge bg-blue-lt">Transport: <?= h($d['transport']) ?></span> <span class="badge bg-green-lt">Hotel: <?= h($d['hotel']) ?></span><?php if (!empty($d['url'])): ?> <a class="badge bg-purple-lt text-decoration-none" href="<?= h($d['url']) ?>" target="_blank" rel="noopener">URL</a><?php endif; ?>
                    <?php if (!empty($documentsByDay[(int)$d['id']])): ?><div class="mt-3 no-print">
                        <?php foreach ($documentsByDay[(int)$d['id']] as $document): $bookingDetails = decoded_booking_details($document); ?>
                            <div class="border rounded p-3 mb-2">
                                <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center mb-2">
                                    <strong><i class="ti ti-file-type-pdf me-1"></i><?= h($document['original_name']) ?></strong>
                                    <a class="btn btn-sm btn-outline-secondary" href="document.php?id=<?= (int)$document['id'] ?>" target="_blank" rel="noopener">Open</a>
                                </div>
                                <div class="row g-2 mb-3">
                                    <?php foreach (day_summary_fields($d) as $label => $value): ?>
                                        <?php if (trim((string)$value) !== ''): ?>
                                            <div class="col-md-4">
                                                <div class="text-secondary small text-uppercase"><?= h($label) ?></div>
                                                <div><?= h($value) ?></div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($bookingDetails): ?>
                                    <div class="row g-2">
                                        <?php foreach (booking_detail_groups($bookingDetails) as $groupTitle => $fields): ?>
                                            <?php if (has_group_values($fields)): ?>
                                                <div class="col-md-6">
                                                    <div class="text-secondary small text-uppercase"><?= h($groupTitle) ?></div>
                                                    <dl class="row mb-0">
                                                        <?php foreach ($fields as $label => $value): ?>
                                                            <?php if (trim((string)$value) !== ''): ?>
                                                                <dt class="col-sm-4"><?= h($label) ?></dt>
                                                                <dd class="col-sm-8"><?= nl2br(h(short_display_value($value))) ?></dd>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </dl>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php $importantNotes = essential_notes($bookingDetails['important_notes'] ?? []); ?>
                                        <?php if ($importantNotes): ?>
                                            <div class="col-12">
                                                <div class="text-secondary small text-uppercase">Important notes</div>
                                                <ul class="mb-0 ps-3">
                                                    <?php foreach ($importantNotes as $note): ?>
                                                        <li><?= h($note) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div><?php endif; ?>
                    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end no-print mt-3">
                        <input type="hidden" name="action" value="import_day_pdf">
                        <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                        <input type="hidden" name="day_id" value="<?= (int)$d['id'] ?>">
                        <div class="col-md-4"><label class="form-label">Add document to this day</label><input name="booking_pdf" type="file" accept="application/pdf,.pdf" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Extra details for OpenAI</label><input name="extra_details" class="form-control" placeholder="Activity time, tickets, meeting point"></div>
                        <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="ti ti-file-plus me-1"></i>Add</button></div>
                    </form>
                    <?php if (!empty($linksByDay[(int)$d['id']])): ?><div class="mt-3 no-print">
                        <div class="text-secondary small text-uppercase mb-2">Websites</div>
                        <?php foreach ($linksByDay[(int)$d['id']] as $link): $linkDetails = decoded_link_details($link); ?>
                            <div class="border rounded p-3 mb-2">
                                <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center">
                                    <div>
                                        <strong><i class="ti ti-world me-1"></i><?= h($link['title']) ?></strong>
                                        <div class="text-secondary small"><?= h($link['url']) ?></div>
                                        <?php if ($link['notes']): ?><div class="mt-1"><?= nl2br(h($link['notes'])) ?></div><?php endif; ?>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 app-item-actions">
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= h($link['url']) ?>" target="_blank" rel="noopener">Open</a>
                                        <form method="post">
                                            <input type="hidden" name="action" value="delete_link">
                                            <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                                            <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
                                <?php if ($linkDetails): ?>
                                    <div class="row g-2 mt-3">
                                        <?php foreach (link_detail_groups($linkDetails) as $groupTitle => $fields): ?>
                                            <?php if (has_group_values($fields)): ?>
                                                <div class="col-md-6">
                                                    <div class="text-secondary small text-uppercase"><?= h($groupTitle) ?></div>
                                                    <dl class="row mb-0">
                                                        <?php foreach ($fields as $label => $value): ?>
                                                            <?php if (trim((string)$value) !== ''): ?>
                                                                <dt class="col-sm-4"><?= h($label) ?></dt>
                                                                <dd class="col-sm-8"><?= nl2br(h($value)) ?></dd>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </dl>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php $linkNotes = $linkDetails['important_notes'] ?? []; ?>
                                        <?php if (is_array($linkNotes) && count(array_filter($linkNotes))): ?>
                                            <div class="col-12">
                                                <div class="text-secondary small text-uppercase">Important notes</div>
                                                <ul class="mb-0 ps-3">
                                                    <?php foreach ($linkNotes as $note): ?>
                                                        <?php if (trim((string)$note) !== ''): ?><li><?= h($note) ?></li><?php endif; ?>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div><?php endif; ?>
                    <form method="post" class="row g-2 align-items-end no-print mt-3">
                        <input type="hidden" name="action" value="add_day_link">
                        <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                        <input type="hidden" name="day_id" value="<?= (int)$d['id'] ?>">
                        <div class="col-md-3"><label class="form-label">Website title</label><input name="title" class="form-control" placeholder="Museum info"></div>
                        <div class="col-md-4"><label class="form-label">Website URL</label><input name="url" class="form-control" required placeholder="https://..."></div>
                        <div class="col-md-3"><label class="form-label">Notes</label><input name="notes" class="form-control" placeholder="Why this is useful"></div>
                        <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="ti ti-link-plus me-1"></i>Add link</button></div>
                    </form>
                </div><div class="col-12 col-md-auto no-print"><form method="post"><input type="hidden" name="action" value="delete_day"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><input type="hidden" name="day_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm btn-outline-danger app-delete-day"><i class="ti ti-trash"></i></button></form></div></div></div><?php endforeach; ?>
            </div><div class="card-body no-print">
                <form method="post" class="row g-2"><input type="hidden" name="action" value="add_day"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><div class="col-md-2"><input name="day_date" type="date" class="form-control" required></div><div class="col-md-3"><input name="location" class="form-control" placeholder="Location"></div><div class="col-md-4"><input name="title" class="form-control" placeholder="Day title"></div><div class="col-md-3"><input name="hotel" class="form-control" placeholder="Hotel"></div><div class="col-md-4"><input name="transport" class="form-control" placeholder="Transport"></div><div class="col-md-8"><input name="url" type="url" class="form-control" placeholder="Hotel or activity URL"></div><div class="col-md-8"><textarea name="details" class="form-control" rows="2" placeholder="Plans, sights, restaurants, notes"></textarea></div><div class="col-12"><button class="btn btn-primary">Add day</button></div></form>
                <?php if ($days): ?>
                    <hr>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="action" value="add_day_link">
                        <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                        <div class="col-md-3"><label class="form-label">Day</label><select name="day_id" class="form-select" required>
                            <?php foreach ($days as $dayOption): ?>
                                <option value="<?= (int)$dayOption['id'] ?>"><?= h($dayOption['day_date']) ?> · <?= h($dayOption['title'] ?: $dayOption['location']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                        <div class="col-md-3"><label class="form-label">Website title</label><input name="title" class="form-control" placeholder="Official info"></div>
                        <div class="col-md-3"><label class="form-label">Website URL</label><input name="url" class="form-control" required placeholder="https://..."></div>
                        <div class="col-md-2"><label class="form-label">Notes</label><input name="notes" class="form-control" placeholder="Opening times, tips"></div>
                        <div class="col-12 col-md-1"><button class="btn btn-outline-primary w-100"><i class="ti ti-link-plus"></i></button></div>
                    </form>
                <?php endif; ?>
            </div></div>

            <div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-backpack me-2"></i>Packing checklist</h3></div><div class="list-group list-group-flush">
                <?php foreach ($items as $i): ?><div class="list-group-item"><div class="row g-2 align-items-center"><div class="col-auto no-print"><form method="post"><input type="hidden" name="action" value="toggle_packed"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><input type="hidden" name="item_id" value="<?= (int)$i['id'] ?>"><button class="btn btn-sm <?= $i['packed'] ? 'btn-success' : 'btn-outline-secondary' ?>"><i class="ti ti-check"></i></button></form></div><div class="col"><span class="<?= $i['packed'] ? 'packed' : '' ?>"><strong><?= h($i['category']) ?>:</strong> <?= h($i['item']) ?></span></div><div class="col-auto text-secondary"><?= h($i['quantity']) ?></div><div class="col-auto no-print"><form method="post"><input type="hidden" name="action" value="delete_item"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><input type="hidden" name="item_id" value="<?= (int)$i['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button></form></div></div></div><?php endforeach; ?>
            </div><form method="post" class="card-body no-print row g-2"><input type="hidden" name="action" value="add_item"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><div class="col-md-3"><input name="category" class="form-control" placeholder="Category"></div><div class="col-md-6"><input name="item" class="form-control" placeholder="Item" required></div><div class="col-md-2"><input name="quantity" class="form-control" placeholder="Quantity"></div><div class="col-12 col-md-1"><button class="btn btn-primary w-100">Add</button></div></form></div>
        <?php endif; ?>
        </div>
    </div></div></div>
</div></div>

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const tripId = <?= (int)$tripId ?>;
const mapPoints = <?= $mapJson ?: '[]' ?>;
const defaultCenter = mapPoints.length ? [parseFloat(mapPoints[0].latitude), parseFloat(mapPoints[0].longitude)] : [23.6978, 120.9605];
const map = L.map('map').setView(defaultCenter, mapPoints.length ? 12 : 7);
L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors &copy; CARTO'
}).addTo(map);
const iconMap = {hotel:'🏨', parking:'🅿️', poi:'📍', restaurant:'🍜', transport:'🚆', other:'⭐'};
const markers = [];
const markersById = {};
const routeLatLngs = [];
mapPoints.forEach(p => {
    const latLng = [parseFloat(p.latitude), parseFloat(p.longitude)];
    routeLatLngs.push(latLng);
    const marker = L.marker(latLng).addTo(map);
    marker.bindPopup(`<div class="map-popup-title">${iconMap[p.point_type] || '📍'} ${escapeHtml(p.name)}</div><div>${escapeHtml(p.address || p.city || '')}</div><div class="text-secondary">${escapeHtml(p.notes || '')}</div>`);
    markers.push(marker);
    markersById[p.id] = marker;
});
let routeHalo = null;
let routeLine = null;
const routeArrows = L.layerGroup().addTo(map);
const routeSegments = [];
if (routeLatLngs.length > 1) {
    const curvedRoute = [];
    for (let i = 0; i < routeLatLngs.length - 1; i++) {
        const segment = geodesicSegment(routeLatLngs[i], routeLatLngs[i + 1], 48);
        routeSegments.push(segment);
        curvedRoute.push(...(i === 0 ? segment : segment.slice(1)));
    }
    routeHalo = L.polyline(curvedRoute, {
        color: '#ffffff',
        weight: 9,
        opacity: 0.95,
        lineCap: 'round'
    }).addTo(map);
    routeLine = L.polyline(curvedRoute, {
        color: '#d63939',
        weight: 4,
        opacity: 0.95,
        lineCap: 'round'
    }).addTo(map);
    drawRouteArrows();
    map.on('zoomend moveend', drawRouteArrows);
}
if (markers.length > 1) map.fitBounds(L.featureGroup(markers).getBounds().pad(0.2));
if (routeHalo) routeHalo.bringToFront();
if (routeLine) routeLine.bringToFront();
markers.forEach(marker => marker.bringToFront());

function drawRouteArrows() {
    routeArrows.clearLayers();
    routeSegments.forEach(segment => {
        if (segment.length < 3) return;

        const middleIndex = Math.floor(segment.length / 2);
        const midpoint = L.latLng(segment[middleIndex]);
        const beforePoint = map.latLngToLayerPoint(L.latLng(segment[middleIndex - 1]));
        const afterPoint = map.latLngToLayerPoint(L.latLng(segment[middleIndex + 1]));
        const angle = Math.atan2(afterPoint.y - beforePoint.y, afterPoint.x - beforePoint.x) * 180 / Math.PI;

        L.marker(midpoint, {
            interactive: false,
            icon: L.divIcon({
                className: 'route-arrow-icon',
                html: `<svg class="route-arrow" viewBox="0 0 24 24" style="transform: rotate(${angle}deg)" aria-hidden="true"><path d="M3 10h12V5l7 7-7 7v-5H3z"></path></svg>`,
                iconSize: [30, 30],
                iconAnchor: [15, 15]
            })
        }).addTo(routeArrows);
    });
}

function geodesicSegment(start, end, steps) {
    const lat1 = toRad(start[0]);
    const lon1 = toRad(start[1]);
    const lat2 = toRad(end[0]);
    const lon2 = toRad(end[1]);
    const delta = 2 * Math.asin(Math.sqrt(
        Math.sin((lat2 - lat1) / 2) ** 2 +
        Math.cos(lat1) * Math.cos(lat2) * Math.sin((lon2 - lon1) / 2) ** 2
    ));

    if (!delta) return [start, end];

    const points = [];
    for (let i = 0; i <= steps; i++) {
        const f = i / steps;
        const a = Math.sin((1 - f) * delta) / Math.sin(delta);
        const b = Math.sin(f * delta) / Math.sin(delta);
        const x = a * Math.cos(lat1) * Math.cos(lon1) + b * Math.cos(lat2) * Math.cos(lon2);
        const y = a * Math.cos(lat1) * Math.sin(lon1) + b * Math.cos(lat2) * Math.sin(lon2);
        const z = a * Math.sin(lat1) + b * Math.sin(lat2);
        points.push([toDeg(Math.atan2(z, Math.sqrt(x * x + y * y))), toDeg(Math.atan2(y, x))]);
    }

    return points;
}

function toRad(degrees) { return degrees * Math.PI / 180; }
function toDeg(radians) { return radians * 180 / Math.PI; }

function focusLocation(pointId) {
    const marker = markersById[pointId];
    if (!marker) return;
    map.setView(marker.getLatLng(), 16);
    marker.openPopup();
    document.getElementById('map').scrollIntoView({behavior: 'smooth', block: 'center'});
}

function escapeHtml(str) {
    return String(str).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
}

async function suggestPoi() {
    const box = document.getElementById('aiResults');
    window.showOpenAiThinking('Finding useful POI suggestions...');
    box.innerHTML = '<div class="alert alert-info">Asking OpenAI for POI suggestions...</div>';
    try {
        const res = await fetch('api/suggest-poi.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                destination: document.getElementById('aiDestination').value,
                interests: document.getElementById('aiInterests').value,
                days: document.getElementById('aiDays').value
            })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Request failed');
        renderAiResults(data.points || []);
    } catch (err) {
        box.innerHTML = `<div class="alert alert-danger">${escapeHtml(err.message)}</div>`;
    } finally {
        const overlay = document.getElementById('openaiThinking');
        if (overlay) {
            overlay.classList.remove('is-visible');
            overlay.setAttribute('aria-hidden', 'true');
        }
    }
}

function renderAiResults(points) {
    const box = document.getElementById('aiResults');
    if (!points.length) { box.innerHTML = '<div class="alert alert-warning">No suggestions returned.</div>'; return; }
    box.innerHTML = '<div class="row row-cards">' + points.map(p => `
        <div class="col-md-6"><div class="card"><div class="card-body">
            <h4>${escapeHtml(p.name || '')}</h4>
            <div class="text-secondary">${escapeHtml(p.city || '')} · ${escapeHtml(p.type || 'poi')} · ${escapeHtml(p.latitude || '')}, ${escapeHtml(p.longitude || '')}</div>
            <p>${escapeHtml(p.reason || '')}</p>
            <form method="post">
                <input type="hidden" name="action" value="save_ai_point"><input type="hidden" name="trip_id" value="${tripId}">
                <input type="hidden" name="point_type" value="${escapeHtml(p.type || 'poi')}">
                <input type="hidden" name="name" value="${escapeHtml(p.name || '')}">
                <input type="hidden" name="city" value="${escapeHtml(p.city || '')}">
                <input type="hidden" name="latitude" value="${escapeHtml(p.latitude || '')}">
                <input type="hidden" name="longitude" value="${escapeHtml(p.longitude || '')}">
                <input type="hidden" name="notes" value="${escapeHtml(p.reason || '')}">
                <button class="btn btn-outline-primary btn-sm">Save to map</button>
            </form>
        </div></div></div>`).join('') + '</div>';
}
</script>
</body>
</html>
