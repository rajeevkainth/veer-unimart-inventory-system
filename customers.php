<?php
// grocery/customers.php (FINAL SAFE)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

require_once __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$store_id = (int)($_SESSION['store_id'] ?? 1);

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2); }

$err = '';
$ok  = '';

/* =========================
   Table exists helpers
========================= */
function table_exists(mysqli $conn, string $table): bool {
  try {
    $t = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
  } catch(Throwable $e) {
    return false;
  }
}

$HAS_CUSTOMERS = table_exists($conn, 'customers');
$HAS_INVOICES  = table_exists($conn, 'invoices');
$HAS_PAYMENTS  = table_exists($conn, 'customer_payments');
$HAS_ALLOC     = table_exists($conn, 'payment_allocations');

if (!$HAS_CUSTOMERS) {
  die("customers table missing");
}

/* =========================
   FIFO Payment Adjuster (only if tables exist)
========================= */
function add_payment_fifo(mysqli $conn, int $store_id, int $customer_id, float $amount, string $mode, string $note, string $pdate): void {
  if ($pdate === '') $pdate = date('Y-m-d H:i:s');
  if (!in_array($mode, ['cash','upi','card','bank','other'], true)) $mode='cash';
  if ($amount <= 0) throw new Exception("Payment amount must be > 0");

  $conn->begin_transaction();

  // Insert payment
  $stmt = $conn->prepare("INSERT INTO customer_payments (store_id, customer_id, payment_date, amount, mode, note)
                          VALUES (?,?,?,?,?,?)");
  $stmt->bind_param("iisdss", $store_id, $customer_id, $pdate, $amount, $mode, $note);
  $stmt->execute();
  $payment_id = (int)$stmt->insert_id;
  $stmt->close();

  // Apply to oldest due invoices
  $remaining = $amount;

  $stmt = $conn->prepare("SELECT id, paid_total, due_total
                          FROM invoices
                          WHERE store_id=? AND customer_id=? AND status IN ('due','partial')
                          ORDER BY invoice_date ASC, id ASC");
  $stmt->bind_param("ii", $store_id, $customer_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $stmt->close();

  while ($res && ($row = $res->fetch_assoc())) {
    if ($remaining <= 0.009) break;

    $inv_id = (int)$row['id'];
    $due = (float)$row['due_total'];
    if ($due <= 0.009) continue;

    $apply = ($remaining >= $due) ? $due : $remaining;

    $newPaid = (float)$row['paid_total'] + $apply;
    $newDue  = (float)$row['due_total'] - $apply;
    if ($newDue < 0) $newDue = 0;

    $newStatus = ($newDue <= 0.009) ? 'paid' : 'partial';

    $u = $conn->prepare("UPDATE invoices
                         SET paid_total=?, due_total=?, status=?
                         WHERE id=? AND store_id=? AND customer_id=? LIMIT 1");
    $u->bind_param("ddsiii", $newPaid, $newDue, $newStatus, $inv_id, $store_id, $customer_id);
    $u->execute();
    $u->close();

    // Allocation is optional, only if table exists
    $a = $conn->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount_applied)
                         VALUES (?,?,?)");
    $a->bind_param("iid", $payment_id, $inv_id, $apply);
    $a->execute();
    $a->close();

    $remaining -= $apply;
  }

  $conn->commit();
}

/* =========================
   Quick payment submit
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_payment'])) {
  if (!$GLOBALS['HAS_INVOICES'] || !$GLOBALS['HAS_PAYMENTS'] || !$GLOBALS['HAS_ALLOC']) {
    $err = "Payments feature needs tables: invoices, customer_payments, payment_allocations. Please create them first.";
  } else {
    $pay_customer_id = (int)($_POST['pay_customer_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $mode   = (string)($_POST['mode'] ?? 'cash');
    $note   = trim((string)($_POST['note'] ?? ''));
    $pdate  = trim((string)($_POST['payment_date'] ?? ''));

    if ($pay_customer_id <= 0) $err = "Invalid customer for payment.";
    elseif ($amount <= 0) $err = "Payment amount must be greater than 0.";
    else {
      try {
        add_payment_fifo($conn, $store_id, $pay_customer_id, $amount, $mode, $note, $pdate);
        $ok = "Payment received & dues adjusted successfully.";
      } catch(Throwable $e) {
        try { $conn->rollback(); } catch(Throwable $ex) {}
        $err = "Payment error: " . $e->getMessage();
      }
    }
  }
}

/* =========================
   Add / Update Customer
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_customer'])) {
  $cid    = (int)($_POST['customer_id'] ?? 0);
  $name   = trim((string)($_POST['name'] ?? ''));
  $mobile = preg_replace('/\s+/', '', trim((string)($_POST['mobile'] ?? '')));
  $email  = trim((string)($_POST['email'] ?? ''));
  $addr   = trim((string)($_POST['address'] ?? ''));
  $type   = (string)($_POST['customer_type'] ?? 'retail');
  $status = (string)($_POST['status'] ?? 'active');
  $notes  = trim((string)($_POST['notes'] ?? ''));

  if ($name === '' || $mobile === '') {
    $err = "Name and Mobile are required.";
  } else {
    if (!in_array($type, ['retail','wholesale'], true)) $type='retail';
    if (!in_array($status, ['active','inactive'], true)) $status='active';

    try {
      if ($cid > 0) {
        $stmt = $conn->prepare("UPDATE customers
          SET name=?, mobile=?, email=?, address=?, customer_type=?, status=?, notes=?
          WHERE id=? AND store_id=? LIMIT 1");
        $stmt->bind_param("sssssssii", $name, $mobile, $email, $addr, $type, $status, $notes, $cid, $store_id);
        $stmt->execute();
        $stmt->close();
        $ok = "Customer updated.";
      } else {
        $stmt = $conn->prepare("INSERT INTO customers (store_id, name, mobile, email, address, customer_type, status, notes)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssss", $store_id, $name, $mobile, $email, $addr, $type, $status, $notes);
        $stmt->execute();
        $stmt->close();
        $ok = "Customer added.";
      }
    } catch (Throwable $e) {
      if (stripos($e->getMessage(), 'uniq_store_mobile') !== false) $err = "This mobile already exists.";
      else $err = "Error: " . $e->getMessage();
    }
  }
}

/* =========================
   Edit fetch
========================= */
$edit = null;
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
  $eid = (int)$_GET['edit'];
  $stmt = $conn->prepare("SELECT * FROM customers WHERE id=? AND store_id=? LIMIT 1");
  $stmt->bind_param("ii", $eid, $store_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $edit = $res ? $res->fetch_assoc() : null;
  $stmt->close();
}

/* =========================
   Filters/Search
========================= */
$q        = trim((string)($_GET['q'] ?? ''));
$due_only = (int)($_GET['due_only'] ?? 0);
$ctype    = (string)($_GET['type'] ?? 'all');
$cstatus  = (string)($_GET['status'] ?? 'all');
$export   = (int)($_GET['export'] ?? 0);

function build_url(array $overrides = []): string {
  $q = $_GET;
  foreach($overrides as $k=>$v){
    if ($v === null) unset($q[$k]); else $q[$k] = $v;
  }
  if (isset($q['export'])) unset($q['export']);
  $qs = http_build_query($q);
  return basename($_SERVER['PHP_SELF']) . ($qs ? '?'.$qs : '');
}
$dueToggleUrl   = build_url(['due_only' => ($due_only===1 ? 0 : 1)]);
$clearFiltersUrl= build_url(['q'=>'','type'=>'all','status'=>'all','due_only'=>0]);
$exportUrl      = build_url(['export'=>1]);

/* =========================
   Customers list + summary
   If invoices table missing => totals will be 0 (no crash)
========================= */
$where  = "c.store_id=?";
$params = [$store_id];
$types  = "i";

if ($q !== '') {
  $where .= " AND (c.name LIKE ? OR c.mobile LIKE ?)";
  $like = "%{$q}%";
  $params[] = $like; $params[] = $like;
  $types .= "ss";
}
if ($ctype === 'retail' || $ctype === 'wholesale') {
  $where .= " AND c.customer_type=?";
  $params[] = $ctype;
  $types .= "s";
}
if ($cstatus === 'active' || $cstatus === 'inactive') {
  $where .= " AND c.status=?";
  $params[] = $cstatus;
  $types .= "s";
}

if ($HAS_INVOICES) {
  $sql = "
    SELECT
      c.*,
      COALESCE(SUM(i.grand_total),0) AS total_billed,
      COALESCE(SUM(i.paid_total),0)  AS total_paid,
      COALESCE(SUM(i.due_total),0)   AS total_due,
      MAX(i.invoice_date) AS last_purchase
    FROM customers c
    LEFT JOIN invoices i
      ON i.customer_id=c.id AND i.store_id=c.store_id AND i.status!='cancelled'
    WHERE $where
    GROUP BY c.id
  ";
  if ($due_only === 1) $sql = "SELECT * FROM ( $sql ) t WHERE t.total_due > 0.009";
  $sql .= " ORDER BY (CASE WHEN c.status='active' THEN 0 ELSE 1 END), total_due DESC, c.name ASC";
} else {
  // No invoices table => still show customers list without totals
  $sql = "SELECT c.*, 0 AS total_billed, 0 AS total_paid, 0 AS total_due, NULL AS last_purchase
          FROM customers c
          WHERE $where
          ORDER BY (CASE WHEN status='active' THEN 0 ELSE 1 END), name ASC";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$list = $stmt->get_result();
$stmt->close();

$rows = [];
$summary_total_customers = 0;
$summary_due_customers   = 0;
$summary_total_due       = 0.0;

if ($list) {
  while($r = $list->fetch_assoc()){
    $rows[] = $r;
    $summary_total_customers++;
    $d = (float)$r['total_due'];
    $summary_total_due += $d;
    if ($d > 0.009) $summary_due_customers++;
  }
}

/* =========================
   Export CSV (Excel)
========================= */
if ($export === 1) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="customers_export.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Customer Name','Mobile','Type','Status','Total Billed','Total Paid','Total Due','Last Purchase']);
  foreach ($rows as $r) {
    $last = $r['last_purchase'] ? date('Y-m-d', strtotime($r['last_purchase'])) : '';
    fputcsv($out, [
      $r['name'], $r['mobile'], $r['customer_type'], $r['status'],
      number_format((float)$r['total_billed'], 2, '.', ''),
      number_format((float)$r['total_paid'], 2, '.', ''),
      number_format((float)$r['total_due'], 2, '.', ''),
      $last
    ]);
  }
  fclose($out);
  exit;
}

$paymentsEnabled = ($HAS_INVOICES && $HAS_PAYMENTS && $HAS_ALLOC);
$warningMissingTables = (!$HAS_INVOICES || !$HAS_PAYMENTS || !$HAS_ALLOC);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Customers</title>
<style>
:root{
  --bg:#f6faf7; --card:#ffffff; --text:#0f172a; --muted:#64748b; --line:#e5e7eb;
  --brand:#16a34a; --brand2:#22c55e; --bad:#ef4444; --warn:#f59e0b;
  --shadow:0 10px 30px rgba(2,6,23,.08); --shadow2:0 6px 18px rgba(2,6,23,.10);
  --radius:18px;
}
*{box-sizing:border-box}
body{
  margin:0;
  font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial;
  color:var(--text);
  background:
    radial-gradient(1200px 700px at 12% -10%, rgba(34,197,94,.18), transparent 60%),
    radial-gradient(900px 500px at 90% 0%, rgba(22,163,74,.12), transparent 55%),
    var(--bg);
}
.wrap{max-width:1200px;margin:0 auto;padding:18px 16px 32px}
.topbar{
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:14px 14px;border:1px solid rgba(22,163,74,.18);
  background:linear-gradient(135deg, rgba(22,163,74,.10), rgba(34,197,94,.06));
  border-radius:20px; box-shadow:var(--shadow2);
  position:sticky;top:10px;z-index:30;
}
.brand{display:flex;align-items:center;gap:10px}
.logo{width:38px;height:38px;border-radius:14px;background:linear-gradient(135deg,var(--brand),var(--brand2));
  box-shadow:0 10px 24px rgba(22,163,74,.25);}
.h1{font-size:18px;font-weight:900;margin:0}
.sub{font-size:12px;color:var(--muted);margin-top:2px}
.top-stats{display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.pill{
  display:flex;align-items:center;gap:8px;
  padding:8px 10px;border-radius:999px;
  border:1px solid rgba(2,6,23,.10);
  background:rgba(255,255,255,.70);
  backdrop-filter: blur(8px);
  font-size:12px;
}
.dot{width:9px;height:9px;border-radius:99px;background:linear-gradient(135deg,var(--brand),var(--brand2));
  box-shadow:0 6px 14px rgba(22,163,74,.22);}
.dot.warn{background:linear-gradient(135deg,#f59e0b,#fbbf24); box-shadow:0 6px 14px rgba(245,158,11,.22);}
.grid{margin-top:16px;display:grid;grid-template-columns:420px minmax(0,1fr);gap:14px}
.card{background:var(--card);border:1px solid rgba(2,6,23,.08);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;isolation:isolate}
.card-h{padding:14px 14px 10px;border-bottom:1px solid rgba(2,6,23,.06);display:flex;align-items:center;justify-content:space-between;gap:10px}
.card-h h2{margin:0;font-size:14px}
.p{padding:14px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
label{font-size:12px;color:var(--muted)}
input,select,textarea{width:100%;border:1px solid rgba(2,6,23,.12);border-radius:14px;padding:10px 11px;font-size:13px;outline:none;background:#fff}
textarea{min-height:70px;resize:vertical}
.btn{border:none;cursor:pointer;border-radius:14px;padding:10px 12px;font-weight:900;font-size:13px}
.btn-primary{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;box-shadow:0 12px 24px rgba(22,163,74,.22)}
.btn-soft{background:rgba(22,163,74,.10);color:#0f172a;border:1px solid rgba(22,163,74,.20);text-decoration:none;display:inline-flex;align-items:center}
.btn-mini{padding:8px 10px;border-radius:12px;font-size:12px}
.btn-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.30);color:#111827}
.btn:active{transform:translateY(1px)}
.msg{margin:10px 0 0;padding:10px 12px;border-radius:14px;font-size:13px;border:1px solid rgba(2,6,23,.08)}
.msg.ok{background:rgba(22,163,74,.09);border-color:rgba(22,163,74,.25)}
.msg.err{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.25)}
.msg.warn{background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.25)}
.filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.filters input{max-width:240px}
.table-wrap{overflow:auto;max-height:calc(100vh - 220px);border-radius:16px}
.card table{width:100%;border-collapse:separate;border-spacing:0}
.card th,.card td{padding:11px 12px;border-bottom:1px solid rgba(2,6,23,.06);font-size:13px;white-space:nowrap;text-align:left}
.card th{position:sticky;top:0;z-index:2;background:linear-gradient(180deg,#ffffff,#fbfffc);font-size:12px;color:var(--muted)}
.badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900;border:1px solid rgba(2,6,23,.10);background:rgba(2,6,23,.03)}
.badge.due{background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.25)}
.badge.paid{background:rgba(22,163,74,.10);border-color:rgba(22,163,74,.22)}
.badge.inactive{background:rgba(148,163,184,.16);border-color:rgba(148,163,184,.30);color:#334155}
.link{text-decoration:none;font-weight:900;color:var(--brand)}
.small{font-size:12px;color:var(--muted)}
@media(max-width:980px){.grid{grid-template-columns:1fr}}
.modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.55);display:none;align-items:center;justify-content:center;z-index:999;padding:16px}
.modal{width:min(520px,100%);background:#fff;border-radius:18px;border:1px solid rgba(2,6,23,.10);box-shadow:0 30px 80px rgba(2,6,23,.25);overflow:hidden}
.modal-h{padding:14px 14px 10px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-bottom:1px solid rgba(2,6,23,.06)}
.xbtn{width:34px;height:34px;border-radius:12px;border:1px solid rgba(2,6,23,.10);background:rgba(2,6,23,.02);cursor:pointer;font-weight:900}
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <div class="brand">
      <div class="logo"></div>
      <div>
        <p class="h1">Customers</p>
        <div class="sub">Search • Edit • Due tracking • Export</div>
      </div>
    </div>

    <div class="top-stats">
      <div class="pill"><span class="dot"></span> <span class="small">Customers:</span> <b><?= (int)$summary_total_customers ?></b></div>
      <div class="pill"><span class="dot warn"></span> <span class="small">Due Customers:</span> <b><?= (int)$summary_due_customers ?></b></div>
      <div class="pill"><span class="dot warn"></span> <span class="small">Total Due:</span> <b>₹<?= money($summary_total_due) ?></b></div>

      <a class="btn btn-mini <?= $due_only===1 ? 'btn-warn' : 'btn-soft' ?>" href="<?= h($dueToggleUrl) ?>">
        <?= $due_only===1 ? 'Showing: Due Only' : 'Due Only' ?>
      </a>

      <a class="btn btn-soft btn-mini" href="<?= h($exportUrl) ?>">Export Excel</a>
      <a class="btn btn-soft btn-mini" href="<?= h($clearFiltersUrl) ?>">Clear</a>
    </div>
  </div>

  <?php if($ok): ?><div class="msg ok"><?=h($ok)?></div><?php endif; ?>
  <?php if($err): ?><div class="msg err"><?=h($err)?></div><?php endif; ?>

  <?php if($warningMissingTables): ?>
    <div class="msg warn" style="margin-top:10px">
      ⚠️ Billing/Due calculations and “Receive Payment” need these tables:
      <b>invoices</b>, <b>customer_payments</b>, <b>payment_allocations</b>.
      Right now totals may show as 0 and Receive button will be hidden.
    </div>
  <?php endif; ?>

  <div class="grid">
    <!-- LEFT: Add/Edit Customer -->
    <div class="card">
      <div class="card-h">
        <h2><?= $edit ? "Edit Customer" : "Add Customer" ?></h2>
        <?php if($edit): ?><a class="btn btn-soft btn-mini" href="customers.php">+ New</a><?php endif; ?>
      </div>
      <div class="p">
        <form method="post" autocomplete="off">
          <input type="hidden" name="customer_id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="field"><label>Customer Name *</label><input name="name" value="<?=h($edit['name'] ?? '')?>" required></div>
          <div class="row">
            <div class="field"><label>Mobile *</label><input name="mobile" value="<?=h($edit['mobile'] ?? '')?>" required></div>
            <div class="field">
              <label>Type</label>
              <?php $t = $edit['customer_type'] ?? 'retail'; ?>
              <select name="customer_type">
                <option value="retail" <?= $t==='retail'?'selected':'' ?>>Retail</option>
                <option value="wholesale" <?= $t==='wholesale'?'selected':'' ?>>Wholesale</option>
              </select>
            </div>
          </div>
          <div class="row">
            <div class="field"><label>Email</label><input name="email" value="<?=h($edit['email'] ?? '')?>"></div>
            <div class="field">
              <label>Status</label>
              <?php $s = $edit['status'] ?? 'active'; ?>
              <select name="status">
                <option value="active" <?= $s==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $s==='inactive'?'selected':'' ?>>Inactive</option>
              </select>
            </div>
          </div>
          <div class="field"><label>Address</label><input name="address" value="<?=h($edit['address'] ?? '')?>"></div>
          <div class="field"><label>Notes</label><textarea name="notes"><?=h($edit['notes'] ?? '')?></textarea></div>
          <button class="btn btn-primary" name="save_customer" value="1"><?= $edit ? "Save Changes" : "Add Customer" ?></button>
        </form>
      </div>
    </div>

    <!-- RIGHT: Customers List -->
    <div class="card">
      <div class="card-h">
        <h2>Customers List</h2>
        <form class="filters" method="get">
          <input name="q" value="<?=h($q)?>" placeholder="Search name/mobile">
          <select name="type">
            <option value="all" <?= $ctype==='all'?'selected':'' ?>>All Types</option>
            <option value="retail" <?= $ctype==='retail'?'selected':'' ?>>Retail</option>
            <option value="wholesale" <?= $ctype==='wholesale'?'selected':'' ?>>Wholesale</option>
          </select>
          <select name="status">
            <option value="all" <?= $cstatus==='all'?'selected':'' ?>>All Status</option>
            <option value="active" <?= $cstatus==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $cstatus==='inactive'?'selected':'' ?>>Inactive</option>
          </select>
          <select name="due_only">
            <option value="0" <?= $due_only===0?'selected':'' ?>>All</option>
            <option value="1" <?= $due_only===1?'selected':'' ?>>Due Only</option>
          </select>
          <button class="btn btn-soft btn-mini">Filter</button>
        </form>
      </div>

      <div class="p" style="padding-top:10px">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Customer</th>
                <th>Mobile</th>
                <th>Total Billed</th>
                <th>Total Paid</th>
                <th>Total Due</th>
                <th>Last Purchase</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if(!empty($rows)): ?>
              <?php foreach($rows as $r): ?>
                <?php
                  $d = (float)$r['total_due'];
                  $badge = $d > 0.009 ? 'due' : 'paid';
                  $statusBadge = ($r['status']==='inactive') ? 'inactive' : '';
                ?>
                <tr>
                  <td>
                    <div style="font-weight:900"><?=h($r['name'])?></div>
                    <div class="small"><span class="badge <?= $statusBadge ?>"><?=h($r['customer_type'])?> • <?=h($r['status'])?></span></div>
                  </td>
                  <td><?=h($r['mobile'])?></td>
                  <td>₹<?=money($r['total_billed'])?></td>
                  <td>₹<?=money($r['total_paid'])?></td>
                  <td><span class="badge <?= $badge ?>">₹<?=money($r['total_due'])?></span></td>
                  <td class="small"><?= $r['last_purchase'] ? date('d M Y', strtotime($r['last_purchase'])) : '-' ?></td>
                  <td style="display:flex;gap:8px;align-items:center">
                    <a class="link" href="customer-view.php?id=<?= (int)$r['id'] ?>">View</a>
                    <span class="small">•</span>
                    <a class="link" href="customers.php?edit=<?= (int)$r['id'] ?>">Edit</a>
                    <?php if($paymentsEnabled && $d > 0.009): ?>
                      <span class="small">•</span>
                      <button type="button" class="btn btn-soft btn-mini"
                        onclick="openPayModal(<?= (int)$r['id'] ?>,'<?=h($r['name'])?>','<?=h($r['mobile'])?>','<?=money($r['total_due'])?>')"
                      >Receive</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="7" class="small">No customers found.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="small" style="margin-top:10px">
          ✅ Core: customer list + due tracking + export + quick payment.
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Payment Modal -->
<div class="modal-backdrop" id="payBackdrop" onclick="closePayModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <div class="modal-h">
      <div>
        <b>Receive Payment</b><br>
        <span class="small" id="paySub"></span>
      </div>
      <button class="xbtn" type="button" onclick="closePayModal()">×</button>
    </div>
    <div class="p">
      <form method="post" autocomplete="off">
        <input type="hidden" name="quick_payment" value="1">
        <input type="hidden" name="pay_customer_id" id="pay_customer_id" value="0">

        <div class="field">
          <label>Payment Date (optional)</label>
          <input name="payment_date" placeholder="YYYY-MM-DD HH:MM:SS (blank = now)">
        </div>

        <div class="field">
          <label>Amount *</label>
          <input name="amount" id="pay_amount" type="number" step="0.01" required>
        </div>

        <div class="field">
          <label>Mode</label>
          <select name="mode">
            <option value="cash">Cash</option>
            <option value="upi">UPI</option>
            <option value="card">Card</option>
            <option value="bank">Bank</option>
            <option value="other">Other</option>
          </select>
        </div>

        <div class="field">
          <label>Note (optional)</label>
          <textarea name="note" placeholder="e.g., received by UPI"></textarea>
        </div>

        <button class="btn btn-primary" type="submit">Save Payment</button>
        <button class="btn btn-soft" type="button" onclick="closePayModal()" style="margin-left:8px">Cancel</button>

        <div class="small" style="margin-top:10px">
          Payment auto-adjusts to <b>oldest pending invoices first</b> (FIFO).
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const bd = document.getElementById('payBackdrop');
const cidEl = document.getElementById('pay_customer_id');
const subEl = document.getElementById('paySub');
const amtEl = document.getElementById('pay_amount');

function openPayModal(id, name, mobile, due){
  cidEl.value = id;
  subEl.textContent = name + " • " + mobile + " • Due ₹" + due;
  amtEl.value = "";
  bd.style.display = "flex";
  setTimeout(()=>amtEl.focus(), 50);
}
function closePayModal(){ bd.style.display = "none"; }
</script>
</body>
</html>