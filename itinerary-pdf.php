<?php
require __DIR__ . '/includes/db.php';
ensure_day_documents_table($pdo);
ensure_day_links_table($pdo);

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('Dompdf is not installed. Run: composer install');
}

require $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

$tripId = (int)($_GET['trip_id'] ?? 0);
if ($tripId <= 0) {
    http_response_code(404);
    exit('Trip not found.');
}

$stmt = $pdo->prepare('SELECT * FROM trips WHERE id=?');
$stmt->execute([$tripId]);
$trip = $stmt->fetch();
if (!$trip) {
    http_response_code(404);
    exit('Trip not found.');
}

$stmt = $pdo->prepare('SELECT * FROM itinerary_days WHERE trip_id=? ORDER BY day_date ASC, id ASC');
$stmt->execute([$tripId]);
$days = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM flights WHERE trip_id=? ORDER BY flight_date ASC, departure_time ASC, id ASC');
$stmt->execute([$tripId]);
$flights = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM day_documents WHERE trip_id=? ORDER BY created_at ASC, id ASC');
$stmt->execute([$tripId]);
$documentsByDay = group_by_day($stmt->fetchAll());

$stmt = $pdo->prepare('SELECT * FROM day_links WHERE trip_id=? ORDER BY created_at ASC, id ASC');
$stmt->execute([$tripId]);
$linksByDay = group_by_day($stmt->fetchAll());

$html = render_itinerary_pdf_html($trip, $days, $flights, $documentsByDay, $linksByDay, request_base_url());

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = safe_pdf_filename(($trip['title'] ?: 'itinerary') . '-itinerary.pdf');
$dompdf->stream($filename, ['Attachment' => false]);

function group_by_day(array $rows): array
{
    $grouped = [];
    foreach ($rows as $row) {
        $dayId = (int)$row['day_id'];
        $grouped[$dayId][] = $row;
    }

    return $grouped;
}

function render_itinerary_pdf_html(array $trip, array $days, array $flights, array $documentsByDay, array $linksByDay, string $baseUrl): string
{
    ob_start();
    ?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 32px 36px; }
        body { background: #ffffff; color: #1f2937; font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.45; margin: 0; }
        h1, h2, h3 { color: #111827; margin: 0; }
        h1 { font-size: 28px; line-height: 1.1; }
        h2 { font-size: 18px; margin-bottom: 10px; }
        h3 { font-size: 13px; margin-bottom: 6px; }
        .cover { background: #f3f7fb; border: 1px solid #c8d7ee; border-left: 8px solid #2563eb; border-radius: 10px; padding: 22px 24px; page-break-inside: avoid; }
        .subtitle { color: #4b5563; font-size: 13px; margin-top: 8px; }
        .trip-notes { margin-top: 14px; white-space: pre-line; }
        .meta { border-collapse: separate; border-spacing: 8px 0; margin-left: -8px; margin-top: 18px; width: 100%; }
        .meta td { background: #fff; border: 1px solid #cbd8ea; border-radius: 6px; padding: 9px; vertical-align: top; width: 33.33%; }
        .label { color: #64748b; display: block; font-size: 8px; font-weight: bold; letter-spacing: .08em; text-transform: uppercase; }
        .value { display: block; font-size: 11px; margin-top: 3px; }
        .section { margin-top: 22px; }
        .section-title { border-bottom: 2px solid #2563eb; color: #1e3a8a; margin-bottom: 12px; padding-bottom: 6px; page-break-after: avoid; page-break-inside: avoid; }
        .flight-wrap { border: 1px solid #cbd8ea; border-radius: 8px; page-break-inside: avoid; }
        .flight-table { border-collapse: collapse; width: 100%; }
        .flight-table tr { page-break-inside: avoid; }
        .flight-table th { background: #e8f0fb; border-bottom: 1px solid #cbd8ea; color: #334155; font-size: 9px; letter-spacing: .06em; padding: 8px; text-align: left; text-transform: uppercase; }
        .flight-table td { border-bottom: 1px solid #e5eaf2; padding: 8px; vertical-align: top; }
        .flight-table tr:last-child td { border-bottom: 0; }
        .day { border: 1px solid #cbd8ea; border-radius: 10px; margin-top: 18px; page-break-inside: auto; }
        .day.first-day { margin-top: 0; }
        .day-header { background: #f3f7fb; border-bottom: 1px solid #cbd8ea; border-left: 6px solid #2563eb; padding: 12px 14px; page-break-after: avoid; page-break-inside: avoid; }
        .day-body { padding: 12px 14px 14px; }
        .day-title { display: table; width: 100%; }
        .day-date { color: #2563eb; display: table-cell; font-size: 12px; font-weight: bold; white-space: nowrap; width: 105px; }
        .day-main { display: table-cell; }
        .pill { background: #eef2ff; border: 1px solid #d8defc; border-radius: 12px; color: #3730a3; display: inline-block; font-size: 9px; margin: 7px 5px 0 0; padding: 3px 8px; }
        .summary { color: #374151; margin-top: 9px; white-space: pre-line; }
        .grid { display: table; margin-top: 10px; width: 100%; }
        .col { display: table-cell; padding-right: 10px; vertical-align: top; width: 50%; }
        .box { background: #fbfdff; border: 1px solid #d3deed; border-radius: 8px; margin-top: 10px; padding: 10px 11px; page-break-inside: avoid; }
        .box h3 { color: #1d4ed8; }
        .field { margin: 0 0 5px; }
        .field strong { color: #334155; display: inline-block; min-width: 86px; }
        .muted { color: #64748b; }
        .list { margin: 5px 0 0 16px; padding: 0; page-break-inside: avoid; }
        .list li { margin-bottom: 3px; }
        .doc-title { font-weight: bold; }
        .footer { border-top: 1px solid #e5e7eb; color: #64748b; font-size: 9px; margin-top: 24px; padding-top: 8px; text-align: center; }
        a { color: #1d4ed8; text-decoration: none; }
    </style>
</head>
<body>
    <div class="cover">
        <h1><?= h($trip['title'] ?: 'Holiday itinerary') ?></h1>
        <div class="subtitle"><?= h($trip['destination'] ?: 'Destination') ?> · <?= h(format_date_range($trip['start_date'] ?? '', $trip['end_date'] ?? '')) ?></div>
        <?php if (trim((string)($trip['notes'] ?? '')) !== ''): ?><div class="trip-notes"><?= h($trip['notes']) ?></div><?php endif; ?>
        <table class="meta" cellspacing="0" cellpadding="0">
            <tr>
                <td><span class="label">Travel from</span><span class="value"><?= h($trip['start_date']) ?></span></td>
                <td><span class="label">Travel to</span><span class="value"><?= h($trip['end_date']) ?></span></td>
                <td><span class="label">Days planned</span><span class="value"><?= count($days) ?></span></td>
            </tr>
        </table>
    </div>

    <?php if ($flights): ?>
        <div class="section">
            <h2 class="section-title">Flights</h2>
            <div class="flight-wrap">
                <table class="flight-table">
                    <thead><tr><th>Date</th><th>Flight</th><th>Route</th><th>Time</th><th>Notes</th></tr></thead>
                    <tbody>
                    <?php foreach ($flights as $flight): ?>
                        <tr>
                            <td><?= h($flight['flight_date']) ?></td>
                            <td><strong><?= h($flight['airline']) ?></strong><br><span class="muted"><?= h($flight['flight_number']) ?></span></td>
                            <td><?= h($flight['departure_airport']) ?> to <?= h($flight['arrival_airport']) ?></td>
                            <td><?= h(trim(($flight['departure_time'] ?? '') . ' - ' . ($flight['arrival_time'] ?? ''), ' -')) ?></td>
                            <td><?= h($flight['notes']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="section">
        <h2 class="section-title">Day By Day</h2>
        <?php foreach ($days as $index => $day): ?>
            <?php
            $dayId = (int)$day['id'];
            $documents = $documentsByDay[$dayId] ?? [];
            $links = $linksByDay[$dayId] ?? [];
            ?>
            <div class="day <?= $index === 0 ? 'first-day' : '' ?>">
                <div class="day-header">
                    <div class="day-title">
                        <div class="day-date">Day <?= $index + 1 ?><br><?= h($day['day_date']) ?></div>
                        <div class="day-main">
                            <h2><?= h($day['title'] ?: 'Untitled day') ?></h2>
                            <?php if ($day['location']): ?><div class="muted"><?= h($day['location']) ?></div><?php endif; ?>
                            <?php if ($day['hotel']): ?><span class="pill">Hotel: <?= h($day['hotel']) ?></span><?php endif; ?>
                            <?php if ($day['transport']): ?><span class="pill">Transport: <?= h($day['transport']) ?></span><?php endif; ?>
                            <?php if (trim((string)$day['details']) !== ''): ?><div class="summary"><?= h($day['details']) ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="day-body">

                <?php foreach ($documents as $document): ?>
                    <?php $details = decoded_json($document['extracted_json'] ?? ''); ?>
                    <div class="box">
                        <h3>Document: <?= h($document['original_name']) ?></h3>
                        <div class="field"><strong>File</strong> <a href="<?= h($baseUrl . '/document.php?id=' . (int)$document['id']) ?>">Open original PDF</a></div>
                        <?php render_detail_fields(booking_pdf_fields($details)); ?>
                        <?php render_notes($details['important_notes'] ?? []); ?>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($links as $link): ?>
                    <?php $details = decoded_json($link['extracted_json'] ?? ''); ?>
                    <div class="box">
                        <h3>Website: <?= h($link['title']) ?></h3>
                        <div class="field"><strong>URL</strong> <a href="<?= h($link['url']) ?>"><?= h($link['url']) ?></a></div>
                        <?php if ($link['notes']): ?><div class="field"><strong>Notes</strong> <?= h($link['notes']) ?></div><?php endif; ?>
                        <?php render_detail_fields(link_pdf_fields($details)); ?>
                        <?php render_notes($details['important_notes'] ?? []); ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="footer">Generated from Holiday Planner on <?= h(date('Y-m-d H:i')) ?></div>
</body>
</html>
    <?php
    return ob_get_clean();
}

function render_detail_fields(array $fields): void
{
    foreach ($fields as $label => $value) {
        $value = short_pdf_value($value);
        if ($value === '') {
            continue;
        }
        echo '<div class="field"><strong>' . h($label) . '</strong> ' . nl2br(h($value)) . '</div>';
    }
}

function render_notes($notes): void
{
    if (is_string($notes) && trim($notes) !== '') {
        $notes = [trim($notes)];
    }
    if (!is_array($notes)) {
        return;
    }

    $clean = [];
    foreach ($notes as $note) {
        $note = short_pdf_value($note, 160);
        if ($note !== '') {
            $clean[] = $note;
        }
    }
    $clean = array_slice($clean, 0, 4);
    if (!$clean) {
        return;
    }

    echo '<div class="field"><strong>Important</strong></div><ul class="list">';
    foreach ($clean as $note) {
        echo '<li>' . h($note) . '</li>';
    }
    echo '</ul>';
}

function booking_pdf_fields(array $details): array
{
    return [
        'Hotel' => $details['hotel'] ?? '',
        'Address' => $details['address'] ?? '',
        'Room' => $details['room'] ?? '',
        'Arrival' => $details['arrival_date'] ?? '',
        'Departure' => $details['departure_date'] ?? '',
        'Nights' => $details['nights'] ?? '',
        'Check-in' => $details['check_in'] ?? '',
        'Check-out' => $details['check_out'] ?? '',
        'Breakfast' => $details['breakfast'] ?? '',
        'Confirmation' => $details['confirmation'] ?? '',
        'Contact' => $details['contact'] ?? '',
        'Parking' => $details['parking'] ?? '',
        'Payment' => $details['payment'] ?? '',
        'Cancellation' => $details['cancellation'] ?? '',
    ];
}

function link_pdf_fields(array $details): array
{
    return [
        'Location' => $details['location'] ?? '',
        'Address' => $details['address'] ?? '',
        'Opening hours' => $details['opening_hours'] ?? '',
        'Activity time' => $details['activity_time'] ?? '',
        'Booking' => $details['booking'] ?? '',
        'Price' => $details['price'] ?? '',
        'Transport' => $details['transport'] ?? '',
        'Contact' => $details['contact'] ?? '',
    ];
}

function decoded_json(string $json): array
{
    if (trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function short_pdf_value($value, int $limit = 260): string
{
    $value = trim(preg_replace('/\s+/', ' ', (string)$value));
    if ($value === '' || strlen($value) <= $limit) {
        return $value;
    }

    return substr($value, 0, $limit - 3) . '...';
}

function format_date_range(string $start, string $end): string
{
    if ($start !== '' && $end !== '') {
        return $start . ' to ' . $end;
    }

    return $start ?: $end;
}

function safe_pdf_filename(string $filename): string
{
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
    return trim((string)$filename, '-') ?: 'itinerary.pdf';
}

function request_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return $scheme . '://' . $host . ($path === '' ? '' : $path);
}
