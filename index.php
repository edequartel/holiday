<?php
session_start();

require __DIR__ . '/includes/db.php';

const HOLIDAY_GIT_REMOTE = 'https://github.com/edequartel/holiday.git';

$action = $_POST['action'] ?? null;
$gitPullResult = $_SESSION['git_pull_result'] ?? null;
unset($_SESSION['git_pull_result']);

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

if ($action === 'add_day') {
    $stmt = $pdo->prepare('INSERT INTO itinerary_days (trip_id, day_date, location, title, details, transport, hotel) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$tripIdPost, $_POST['day_date'], $_POST['location'], $_POST['title'], $_POST['details'], $_POST['transport'], $_POST['hotel']]);
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
    $stmt = $pdo->prepare('INSERT INTO map_points (trip_id, point_type, name, address, city, latitude, longitude, notes, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$tripIdPost, $_POST['point_type'], $_POST['name'], $_POST['address'], $_POST['city'], $_POST['latitude'], $_POST['longitude'], $_POST['notes'], 'manual']);
    redirect_to_trip($tripIdPost);
}

if ($action === 'save_ai_point') {
    $stmt = $pdo->prepare('INSERT INTO map_points (trip_id, point_type, name, city, latitude, longitude, notes, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$tripIdPost, $_POST['point_type'], $_POST['name'], $_POST['city'], $_POST['latitude'], $_POST['longitude'], $_POST['notes'], 'openai']);
    redirect_to_trip($tripIdPost);
}

if (in_array($action, ['delete_day','delete_flight','delete_item','delete_point'], true)) {
    $map = [
        'delete_day' => ['itinerary_days', 'day_id'],
        'delete_flight' => ['flights', 'flight_id'],
        'delete_item' => ['packing_items', 'item_id'],
        'delete_point' => ['map_points', 'point_id'],
    ];
    [$table, $field] = $map[$action];
    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id=? AND trip_id=?");
    $stmt->execute([(int)$_POST[$field], $tripIdPost]);
    redirect_to_trip($tripIdPost);
}

$trips = $pdo->query('SELECT * FROM trips ORDER BY start_date DESC, id DESC')->fetchAll();
$tripId = isset($_GET['trip_id']) ? (int)$_GET['trip_id'] : (int)($trips[0]['id'] ?? 0);

$trip = null; $days = []; $flights = []; $items = []; $points = [];
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
}
$totalItems = count($items);
$packedItems = count(array_filter($items, fn($i) => (int)$i['packed'] === 1));
$packedPercent = $totalItems ? round(($packedItems / $totalItems) * 100) : 0;
$mapJson = json_encode($points, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

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
<div class="page"><div class="page-wrapper">
    <div class="page-header d-print-none"><div class="container-xl"><div class="row align-items-center">
        <div class="col"><h2 class="page-title"><i class="ti ti-plane-departure me-2"></i>Holiday Planner</h2><div class="text-secondary">Trips · flights · itinerary · map · OpenAI POI suggestions</div></div>
        <div class="col-auto d-flex gap-2">
            <form method="post">
                <input type="hidden" name="action" value="git_pull">
                <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                <button class="btn btn-outline-secondary"><i class="ti ti-git-pull-request me-1"></i>Git pull</button>
            </form>
            <button onclick="window.print()" class="btn btn-outline-primary"><i class="ti ti-printer me-1"></i>Print</button>
        </div>
    </div></div></div>

    <div class="page-body"><div class="container-xl"><div class="row row-cards">
        <?php if ($gitPullResult): ?>
            <div class="col-12 no-print"><div class="alert alert-<?= h($gitPullResult['type']) ?> mb-0">
                <strong>Git pull</strong>
                <pre class="mb-0 mt-2"><?= h($gitPullResult['message'] ?: 'No output returned.') ?></pre>
            </div></div>
        <?php endif; ?>
        <div class="col-lg-3 no-print">
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
        </div>

        <div class="col-lg-9">
        <?php if (!$trip): ?>
            <div class="empty"><div class="empty-icon"><i class="ti ti-map-2"></i></div><p class="empty-title">No trip yet</p><p class="empty-subtitle text-secondary">Create your first holiday plan.</p></div>
        <?php else: ?>
            <div class="card mb-3"><div class="card-body"><div class="row align-items-center">
                <div class="col"><h2><?= h($trip['title']) ?></h2><div class="text-secondary"><?= h($trip['destination']) ?> · <?= h($trip['start_date']) ?> to <?= h($trip['end_date']) ?></div><p class="mt-2"><?= nl2br(h($trip['notes'])) ?></p></div>
                <div class="col-md-4"><div class="text-secondary mb-1">Packing progress</div><div class="progress progress-lg"><div class="progress-bar bg-success" style="width: <?= $packedPercent ?>%"><?= $packedPercent ?>%</div></div><div class="text-secondary mt-1"><?= $packedItems ?> / <?= $totalItems ?> packed</div></div>
            </div></div></div>

            <div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-map-pin me-2"></i>Map: hotels, parking and POI</h3></div><div id="map"></div>
                <div class="card-body no-print"><div class="row g-2">
                    <form method="post" class="row g-2 col-12">
                        <input type="hidden" name="action" value="add_point"><input type="hidden" name="trip_id" value="<?= $tripId ?>">
                        <div class="col-md-2"><select name="point_type" class="form-select"><option value="hotel">Hotel</option><option value="parking">Parking</option><option value="poi">POI</option><option value="restaurant">Restaurant</option><option value="transport">Transport</option><option value="other">Other</option></select></div>
                        <div class="col-md-3"><input name="name" class="form-control" required placeholder="Name"></div>
                        <div class="col-md-2"><input name="city" class="form-control" placeholder="City"></div>
                        <div class="col-md-2"><input name="latitude" class="form-control" required placeholder="Latitude"></div>
                        <div class="col-md-2"><input name="longitude" class="form-control" required placeholder="Longitude"></div>
                        <div class="col-md-1"><button class="btn btn-primary w-100">Add</button></div>
                        <div class="col-md-6"><input name="address" class="form-control" placeholder="Address"></div>
                        <div class="col-md-6"><input name="notes" class="form-control" placeholder="Notes"></div>
                    </form>
                </div></div>
            </div>

            <div class="card mb-3 no-print"><div class="card-header"><h3 class="card-title"><i class="ti ti-sparkles me-2"></i>OpenAI POI suggestions</h3></div><div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4"><label class="form-label">Destination</label><input id="aiDestination" class="form-control" value="<?= h($trip['destination']) ?>"></div>
                    <div class="col-md-5"><label class="form-label">Interests</label><input id="aiInterests" class="form-control" value="culture, food, nature, accessible travel, birdwatching"></div>
                    <div class="col-md-2"><label class="form-label">Days</label><input id="aiDays" type="number" class="form-control" value="<?= max(1, count($days)) ?>"></div>
                    <div class="col-md-1"><button type="button" onclick="suggestPoi()" class="btn btn-primary w-100">Go</button></div>
                </div>
                <div id="aiResults" class="mt-3"></div>
            </div></div>

            <div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-plane me-2"></i>Flights</h3></div><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>Date</th><th>Flight</th><th>Route</th><th>Time</th><th class="no-print"></th></tr></thead><tbody>
                <?php foreach ($flights as $f): ?><tr><td><?= h($f['flight_date']) ?></td><td><strong><?= h($f['airline']) ?></strong><br><span class="text-secondary"><?= h($f['flight_number']) ?></span></td><td><?= h($f['departure_airport']) ?> → <?= h($f['arrival_airport']) ?></td><td><?= h($f['departure_time']) ?> - <?= h($f['arrival_time']) ?></td><td class="no-print"><form method="post"><input type="hidden" name="action" value="delete_flight"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><input type="hidden" name="flight_id" value="<?= (int)$f['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button></form></td></tr><?php endforeach; ?>
            </tbody></table></div><form method="post" class="card-body no-print row g-2"><input type="hidden" name="action" value="add_flight"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><div class="col-md-2"><input name="flight_date" type="date" class="form-control"></div><div class="col-md-2"><input name="airline" class="form-control" placeholder="Airline"></div><div class="col-md-2"><input name="flight_number" class="form-control" placeholder="Flight no."></div><div class="col-md-2"><input name="departure_airport" class="form-control" placeholder="From"></div><div class="col-md-2"><input name="arrival_airport" class="form-control" placeholder="To"></div><div class="col-md-1"><input name="departure_time" type="time" class="form-control"></div><div class="col-md-1"><input name="arrival_time" type="time" class="form-control"></div><div class="col-10"><input name="notes" class="form-control" placeholder="Notes"></div><div class="col-2"><button class="btn btn-primary w-100">Add flight</button></div></form></div>

            <div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-calendar-event me-2"></i>Itinerary</h3></div><div class="list-group list-group-flush">
                <?php foreach ($days as $d): ?><div class="list-group-item"><div class="row"><div class="col"><strong><?= h($d['day_date']) ?> · <?= h($d['title']) ?></strong><div class="text-secondary"><?= h($d['location']) ?></div><p class="mt-2"><?= nl2br(h($d['details'])) ?></p><span class="badge bg-blue-lt">Transport: <?= h($d['transport']) ?></span> <span class="badge bg-green-lt">Hotel: <?= h($d['hotel']) ?></span></div><div class="col-auto no-print"><form method="post"><input type="hidden" name="action" value="delete_day"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><input type="hidden" name="day_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button></form></div></div></div><?php endforeach; ?>
            </div><form method="post" class="card-body no-print row g-2"><input type="hidden" name="action" value="add_day"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><div class="col-md-2"><input name="day_date" type="date" class="form-control" required></div><div class="col-md-3"><input name="location" class="form-control" placeholder="Location"></div><div class="col-md-4"><input name="title" class="form-control" placeholder="Day title"></div><div class="col-md-3"><input name="hotel" class="form-control" placeholder="Hotel"></div><div class="col-md-4"><input name="transport" class="form-control" placeholder="Transport"></div><div class="col-md-8"><textarea name="details" class="form-control" rows="2" placeholder="Plans, sights, restaurants, notes"></textarea></div><div class="col-12"><button class="btn btn-primary">Add day</button></div></form></div>

            <div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-backpack me-2"></i>Packing checklist</h3></div><div class="list-group list-group-flush">
                <?php foreach ($items as $i): ?><div class="list-group-item"><div class="row align-items-center"><div class="col-auto no-print"><form method="post"><input type="hidden" name="action" value="toggle_packed"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><input type="hidden" name="item_id" value="<?= (int)$i['id'] ?>"><button class="btn btn-sm <?= $i['packed'] ? 'btn-success' : 'btn-outline-secondary' ?>"><i class="ti ti-check"></i></button></form></div><div class="col"><span class="<?= $i['packed'] ? 'packed' : '' ?>"><strong><?= h($i['category']) ?>:</strong> <?= h($i['item']) ?></span></div><div class="col-auto text-secondary"><?= h($i['quantity']) ?></div><div class="col-auto no-print"><form method="post"><input type="hidden" name="action" value="delete_item"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><input type="hidden" name="item_id" value="<?= (int)$i['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button></form></div></div></div><?php endforeach; ?>
            </div><form method="post" class="card-body no-print row g-2"><input type="hidden" name="action" value="add_item"><input type="hidden" name="trip_id" value="<?= $tripId ?>"><div class="col-md-3"><input name="category" class="form-control" placeholder="Category"></div><div class="col-md-6"><input name="item" class="form-control" placeholder="Item" required></div><div class="col-md-2"><input name="quantity" class="form-control" placeholder="Quantity"></div><div class="col-md-1"><button class="btn btn-primary w-100">Add</button></div></form></div>
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
mapPoints.forEach(p => {
    const marker = L.marker([parseFloat(p.latitude), parseFloat(p.longitude)]).addTo(map);
    marker.bindPopup(`<div class="map-popup-title">${iconMap[p.point_type] || '📍'} ${escapeHtml(p.name)}</div><div>${escapeHtml(p.city || '')}</div><div class="text-secondary">${escapeHtml(p.notes || '')}</div>`);
    markers.push(marker);
});
if (markers.length > 1) map.fitBounds(L.featureGroup(markers).getBounds().pad(0.2));

function escapeHtml(str) {
    return String(str).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
}

async function suggestPoi() {
    const box = document.getElementById('aiResults');
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
