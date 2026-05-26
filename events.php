<?php
// events.php
// Public Events showcase with calendar, search, filters, cards + modal

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Header (your global nav, fonts, CSS vars, etc.)
include('header.php');

// Database connection
include('includes/db.php');

// Check if we have a valid database connection
if (!isset($conn) || !$conn) {
    die("Database connection failed");
}

// ---- Helpers: safe param, dual DB fetch ----
function g($key, $default = null) {
    return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
}

// ✅ Normalize upload paths so they never contain ../ and always start with uploads/
function normalize_upload_path(string $p): string {
    $p = str_replace('\\', '/', $p);             // Windows → web slashes
    $p = preg_replace('~^(\.\./)+~', '', $p);    // remove any ../ prefixes
    $p = ltrim($p, '/');                          // remove leading /
    if (strpos($p, 'uploads/') !== 0) {
        $p = 'uploads/' . $p;                    // ensure uploads/ prefix
    }
    return $p;
}

// Build filters from query string
$q        = g('q', '');
$status   = g('status', 'all'); // all | upcoming | ongoing | completed
$year     = g('year', '');
$month    = g('month', '');     // 1..12
$day      = g('day', '');       // 1..31
$limit    = (int)g('limit', 200);
if ($limit < 1 || $limit > 1000) $limit = 200;

// Date filter (optional)
$selectedDate = null;
if ($year && $month && $day) {
    $selectedDate = sprintf('%04d-%02d-%02d', (int)$year, (int)$month, (int)$day);
}

// Compose SQL (compatible with mysqli)
$where  = ["e.approval_status = 'approved'"]; // Only show approved events
$params = [];
$types = '';

// Search (title/description)
if ($q !== '') {
    $where[] = "(e.title LIKE ? OR e.description LIKE ? OR c.group_name LIKE ?)";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $types .= 'sss';
}

// Status
if (in_array($status, ['upcoming', 'ongoing', 'completed'], true)) {
    $where[] = "e.status = ?";
    $params[] = $status;
    $types .= 's';
}

// Specific date: match if date falls within event range (start_date .. end_date)
if ($selectedDate) {
    $where[] = "(? BETWEEN e.start_date AND e.end_date)";
    $params[] = $selectedDate;
    $types .= 's';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Main query: join clubs for display
$sql = "
SELECT
    e.id,
    e.club_id,
    e.title,
    e.description,
    e.start_date,
    e.end_date,
    e.status,
    e.created_at,
    e.start_time,
    e.end_time,
    c.group_name AS club_name,
    c.cluster
FROM events e
LEFT JOIN clubs c ON c.id = e.club_id
{$whereSql}
ORDER BY e.start_date DESC, e.id DESC
LIMIT {$limit}
";

// Execute main query
$events = [];
$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $events = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get event photos for each event
$eventPhotos = [];
if (!empty($events)) {
    $eventIds = array_column($events, 'id');
    $placeholders = str_repeat('?,', count($eventIds) - 1) . '?';

    $photosSql = "SELECT event_id, file_path, original_name FROM event_photos WHERE event_id IN ($placeholders) ORDER BY id ASC";
    $photosStmt = $conn->prepare($photosSql);

    if ($photosStmt) {
        $photosStmt->bind_param(str_repeat('i', count($eventIds)), ...$eventIds);
        $photosStmt->execute();
        $photosResult = $photosStmt->get_result();

        while ($photo = $photosResult->fetch_assoc()) {
            // ✅ Normalize path here to strip any ../ and force uploads/
            $photo['file_path'] = normalize_upload_path($photo['file_path']);
            $eventId = (int)$photo['event_id'];
            if (!isset($eventPhotos[$eventId])) {
                $eventPhotos[$eventId] = [];
            }
            $eventPhotos[$eventId][] = $photo;
        }
        $photosStmt->close();
    }
}

// Second query for calendar: counts by date in visible month - ONLY APPROVED EVENTS
$today = new DateTime('today');
$monthForCal = ($year && $month) ? DateTime::createFromFormat('Y-n', "{$year}-{$month}") : new DateTime('first day of this month');

if (!$monthForCal) {
    $monthForCal = new DateTime('first day of this month');
}

$calStart = (clone $monthForCal)->modify('first day of this month')->modify('-7 day');
$calEnd   = (clone $monthForCal)->modify('last day of this month')->modify('+7 day');

$sqlCal = "
SELECT
    id, title, start_date, end_date, status
FROM events
WHERE (start_date <= ?) AND (end_date >= ?) AND approval_status = 'approved'
";

// Execute calendar query
$calendarRows = [];
$stmtCal = $conn->prepare($sqlCal);

if ($stmtCal) {
    $cs = $calStart->format('Y-m-d');
    $ce = $calEnd->format('Y-m-d');
    $stmtCal->bind_param('ss', $ce, $cs);

    if ($stmtCal->execute()) {
        $resultCal = $stmtCal->get_result();
        $calendarRows = $resultCal->fetch_all(MYSQLI_ASSOC);
    }
    $stmtCal->close();
}

// Build a hash: date => count of events intersecting that date (for the calendar dots)
$calendarHeat = [];
foreach ($calendarRows as $row) {
    try {
        $s = new DateTime($row['start_date']);
        $e = new DateTime($row['end_date']);
        for ($d = clone $s; $d <= $e; $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            if (!isset($calendarHeat[$key])) $calendarHeat[$key] = 0;
            $calendarHeat[$key]++;
        }
    } catch (Exception $e) {
        // Date processing error
    }
}

// Utility for nice time display
function fmtTime($t) {
    if (!$t) return null;
    if ($t === '00:00:00' || $t === '00:00') return null;
    try {
        return (new DateTime($t))->format('g:i A');
    } catch (Exception $e) {
        return $t;
    }
}
?>

<style>
/* ---------- Page wrapper ---------- */
.events-hero {
    padding: 40px 16px 24px;
    background: linear-gradient(135deg, #1a5276, #154360);
    color: #fff;
    text-align: center;
}
.events-hero h1 {
    font-family: 'Poppins', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    font-size: clamp(24px, 4vw, 40px);
    margin: 0 0 8px;
}
.events-hero p { opacity:.95; margin: 0; }

/* Controls */
.events-controls {
    max-width: 1200px; margin: 20px auto 0; padding: 0 16px;
}
.controls-row {
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 12px;
}
.search-input, .status-select, .reset-btn {
    border-radius: 999px;
    border: 1px solid #e2e6ea;
    padding: 10px 14px;
    font-size: 14px;
}
.search-input:focus, .status-select:focus {
    outline: none; border-color: #1a5276; box-shadow: 0 0 0 3px rgba(26,82,118,.12);
}
.reset-btn {
    background: #e9ecef; cursor: pointer; font-weight: 600;
}
.reset-btn:hover { filter: brightness(.97); }

/* Layout: Calendar + Cards */
.events-layout {
    max-width: 1200px; margin: 28px auto; padding: 0 16px;
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 24px;
}
@media (max-width: 992px) {
    .events-layout { grid-template-columns: 1fr; }
}

/* Calendar */
.calendar {
    background: #fff; border: 1px solid #e9ecef; border-radius: 16px; box-shadow: 0 10px 24px rgba(0,0,0,.06);
    overflow: hidden;
}
.cal-header {
    display:flex; align-items:center; justify-content:space-between;
    padding: 14px 16px; background: linear-gradient(135deg, #1a5276, #28b463); color: #fff;
}
.cal-title { font-weight: 700; letter-spacing: .3px; }
.cal-nav { display: flex; gap: 8px; }
.cal-btn {
    border: 1px solid rgba(255,255,255,.6); background: rgba(0,0,0,.15); color:#fff;
    border-radius: 999px; width: 34px; height: 34px; display: grid; place-items:center; cursor:pointer;
}
.cal-grid {
    padding: 12px; display:grid; gap: 8px;
    grid-template-columns: repeat(7, 1fr);
}
.cal-dow {
    font-size: 12px; color:#6c757d; text-transform: uppercase; font-weight: 700; text-align:center;
}
.cal-cell {
    background: #f8f9fa; border: 1px solid #eef1f4; min-height: 78px; border-radius: 10px; padding: 6px;
    display:flex; flex-direction:column; gap: 4px; position:relative; cursor:pointer;
    transition: transform .15s ease, box-shadow .15s ease;
}
.cal-cell:hover { transform: translateY(-1px); box-shadow: 0 8px 16px rgba(0,0,0,.06); }
.cal-cell.out { opacity:.45; }
.cal-date { font-weight:700; color:#2c3e50; font-size: 13px; }
.cal-pips { display:flex; gap: 4px; flex-wrap:wrap; margin-top: 2px; } /* ✅ directly under the date */
.cal-pip {
    width: 6px; height: 6px; border-radius:999px; background: #28b463;
}
.cal-pip.busy-2 { width: 10px; height: 6px; }
.cal-pip.busy-3 { width: 14px; height: 6px; }
.cal-pip.busy-4 { width: 18px; height: 6px; }
.cal-legend {
    padding: 10px 12px; border-top: 1px solid #eef1f4; font-size: 12px; color:#6c757d;
}

/* Cards grid */
.cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 18px;
}
.event-card {
    background: #fff; border: 1px solid #e9ecef; border-radius: 16px; padding: 0; /* padding moved into sections */
    box-shadow: 0 10px 20px rgba(0,0,0,.06);
    display:flex; flex-direction:column; gap: 10px;
    transition: transform .18s ease, box-shadow .18s ease;
    cursor: pointer;
    overflow: hidden;
}
.event-card:hover { transform: translateY(-3px); box-shadow: 0 16px 28px rgba(0,0,0,.09); }

/* ✅ Thumbnail block */
.event-thumb {
    width: 100%;
    aspect-ratio: 16 / 9;           /* nice, consistent cards */
    background: #eef1f4;
    position: relative;
    overflow: hidden;
}
.event-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display:block;
}
.event-body {
    padding: 18px;
}

.event-badge {
    display:inline-flex; align-items:center; gap:6px;
    border-radius:999px; padding: 6px 10px; font-size: 12px; font-weight:700; width:max-content;
}
.badge-upcoming { background: #eaf7ef; color:#1e7e34; border:1px solid #c7e9d1; }
.badge-ongoing { background: #fff3cd; color:#856404; border:1px solid #ffeaa7; }
.badge-completed { background: #fbe8e8; color:#a31919; border:1px solid #f3caca; }

.event-title { font-weight: 700; font-size: 18px; color:#1b2936; margin: 8px 0 0; }
.event-club  { color:#5b6b79; font-size: 13px; }
.event-dates { color:#2c3e50; font-size: 14px; display:flex; align-items:center; gap:8px; margin-top: 6px; }
.event-desc  { color:#6c757d; font-size: 14px; line-height: 1.5; max-height: 3.8em; overflow: hidden; margin-top: 8px; }

/* Modal */
.modal-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,.5);
    display:none; align-items: center; justify-content: center; z-index: 1000;
}
.modal-backdrop.show { display:flex; }
.modal {
    width: min(720px, 92vw); background: #fff; border-radius: 16px; overflow:hidden;
    box-shadow: 0 24px 64px rgba(0,0,0,.25);
}
.modal-header {
    padding: 14px 16px; background: linear-gradient(135deg, #1a5276, #154360); color:#fff;
    display:flex; align-items:center; justify-content: space-between;
}
.modal-title { font-weight: 700; }
.modal-close { background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.6); color:#fff; border-radius: 999px; width:34px; height:34px; display:grid; place-items:center; cursor:pointer; }
.modal-body { padding: 16px; display:grid; gap: 10px; }
.modal-row { display:flex; gap: 10px; color:#2c3e50; }
.modal-label { width: 140px; font-weight:700; color:#5b6b79; }
.modal-desc { color:#445566; line-height:1.6; white-space:pre-wrap; }

/* Event photos in modal */
.event-photos {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 10px;
    margin-top: 15px;
}
.event-photo {
    width: 100%;
    height: 100px;
    object-fit: cover;
    border-radius: 8px;
    cursor: pointer;
    transition: transform 0.2s;
}
.event-photo:hover {
    transform: scale(1.05);
}

/* Empty state */
.empty {
    background:#fff; border:1px dashed #cfd6dc; color:#6c757d;
    padding: 22px; border-radius: 12px; text-align:center;
}

/* Debug info */
.debug-info {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
    margin: 10px 0;
    font-size: 12px;
    color: #6c757d;
}

/* Cluster badges */
.cluster-badge {
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 10px;
    margin-left: 5px;
}
.cluster-poverty { background: #e8f5e8; color: #2e7d32; }
.cluster-unemployment { background: #e3f2fd; color: #1565c0; }
.cluster-carbon { background: #fff3e0; color: #ef6c00; }

/* Approved badge */
.approved-badge {
    background: #d1edff; 
    color: #0c5460; 
    border: 1px solid #bee5eb;
    font-size: 0.7rem;
    padding: 2px 8px;
    border-radius: 10px;
    margin-left: 5px;
}
</style>

<section class="events-hero">
  <h1>3ZERO Club — Events</h1>
  <p>Discover approved events across AIU's 3ZERO community.</p>
</section>

<section class="events-controls">
  <form id="eventsFilterForm" method="get" class="controls-row">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="search-input" placeholder="Search events (title, description, or club name)..." />
    <select name="status" class="status-select">
      <option value="all"      <?= $status==='all' ? 'selected':'' ?>>All statuses</option>
      <option value="upcoming" <?= $status==='upcoming' ? 'selected':'' ?>>Upcoming</option>
      <option value="ongoing" <?= $status==='ongoing' ? 'selected':'' ?>>Ongoing</option>
      <option value="completed" <?= $status==='completed' ? 'selected':'' ?>>Completed</option>
    </select>
    <button type="button" class="reset-btn" id="resetFilters">Reset</button>

    <!-- preserve calendar selection if any -->
    <?php if ($selectedDate): ?>
      <input type="hidden" name="year"  value="<?= (int)$year ?>">
      <input type="hidden" name="month" value="<?= (int)$month ?>">
      <input type="hidden" name="day"   value="<?= (int)$day ?>">
    <?php endif; ?>
  </form>
</section>

<section class="events-layout">
  <!-- Calendar -->
  <aside class="calendar" id="calendar"
          data-year="<?= (int)$monthForCal->format('Y') ?>" 
          data-month="<?= (int)$monthForCal->format('n') ?>">
      <div class="cal-header">
          <button class="cal-btn" id="calPrev" title="Previous month">
              <i class="fas fa-chevron-left"></i>
          </button>
          <div class="cal-title" id="calTitle"></div>
          <button class="cal-btn" id="calNext" title="Next month">
              <i class="fas fa-chevron-right"></i>
          </button>
      </div>
      <div class="cal-grid" id="calGrid">
          <!-- DOW row -->
          <div class="cal-dow">Sun</div>
          <div class="cal-dow">Mon</div>
          <div class="cal-dow">Tue</div>
          <div class="cal-dow">Wed</div>
          <div class="cal-dow">Thu</div>
          <div class="cal-dow">Fri</div>
          <div class="cal-dow">Sat</div>
          <!-- dynamic cells go here -->
      </div>
      <div class="cal-legend">
          Click a date to filter events spanning that day. Green bars indicate busier days.
      </div>
  </aside>

  <!-- Cards -->
  <div>
    <?php if (empty($events)): ?>
      <div class="empty">
        <i class="fas fa-calendar-times" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.5;"></i>
        <h4>No approved events found</h4>
        <p>Try changing your search criteria or check back later for new events.</p>
        <?php if ($q || $status || $selectedDate): ?>
          <div class="mt-3">
            <a href="events.php" class="btn btn-sm btn-outline-primary">Clear filters</a>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="cards-grid" id="eventsGrid">
        <?php foreach ($events as $ev):
          try {
            $sd = (new DateTime($ev['start_date']))->format('M j, Y');
            $ed = (new DateTime($ev['end_date']))->format('M j, Y');
            $st = fmtTime($ev['start_time']);
            $et = fmtTime($ev['end_time']);
            $period = ($ev['start_date'] === $ev['end_date'])
                      ? $sd . ($st ? " · {$st}" : '')
                      : "{$sd}" . ($st ? " · {$st}" : '') . " — {$ed}" . ($et ? " · {$et}" : '');
            
            // Set badge class based on status
            $badgeClass = match($ev['status']) {
                'ongoing' => 'badge-ongoing',
                'completed' => 'badge-completed',
                default => 'badge-upcoming'
            };
            
            $clubName   = $ev['club_name'] ? $ev['club_name'] : '3ZERO Club';
            $descShort  = $ev['description'] ? strip_tags($ev['description']) : '';

            // Photos (already normalized server-side)
            $photos = $eventPhotos[$ev['id']] ?? [];
            $thumbSrc = $photos[0]['file_path'] ?? null;

            // Cluster badge
            $clusterClass = '';
            if (isset($ev['cluster'])) {
                if (strpos($ev['cluster'], 'Poverty') !== false) {
                    $clusterClass = 'cluster-poverty';
                } elseif (strpos($ev['cluster'], 'Unemployment') !== false) {
                    $clusterClass = 'cluster-unemployment';
                } elseif (strpos($ev['cluster'], 'Carbon') !== false) {
                    $clusterClass = 'cluster-carbon';
                }
            }
          } catch (Exception $e) {
            continue;
          }
        ?>
          <article class="event-card"
                   data-id="<?= (int)$ev['id'] ?>"
                   data-title="<?= htmlspecialchars($ev['title']) ?>"
                   data-club="<?= htmlspecialchars($clubName) ?>"
                   data-status="<?= htmlspecialchars($ev['status']) ?>"
                   data-start="<?= htmlspecialchars($ev['start_date']) ?>"
                   data-end="<?= htmlspecialchars($ev['end_date']) ?>"
                   data-stime="<?= htmlspecialchars($ev['start_time']) ?>"
                   data-etime="<?= htmlspecialchars($ev['end_time']) ?>"
                   data-desc="<?= htmlspecialchars($descShort) ?>"
                   data-cluster="<?= htmlspecialchars($ev['cluster'] ?? '') ?>"
                   data-photos='<?= json_encode($photos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>'>

              <!-- ✅ THUMBNAIL -->
              <div class="event-thumb">
                <?php if ($thumbSrc): ?>
                  <img src="<?= htmlspecialchars($thumbSrc) ?>"
                       alt="Event thumbnail: <?= htmlspecialchars($ev['title']) ?>"
                       onerror="this.onerror=null; this.src='data:image/svg+xml;base64,<?= base64_encode('<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; viewBox=&quot;0 0 800 450&quot;><rect width=&quot;800&quot; height=&quot;450&quot; fill=&quot;#eef1f4&quot;/><text x=&quot;50%&quot; y=&quot;50%&quot; dominant-baseline=&quot;middle&quot; text-anchor=&quot;middle&quot; font-family=&quot;Arial&quot; font-size=&quot;22&quot; fill=&quot;#9aa6b2&quot;>Thumbnail unavailable</text></svg>') ?>';">
                <?php else: ?>
                  <img src="data:image/svg+xml;base64,<?= base64_encode('<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; viewBox=&quot;0 0 800 450&quot;><rect width=&quot;800&quot; height=&quot;450&quot; fill=&quot;#eef1f4&quot;/><text x=&quot;50%&quot; y=&quot;50%&quot; dominant-baseline=&quot;middle&quot; text-anchor=&quot;middle&quot; font-family=&quot;Arial&quot; font-size=&quot;22&quot; fill=&quot;#9aa6b2&quot;>No photo</text></svg>') ?>"
                       alt="No photo available">
                <?php endif; ?>
              </div>

              <!-- BODY -->
              <div class="event-body">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                  <span class="event-badge <?= $badgeClass ?>">
                    <i class="fas fa-circle"></i><?= htmlspecialchars(ucfirst($ev['status'])) ?>
                  </span>
                  <div style="display: flex; gap: 5px;">
                    <?php if ($clusterClass): ?>
                      <span class="cluster-badge <?= $clusterClass ?>">
                        <?= htmlspecialchars($ev['cluster']) ?>
                      </span>
                    <?php endif; ?>
                    <span class="approved-badge" title="Approved Event">
                      <i class="fas fa-check-circle"></i> Approved
                    </span>
                  </div>
                </div>
                <h3 class="event-title"><?= htmlspecialchars($ev['title']) ?></h3>
                <div class="event-club">
                  <i class="fas fa-users"></i> <?= htmlspecialchars($clubName) ?>
                </div>
                <div class="event-dates"><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($period) ?></div>
                <?php if ($descShort): ?>
                  <div class="event-desc"><?= htmlspecialchars($descShort) ?></div>
                <?php endif; ?>
                <?php if (!empty($photos)): ?>
                  <div class="event-photos-preview" style="margin-top:8px;">
                    <small class="text-muted">
                      <i class="fas fa-images"></i> <?= count($photos) ?> photo<?= count($photos) !== 1 ? 's' : '' ?>
                    </small>
                  </div>
                <?php endif; ?>
              </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- Modal -->
<div class="modal-backdrop" id="eventModalBackdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="eventModalTitle">
    <div class="modal-header">
      <div class="modal-title" id="eventModalTitle">Event</div>
      <button class="modal-close" id="eventModalClose" aria-label="Close">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="modal-body">
      <div class="modal-row">
        <div class="modal-label">Club</div>
        <div id="mClub"></div>
      </div>
      <div class="modal-row">
        <div class="modal-label">Cluster</div>
        <div id="mCluster"></div>
      </div>
      <div class="modal-row">
        <div class="modal-label">Status</div>
        <div id="mStatus"></div>
      </div>
      <div class="modal-row">
        <div class="modal-label">Approval</div>
        <div>
          <span class="approved-badge">
            <i class="fas fa-check-circle"></i> Approved
          </span>
        </div>
      </div>
      <div class="modal-row">
        <div class="modal-label">Dates</div>
        <div id="mDates"></div>
      </div>
      <div class="modal-row">
        <div class="modal-label">Description</div>
        <div id="mDesc" class="modal-desc"></div>
      </div>
      <div class="modal-row" id="mPhotosRow" style="display: none;">
        <div class="modal-label">Photos</div>
        <div class="event-photos" id="mPhotos"></div>
      </div>
    </div>
  </div>
</div>

<script>
// ---- Calendar builder (vanilla) ----
(() => {
  const cal = document.getElementById('calendar');
  if (!cal) return;

  const year  = parseInt(cal.dataset.year, 10);
  const month = parseInt(cal.dataset.month, 10); // 1..12

  const calTitle = document.getElementById('calTitle');
  const calGrid  = document.getElementById('calGrid');
  const prevBtn  = document.getElementById('calPrev');
  const nextBtn  = document.getElementById('calNext');

  // Heatmap data from PHP
  const heat = <?= json_encode($calendarHeat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

  let view = new Date(year, month - 1, 1);
  const monthName = (d) => d.toLocaleString('en-US', { month: 'long', year: 'numeric' });

  // ✅ local (non-UTC) YYYY-MM-DD to match PHP keys precisely
  const ymdLocal = (d) => {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  };

  function build() {
    // Remove previous dynamic cells (keep 7 DOW headers)
    while (calGrid.children.length > 7) calGrid.removeChild(calGrid.lastChild);

    calTitle.textContent = monthName(view);

    const firstOfMonth = new Date(view.getFullYear(), view.getMonth(), 1);
    const lastOfMonth  = new Date(view.getFullYear(), view.getMonth() + 1, 0);
    const startOffset  = firstOfMonth.getDay(); // 0=Sun
    const totalDays    = lastOfMonth.getDate();

    // Fill leading blanks from previous month
    for (let i = 0; i < startOffset; i++) {
      const cell = document.createElement('div');
      cell.className = 'cal-cell out';
      calGrid.appendChild(cell);
    }

    // Days of this month
    for (let d = 1; d <= totalDays; d++) {
      const cell = document.createElement('div');
      cell.className = 'cal-cell';
      const date = new Date(view.getFullYear(), view.getMonth(), d);
      const ymd  = ymdLocal(date); // ✅ local key

      const head = document.createElement('div');
      head.className = 'cal-date';
      head.textContent = d;
      cell.appendChild(head);

      // ✅ Green dots directly under the date if there are events
      const pips = document.createElement('div');
      pips.className = 'cal-pips';

      const count = heat[ymd] || 0;
      for (let i = 0; i < Math.min(count, 4); i++) {
        const pip = document.createElement('div');
        pip.className = 'cal-pip' + (count >= 2 ? (count >= 4 ? ' busy-4' : count === 3 ? ' busy-3' : ' busy-2') : '');
        pips.appendChild(pip);
      }
      cell.appendChild(pips);

      cell.addEventListener('click', () => {
        // Navigate by setting Y/M/D in query
        const url = new URL(window.location.href);
        url.searchParams.set('year',  String(date.getFullYear()));
        url.searchParams.set('month', String(date.getMonth() + 1));
        url.searchParams.set('day',   String(date.getDate()));
        // preserve q and status
        const qEl = document.querySelector('input[name="q"]');
        const sEl = document.querySelector('select[name="status"]');
        if (qEl && qEl.value) url.searchParams.set('q', qEl.value);
        if (sEl) url.searchParams.set('status', sEl.value);
        window.location.href = url.toString();
      });

      calGrid.appendChild(cell);
    }
  }

  prevBtn.addEventListener('click', () => {
    view = new Date(view.getFullYear(), view.getMonth() - 1, 1);
    const url = new URL(window.location.href);
    url.searchParams.set('year',  String(view.getFullYear()));
    url.searchParams.set('month', String(view.getMonth() + 1));
    url.searchParams.delete('day'); // reset specific day
    window.location.href = url.toString();
  });
  nextBtn.addEventListener('click', () => {
    view = new Date(view.getFullYear(), view.getMonth() + 1, 1);
    const url = new URL(window.location.href);
    url.searchParams.set('year',  String(view.getFullYear()));
    url.searchParams.set('month', String(view.getMonth() + 1));
    url.searchParams.delete('day');
    window.location.href = url.toString();
  });

  build();
})();

// ---- Filters: submit on change, reset ----
(() => {
  const form = document.getElementById('eventsFilterForm');
  const status = form?.querySelector('select[name="status"]');
  const search = form?.querySelector('input[name="q"]');
  const reset  = document.getElementById('resetFilters');

  status?.addEventListener('change', () => form.submit());
  search?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') form.submit();
  });
  reset?.addEventListener('click', () => {
    const url = new URL(window.location.href);
    url.searchParams.delete('q');
    url.searchParams.delete('status');
    url.searchParams.delete('year');
    url.searchParams.delete('month');
    url.searchParams.delete('day');
    window.location.href = url.toString();
  });
})();

// ---- Modal handling ----
(() => {
  const grid   = document.getElementById('eventsGrid');
  const modal  = document.getElementById('eventModalBackdrop');
  const close  = document.getElementById('eventModalClose');

  const mTitle = document.getElementById('eventModalTitle');
  const mClub  = document.getElementById('mClub');
  const mCluster = document.getElementById('mCluster');
  const mStatus= document.getElementById('mStatus');
  const mDates = document.getElementById('mDates');
  const mDesc  = document.getElementById('mDesc');
  const mPhotosRow = document.getElementById('mPhotosRow');
  const mPhotos = document.getElementById('mPhotos');

  const fmtDate = (iso) => {
    if (!iso) return '';
    const d = new Date(iso + 'T00:00:00');
    return d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  };
  const fmtTime = (t) => {
    if (!t || t === '00:00:00' || t === '00:00') return '';
    const [H, M] = t.split(':');
    const d = new Date();
    d.setHours(parseInt(H, 10), parseInt(M, 10), 0, 0);
    return d.toLocaleString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
  };

  function openModal(card) {
    const title = card.dataset.title || 'Event';
    const club  = card.dataset.club || '3ZERO Club';
    const cluster = card.dataset.cluster || '';
    const status = card.dataset.status || 'upcoming';
    const sd    = card.dataset.start || '';
    const ed    = card.dataset.end   || '';
    const st    = card.dataset.stime || '';
    const et    = card.dataset.etime || '';
    const desc  = card.dataset.desc  || '';
    const photos = JSON.parse(card.dataset.photos || '[]');

    let dates = '';
    if (sd && ed) {
      if (sd === ed) {
        dates = fmtDate(sd) + (fmtTime(st) ? ' · ' + fmtTime(st) : '');
      } else {
        dates = fmtDate(sd) + (fmtTime(st) ? ' · ' + fmtTime(st) : '') + ' — ' +
                fmtDate(ed) + (fmtTime(et) ? ' · ' + fmtTime(et) : '');
      }
    }

    mTitle.textContent = title;
    mClub.textContent  = club;
    mCluster.textContent = cluster || '—';
    mStatus.textContent= (status || 'Upcoming').replace(/^./, c => c.toUpperCase());
    mDates.textContent = dates || '—';
    mDesc.textContent  = desc || '—';

    // Handle photos (✅ client-side safety: remove any ../ just in case)
    if (photos.length > 0) {
      mPhotos.innerHTML = '';
      photos.forEach(photo => {
        const img = document.createElement('img');
        const src = String(photo.file_path || '').replace(/^(\.\.\/)+/, '');
        img.src = src;
        img.alt = photo.original_name || 'Event photo';
        img.className = 'event-photo';
        img.addEventListener('click', () => window.open(src, '_blank'));
        mPhotos.appendChild(img);
      });
      mPhotosRow.style.display = 'flex';
    } else {
      mPhotosRow.style.display = 'none';
    }

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal() {
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
  }

  grid?.addEventListener('click', (e) => {
    const card = e.target.closest('.event-card');
    if (card) openModal(card);
  });
  close?.addEventListener('click', closeModal);
  modal?.addEventListener('click', (e) => {
    if (e.target === modal) closeModal();
  });
  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModal();
  });
})();
</script>

<?php include('footer.php'); ?>
