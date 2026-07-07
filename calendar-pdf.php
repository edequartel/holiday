<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/calendar-functions.php';
ensure_itinerary_days_table($pdo);
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

$selectedMonth = valid_calendar_month((string)($_GET['month'] ?? '')) ?: date('Y-m');
$monthStart = new DateTimeImmutable($selectedMonth . '-01');
$monthEnd = $monthStart->modify('last day of this month');

$stmt = $pdo->prepare('SELECT * FROM itinerary_days WHERE trip_id=? ORDER BY day_date ASC, id ASC');
$stmt->execute([$tripId]);
$days = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM flights WHERE trip_id=? ORDER BY flight_date ASC, departure_time ASC, id ASC');
$stmt->execute([$tripId]);
$flights = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM day_documents WHERE trip_id=? ORDER BY created_at ASC, id ASC');
$stmt->execute([$tripId]);
$documents = $stmt->fetchAll();
$documentsByDay = group_unique_documents_by_day($documents);
$documentCounts = count_unique_documents_by_day($documents);

$stmt = $pdo->prepare('SELECT day_id, COUNT(*) AS total FROM day_links WHERE trip_id=? GROUP BY day_id');
$stmt->execute([$tripId]);
$linkCounts = day_count_map($stmt->fetchAll());

$itemsByDate = calendar_pdf_items_by_date($days, $flights, $documentsByDay, $documentCounts, $linkCounts, $monthStart, $monthEnd);
$html = render_calendar_pdf_html($trip, $selectedMonth, $monthStart, $itemsByDate);

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = safe_pdf_filename(($trip['title'] ?: 'trip') . '-calendar-' . $selectedMonth . '.pdf');
$dompdf->stream($filename, ['Attachment' => true]);

function calendar_pdf_items_by_date(array $days, array $flights, array $documentsByDay, array $documentCounts, array $linkCounts, DateTimeImmutable $monthStart, DateTimeImmutable $monthEnd): array
{
    $itemsByDate = [];
    foreach ($days as $day) {
        $dateRange = calendar_day_date_range($day, $documentsByDay[(int)$day['id']] ?? [], $days);
        $start = valid_calendar_date($dateRange['start']);
        if ($start === '') {
            continue;
        }

        $end = calendar_pdf_range_end($dateRange);
        if (!calendar_pdf_overlaps_month($start, $end, $monthStart, $monthEnd)) {
            continue;
        }

        $item = [
            'type' => 'day',
            'title' => trim((string)($day['title'] ?? '')) ?: 'Planned day',
            'location' => trim((string)($day['location'] ?? '')),
            'hotel' => trim((string)($day['hotel'] ?? '')),
            'transport' => trim((string)($day['transport'] ?? '')),
            'time' => calendar_time_from_day($day),
            'details' => short_calendar_text((string)($day['details'] ?? ''), 95),
            'documents' => $documentCounts[(int)$day['id']] ?? 0,
            'links' => $linkCounts[(int)$day['id']] ?? 0,
        ];
        calendar_pdf_add_item_to_dates($itemsByDate, $start, $end, $monthStart, $monthEnd, $item);
    }

    foreach ($flights as $flight) {
        if (trim((string)($flight['flight_date'] ?? '')) === '') {
            continue;
        }

        $dateRange = calendar_flight_date_range($flight);
        $start = valid_calendar_date($dateRange['start']);
        if ($start === '') {
            continue;
        }

        $end = calendar_pdf_range_end($dateRange);
        if (!calendar_pdf_overlaps_month($start, $end, $monthStart, $monthEnd)) {
            continue;
        }

        $item = [
            'type' => 'flight',
            'title' => flight_title($flight),
            'route' => trim((string)($flight['departure_airport'] ?? '') . ' to ' . (string)($flight['arrival_airport'] ?? '')),
            'time' => flight_time_range($flight),
            'details' => short_calendar_text((string)($flight['notes'] ?? ''), 85),
        ];
        calendar_pdf_add_item_to_dates($itemsByDate, $start, $end, $monthStart, $monthEnd, $item);
    }

    ksort($itemsByDate);
    return $itemsByDate;
}

function calendar_pdf_range_end(array $dateRange): string
{
    $endExclusive = valid_calendar_date((string)($dateRange['endExclusive'] ?? ''));
    if ($endExclusive === '') {
        return valid_calendar_date((string)($dateRange['start'] ?? ''));
    }

    return (new DateTimeImmutable($endExclusive))->modify('-1 day')->format('Y-m-d');
}

function calendar_pdf_overlaps_month(string $start, string $end, DateTimeImmutable $monthStart, DateTimeImmutable $monthEnd): bool
{
    return $start <= $monthEnd->format('Y-m-d') && $end >= $monthStart->format('Y-m-d');
}

function calendar_pdf_add_item_to_dates(array &$itemsByDate, string $start, string $end, DateTimeImmutable $monthStart, DateTimeImmutable $monthEnd, array $item): void
{
    $current = new DateTimeImmutable(max($start, $monthStart->format('Y-m-d')));
    $last = new DateTimeImmutable(min($end, $monthEnd->format('Y-m-d')));

    while ($current <= $last) {
        $date = $current->format('Y-m-d');
        $itemsByDate[$date][] = $item;
        $current = $current->modify('+1 day');
    }
}

function render_calendar_pdf_html(array $trip, string $selectedMonth, DateTimeImmutable $monthStart, array $itemsByDate): string
{
    $monthLabel = $monthStart->format('F Y');
    $firstWeekday = (int)$monthStart->format('N');
    $daysInMonth = (int)$monthStart->format('t');
    $cells = [];
    for ($blank = 1; $blank < $firstWeekday; $blank++) {
        $cells[] = null;
    }
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $cells[] = $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('m'), $day);
    }
    while (count($cells) % 7 !== 0) {
        $cells[] = null;
    }

    ob_start();
    ?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22px 24px; }
        body { color: #1f2937; font-family: DejaVu Sans, sans-serif; font-size: 9px; line-height: 1.25; margin: 0; }
        h1, h2 { margin: 0; }
        h1 { color: #111827; font-size: 24px; line-height: 1.1; }
        h2 { color: #1d4ed8; font-size: 15px; margin-top: 4px; }
        .header { border-bottom: 3px solid #2563eb; margin-bottom: 12px; padding-bottom: 10px; }
        .subtitle { color: #64748b; font-size: 10px; margin-top: 5px; }
        table.calendar { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .calendar th { background: #e8f0fb; border: 1px solid #cbd8ea; color: #334155; font-size: 9px; letter-spacing: .05em; padding: 6px 4px; text-align: center; text-transform: uppercase; }
        .calendar td { border: 1px solid #cbd8ea; height: 101px; padding: 5px; vertical-align: top; width: 14.285%; }
        .empty { background: #f8fafc; }
        .day-number { color: #111827; font-size: 13px; font-weight: bold; margin-bottom: 4px; }
        .item { border-left: 3px solid #2563eb; margin-bottom: 4px; padding: 2px 0 2px 4px; page-break-inside: avoid; }
        .item.flight { border-left-color: #f59e0b; }
        .item-title { font-weight: bold; }
        .item-meta { color: #4b5563; margin-top: 1px; }
        .muted { color: #64748b; }
        .footer { color: #64748b; font-size: 8px; margin-top: 8px; text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1><?= h($trip['title'] ?: 'Holiday calendar') ?></h1>
        <h2><?= h($monthLabel) ?></h2>
        <div class="subtitle"><?= h($trip['destination'] ?? '') ?> · selected month <?= h($selectedMonth) ?></div>
    </div>

    <table class="calendar">
        <thead>
            <tr>
                <th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th><th>Sun</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (array_chunk($cells, 7) as $week): ?>
                <tr>
                    <?php foreach ($week as $date): ?>
                        <?php if ($date === null): ?>
                            <td class="empty"></td>
                        <?php else: ?>
                            <?php $dateKey = $date->format('Y-m-d'); ?>
                            <td>
                                <div class="day-number"><?= h($date->format('j')) ?></div>
                                <?php foreach ($itemsByDate[$dateKey] ?? [] as $item): ?>
                                    <div class="item <?= h($item['type']) ?>">
                                        <div class="item-title"><?= h($item['title']) ?></div>
                                        <?php if (($item['time'] ?? '') !== ''): ?><div class="item-meta"><?= h($item['time']) ?></div><?php endif; ?>
                                        <?php if (($item['route'] ?? '') !== ''): ?><div class="item-meta"><?= h($item['route']) ?></div><?php endif; ?>
                                        <?php if (($item['location'] ?? '') !== ''): ?><div class="item-meta"><?= h($item['location']) ?></div><?php endif; ?>
                                        <?php if (($item['hotel'] ?? '') !== ''): ?><div class="item-meta">Hotel: <?= h($item['hotel']) ?></div><?php endif; ?>
                                        <?php if (($item['transport'] ?? '') !== ''): ?><div class="item-meta">Transport: <?= h($item['transport']) ?></div><?php endif; ?>
                                        <?php if (($item['details'] ?? '') !== ''): ?><div class="muted"><?= h($item['details']) ?></div><?php endif; ?>
                                        <?php if (($item['documents'] ?? 0) || ($item['links'] ?? 0)): ?><div class="muted"><?= (int)($item['documents'] ?? 0) ?> docs · <?= (int)($item['links'] ?? 0) ?> links</div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="footer">Generated from Holiday Planner on <?= h(date('Y-m-d H:i')) ?></div>
</body>
</html>
    <?php
    return ob_get_clean();
}
