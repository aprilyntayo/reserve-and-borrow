<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId         = $_SESSION['user_id'];
$userName       = $_SESSION['uname']        ?? 'Program Head';
$programCode    = $_SESSION['program_code'] ?? '';
$signatoryLabel = ($programCode ? strtoupper($programCode) . ' ' : '') . 'Program Head';

// ── Filters ──────────────────────────────────────────────────────────────
$filterStatus = $_GET['status']   ?? 'All';
$filterSearch = trim($_GET['search'] ?? '');
$page         = max(1, intval($_GET['page'] ?? 1));
$perPage      = 15;
$offset       = ($page - 1) * $perPage;

// ── Base query — only records where Program Head has acted (signed or rejected) ──
$where = "WHERE (name_dept_head IS NOT NULL AND name_dept_head != '') OR status = 'Rejected'";

if ($filterStatus === 'Verified') {
    $where = "WHERE name_dept_head IS NOT NULL AND name_dept_head != ''
              AND (name_approved_by IS NULL OR name_approved_by = '')
              AND status = 'Pending'";
} elseif ($filterStatus === 'Approved') {
    $where = "WHERE status = 'Approved'";
} elseif ($filterStatus === 'Rejected') {
    $where = "WHERE status = 'Rejected'";
}

if (!empty($filterSearch)) {
    $safeSearch = $conn->real_escape_string($filterSearch);
    $where .= " AND (user_name LIKE '%{$safeSearch}%'
                  OR equipment_name LIKE '%{$safeSearch}%'
                  OR purpose LIKE '%{$safeSearch}%')";
}

// ── Count ─────────────────────────────────────────────────────────────────
$countRes  = $conn->query("SELECT COUNT(*) AS c FROM borrows $where");
$totalRows = (int)($countRes->fetch_assoc()['c'] ?? 0);
$totalPages = max(1, ceil($totalRows / $perPage));

// ── Fetch ─────────────────────────────────────────────────────────────────
$sql = "SELECT b.id, b.user_name, b.equipment_name,
               b.borrow_date, b.return_date, b.start_time, b.end_time,
               b.purpose, b.status,
               b.name_mmit_director, b.sig_mmit_director,
               b.name_dept_head,    b.sig_dept_head,
               b.name_approved_by,  b.sig_approved_by,
               u.email
        FROM borrows b
        LEFT JOIN users u ON b.user_id = u.id
        $where
        ORDER BY b.id DESC
        LIMIT $perPage OFFSET $offset";
$records = $conn->query($sql);

// ── Quick counts for pills ────────────────────────────────────────────────
$cAll      = (int)$conn->query("SELECT COUNT(*) AS c FROM borrows WHERE (name_dept_head IS NOT NULL AND name_dept_head != '') OR status='Rejected'")->fetch_assoc()['c'];
$cVerified = (int)$conn->query("SELECT COUNT(*) AS c FROM borrows WHERE name_dept_head IS NOT NULL AND name_dept_head!='' AND (name_approved_by IS NULL OR name_approved_by='') AND status='Pending'")->fetch_assoc()['c'];
$cApproved = (int)$conn->query("SELECT COUNT(*) AS c FROM borrows WHERE status='Approved'")->fetch_assoc()['c'];
$cRejected = (int)$conn->query("SELECT COUNT(*) AS c FROM borrows WHERE status='Rejected'")->fetch_assoc()['c'];

// ── Notifications ─────────────────────────────────────────────────────────
$notifStmt = $conn->prepare("SELECT id, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$notifStmt->bind_param("i", $userId);
$notifStmt->execute();
$notifList  = [];
while ($n = $notifStmt->get_result()->fetch_assoc()) $notifList[] = $n;
$notifCount = count($notifList);

// ── Helpers ───────────────────────────────────────────────────────────────
function fmtDate($s, $e) {
    return $s === $e ? date('M d, Y', strtotime($s))
        : date('M d, Y', strtotime($s)) . ' → ' . date('M d, Y', strtotime($e));
}
function fmtTime($s, $e) {
    return date('h:i A', strtotime($s)) . ' - ' . date('h:i A', strtotime($e));
}
function parseField($purpose, $label) {
    if (preg_match('/' . preg_quote($label, '/') . ':\s*([^|]+)/i', $purpose, $m)) return trim($m[1]);
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Request History — Program Head | AssetEase</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --red:       #95122C;
    --red-dark:  #7a0c23;
    --dark:      #100C08;
    --gold:      #FFD700;
    --bg:        #F5EFED;
    --white:     #ffffff;
    --shadow:    0 4px 20px rgba(0,0,0,0.08);
    --shadow-md: 0 8px 30px rgba(0,0,0,0.12);
    --radius:    14px;
    --sidebar-w: 268px;
}
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Poppins',sans-serif; background:var(--bg); display:flex; min-height:100vh; color:var(--dark); }

/* ── Sidebar (identical to dashboard) ──────────────────────── */
.sidebar { width:var(--sidebar-w); height:100vh; background:linear-gradient(180deg,var(--dark) 0%,var(--red) 100%); color:#fff; position:fixed; left:0; top:0; display:flex; flex-direction:column; overflow:hidden; z-index:1001; }
.sidebar-logo { display:flex; align-items:center; gap:13px; padding:26px 22px 20px; border-bottom:1px solid rgba(255,255,255,0.1); flex-shrink:0; text-decoration:none; }
.logo-img-wrap { position:relative; width:44px; height:44px; flex-shrink:0; }
.logo-img-wrap img { width:44px; height:44px; object-fit:cover; border-radius:10px; background:#fff; padding:2px; border:2px solid rgba(255,215,0,0.5); }
.logo-glow { position:absolute; inset:-4px; border-radius:14px; background:radial-gradient(circle,rgba(255,215,0,0.25) 0%,transparent 70%); pointer-events:none; }
.sidebar-logo-text h2 { color:var(--gold); font-size:18px; font-weight:700; letter-spacing:2px; line-height:1; }
.sidebar-logo-text span { font-size:10px; color:rgba(255,255,255,0.5); letter-spacing:1px; text-transform:uppercase; }
.admin-badge { margin:14px 16px 6px; display:flex; align-items:center; gap:10px; padding:10px 14px; background:rgba(255,215,0,0.12); border:1px solid rgba(255,215,0,0.25); border-radius:10px; flex-shrink:0; }
.admin-badge img { width:34px; height:34px; border-radius:50%; border:2px solid var(--gold); object-fit:cover; }
.admin-badge-info .ab-name { font-size:12px; font-weight:700; color:#fff; line-height:1; }
.admin-badge-info .ab-role { font-size:10px; color:var(--gold); text-transform:uppercase; letter-spacing:0.8px; margin-top:3px; }
.sidebar-nav { flex:1; overflow-y:auto; padding:10px 0 8px; scrollbar-width:none; }
.sidebar-nav::-webkit-scrollbar { display:none; }
.nav-section-label { padding:14px 22px 5px; font-size:9.5px; font-weight:700; letter-spacing:2.5px; color:rgba(255,255,255,0.38); text-transform:uppercase; }
.sidebar a { display:flex; align-items:center; gap:12px; padding:11px 22px; color:rgba(255,255,255,0.78); text-decoration:none; font-size:0.855rem; font-weight:500; border-left:3px solid transparent; transition:all 0.22s; margin:1px 8px; border-radius:8px; }
.sidebar a .nav-icon { width:30px; height:30px; display:flex; align-items:center; justify-content:center; font-size:14px; border-radius:7px; background:rgba(255,255,255,0.06); flex-shrink:0; transition:all 0.22s; }
.sidebar a:hover { background:rgba(255,255,255,0.10); color:#fff; border-left-color:rgba(255,255,255,0.3); }
.sidebar a.active { background:rgba(255,255,255,0.15); color:#fff; border-left-color:var(--gold); font-weight:600; }
.sidebar a.active .nav-icon { background:rgba(255,215,0,0.18); color:var(--gold); }
.sidebar hr { border:none; border-top:1px solid rgba(255,255,255,0.09); margin:8px 18px; }
.sidebar-promo { margin:8px 14px 18px; padding:14px 15px; background:linear-gradient(135deg,rgba(255,215,0,0.18),rgba(255,255,255,0.06)); border:1px solid rgba(255,215,0,0.28); border-radius:12px; flex-shrink:0; }
.sidebar-promo p { font-size:11.5px; color:rgba(255,255,255,0.82); line-height:1.5; }
.sidebar-promo strong { color:var(--gold); }

/* ── Main ───────────────────────────────────────────────────── */
.main-wrap { margin-left:var(--sidebar-w); flex:1; display:flex; flex-direction:column; min-height:100vh; }
.top-header { position:sticky; top:0; background:var(--white); display:flex; justify-content:space-between; align-items:center; padding:0 36px; height:68px; box-shadow:0 1px 0 rgba(0,0,0,0.07),0 2px 12px rgba(0,0,0,0.04); z-index:999; gap:16px; }
.header-left { display:flex; align-items:center; gap:14px; min-width:0; }
.header-logo-mini { display:flex; align-items:center; gap:9px; padding-right:14px; border-right:1px solid #eee; }
.header-logo-mini img { width:34px; height:34px; border-radius:8px; object-fit:cover; border:1.5px solid rgba(149,18,44,0.2); }
.header-logo-mini span { font-size:14px; font-weight:700; color:var(--red); letter-spacing:1.5px; }
.header-title-area h1 { font-size:19px; font-weight:700; color:var(--dark); line-height:1.1; }
.header-title-area p  { font-size:12px; color:#999; margin-top:1px; }
.header-right { display:flex; align-items:center; gap:11px; flex-shrink:0; }
.notif-wrap { position:relative; }
.notif-btn { width:40px; height:40px; border-radius:50%; background:var(--bg); border:none; display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--dark); font-size:16px; transition:0.2s; }
.notif-btn:hover { background:#ede6e3; }
.notif-badge { position:absolute; top:-1px; right:-1px; background:#dc3545; color:#fff; font-size:9px; font-weight:700; width:17px; height:17px; border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid #fff; }
.notif-dropdown { position:absolute; top:50px; right:0; width:360px; background:#fff; border-radius:var(--radius); box-shadow:var(--shadow-md); z-index:2000; display:none; max-height:390px; overflow-y:auto; border:1px solid rgba(0,0,0,0.07); }
.notif-dropdown.active { display:block; }
.notif-header { padding:14px 18px 10px; font-weight:700; font-size:13px; border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; }
.notif-item { padding:12px 18px; border-bottom:1px solid #f8f8f8; display:flex; gap:10px; }
.notif-item .ni-icon { color:var(--red); font-size:13px; margin-top:2px; flex-shrink:0; }
.notif-item .ni-msg  { font-size:12.5px; color:#444; line-height:1.4; }
.notif-item .ni-time { font-size:11px; color:#bbb; margin-top:2px; }
.notif-empty { padding:28px; text-align:center; color:#bbb; font-size:13px; }
.profile-pill { display:flex; align-items:center; gap:9px; background:var(--bg); border-radius:30px; padding:5px 14px 5px 6px; cursor:pointer; border:1px solid transparent; }
.profile-pill img { width:32px; height:32px; border-radius:50%; object-fit:cover; border:2px solid var(--red); }
.profile-pill span { font-size:13px; font-weight:600; color:var(--dark); }
.profile-pill .pp-role { font-size:10px; color:#999; display:block; }

/* ── Content ────────────────────────────────────────────────── */
.content { padding:28px 36px 50px; flex:1; }

/* ── Page title ─────────────────────────────────────────────── */
.page-title-bar { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:12px; }
.page-title-bar h2 { font-size:22px; font-weight:700; color:var(--dark); display:flex; align-items:center; gap:10px; }
.page-title-bar h2 i { color:var(--red); }
.page-title-bar p { font-size:13px; color:#999; margin-top:2px; }
.back-btn { display:inline-flex; align-items:center; gap:7px; padding:8px 16px; background:var(--white); border:1.5px solid #e0d8d5; border-radius:25px; color:#666; font-size:13px; font-weight:500; text-decoration:none; transition:0.2s; }
.back-btn:hover { border-color:var(--red); color:var(--red); }

/* ── Filters bar ────────────────────────────────────────────── */
.filter-bar { background:var(--white); border-radius:var(--radius); padding:18px 22px; box-shadow:var(--shadow); margin-bottom:22px; display:flex; flex-wrap:wrap; gap:12px; align-items:center; }
.filter-pills { display:flex; gap:8px; flex-wrap:wrap; flex:1; }
.filter-pill { padding:7px 16px; border-radius:25px; border:1.5px solid #e0d8d5; background:#fff; color:#666; font-size:12.5px; font-weight:500; cursor:pointer; transition:all 0.2s; font-family:'Poppins'; display:inline-flex; align-items:center; gap:6px; text-decoration:none; }
.filter-pill:hover  { border-color:var(--red); color:var(--red); }
.filter-pill.active { background:var(--red); color:#fff; border-color:var(--red); box-shadow:0 2px 8px rgba(149,18,44,0.2); }
.search-form { display:flex; align-items:center; gap:8px; }
.search-input { padding:8px 14px; border:1.5px solid #e0d8d5; border-radius:25px; font-family:'Poppins'; font-size:13px; color:var(--dark); outline:none; width:220px; transition:0.2s; }
.search-input:focus { border-color:var(--red); box-shadow:0 0 0 3px rgba(149,18,44,0.07); }
.search-btn { padding:8px 16px; background:var(--red); color:#fff; border:none; border-radius:25px; font-family:'Poppins'; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; transition:0.2s; }
.search-btn:hover { background:var(--red-dark); }

/* ── Summary stat strip ─────────────────────────────────────── */
.stat-strip { display:flex; gap:14px; margin-bottom:22px; flex-wrap:wrap; }
.stat-chip { background:var(--white); border-radius:10px; padding:14px 20px; box-shadow:var(--shadow); display:flex; align-items:center; gap:12px; flex:1; min-width:140px; border-left:4px solid transparent; }
.stat-chip:nth-child(1) { border-left-color:#f39c12; }
.stat-chip:nth-child(2) { border-left-color:#3b82f6; }
.stat-chip:nth-child(3) { border-left-color:#22c55e; }
.stat-chip:nth-child(4) { border-left-color:#dc3545; }
.stat-chip-icon { width:38px; height:38px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
.sc-amber { background:#fff7ed; color:#f97316; }
.sc-blue  { background:#eff6ff; color:#3b82f6; }
.sc-green { background:#f0fdf4; color:#22c55e; }
.sc-red   { background:#fef2f2; color:#dc3545; }
.stat-chip-body .sc-val  { font-size:22px; font-weight:700; color:var(--dark); line-height:1; }
.stat-chip-body .sc-lbl  { font-size:11.5px; color:#aaa; margin-top:3px; }

/* ── Table card ─────────────────────────────────────────────── */
.card { background:var(--white); border-radius:var(--radius); padding:24px 26px; box-shadow:var(--shadow); }
.card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
.card-title { font-size:15px; font-weight:700; color:var(--dark); display:flex; align-items:center; gap:8px; }
.card-title i { color:var(--red); }
.result-count { font-size:12px; color:#aaa; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; min-width:900px; }
thead tr { border-bottom:2px solid #f0ebe8; }
th { padding:11px 14px; text-align:left; font-size:11px; font-weight:700; letter-spacing:0.6px; text-transform:uppercase; color:#aaa; }
tbody tr { border-bottom:1px solid #f7f3f1; transition:background 0.15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:#fdf9f8; }
td { padding:13px 14px; font-size:13px; color:#444; vertical-align:middle; }
td strong { color:var(--dark); }

/* ── Status badges ───────────────────────────────────────────── */
.badge-status { display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border-radius:20px; font-size:11.5px; font-weight:600; }
.badge-status::before { content:''; width:6px; height:6px; border-radius:50%; display:inline-block; }
.status-Pending  { background:#fff8e6; color:#b07800; }
.status-Pending::before  { background:#f39c12; }
.status-Approved { background:#edfaf1; color:#1a7a3c; }
.status-Approved::before { background:#28a745; }
.status-Rejected { background:#fdf0f0; color:#9b1c1c; }
.status-Rejected::before { background:#dc3545; }

/* ── Progress badge ──────────────────────────────────────────── */
.sig-progress-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:6px; font-size:11.5px; font-weight:700; cursor:pointer; transition:all 0.18s; border:1px solid; }
.sig-0of3 { background:#f9fafb; color:#6b7280; border-color:#d1d5db; }
.sig-1of3 { background:#fff7ed; color:#9a3412; border-color:#fdba74; }
.sig-2of3 { background:#eff6ff; color:#1e40af; border-color:#93c5fd; }
.sig-3of3 { background:#f0fdf4; color:#166534; border-color:#86efac; }

/* ── No results ──────────────────────────────────────────────── */
.no-results { text-align:center; padding:50px 20px; color:#ccc; }
.no-results i { font-size:40px; display:block; margin-bottom:12px; }
.no-results p { font-size:14px; }

/* ── Pagination ──────────────────────────────────────────────── */
.pagination { display:flex; justify-content:center; gap:6px; margin-top:24px; flex-wrap:wrap; }
.page-btn { padding:7px 14px; border-radius:8px; border:1.5px solid #e0d8d5; background:#fff; color:#666; font-size:13px; font-family:'Poppins'; cursor:pointer; text-decoration:none; transition:0.2s; display:inline-flex; align-items:center; gap:5px; }
.page-btn:hover  { border-color:var(--red); color:var(--red); }
.page-btn.active { background:var(--red); color:#fff; border-color:var(--red); font-weight:700; }
.page-btn.disabled { opacity:0.4; pointer-events:none; }

/* ── Sig View Modal ──────────────────────────────────────────── */
.modal-overlay { display:none; position:fixed; z-index:1500; inset:0; background:rgba(0,0,0,0.45); align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:18px; padding:32px; max-width:640px; width:92%; box-shadow:0 16px 48px rgba(0,0,0,0.18); animation:modalIn 0.22s ease; max-height:90vh; overflow-y:auto; }
@keyframes modalIn { from{transform:scale(0.94);opacity:0} to{transform:scale(1);opacity:1} }
.modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid #f0ebe8; }
.modal-header h2 { font-size:18px; color:var(--dark); font-weight:700; }
.modal-close { background:none; border:none; cursor:pointer; color:#bbb; font-size:20px; }
.modal-close:hover { color:#555; }
.modal-info { background:#fdf9f8; border-radius:10px; padding:14px; color:#555; font-size:13px; line-height:1.7; margin-bottom:18px; }
.sig-view-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
.sig-view-item { text-align:center; border:1px solid #e5e7eb; border-radius:10px; padding:14px 10px; }
.sig-view-item.done    { border-color:#86efac; background:#f0fff4; }
.sig-view-item.pending { background:#f9fafb; }
.sig-view-step  { font-size:10px; font-weight:700; color:var(--red); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px; }
.sig-view-img-wrap { height:72px; display:flex; align-items:center; justify-content:center; margin-bottom:8px; }
.sig-view-img-wrap img { max-width:100%; max-height:70px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; padding:3px; object-fit:contain; }
.sig-view-placeholder { width:100%; height:52px; border-bottom:1.5px dashed #d1d5db; display:flex; align-items:center; justify-content:center; color:#d1d5db; font-size:11px; font-style:italic; }
.sig-view-name  { font-size:12px; font-weight:700; color:#1f2937; margin-top:4px; }
.sig-view-role  { font-size:10.5px; color:#9ca3af; margin-top:2px; }
.sig-view-badge-done    { display:inline-flex; align-items:center; gap:4px; background:#dcfce7; color:#166534; border-radius:20px; padding:2px 10px; font-size:10.5px; font-weight:700; margin-top:6px; }
.sig-view-badge-pending { display:inline-flex; align-items:center; gap:4px; background:#f3f4f6; color:#6b7280; border-radius:20px; padding:2px 10px; font-size:10.5px; font-weight:600; margin-top:6px; }
.modal-actions { display:flex; justify-content:flex-end; margin-top:20px; }
.btn-modal-cancel { padding:10px 22px; border-radius:9px; border:none; font-size:13px; font-weight:600; font-family:'Poppins'; cursor:pointer; background:#f0ebe8; color:#555; }
.btn-modal-cancel:hover { background:#e0d8d5; }

@media(max-width:768px){
    :root{--sidebar-w:0px;}
    .sidebar{display:none;}
    .main-wrap{margin-left:0;}
    .content{padding:16px 12px 40px;}
    .top-header{padding:0 14px;height:auto;min-height:60px;flex-wrap:wrap;padding-top:8px;padding-bottom:8px;}
    .header-logo-mini{display:none;}
    .stat-strip{gap:8px;}
    .sig-view-grid{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<!-- ═══ SIDEBAR ═══════════════════════════════════════════════════════════ -->
<div class="sidebar">
    <a href="programhead_dashboard.php" class="sidebar-logo">
        <div class="logo-img-wrap">
            <img src="image/logo.png" alt="AssetEase Logo">
            <div class="logo-glow"></div>
        </div>
        <div class="sidebar-logo-text">
            <h2>ASSETEASE</h2>
            <span>Program Head Portal</span>
        </div>
    </a>
    <div class="admin-badge">
        <img src="https://ui-avatars.com/api/?name=<?= urlencode($userName) ?>&background=95122C&color=fff&rounded=true" alt="">
        <div class="admin-badge-info">
            <div class="ab-name"><?= htmlspecialchars(strlen($userName) > 16 ? substr($userName, 0, 16) . '…' : $userName) ?></div>
            <div class="ab-role"><?= htmlspecialchars($signatoryLabel) ?></div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="programhead_dashboard.php">
            <div class="nav-icon"><i class="fas fa-table-cells-large"></i></div> Dashboard
        </a>
        <a href="prog_history.php" class="active">
            <div class="nav-icon"><i class="fas fa-clock-rotate-left"></i></div> Request History
        </a>
        <hr>
        <div class="nav-section-label">System</div>
        <a href="logout.php"><div class="nav-icon"><i class="fas fa-right-from-bracket"></i></div> Logout</a>
    </nav>
    <div class="sidebar-promo">
        <p><strong>History:</strong> View all requests you have verified or rejected as Program Head.</p>
    </div>
</div>

<!-- ═══ MAIN ═══════════════════════════════════════════════════════════════ -->
<div class="main-wrap">
    <div class="top-header">
        <div class="header-left">
            <div class="header-logo-mini">
                <img src="image/logo.png" alt="AssetEase">
                <span>ASSETEASE</span>
            </div>
            <div class="header-title-area">
                <h1>Request History</h1>
                <p>All requests you have acted on as <?= htmlspecialchars($signatoryLabel) ?></p>
            </div>
        </div>
        <div class="header-right">
            <div class="notif-wrap">
                <button class="notif-btn" onclick="toggleNotif(event)">
                    <i class="fas fa-bell"></i>
                    <?php if ($notifCount > 0): ?><span class="notif-badge"><?= $notifCount ?></span><?php endif; ?>
                </button>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <span><i class="fas fa-bell" style="color:var(--red);margin-right:6px;"></i>Notifications</span>
                        <span style="font-size:11px;color:#bbb;"><?= $notifCount ?> items</span>
                    </div>
                    <?php if (!empty($notifList)): foreach ($notifList as $notif): ?>
                    <div class="notif-item">
                        <i class="fas fa-bell ni-icon"></i>
                        <div>
                            <div class="ni-msg"><?= htmlspecialchars($notif['message']) ?></div>
                            <div class="ni-time"><?= date('M d, h:i A', strtotime($notif['created_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <div class="notif-empty"><i class="fas fa-bell-slash" style="font-size:24px;display:block;margin-bottom:8px;color:#ddd;"></i>No notifications yet</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="profile-pill">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($userName) ?>&background=95122C&color=fff&rounded=true" alt="">
                <div>
                    <span><?= htmlspecialchars(strlen($userName) > 14 ? substr($userName, 0, 14) . '…' : $userName) ?></span>
                    <span class="pp-role"><?= htmlspecialchars($signatoryLabel) ?></span>
                </div>
            </div>
        </div>
    </div><!-- /top-header -->

    <div class="content">

        <!-- Page title -->
        <div class="page-title-bar">
            <div>
                <h2><i class="fas fa-clock-rotate-left"></i> Request History</h2>
                <p>A complete log of all equipment borrow requests you have verified or rejected.</p>
            </div>
            <a href="programhead_dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <!-- Stat strip -->
        <div class="stat-strip">
            <div class="stat-chip">
                <div class="stat-chip-icon sc-amber"><i class="fas fa-list"></i></div>
                <div class="stat-chip-body">
                    <div class="sc-val"><?= $cAll ?></div>
                    <div class="sc-lbl">Total Acted On</div>
                </div>
            </div>
            <div class="stat-chip">
                <div class="stat-chip-icon sc-blue"><i class="fas fa-check-double"></i></div>
                <div class="stat-chip-body">
                    <div class="sc-val"><?= $cVerified ?></div>
                    <div class="sc-lbl">Verified (Awaiting VP)</div>
                </div>
            </div>
            <div class="stat-chip">
                <div class="stat-chip-icon sc-green"><i class="fas fa-circle-check"></i></div>
                <div class="stat-chip-body">
                    <div class="sc-val"><?= $cApproved ?></div>
                    <div class="sc-lbl">Fully Approved</div>
                </div>
            </div>
            <div class="stat-chip">
                <div class="stat-chip-icon sc-red"><i class="fas fa-circle-xmark"></i></div>
                <div class="stat-chip-body">
                    <div class="sc-val"><?= $cRejected ?></div>
                    <div class="sc-lbl">Rejected</div>
                </div>
            </div>
        </div>

        <!-- Filter & Search bar -->
        <div class="filter-bar">
            <div class="filter-pills">
                <a href="?status=All<?= $filterSearch ? '&search='.urlencode($filterSearch) : '' ?>"
                   class="filter-pill <?= $filterStatus==='All'?'active':'' ?>">
                    <i class="fas fa-layer-group" style="font-size:10px;"></i> All (<?= $cAll ?>)
                </a>
                <a href="?status=Verified<?= $filterSearch ? '&search='.urlencode($filterSearch) : '' ?>"
                   class="filter-pill <?= $filterStatus==='Verified'?'active':'' ?>">
                    <i class="fas fa-check-double" style="font-size:10px;"></i> Verified — Awaiting VP (<?= $cVerified ?>)
                </a>
                <a href="?status=Approved<?= $filterSearch ? '&search='.urlencode($filterSearch) : '' ?>"
                   class="filter-pill <?= $filterStatus==='Approved'?'active':'' ?>">
                    <i class="fas fa-circle-check" style="font-size:10px;"></i> Fully Approved (<?= $cApproved ?>)
                </a>
                <a href="?status=Rejected<?= $filterSearch ? '&search='.urlencode($filterSearch) : '' ?>"
                   class="filter-pill <?= $filterStatus==='Rejected'?'active':'' ?>">
                    <i class="fas fa-xmark" style="font-size:10px;"></i> Rejected (<?= $cRejected ?>)
                </a>
            </div>
            <form method="GET" class="search-form">
                <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
                <input type="text" name="search" class="search-input"
                       placeholder="Search user, equipment…"
                       value="<?= htmlspecialchars($filterSearch) ?>">
                <button type="submit" class="search-btn"><i class="fas fa-search"></i> Search</button>
                <?php if ($filterSearch): ?>
                <a href="?status=<?= urlencode($filterStatus) ?>" class="back-btn" style="padding:8px 12px;">
                    <i class="fas fa-xmark"></i> Clear
                </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Table -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-table-list"></i> History Records</div>
                <div class="result-count">
                    Showing <?= min($offset + 1, $totalRows) ?>–<?= min($offset + $perPage, $totalRows) ?>
                    of <?= $totalRows ?> record<?= $totalRows !== 1 ? 's' : '' ?>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Requestor</th>
                            <th>Equipment</th>
                            <th>Date Range</th>
                            <th>Time</th>
                            <th>Event / Purpose</th>
                            <th>Signatures</th>
                            <th>Final Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($records && $records->num_rows > 0):
                        while ($r = $records->fetch_assoc()):
                            $event   = parseField($r['purpose'], 'Event');
                            $dept    = parseField($r['purpose'], 'Department');
                            $contact = parseField($r['purpose'], 'Contact');
                            if (!$event) {
                                $raw   = preg_replace('/Contact:\s*[^|]+(\||$)/i',    '', $r['purpose']);
                                $raw   = preg_replace('/Department:\s*[^|]+(\||$)/i', '', $raw);
                                $event = trim(str_replace('|', '', preg_replace('/Purpose:\s*/i', '', $raw)));
                            }
                            $mmitDone = !empty($r['name_mmit_director']);
                            $deptDone = !empty($r['name_dept_head']);
                            $vpDone   = !empty($r['name_approved_by']);
                            $doneCount = ($mmitDone?1:0) + ($deptDone?1:0) + ($vpDone?1:0);
                            $sigClass  = ['sig-0of3','sig-1of3','sig-2of3','sig-3of3'][$doneCount];

                            $jsData = htmlspecialchars(json_encode([
                                'id'       => $r['id'],
                                'user'     => $r['user_name'],
                                'equip'    => $r['equipment_name'],
                                'date'     => fmtDate($r['borrow_date'], $r['return_date']),
                                'time'     => fmtTime($r['start_time'],  $r['end_time']),
                                'event'    => $event,
                                'dept'     => $dept,
                                'contact'  => $contact,
                                'sigMmit'  => $r['sig_mmit_director'] ?? '',
                                'nameMmit' => $r['name_mmit_director'] ?? '',
                                'sigDept'  => $r['sig_dept_head']      ?? '',
                                'nameDept' => $r['name_dept_head']     ?? '',
                                'sigVp'    => $r['sig_approved_by']    ?? '',
                                'nameVp'   => $r['name_approved_by']   ?? '',
                                'status'   => $r['status'],
                            ]), ENT_QUOTES);
                    ?>
                    <tr>
                        <td style="color:#bbb;font-size:12px;">#<?= $r['id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($r['user_name']) ?></strong>
                            <?php if ($dept): ?>
                            <div style="font-size:11px;color:var(--red);margin-top:2px;">
                                <i class="fas fa-building" style="font-size:9px;"></i> <?= htmlspecialchars($dept) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($r['equipment_name']) ?></td>
                        <td style="white-space:nowrap;font-size:12.5px;"><?= fmtDate($r['borrow_date'], $r['return_date']) ?></td>
                        <td style="white-space:nowrap;font-size:12.5px;"><?= fmtTime($r['start_time'], $r['end_time']) ?></td>
                        <td style="max-width:200px;">
                            <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px;" title="<?= htmlspecialchars($event) ?>">
                                <?= htmlspecialchars(substr($event, 0, 45)) . (strlen($event) > 45 ? '…' : '') ?>
                            </div>
                            <?php if ($contact): ?>
                            <div style="font-size:11px;color:#aaa;margin-top:2px;">
                                <i class="fas fa-phone" style="font-size:9px;"></i> <?= htmlspecialchars($contact) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button"
                                class="sig-progress-badge <?= $sigClass ?>"
                                onclick='showSigModal(<?= $jsData ?>)'
                                title="View signatures">
                                <i class="fas fa-signature"></i> <?= $doneCount ?>/3
                                <?php if ($doneCount === 3): ?>
                                    <i class="fas fa-check-circle" style="color:#22c55e;"></i>
                                <?php endif; ?>
                            </button>
                        </td>
                        <td><span class="badge-status status-<?= $r['status'] ?>"><?= $r['status'] ?></span></td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="8">
                        <div class="no-results">
                            <i class="fas fa-clock-rotate-left"></i>
                            <p>No records found<?= $filterSearch ? ' for "'.htmlspecialchars($filterSearch).'"' : '' ?>.</p>
                        </div>
                    </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <a href="?status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($filterSearch) ?>&page=<?= max(1,$page-1) ?>"
                   class="page-btn <?= $page<=1?'disabled':'' ?>">
                    <i class="fas fa-chevron-left"></i> Prev
                </a>
                <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
                <a href="?status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($filterSearch) ?>&page=<?= $p ?>"
                   class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="?status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($filterSearch) ?>&page=<?= min($totalPages,$page+1) ?>"
                   class="page-btn <?= $page>=$totalPages?'disabled':'' ?>">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /content -->
</div><!-- /main-wrap -->

<!-- ═══ SIGNATURE VIEW MODAL ══════════════════════════════════════════════ -->
<div id="sigModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h2><i class="fas fa-signature" style="color:#4f46e5;margin-right:8px;"></i>Signature Progress</h2>
            <button class="modal-close" onclick="closeSigModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div id="sigModalInfo" class="modal-info"></div>
        <div id="sigModalStatus" style="margin-bottom:14px;font-size:13px;color:#555;"></div>
        <div class="sig-view-grid">
            <?php foreach ([1=>'MMIT Director', 2=>'Program Head', 3=>'VP for Administration &amp; Finance'] as $n => $role): ?>
            <div class="sig-view-item" id="svItem<?= $n ?>">
                <div class="sig-view-step">Step <?= $n ?></div>
                <div class="sig-view-img-wrap" id="svImg<?= $n ?>"></div>
                <div class="sig-view-name" id="svName<?= $n ?>"></div>
                <div class="sig-view-role"><?= $role ?></div>
                <div id="svBadge<?= $n ?>"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-modal-cancel" onclick="closeSigModal()">Close</button>
        </div>
    </div>
</div>

<script>
function toggleNotif(e) {
    e.stopPropagation();
    document.getElementById('notifDropdown').classList.toggle('active');
}
document.addEventListener('click', () => {
    document.getElementById('notifDropdown')?.classList.remove('active');
});

function showSigModal(data) {
    document.getElementById('sigModalInfo').innerHTML =
        '<strong>Request #' + data.id + '</strong> &mdash; ' + escHtml(data.user) +
        '<br>Equipment: <strong>' + escHtml(data.equip) + '</strong>' +
        '<br>Date: ' + escHtml(data.date) + ' &nbsp;|&nbsp; Time: ' + escHtml(data.time);

    const slots = [
        { sig: data.sigMmit, name: data.nameMmit },
        { sig: data.sigDept, name: data.nameDept },
        { sig: data.sigVp,   name: data.nameVp   },
    ];
    let done = 0;
    slots.forEach((s, i) => {
        const n   = i + 1;
        const isDone = !!(s.sig && s.sig.length > 0);
        if (isDone) done++;
        document.getElementById('svItem' + n).className = 'sig-view-item ' + (isDone ? 'done' : 'pending');
        document.getElementById('svImg'  + n).innerHTML = isDone
            ? '<img src="' + s.sig + '" alt="Signature">'
            : '<div class="sig-view-placeholder">Awaiting signature</div>';
        document.getElementById('svName' + n).textContent = s.name || '';
        document.getElementById('svBadge'+ n).innerHTML   = isDone
            ? '<span class="sig-view-badge-done"><i class="fas fa-check-circle"></i> Signed</span>'
            : '<span class="sig-view-badge-pending"><i class="fas fa-hourglass-half"></i> Pending</span>';
    });

    document.getElementById('sigModalStatus').innerHTML = done === 3
        ? '<span style="color:#166534;font-weight:700;"><i class="fas fa-circle-check"></i> All 3 signatures complete.</span>'
        : '<span style="color:#92400e;font-weight:600;"><i class="fas fa-hourglass-half"></i> ' + done + ' of 3 signatures collected.</span>';

    document.getElementById('sigModal').classList.add('open');
}
function closeSigModal() { document.getElementById('sigModal').classList.remove('open'); }
window.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) closeSigModal();
});

function escHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}
</script>
</body>
</html>