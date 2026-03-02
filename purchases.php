<?php
// public_html/grocery/purchases.php
session_start();
require __DIR__ . "/config/db.php";

/* =========================
   SAFE MODE (no 500)
========================= */
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($debug) {
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);
} else {
  ini_set('display_errors', 0);
  ini_set('display_startup_errors', 0);
  error_reporting(E_ALL & ~E_NOTICE);
}

/* -----------------------
   Page Config
------------------------ */
$active = 'purchases';
$pageTitle = "Purchases";

/* -----------------------
   Helpers
------------------------ */
if(!function_exists('h')){
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function money_eu($n){
  $x = is_numeric($n) ? (float)$n : 0.0;
  return "€" . number_format($x, 2);
}
function get($k, $d=''){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }

function table_has_col(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $rs = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
  if(!$rs) return false;
  $ok = $rs->num_rows > 0;
  $rs->free();
  return $ok;
}

/* -----------------------
   Flash messages
------------------------ */
$okMsg = $_SESSION['okMsg'] ?? '';
$errMsg = $_SESSION['errMsg'] ?? '';
unset($_SESSION['okMsg'], $_SESSION['errMsg']);

/* -----------------------
   Filters
------------------------ */
$q        = get('q','');
$status   = get('status','all'); // all|open|paid|partial|cancelled
$supplier = (int)get('supplier', 0);
$from     = get('from','');
$to       = get('to','');

if($from === '') $from = date('Y-m-01');
if($to === '')   $to   = date('Y-m-t');

/* =========================
   Detect DB columns safely
========================= */
$HAS_SUP_ACTIVE    = table_has_col($conn, 'suppliers', 'is_active');
$HAS_SUP_NAME      = table_has_col($conn, 'suppliers', 'supplier_name') ? 'supplier_name' : (table_has_col($conn,'suppliers','name') ? 'name' : '');
$HAS_PUR_SUP_ID    = table_has_col($conn, 'purchases', 'supplier_id');
$HAS_PUR_DATE      = table_has_col($conn, 'purchases', 'purchase_date') ? 'purchase_date' : (table_has_col($conn,'purchases','created_at') ? 'created_at' : '');
$HAS_PUR_NO        = table_has_col($conn, 'purchases', 'purchase_no') ? 'purchase_no' : (table_has_col($conn,'purchases','invoice_no') ? 'invoice_no' : '');
$HAS_PUR_TOTAL     = table_has_col($conn, 'purchases', 'total_amount') ? 'total_amount' : (table_has_col($conn,'purchases','grand_total') ? 'grand_total' : '');
$HAS_PUR_PAID      = table_has_col($conn, 'purchases', 'paid_amount') ? 'paid_amount' : (table_has_col($conn,'purchases','paid') ? 'paid' : '');
$HAS_PUR_BAL       = table_has_col($conn, 'purchases', 'balance_amount');
$HAS_PUR_STATUS    = table_has_col($conn, 'purchases', 'status');

$schemaError = '';
if($HAS_SUP_NAME === '')  $schemaError .= "Suppliers table missing supplier_name/name. ";
if($HAS_PUR_DATE === '')  $schemaError .= "Purchases table missing purchase_date/created_at. ";
if($HAS_PUR_NO === '')    $schemaError .= "Purchases table missing purchase_no/invoice_no. ";
if($HAS_PUR_TOTAL === '') $schemaError .= "Purchases table missing total_amount/grand_total. ";

// Paid can be absent; we will treat as 0
// Supplier_id can be absent; we will not join then

/* -----------------------
   Suppliers dropdown
------------------------ */
$suppliers = [];
try{
  if($HAS_SUP_NAME !== ''){
    $sqlSup = "SELECT id, `$HAS_SUP_NAME` AS supplier_name FROM suppliers";
    if($HAS_SUP_ACTIVE) $sqlSup .= " WHERE is_active=1";
    $sqlSup .= " ORDER BY `$HAS_SUP_NAME` ASC";
    $res = $conn->query($sqlSup);
    while($r = $res->fetch_assoc()){
      $suppliers[] = ['id'=>(int)$r['id'], 'supplier_name'=>$r['supplier_name']];
    }
  }
}catch(Throwable $e){
  // ignore dropdown failure; page can still load
}

/* =========================
   Build list query (safe)
========================= */
$list = null;
$listError = '';

if($schemaError !== ''){
  $listError = $schemaError;
} else {
  // Base select columns
  $dateCol = $HAS_PUR_DATE;
  $noCol   = $HAS_PUR_NO;
  $totCol  = $HAS_PUR_TOTAL;
  $paidCol = $HAS_PUR_PAID ?: ''; // may be empty

  // compute paid/balance safely
  $paidExpr = $paidCol ? "COALESCE(p.`$paidCol`,0)" : "0";
  $balExpr  = $HAS_PUR_BAL ? "COALESCE(p.balance_amount, (COALESCE(p.`$totCol`,0) - $paidExpr))"
                           : "(COALESCE(p.`$totCol`,0) - $paidExpr)";

  // status expression
  if($HAS_PUR_STATUS){
    $statusExpr = "p.status";
  } else {
    // derive status from totals (open/partial/paid)
    $statusExpr = "CASE
      WHEN COALESCE(p.`$totCol`,0) <= 0 THEN 'open'
      WHEN $paidExpr >= COALESCE(p.`$totCol`,0) THEN 'paid'
      WHEN $paidExpr > 0 THEN 'partial'
      ELSE 'open'
    END";
  }

  $joinSup = "";
  $supNameSelect = "'' AS supplier_name";
  $where = [];
  $params = [];
  $types = "";

  // Date filter
  $where[] = "DATE(p.`$dateCol`) BETWEEN ? AND ?";
  $params[] = $from;
  $params[] = $to;
  $types .= "ss";

  // If supplier_id exists and suppliers table has a name, we join
  if($HAS_PUR_SUP_ID && $HAS_SUP_NAME !== ''){
    $joinSup = "LEFT JOIN suppliers s ON s.id = p.supplier_id";
    $supNameSelect = "COALESCE(s.`$HAS_SUP_NAME`,'') AS supplier_name";

    if($q !== ''){
      $where[] = "(p.`$noCol` LIKE ? OR s.`$HAS_SUP_NAME` LIKE ?)";
      $like = "%".$q."%";
      $params[] = $like; $params[] = $like;
      $types .= "ss";
    }
    if($supplier > 0){
      $where[] = "p.supplier_id = ?";
      $params[] = $supplier;
      $types .= "i";
    }
  } else {
    // No supplier join available: search only by purchase_no/invoice_no
    if($q !== ''){
      $where[] = "(p.`$noCol` LIKE ?)";
      $like = "%".$q."%";
      $params[] = $like;
      $types .= "s";
    }
    // supplier filter not possible without supplier_id
  }

  if($status !== 'all'){
    if($HAS_PUR_STATUS){
      $where[] = "p.status = ?";
      $params[] = $status;
      $types .= "s";
    } else {
      // filter on derived status
      $where[] = "($statusExpr) = ?";
      $params[] = $status;
      $types .= "s";
    }
  }

  $sqlWhere = "WHERE " . implode(" AND ", $where);

  $sql = "
    SELECT
      p.id,
      p.`$noCol` AS purchase_no,
      p.`$dateCol` AS purchase_date,
      COALESCE(p.`$totCol`,0) AS total_amount,
      $paidExpr AS paid_amount,
      $balExpr AS balance_amount,
      ($statusExpr) AS status,
      $supNameSelect
    FROM purchases p
    $joinSup
    $sqlWhere
    ORDER BY p.`$dateCol` DESC
    LIMIT 300
  ";

  try{
    $stmt = $conn->prepare($sql);
    if($types !== ''){
      $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();
  } catch(Throwable $e){
    $listError = $debug ? ($e->getMessage()."\n\nSQL:\n".$sql) : "Database query failed.";
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($pageTitle); ?> • UniMart</title>
<link rel="stylesheet" href="assets/css/app.css">
<style>
  :root{
    --g:#1F7A3F; --g2:#14532D;
    --bg:#f7faf7; --card:#fff; --line:#e5e7eb;
    --text:#0f172a; --muted:#64748b;
    --shadow:0 10px 30px rgba(2,6,23,.08);
    --shadow2:0 6px 18px rgba(2,6,23,.10);
    --radius:16px;
  }
  *{box-sizing:border-box}
  body{
    margin:0;
    font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial;
    color:var(--text);
    background:linear-gradient(180deg, #f2fbf2, var(--bg));
  }
  a{color:inherit; text-decoration:none}
  .layout{display:flex; min-height:100vh}
  .main{flex:1; min-width:0}
  .wrap{max-width:1200px; margin:0 auto; padding:18px 16px 26px}
  .topbar{
    position:sticky; top:0; z-index:999;
    background:rgba(247,250,247,.9);
    backdrop-filter: blur(8px);
    border-bottom:1px solid var(--line);
  }
  .topbar .wrap{padding:14px 16px}
  .head{display:flex; align-items:center; justify-content:space-between; gap:12px;}
  .title{display:flex; flex-direction:column; gap:2px;}
  .title h1{margin:0;font-size:20px;letter-spacing:.2px;}
  .title .sub{color:var(--muted);font-size:13px;}
  .actionsTop{display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
  .btn{
    border:0;border-radius:12px;padding:10px 14px;
    font-weight:900;cursor:pointer;transition:.15s;
    display:inline-flex; align-items:center; gap:8px;
  }
  .btn-primary{
    background:linear-gradient(135deg, var(--g), var(--g2));
    color:#fff; box-shadow: 0 10px 18px rgba(31,122,63,.22);
  }
  .btn-ghost{background:#fff;border:1px solid var(--line);color:var(--text);}
  .btn-primary:hover{transform: translateY(-1px)}
  .pill{
    display:inline-flex; align-items:center; gap:8px;
    padding:8px 10px;border:1px solid var(--line);
    background:#fff;border-radius:999px;
    box-shadow:0 6px 16px rgba(2,6,23,.06);
    font-size:13px;color:var(--muted);
  }
  .dot{width:10px;height:10px;border-radius:999px;background:var(--g)}
  .card{
    margin-top:14px;background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    overflow:hidden; isolation:isolate;
  }
  .card-h{
    padding:14px 14px 12px;
    border-bottom:1px solid var(--line);
    display:flex; align-items:center; justify-content:space-between; gap:10px;
  }
  .card-h .h{font-weight:900;display:flex; align-items:center; gap:10px;}
  .badge{
    display:inline-flex; align-items:center;
    padding:6px 10px;border-radius:999px;
    font-size:12px;border:1px solid var(--line);
    background:#f8fff8;color:var(--g2);
    font-weight:900;
  }
  .filters{
    display:grid;
    grid-template-columns: 1.2fr 180px 180px 180px 1fr auto;
    gap:10px;
    padding:12px 14px;
    border-bottom:1px solid var(--line);
    background:#fbfffb;
  }
  @media (max-width: 980px){
    .filters{grid-template-columns:1fr 1fr; }
    .filters .full{grid-column:1/-1}
  }
  label{display:block; font-size:12px; color:var(--muted); margin:0 0 6px}
  input, select{
    width:100%;
    border:1px solid var(--line);
    border-radius:12px;
    padding:10px 12px;
    outline:none;
    background:#fff;
    font-size:14px;
  }
  input:focus, select:focus{
    border-color: rgba(31,122,63,.45);
    box-shadow: 0 0 0 4px rgba(31,122,63,.10);
  }
  .table-wrap{overflow:auto; max-height:72vh}
  table{width:100%; border-collapse:separate; border-spacing:0}
  th, td{
    padding:12px 12px;
    border-bottom:1px solid var(--line);
    white-space:nowrap;
    vertical-align:middle;
    font-size:14px;
  }
  th{
    position:sticky; top:0;
    background:#f6fff6;
    z-index:1;
    font-size:12px;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.08em;
  }
  .muted{color:var(--muted); font-size:12px}
  .status{
    display:inline-flex; align-items:center; gap:8px;
    padding:6px 10px; border-radius:999px;
    font-size:12px; font-weight:900;
    border:1px solid var(--line);
  }
  .st-open{background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe}
  .st-partial{background:#fff7ed; color:#9a3412; border-color:#fed7aa}
  .st-paid{background:#ecfdf5; color:#065f46; border-color:#a7f3d0}
  .st-cancelled{background:#fff1f2; color:#9f1239; border-color:#fecdd3}
  .link{font-weight:900; color:var(--g2)}
  .sum{
    padding:12px 14px;border-top:1px solid var(--line);
    background:#fff;display:flex; gap:16px; flex-wrap:wrap;
    color:var(--muted);font-size:13px;
  }
  .sum b{color:var(--text)}
  .errbox{
    margin-top:14px;
    padding:14px 14px;
    border-radius:16px;
    border:1px solid #fecaca;
    background:#fff1f2;
    box-shadow:0 8px 18px rgba(2,6,23,.08);
    white-space:pre-wrap;
  }
</style>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . "/partials/sidebar.php"; ?>

  <div class="main">
    <div class="topbar">
      <div class="wrap">
        <div class="head">
          <div class="title">
            <h1>Purchases</h1>
            <div class="sub">Bills & stock-in history • Track supplier due with accuracy</div>
          </div>
          <div class="actionsTop">
            <a class="btn btn-primary" href="/grocery/purchase-create.php">➕ New Purchase</a>
            <div class="pill"><span class="dot"></span> UniMart Inventory</div>
          </div>
        </div>

        <?php if($okMsg): ?>
          <div style="margin-top:12px;padding:12px 14px;border-radius:14px;border:1px solid #bbf7d0;background:#f0fdf4;box-shadow:0 6px 14px rgba(2,6,23,.06)">
            <?php echo h($okMsg); ?>
          </div>
        <?php endif; ?>
        <?php if($errMsg): ?>
          <div style="margin-top:12px;padding:12px 14px;border-radius:14px;border:1px solid #fecaca;background:#fff1f2;box-shadow:0 6px 14px rgba(2,6,23,.06)">
            <?php echo h($errMsg); ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="wrap">
      <div class="card">
        <div class="card-h">
          <div class="h">📋 Purchase Bills</div>
          <span class="badge">Last 300</span>
        </div>

        <form class="filters" method="get" action="purchases.php">
          <div class="full">
            <label>Search (Purchase No / Supplier)</label>
            <input name="q" value="<?php echo h($q); ?>" placeholder="e.g. PB-00021 or Fresh Veg Traders">
          </div>

          <div>
            <label>Status</label>
            <select name="status">
              <option value="all" <?php echo $status==='all'?'selected':''; ?>>All</option>
              <option value="open" <?php echo $status==='open'?'selected':''; ?>>Open</option>
              <option value="partial" <?php echo $status==='partial'?'selected':''; ?>>Partial</option>
              <option value="paid" <?php echo $status==='paid'?'selected':''; ?>>Paid</option>
              <option value="cancelled" <?php echo $status==='cancelled'?'selected':''; ?>>Cancelled</option>
            </select>
          </div>

          <div>
            <label>From</label>
            <input type="date" name="from" value="<?php echo h($from); ?>">
          </div>

          <div>
            <label>To</label>
            <input type="date" name="to" value="<?php echo h($to); ?>">
          </div>

          <div class="full">
            <label>Supplier</label>
            <select name="supplier">
              <option value="0">All suppliers</option>
              <?php foreach($suppliers as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>" <?php echo ($supplier==(int)$s['id'])?'selected':''; ?>>
                  <?php echo h($s['supplier_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="full" style="display:flex; gap:10px; align-items:end; justify-content:flex-end">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-ghost" href="purchases.php">Reset</a>
          </div>
        </form>

        <?php if($listError): ?>
          <div class="errbox">
            <b>Could not load purchases:</b>
            <?php echo "\n".h($listError); ?>
            <?php if(!$debug): ?>
              <div style="margin-top:10px;color:#64748b;font-size:12px">
                Tip: open <b>purchases.php?debug=1</b> to see detailed info (admin only).
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Purchase No</th>
                <th>Date</th>
                <th>Supplier</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Balance</th>
                <th>Status</th>
                <th style="text-align:right">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php $sumTotal=0; $sumPaid=0; $sumBal=0; ?>

              <?php if(!$list): ?>
                <tr><td colspan="8" style="padding:18px;color:var(--muted)">Waiting for data…</td></tr>

              <?php elseif($list->num_rows === 0): ?>
                <tr><td colspan="8" style="padding:18px;color:var(--muted)">No purchases found in selected filters.</td></tr>

              <?php else: ?>
                <?php while($r = $list->fetch_assoc()): ?>
                  <?php
                    $sumTotal += (float)$r['total_amount'];
                    $sumPaid  += (float)$r['paid_amount'];
                    $sumBal   += (float)$r['balance_amount'];

                    $st = strtolower(trim((string)$r['status']));
                    $stClass = 'st-open';
                    if($st === 'paid') $stClass = 'st-paid';
                    elseif($st === 'partial') $stClass = 'st-partial';
                    elseif($st === 'cancelled') $stClass = 'st-cancelled';

                    $dt = strtotime($r['purchase_date'] ?? '');
                    $dtShow = $dt ? date('d M Y, h:i A', $dt) : '-';

                    $supShow = trim((string)($r['supplier_name'] ?? ''));
                    if($supShow === '') $supShow = '—';
                  ?>
                  <tr>
                    <td style="font-weight:900"><?php echo h($r['purchase_no']); ?></td>
                    <td class="muted"><?php echo h($dtShow); ?></td>
                    <td>
                      <div style="font-weight:900"><?php echo h($supShow); ?></div>
                      <div class="muted">ID #<?php echo (int)$r['id']; ?></div>
                    </td>
                    <td style="font-weight:900"><?php echo money_eu($r['total_amount']); ?></td>
                    <td><?php echo money_eu($r['paid_amount']); ?></td>
                    <td style="font-weight:900"><?php echo money_eu($r['balance_amount']); ?></td>
                    <td><span class="status <?php echo h($stClass); ?>">● <?php echo h(ucfirst($st)); ?></span></td>
                    <td style="text-align:right">
                      <a class="link" href="/grocery/purchase-view.php?id=<?php echo (int)$r['id']; ?>">View</a>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="sum">
          <div>Total: <b><?php echo money_eu($sumTotal); ?></b></div>
          <div>Paid: <b><?php echo money_eu($sumPaid); ?></b></div>
          <div>Balance: <b><?php echo money_eu($sumBal); ?></b></div>
          <div>Range: <b><?php echo h($from); ?></b> → <b><?php echo h($to); ?></b></div>
        </div>
      </div>
    </div>

  </div>
</div>
</body>
</html>