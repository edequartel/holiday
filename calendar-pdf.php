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
$nextMonthStart = $monthStart->modify('first day of next month');

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

$months = [
    [
        'month' => $selectedMonth,
        'start' => $monthStart,
        'itemsByDate' => calendar_pdf_items_by_date($days, $flights, $documentsByDay, $monthStart),
    ],
    [
        'month' => $nextMonthStart->format('Y-m'),
        'start' => $nextMonthStart,
        'itemsByDate' => calendar_pdf_items_by_date($days, $flights, $documentsByDay, $nextMonthStart),
    ],
];
$html = render_calendar_pdf_html($trip, $months);

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = safe_pdf_filename(($trip['title'] ?: 'trip') . '-calendar-' . $selectedMonth . '-' . $nextMonthStart->format('Y-m') . '.pdf');
$dompdf->stream($filename, ['Attachment' => true]);

function calendar_pdf_items_by_date(array $days, array $flights, array $documentsByDay, DateTimeImmutable $monthStart): array
{
    $monthEnd = $monthStart->modify('last day of this month');
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
            'accommodation' => trim((string)($day['hotel'] ?? '')),
            'time' => calendar_time_from_day($day),
            'range' => $start === $end ? '' : $start . ' to ' . $end,
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
            'range' => $start === $end ? '' : $start . ' to ' . $end,
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

function render_calendar_pdf_html(array $trip, array $months): string
{
    ob_start();
    ?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px 20px; }
        body { color: #1f2937; font-family: DejaVu Sans, sans-serif; font-size: 8px; line-height: 1.18; margin: 0; }
        h1, h2 { margin: 0; }
        h1 { color: #111827; font-size: 18px; line-height: 1.05; }
        h2 { color: #1d4ed8; font-size: 13px; margin-top: 2px; }
        .calendar-page { page-break-after: always; page-break-inside: avoid; }
        .calendar-page:last-child { page-break-after: auto; }
        .header { border-bottom: 2px solid #2563eb; margin-bottom: 6px; padding-bottom: 5px; }
        .subtitle { color: #64748b; font-size: 8px; margin-top: 3px; }
        table.calendar { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .calendar th { background: #e8f0fb; border: 1px solid #cbd8ea; color: #334155; font-size: 8px; letter-spacing: .04em; padding: 4px 3px; text-align: center; text-transform: uppercase; }
        .calendar td { border: 1px solid #cbd8ea; height: 76px; padding: 3px; vertical-align: top; width: 14.285%; }
        .empty { background: #f8fafc; }
        .day-number { color: #111827; font-size: 10px; font-weight: bold; margin-bottom: 2px; }
        .item { border-left: 2px solid #2563eb; margin-bottom: 2px; padding: 1px 0 1px 3px; page-break-inside: avoid; }
        .item.flight { border-left-color: #f59e0b; }
        .item-title { font-weight: bold; }
        .item-meta { color: #4b5563; margin-top: 0; }
        .more { color: #64748b; font-size: 7px; margin-top: 1px; }
        .footer { color: #64748b; font-size: 7px; margin-top: 4px; text-align: right; }
    </style>
</head>
<body>
    <?php foreach ($months as $month): ?>
        <?php render_calendar_pdf_month($trip, (string)$month['month'], $month['start'], $month['itemsByDate']); ?>
    <?php endforeach; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}

function render_calendar_pdf_month(array $trip, string $selectedMonth, DateTimeImmutable $monthStart, array $itemsByDate): void
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

    ?>
<div class="calendar-page">
    <div class="header">
        <h1><?= h($trip['title'] ?: 'Holiday calendar') ?></h1>
        <h2><?= h($monthLabel) ?></h2>
        <div class="subtitle"><?= h($trip['destination'] ?? '') ?> &middot; month <?= h($selectedMonth) ?></div>
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
                            <?php $dayItems = $itemsByDate[$dateKey] ?? []; ?>
                            <td>
                                <div class="day-number"><?= h($date->format('j')) ?></div>
                                <?php foreach (array_slice($dayItems, 0, 4) as $item): ?>
                                    <div class="item <?= h($item['type']) ?>">
                                        <div class="item-title"><?= h($item['title']) ?></div>
                                        <?php if (($item['time'] ?? '') !== ''): ?><div class="item-meta"><?= h($item['time']) ?></div><?php endif; ?>
                                        <?php if (($item['route'] ?? '') !== ''): ?><div class="item-meta"><?= h($item['route']) ?></div><?php endif; ?>
                                        <?php if (($item['location'] ?? '') !== ''): ?><div class="item-meta"><?= h($item['location']) ?></div><?php endif; ?>
                                        <?php if (($item['accommodation'] ?? '') !== ''): ?><div class="item-meta">Stay: <?= h($item['accommodation']) ?></div><?php endif; ?>
                                        <?php if (($item['range'] ?? '') !== ''): ?><div class="item-meta"><?= h($item['range']) ?></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($dayItems) > 4): ?><div class="more">+<?= count($dayItems) - 4 ?> more</div><?php endif; ?>
                            </td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="footer">Generated from Holiday Planner on <?= h(date('Y-m-d H:i')) ?></div>
</div>
<?php
}
