<?php
// achievements.php (PUBLIC VIEW)
// Shows only APPROVED achievements with filters, thumbnails, and a modal.

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Common header (your global nav/layout)
include('header.php');

// DB
include('includes/db.php');
if (!isset($conn) || !$conn) {
    die("Database connection failed");
}

/* ---------------- Helpers ---------------- */
function g($key, $default = null) { return isset($_GET[$key]) ? trim($_GET[$key]) : $default; }

/** Normalize upload path: no ../ and always "uploads/" prefix */
function normalize_upload_path(string $p): string {
    $p = str_replace('\\', '/', $p);
    $p = preg_replace('~^(\.\./)+~', '', $p);
    $p = ltrim($p, '/');
    if (strpos($p, 'uploads/') !== 0) $p = 'uploads/' . $p;
    return $p;
}

/* ---------------- Filters from query string ---------------- */
$q        = g('q', '');
$club_id  = g('club_id', '');
$year     = g('year', '');
$month    = g('month', '');
$limit    = (int)g('limit', 200);
if ($limit < 1 || $limit > 1000) $limit = 200;

$where  = [];
$params = [];
$types  = '';

/* Only approved (PUBLIC) */
$where[] = "a.approval_status = 'approved'";

/* Search */
if ($q !== '') {
    $where[] = "(a.title LIKE ? OR a.description LIKE ? OR c.group_name LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}

/* Club filter */
if ($club_id !== '' && is_numeric($club_id)) {
    $where[] = "a.club_id = ?";
    $params[] = (int)$club_id;
    $types   .= 'i';
}

/* Year filter */
if ($year !== '' && is_numeric($year)) {
    $where[] = "YEAR(a.achieved_on) = ?";
    $params[] = (int)$year;
    $types   .= 'i';
}

/* Month filter */
if ($month !== '' && is_numeric($month) && $month >= 1 && $month <= 12) {
    $where[] = "MONTH(a.achieved_on) = ?";
    $params[] = (int)$month;
    $types   .= 'i';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ---------------- Main achievements query ---------------- */
$sql = "
SELECT
    a.id,
    a.club_id,
    a.title,
    a.description,
    a.achieved_on,
    a.created_at,
    c.group_name AS club_name,
    c.cluster
FROM achievements a
LEFT JOIN clubs c ON c.id = a.club_id
{$whereSql}
ORDER BY a.achieved_on DESC, a.id DESC
LIMIT {$limit}";

$achievements = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $achievements = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

/* ---------------- Photos per achievement ---------------- */
$achievementPhotos = [];
if (!empty($achievements)) {
    $ids = array_column($achievements, 'id');
    $phMarks = implode(',', array_fill(0, count($ids), '?'));
    $phSql = "SELECT achievement_id, file_path, original_name
              FROM achievement_photos
              WHERE achievement_id IN ($phMarks)
              ORDER BY id ASC";
    $phStmt = $conn->prepare($phSql);
    if ($phStmt) {
        $phStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $phStmt->execute();
        $res = $phStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['file_path'] = normalize_upload_path($row['file_path']);
            $aid = (int)$row['achievement_id'];
            if (!isset($achievementPhotos[$aid])) $achievementPhotos[$aid] = [];
            $achievementPhotos[$aid][] = $row;
        }
        $phStmt->close();
    }
}

/* ---------------- Filter dropdowns ---------------- */
$clubs = [];
$clubsRes = $conn->query("SELECT id, group_name FROM clubs ORDER BY group_name ASC");
if ($clubsRes) $clubs = $clubsRes->fetch_all(MYSQLI_ASSOC);

$years = [];
$yearsRes = $conn->query("
  SELECT DISTINCT YEAR(achieved_on) AS year
  FROM achievements
  WHERE achieved_on IS NOT NULL AND approval_status='approved'
  ORDER BY year DESC");
if ($yearsRes) $years = $yearsRes->fetch_all(MYSQLI_ASSOC);
?>
<style>
/* (Styles trimmed for brevity: same layout, grid, modal) */
.achievements-hero{padding:40px 16px 24px;background:linear-gradient(135deg,#1a5276,#154360);color:#fff;text-align:center}
.achievements-hero h1{font-family:'Poppins',system-ui,Segoe UI,Arial,sans-serif;font-size:clamp(24px,4vw,40px);margin:0 0 8px}
.achievements-controls{max-width:1200px;margin:20px auto 0;padding:0 16px}
.controls-row{display:grid;grid-template-columns:1fr auto auto auto auto;gap:12px}
.search-input,.club-select,.year-select,.month-select,.reset-btn{border-radius:999px;border:1px solid #e2e6ea;padding:10px 14px;font-size:14px}
.search-input:focus,.club-select:focus,.year-select:focus,.month-select:focus{outline:none;border-color:#1a5276;box-shadow:0 0 0 3px rgba(26,82,118,.12)}
.reset-btn{background:#e9ecef;cursor:pointer;font-weight:600}
.achievements-layout{max-width:1200px;margin:28px auto;padding:0 16px}
.cards-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:24px}
.achievement-card{background:#fff;border:1px solid #e9ecef;border-left:4px solid #1a5276;border-radius:16px;box-shadow:0 10px 20px rgba(0,0,0,.06);display:flex;flex-direction:column;gap:10px;transition:transform .18s,box-shadow .18s;cursor:pointer;overflow:hidden}
.achievement-card:hover{transform:translateY(-3px);box-shadow:0 16px 28px rgba(0,0,0,.09)}
.achievement-thumb{width:100%;aspect-ratio:16/9;background:#eef1f4;position:relative;overflow:hidden}
.achievement-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.achievement-body{padding:20px}
.achievement-title{font-weight:700;font-size:20px;color:#1b2936;margin:0 0 8px;line-height:1.3}
.achievement-club{color:#5b6b79;font-size:14px;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.achievement-date{color:#2c3e50;font-size:14px;display:flex;align-items:center;gap:8px;margin-bottom:12px}
.achievement-desc{color:#6c757d;font-size:14px;line-height:1.5;max-height:4.2em;overflow:hidden;margin-top:8px}
.achievement-thumbs{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}
.achievement-thumb-item{width:100%;aspect-ratio:1;background:#f8f9fa;border-radius:8px;overflow:hidden}
.achievement-thumb-item img{width:100%;height:100%;object-fit:cover;display:block}
.achievement-thumb-count{display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.7);color:#fff;font-size:12px;font-weight:600}
.cluster-badge{font-size:.7rem;padding:2px 6px;border-radius:10px;margin-left:5px}
.cluster-poverty{background:#e8f5e8;color:#2e7d32}
.cluster-unemployment{background:#e3f2fd;color:#1565c0}
.cluster-carbon{background:#fff3e0;color:#ef6c00}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:1000}
.modal-backdrop.show{display:flex}
.modal{width:min(800px,92vw);background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.25)}
.modal-header{padding:14px 16px;background:linear-gradient(135deg,#1a5276,#154360);color:#fff;display:flex;align-items:center;justify-content:space-between}
.modal-title{font-weight:700;font-size:18px}
.modal-close{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.6);color:#fff;border-radius:999px;width:34px;height:34px;display:grid;place-items:center;cursor:pointer}
.modal-body{padding:20px;display:grid;gap:12px}
.modal-row{display:flex;gap:12px;color:#2c3e50}
.modal-label{width:120px;font-weight:700;color:#5b6b79;flex-shrink:0}
.modal-desc{color:#445566;line-height:1.6;white-space:pre-wrap}
.achievement-photos{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-top:15px}
.achievement-photo{width:100%;height:120px;object-fit:cover;border-radius:8px;cursor:pointer;transition:transform .2s}
.achievement-photo:hover{transform:scale(1.05)}
.empty{background:#fff;border:1px dashed #cfd6dc;color:#6c757d;padding:40px 22px;border-radius:12px;text-align:center}
@media(max-width:768px){.controls-row{grid-template-columns:1fr;gap:8px}.cards-grid{grid-template-columns:1fr;gap:16px}.modal-row{flex-direction:column;gap:4px}.modal-label{width:100%}}
</style>

<section class="achievements-hero">
  <h1>3ZERO Club — Achievements</h1>
  <p>Celebrating approved successes across the 3ZERO community.</p>
</section>

<section class="achievements-controls">
  <form id="achievementsFilterForm" method="get" class="controls-row">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="search-input" placeholder="Search achievements..." />
    <select name="club_id" class="club-select">
      <option value="">All Clubs</option>
      <?php foreach ($clubs as $club): ?>
        <option value="<?= $club['id'] ?>" <?= $club_id == $club['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($club['group_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="year" class="year-select">
      <option value="">All Years</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= $y['year'] ?>" <?= $year == $y['year'] ? 'selected' : '' ?>><?= $y['year'] ?></option>
      <?php endforeach; ?>
    </select>
    <select name="month" class="month-select">
      <option value="">All Months</option>
      <?php for ($m=1;$m<=12;$m++): ?>
        <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
      <?php endfor; ?>
    </select>
    <button type="button" class="reset-btn" id="resetFilters">Reset</button>
  </form>
</section>

<section class="achievements-layout">
  <?php if (empty($achievements)): ?>
    <div class="empty">
      <i class="fas fa-trophy" style="font-size:3rem;opacity:.5"></i>
      <h4 class="mt-3">No achievements found</h4>
      <p>Try changing your search or filters.</p>
    </div>
  <?php else: ?>
    <div class="cards-grid" id="achievementsGrid">
      <?php foreach ($achievements as $ach):
        try {
          $achievedDate = $ach['achieved_on'] ? (new DateTime($ach['achieved_on']))->format('M j, Y') : 'Date not specified';
          $clubName     = $ach['club_name'] ?: '3ZERO Club';
          $descShort    = $ach['description'] ? strip_tags($ach['description']) : '';

          $photos  = $achievementPhotos[$ach['id']] ?? [];
          $thumb   = $photos[0]['file_path'] ?? null;

          $clusterClass = '';
          if (!empty($ach['cluster'])) {
              if (str_contains($ach['cluster'], 'Poverty')) $clusterClass = 'cluster-poverty';
              elseif (str_contains($ach['cluster'], 'Unemployment')) $clusterClass = 'cluster-unemployment';
              elseif (str_contains($ach['cluster'], 'Carbon')) $clusterClass = 'cluster-carbon';
          }
        } catch (Exception $e) { continue; }
      ?>
      <article class="achievement-card"
               data-id="<?= (int)$ach['id'] ?>"
               data-title="<?= htmlspecialchars($ach['title']) ?>"
               data-club="<?= htmlspecialchars($clubName) ?>"
               data-date="<?= htmlspecialchars($ach['achieved_on']) ?>"
               data-desc="<?= htmlspecialchars($descShort) ?>"
               data-cluster="<?= htmlspecialchars($ach['cluster'] ?? '') ?>"
               data-photos='<?= json_encode($photos, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>'>

        <div class="achievement-thumb">
          <?php if ($thumb): ?>
            <img src="<?= htmlspecialchars($thumb) ?>"
                 alt="Achievement thumbnail: <?= htmlspecialchars($ach['title']) ?>"
                 onerror="this.onerror=null;this.src='data:image/svg+xml;base64,<?= base64_encode('<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; viewBox=&quot;0 0 800 450&quot;><rect width=&quot;800&quot; height=&quot;450&quot; fill=&quot;#eef1f4&quot;/><text x=&quot;50%&quot; y=&quot;50%&quot; dominant-baseline=&quot;middle&quot; text-anchor=&quot;middle&quot; font-family=&quot;Arial&quot; font-size=&quot;22&quot; fill=&quot;#9aa6b2&quot;>Thumbnail unavailable</text></svg>') ?>';">
          <?php else: ?>
            <img src="data:image/svg+xml;base64,<?= base64_encode('<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; viewBox=&quot;0 0 800 450&quot;><rect width=&quot;800&quot; height=&quot;450&quot; fill=&quot;#eef1f4&quot;/><text x=&quot;50%&quot; y=&quot;50%&quot; dominant-baseline=&quot;middle&quot; text-anchor=&quot;middle&quot; font-family=&quot;Arial&quot; font-size=&quot;22&quot; fill=&quot;#9aa6b2&quot;>No photo</text></svg>') ?>" alt="No photo">
          <?php endif; ?>
        </div>

        <div class="achievement-body">
          <h3 class="achievement-title"><?= htmlspecialchars($ach['title']) ?></h3>
          <div class="achievement-club">
            <i class="fas fa-users"></i> <?= htmlspecialchars($clubName) ?>
            <?php if ($clusterClass): ?>
              <span class="cluster-badge <?= $clusterClass ?>"><?= htmlspecialchars($ach['cluster']) ?></span>
            <?php endif; ?>
          </div>
          <div class="achievement-date"><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($achievedDate) ?></div>
          <?php if ($descShort): ?><div class="achievement-desc"><?= htmlspecialchars($descShort) ?></div><?php endif; ?>
          <?php if (!empty($photos)): ?>
            <div class="achievement-thumbs">
              <?php foreach (array_slice($photos, 0, 3) as $i => $p): ?>
                <div class="achievement-thumb-item">
                  <img src="<?= htmlspecialchars($p['file_path']) ?>"
                       alt="Achievement photo <?= $i+1 ?>"
                       onerror="this.onerror=null;this.style.display='none';this.parentElement.innerHTML='<div class=&quot;achievement-thumb-count&quot;>Photo <?= $i+1 ?></div>';">
                </div>
              <?php endforeach; ?>
              <?php if (count($photos) > 3): ?>
                <div class="achievement-thumb-item"><div class="achievement-thumb-count">+<?= count($photos)-3 ?> more</div></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- Modal -->
<div class="modal-backdrop" id="achievementModalBackdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="achievementModalTitle">
    <div class="modal-header">
      <div class="modal-title" id="achievementModalTitle">Achievement</div>
      <button class="modal-close" id="achievementModalClose" aria-label="Close"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="modal-row"><div class="modal-label">Club</div><div id="mClub"></div></div>
      <div class="modal-row"><div class="modal-label">Cluster</div><div id="mCluster"></div></div>
      <div class="modal-row"><div class="modal-label">Achieved On</div><div id="mDate"></div></div>
      <div class="modal-row"><div class="modal-label">Description</div><div id="mDesc" class="modal-desc"></div></div>
      <div class="modal-row" id="mPhotosRow" style="display:none;"><div class="modal-label">Photos</div><div class="achievement-photos" id="mPhotos"></div></div>
    </div>
  </div>
</div>

<script>
(() => {
  const form = document.getElementById('achievementsFilterForm');
  const club = form?.querySelector('[name="club_id"]');
  const yr   = form?.querySelector('[name="year"]');
  const mo   = form?.querySelector('[name="month"]');
  const q    = form?.querySelector('[name="q"]');
  const reset = document.getElementById('resetFilters');

  club?.addEventListener('change', () => form.submit());
  yr?.addEventListener('change', () => form.submit());
  mo?.addEventListener('change', () => form.submit());
  q?.addEventListener('keydown', e => { if (e.key === 'Enter') form.submit(); });

  reset?.addEventListener('click', () => {
    const url = new URL(window.location.href);
    ['q','club_id','year','month'].forEach(k => url.searchParams.delete(k));
    window.location.href = url.toString();
  });

  // Clean URL (only non-empty fields)
  form?.addEventListener('submit', (e) => {
    e.preventDefault();
    const url = new URL(window.location.origin + window.location.pathname);
    new FormData(form).forEach((v,k) => { if (String(v).trim() !== '') url.searchParams.set(k,v); });
    window.location.href = url.toString();
  });
})();

(() => {
  const grid   = document.getElementById('achievementsGrid');
  const modal  = document.getElementById('achievementModalBackdrop');
  const close  = document.getElementById('achievementModalClose');

  const mTitle = document.getElementById('achievementModalTitle');
  const mClub  = document.getElementById('mClub');
  const mCluster = document.getElementById('mCluster');
  const mDate  = document.getElementById('mDate');
  const mDesc  = document.getElementById('mDesc');
  const mPhotosRow = document.getElementById('mPhotosRow');
  const mPhotos = document.getElementById('mPhotos');

  const fmtDate = iso => {
    if (!iso) return 'Date not specified';
    const d = new Date(iso + 'T00:00:00');
    return d.toLocaleString('en-US', { month:'long', day:'numeric', year:'numeric' });
  };

  function openModal(card) {
    const title   = card.dataset.title || 'Achievement';
    const club    = card.dataset.club || '3ZERO Club';
    const cluster = card.dataset.cluster || '';
    const date    = card.dataset.date || '';
    const desc    = card.dataset.desc || '';
    let photos    = [];
    try { photos = JSON.parse(card.dataset.photos || '[]'); } catch(e){}

    mTitle.textContent = title;
    mClub.textContent  = club;
    mCluster.textContent = cluster || '—';
    mDate.textContent  = fmtDate(date);
    mDesc.textContent  = desc || 'No description available';

    if (Array.isArray(photos) && photos.length) {
      mPhotos.innerHTML = '';
      photos.forEach(p => {
        const img = document.createElement('img');
        const src = String(p.file_path || '').replace(/^(\.\.\/)+/, '');
        img.src = src;
        img.alt = p.original_name || 'Achievement photo';
        img.className = 'achievement-photo';
        img.addEventListener('click', () => window.open(src, '_blank'));
        mPhotos.appendChild(img);
      });
      mPhotosRow.style.display = 'flex';
    } else {
      mPhotosRow.style.display = 'none';
    }

    modal.classList.add('show');
    modal.setAttribute('aria-hidden','false');
  }

  function closeModal(){ modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); }

  grid?.addEventListener('click', e => {
    const card = e.target.closest('.achievement-card');
    if (card) openModal(card);
  });
  close?.addEventListener('click', closeModal);
  modal?.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  window.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
})();
</script>

<?php include('footer.php'); ?>
