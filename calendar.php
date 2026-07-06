<?php
require __DIR__ . '/includes/db.php';
ensure_itinerary_days_table($pdo);
ensure_day_documents_table($pdo);
ensure_day_links_table($pdo);

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
$flightsByDate = flights_by_date($flights);
$dayDateSet = array_fill_keys(array_map(fn($day) => (string)$day['day_date'], $days), true);

$stmt = $pdo->prepare('SELECT * FROM day_documents WHERE trip_id=? ORDER BY created_at ASC, id ASC');
$stmt->execute([$tripId]);
$documents = $stmt->fetchAll();
$documentsByDay = group_unique_documents_by_day($documents);
$documentCounts = count_unique_documents_by_day($documents);

$stmt = $pdo->prepare('SELECT day_id, COUNT(*) AS total FROM day_links WHERE trip_id=? GROUP BY day_id');
$stmt->execute([$tripId]);
$linkCounts = day_count_map($stmt->fetchAll());

$events = [];
foreach ($days as $day) {
    $dateRange = calendar_day_date_range($day, $documentsByDay[(int)$day['id']] ?? [], $days);
    $eventProps = [
        'id' => (string)$day['id'],
        'title' => trim((string)($day['title'] ?? '')) ?: 'Planned day',
        'start' => $dateRange['start'],
        'allDay' => true,
        'url' => 'index.php?trip_id=' . $tripId . '#day-' . (int)$day['id'],
        'extendedProps' => [
            'location' => (string)($day['location'] ?? ''),
            'hotel' => (string)($day['hotel'] ?? ''),
            'url' => (string)($day['url'] ?? ''),
            'transport' => (string)($day['transport'] ?? ''),
            'time' => calendar_time_from_day($day),
            'dateRange' => $dateRange['label'],
            'details' => short_calendar_text((string)($day['details'] ?? ''), 120),
            'documents' => $documentCounts[(int)$day['id']] ?? 0,
            'links' => $linkCounts[(int)$day['id']] ?? 0,
        ],
    ];
    if ($dateRange['endExclusive'] !== '') {
        $eventProps['end'] = $dateRange['endExclusive'];
        $eventProps['classNames'] = ['calendar-multiday-event'];
    }

    $events[] = $eventProps;
}

foreach ($flights as $flight) {
    if (trim((string)($flight['flight_date'] ?? '')) === '') {
        continue;
    }

    $flightTitle = trim(implode(' ', array_filter([
        $flight['airline'] ?? '',
        $flight['flight_number'] ?? '',
    ]))) ?: 'Flight';

    $dateRange = calendar_flight_date_range($flight);
    $eventProps = [
        'id' => 'flight-' . (int)$flight['id'],
        'title' => $flightTitle,
        'start' => $dateRange['start'],
        'allDay' => true,
        'url' => 'index.php?trip_id=' . $tripId,
        'classNames' => ['calendar-flight-event'],
        'extendedProps' => [
            'type' => 'Flight',
            'route' => trim((string)($flight['departure_airport'] ?? '') . ' → ' . (string)($flight['arrival_airport'] ?? '')),
            'time' => flight_time_range($flight),
            'dateRange' => $dateRange['label'],
            'details' => short_calendar_text((string)($flight['notes'] ?? ''), 100),
        ],
    ];
    if ($dateRange['endExclusive'] !== '') {
        $eventProps['end'] = $dateRange['endExclusive'];
        $eventProps['classNames'][] = 'calendar-multiday-flight-event';
    }

    $events[] = $eventProps;
}

$eventsJson = json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$initialDate = $trip['start_date'] ?: ($days[0]['day_date'] ?? date('Y-m-d'));

function flights_by_date(array $flights): array
{
    $grouped = [];
    foreach ($flights as $flight) {
        $date = trim((string)($flight['flight_date'] ?? ''));
        if ($date === '') {
            continue;
        }
        $grouped[$date][] = $flight;
    }

    return $grouped;
}

function day_count_map(array $rows): array
{
    $counts = [];
    foreach ($rows as $row) {
        $counts[(int)$row['day_id']] = (int)$row['total'];
    }

    return $counts;
}

function calendar_day_date_range(array $day, array $documents, array $allDays): array
{
    $dayDate = valid_calendar_date((string)($day['day_date'] ?? '')) ?: date('Y-m-d');
    $start = $dayDate;
    $endInclusive = '';

    foreach ($documents as $document) {
        $details = decoded_document_details($document);
        if (!$details) {
            continue;
        }

        $arrival = valid_calendar_date((string)($details['arrival_date'] ?? $details['day_date'] ?? ''));
        $departure = valid_calendar_date((string)($details['departure_date'] ?? ''));
        if ($arrival !== '' && $arrival < $start) {
            $start = $arrival;
        }
        if ($departure !== '' && $departure > $endInclusive) {
            $endInclusive = $departure;
        }
    }

    if ($endInclusive === '') {
        $endInclusive = calendar_labeled_date_from_text((string)($day['details'] ?? ''), 'Departure');
    }
    $arrivalFromText = calendar_labeled_date_from_text((string)($day['details'] ?? ''), 'Arrival');
    if ($arrivalFromText !== '' && $arrivalFromText < $start) {
        $start = $arrivalFromText;
    }

    if ($endInclusive !== '' && calendar_range_crosses_different_hotel($day, $start, $endInclusive, $allDays)) {
        return calendar_range_payload($dayDate, '');
    }

    return calendar_range_payload($start, $endInclusive);
}

function calendar_range_crosses_different_hotel(array $day, string $start, string $endInclusive, array $allDays): bool
{
    $currentHotel = normalize_calendar_hotel((string)($day['hotel'] ?? ''));
    if ($currentHotel === '' || $endInclusive === '' || $endInclusive <= $start) {
        return false;
    }

    $dayDate = valid_calendar_date((string)($day['day_date'] ?? ''));
    foreach ($allDays as $candidate) {
        $candidateDate = valid_calendar_date((string)($candidate['day_date'] ?? ''));
        if ($candidateDate === '' || $candidateDate < $start || $candidateDate > $endInclusive || $candidateDate === $dayDate) {
            continue;
        }

        $candidateHotel = normalize_calendar_hotel((string)($candidate['hotel'] ?? ''));
        if ($candidateHotel !== '' && $candidateHotel !== $currentHotel) {
            return true;
        }
    }

    return false;
}

function normalize_calendar_hotel(string $hotel): string
{
    $hotel = strtolower(trim(preg_replace('/\s+/', ' ', $hotel)));
    if ($hotel === '') {
        return '';
    }

    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $hotel);
    if (is_string($converted) && $converted !== '') {
        $hotel = $converted;
    }

    return preg_replace('/[^a-z0-9]+/', '', $hotel) ?: '';
}

function calendar_flight_date_range(array $flight): array
{
    $start = valid_calendar_date((string)($flight['flight_date'] ?? '')) ?: date('Y-m-d');
    $arrivalDate = calendar_labeled_date_from_text((string)($flight['notes'] ?? ''), 'Arrival');
    if ($arrivalDate === '') {
        $departureTime = trim((string)($flight['departure_time'] ?? ''));
        $arrivalTime = trim((string)($flight['arrival_time'] ?? ''));
        if ($departureTime !== '' && $arrivalTime !== '' && $arrivalTime < $departureTime) {
            $arrivalDate = calendar_add_days($start, 1);
        }
    }

    return calendar_range_payload($start, $arrivalDate);
}

function calendar_range_payload(string $start, string $endInclusive): array
{
    if ($endInclusive === '' || $endInclusive <= $start) {
        return [
            'start' => $start,
            'endExclusive' => '',
            'label' => '',
        ];
    }

    return [
        'start' => $start,
        'endExclusive' => calendar_add_days($endInclusive, 1),
        'label' => $start . ' to ' . $endInclusive,
    ];
}

function calendar_labeled_date_from_text(string $text, string $label): string
{
    if (preg_match('/^' . preg_quote($label, '/') . ':\s*(\d{4}-\d{2}-\d{2})\b/im', $text, $matches)) {
        return valid_calendar_date($matches[1]);
    }

    return '';
}

function valid_calendar_date(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function calendar_add_days(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify('+' . $days . ' days')->format('Y-m-d');
}

function short_calendar_text(string $value, int $limit = 120): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value));
    if (strlen($value) <= $limit) {
        return $value;
    }

    return substr($value, 0, $limit - 3) . '...';
}

function calendar_time_from_day(array $day): string
{
    $source = trim((string)($day['transport'] ?? '') . ' ' . (string)($day['details'] ?? ''));
    if (preg_match('/\b(?:[01]?\d|2[0-3])[:.][0-5]\d\b/', $source, $matches)) {
        return str_replace('.', ':', $matches[0]);
    }
    if (preg_match('/\b(?:[1-9]|1[0-2])\s?(?:am|pm)\b/i', $source, $matches)) {
        return strtoupper(preg_replace('/\s+/', '', $matches[0]));
    }

    return '';
}

function flight_time_range(array $flight): string
{
    $departure = trim((string)($flight['departure_time'] ?? ''));
    $arrival = trim((string)($flight['arrival_time'] ?? ''));
    if ($departure !== '' && $arrival !== '') {
        return $departure . ' - ' . $arrival;
    }

    return $departure ?: $arrival;
}

function flight_title(array $flight): string
{
    return trim(implode(' ', array_filter([
        $flight['airline'] ?? '',
        $flight['flight_number'] ?? '',
    ]))) ?: 'Flight';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= h($trip['title']) ?> calendar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css" rel="stylesheet">
    <link href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>" rel="stylesheet">
</head>
<body>
<div class="page"><div class="page-wrapper">
    <div class="page-header d-print-none">
        <div class="container-xl">
            <div class="row g-2 align-items-center">
                <div class="col-12 col-md">
                    <h2 class="page-title"><i class="ti ti-calendar-event me-2"></i><?= h($trip['title']) ?> calendar</h2>
                    <div class="text-secondary"><?= h($trip['destination']) ?> · <?= h($trip['start_date']) ?> to <?= h($trip['end_date']) ?></div>
                </div>
                <div class="col-12 col-md-auto d-flex flex-wrap gap-2 app-header-actions">
                    <a class="btn btn-outline-secondary" href="index.php?trip_id=<?= (int)$tripId ?>"><i class="ti ti-arrow-left me-1"></i>Planner</a>
                    <a class="btn btn-outline-primary" href="itinerary-pdf.php?trip_id=<?= (int)$tripId ?>" target="_blank" rel="noopener"><i class="ti ti-file-type-pdf me-1"></i>Itinerary PDF</a>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">
        <div class="container-xl">
            <div class="row row-cards">
                <div class="col-12">
                    <div class="card calendar-shell">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-layout-grid me-2"></i>Trip calendar</h3>
                        </div>
                        <div class="card-body">
                            <div id="tripCalendar"></div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-list-details me-2"></i>Brief day overview</h3>
                        </div>
                        <div class="card-body">
                            <div class="row row-cards">
                                <?php foreach ($days as $index => $day): ?>
                                    <?php
                                    $dayId = (int)$day['id'];
                                    $documents = $documentCounts[$dayId] ?? 0;
                                    $links = $linkCounts[$dayId] ?? 0;
                                    $dayFlights = $flightsByDate[(string)$day['day_date']] ?? [];
                                    $dateRange = calendar_day_date_range($day, $documentsByDay[$dayId] ?? [], $days);
                                    ?>
                                    <div class="col-sm-6 col-lg-4">
                                        <a class="card card-sm calendar-day-card <?= $dayFlights ? 'calendar-day-card-has-flight' : '' ?> text-reset text-decoration-none" href="index.php?trip_id=<?= (int)$tripId ?>#day-<?= $dayId ?>">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between gap-2 align-items-start">
                                                    <div>
                                                        <div class="text-secondary small">Day <?= $index + 1 ?> · <?= h($dateRange['label'] ?: $day['day_date']) ?></div>
                                                        <h3 class="h4 mb-1"><?= h($day['title'] ?: 'Planned day') ?></h3>
                                                    </div>
                                                    <span class="badge bg-blue-lt"><?= h(date('D', strtotime((string)$day['day_date']))) ?></span>
                                                </div>
                                                <?php if ($day['location']): ?><div class="text-secondary mb-2"><?= h($day['location']) ?></div><?php endif; ?>
                                                <div class="d-flex flex-wrap gap-1 mb-2">
                                                    <?php if ($day['hotel']): ?><span class="badge bg-green-lt"><?= h($day['hotel']) ?></span><?php endif; ?>
                                                    <?php if (!empty($day['url'])): ?><span class="badge bg-purple-lt">URL</span><?php endif; ?>
                                                    <?php if ($day['transport']): ?><span class="badge bg-blue-lt"><?= h($day['transport']) ?></span><?php endif; ?>
                                                    <?php if ($documents): ?><span class="badge bg-orange-lt"><?= $documents ?> doc<?= $documents === 1 ? '' : 's' ?></span><?php endif; ?>
                                                    <?php if ($links): ?><span class="badge bg-purple-lt"><?= $links ?> link<?= $links === 1 ? '' : 's' ?></span><?php endif; ?>
                                                </div>
                                                <?php if (trim((string)$day['details']) !== ''): ?><div class="small text-secondary"><?= h(short_calendar_text((string)$day['details'])) ?></div><?php endif; ?>
                                                <?php foreach ($dayFlights as $flight): ?>
                                                    <div class="calendar-flight-line mt-2">
                                                        <span>Flight:</span> <?= h(flight_title($flight)) ?><br>
                                                        <span>Route:</span> <?= h(trim((string)$flight['departure_airport'] . ' → ' . (string)$flight['arrival_airport'])) ?>
                                                        <?php if (flight_time_range($flight)): ?><br><span>Time:</span> <?= h(flight_time_range($flight)) ?><?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach ($flightsByDate as $date => $dateFlights): ?>
                                    <?php if (isset($dayDateSet[$date])) continue; ?>
                                    <div class="col-sm-6 col-lg-4">
                                        <a class="card card-sm calendar-day-card calendar-flight-card text-reset text-decoration-none" href="index.php?trip_id=<?= (int)$tripId ?>">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between gap-2 align-items-start">
                                                    <div>
                                                        <div class="text-secondary small"><?= h($date) ?></div>
                                                        <h3 class="h4 mb-1">Flight day</h3>
                                                    </div>
                                                    <span class="badge bg-indigo-lt"><?= h(date('D', strtotime($date))) ?></span>
                                                </div>
                                                <?php foreach ($dateFlights as $flight): ?>
                                                    <div class="calendar-flight-line mt-2">
                                                        <span>Flight:</span> <?= h(flight_title($flight)) ?><br>
                                                        <span>Route:</span> <?= h(trim((string)$flight['departure_airport'] . ' → ' . (string)$flight['arrival_airport'])) ?>
                                                        <?php if (flight_time_range($flight)): ?><br><span>Time:</span> <?= h(flight_time_range($flight)) ?><?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$days && !$flights): ?>
                                    <div class="col-12"><div class="empty"><div class="empty-icon"><i class="ti ti-calendar-off"></i></div><p class="empty-title">No itinerary days or flights yet</p></div></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div></div>

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const calendarEl = document.getElementById('tripCalendar');
    const events = <?= $eventsJson ?: '[]' ?>;
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        initialDate: <?= json_encode($initialDate) ?>,
        height: 'auto',
        firstDay: 1,
        dayMaxEvents: 3,
        fixedWeekCount: false,
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,listMonth'
        },
        buttonText: {
            today: 'Today',
            month: 'Grid',
            list: 'List'
        },
        events,
        eventClick(info) {
            if (info.event.url) {
                info.jsEvent.preventDefault();
                window.location.href = info.event.url;
            }
        },
        eventContent(info) {
            const props = info.event.extendedProps;
            const lines = [
                ['Type', props.type],
                ['Route', props.route],
                ['Location', props.location],
                ['Hotel', props.hotel],
                ['URL', props.url],
                ['Transport', props.transport],
                ['Time', props.time],
                ['Dates', props.dateRange],
                ['Details', props.details],
                ['Documents', props.documents ? `${props.documents}` : ''],
                ['Links', props.links ? `${props.links}` : '']
            ].filter(([, value]) => value);
            const wrapper = document.createElement('div');
            wrapper.className = 'calendar-event';
            wrapper.innerHTML = `
                <div class="calendar-event-title">${escapeHtml(info.event.title)}</div>
                ${lines.map(([label, value]) => `<div class="calendar-event-line calendar-event-line-${escapeHtml(label.toLowerCase())}"><span>${escapeHtml(label)}:</span> ${escapeHtml(value)}</div>`).join('')}
            `;
            return { domNodes: [wrapper] };
        }
    });
    calendar.render();
});

function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[character]));
}
</script>
</body>
</html>
