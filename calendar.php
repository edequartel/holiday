<?php
require __DIR__ . '/includes/db.php';
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

$stmt = $pdo->prepare('SELECT day_id, COUNT(*) AS total FROM day_documents WHERE trip_id=? GROUP BY day_id');
$stmt->execute([$tripId]);
$documentCounts = day_count_map($stmt->fetchAll());

$stmt = $pdo->prepare('SELECT day_id, COUNT(*) AS total FROM day_links WHERE trip_id=? GROUP BY day_id');
$stmt->execute([$tripId]);
$linkCounts = day_count_map($stmt->fetchAll());

$events = [];
foreach ($days as $day) {
    $summaryParts = array_filter([
        trim((string)($day['location'] ?? '')),
        trim((string)($day['hotel'] ?? '')) ? 'Hotel: ' . trim((string)$day['hotel']) : '',
        trim((string)($day['transport'] ?? '')) ? 'Transport: ' . trim((string)$day['transport']) : '',
    ]);

    $events[] = [
        'id' => (string)$day['id'],
        'title' => trim((string)($day['title'] ?? '')) ?: 'Planned day',
        'start' => (string)$day['day_date'],
        'allDay' => true,
        'url' => 'index.php?trip_id=' . $tripId . '#day-' . (int)$day['id'],
        'extendedProps' => [
            'location' => (string)($day['location'] ?? ''),
            'hotel' => (string)($day['hotel'] ?? ''),
            'transport' => (string)($day['transport'] ?? ''),
            'details' => short_calendar_text((string)($day['details'] ?? ''), 120),
            'summary' => implode(' · ', $summaryParts),
            'documents' => $documentCounts[(int)$day['id']] ?? 0,
            'links' => $linkCounts[(int)$day['id']] ?? 0,
        ],
    ];
}

$eventsJson = json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$initialDate = $trip['start_date'] ?: ($days[0]['day_date'] ?? date('Y-m-d'));

function day_count_map(array $rows): array
{
    $counts = [];
    foreach ($rows as $row) {
        $counts[(int)$row['day_id']] = (int)$row['total'];
    }

    return $counts;
}

function short_calendar_text(string $value, int $limit = 120): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value));
    if (strlen($value) <= $limit) {
        return $value;
    }

    return substr($value, 0, $limit - 3) . '...';
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
    <link href="assets/app.css" rel="stylesheet">
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
                                    ?>
                                    <div class="col-sm-6 col-lg-4">
                                        <a class="card card-sm calendar-day-card text-reset text-decoration-none" href="index.php?trip_id=<?= (int)$tripId ?>#day-<?= $dayId ?>">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between gap-2 align-items-start">
                                                    <div>
                                                        <div class="text-secondary small">Day <?= $index + 1 ?> · <?= h($day['day_date']) ?></div>
                                                        <h3 class="h4 mb-1"><?= h($day['title'] ?: 'Planned day') ?></h3>
                                                    </div>
                                                    <span class="badge bg-blue-lt"><?= h(date('D', strtotime((string)$day['day_date']))) ?></span>
                                                </div>
                                                <?php if ($day['location']): ?><div class="text-secondary mb-2"><?= h($day['location']) ?></div><?php endif; ?>
                                                <div class="d-flex flex-wrap gap-1 mb-2">
                                                    <?php if ($day['hotel']): ?><span class="badge bg-green-lt"><?= h($day['hotel']) ?></span><?php endif; ?>
                                                    <?php if ($day['transport']): ?><span class="badge bg-blue-lt"><?= h($day['transport']) ?></span><?php endif; ?>
                                                    <?php if ($documents): ?><span class="badge bg-orange-lt"><?= $documents ?> doc<?= $documents === 1 ? '' : 's' ?></span><?php endif; ?>
                                                    <?php if ($links): ?><span class="badge bg-purple-lt"><?= $links ?> link<?= $links === 1 ? '' : 's' ?></span><?php endif; ?>
                                                </div>
                                                <?php if (trim((string)$day['details']) !== ''): ?><div class="small text-secondary"><?= h(short_calendar_text((string)$day['details'])) ?></div><?php endif; ?>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$days): ?>
                                    <div class="col-12"><div class="empty"><div class="empty-icon"><i class="ti ti-calendar-off"></i></div><p class="empty-title">No itinerary days yet</p></div></div>
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
            const meta = [props.location, props.hotel ? `Hotel: ${props.hotel}` : '', props.transport ? `Transport: ${props.transport}` : ''].filter(Boolean).join(' · ');
            const counts = [props.documents ? `${props.documents} doc${props.documents === 1 ? '' : 's'}` : '', props.links ? `${props.links} link${props.links === 1 ? '' : 's'}` : ''].filter(Boolean).join(' · ');
            const wrapper = document.createElement('div');
            wrapper.className = 'calendar-event';
            wrapper.innerHTML = `
                <div class="calendar-event-title">${escapeHtml(info.event.title)}</div>
                ${meta ? `<div class="calendar-event-meta">${escapeHtml(meta)}</div>` : ''}
                ${props.details ? `<div class="calendar-event-meta">${escapeHtml(props.details)}</div>` : ''}
                ${counts ? `<div class="calendar-event-counts">${escapeHtml(counts)}</div>` : ''}
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
