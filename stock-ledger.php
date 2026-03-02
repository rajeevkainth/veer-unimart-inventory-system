<?php
// stock-ledger.php (FINAL FIXED FOR YOUR MARIADB)
// ✅ No prepared placeholders in SHOW queries (fixes SL99 near '?')

ob_start();

ini_set('display_errors', 1);          // keep ON for now, turn OFF after stable
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function SL_FAIL($code, $msg=''){
  http_response_code(500);
  echo "<h3 style='font-family:Arial'>Stock Ledger Error <b>$code</b></h3>";
  if($msg!=='') echo "<div style='font-family:Arial;color:#444'>".htmlspecialchars($msg)."</div>";
  exit;
}

if(!file_exists(__DIR__ . '/config/db.php')){
  SL_FAIL('SL01', 'Missing file: grocery/config/db.php');
}
require_once __DIR__ . '/config/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
  SL_FAIL('SL02', 'DB $conn not found / not mysqli. Check db.php');
}
if ($conn->connect_errno) {
  SL_FAIL('SL03', 'DB connect error: '.$conn->connect_error);
}

if(!function_exists('h')){
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/* =========================
   MariaDB-safe SHOW helpers (NO '?')
========================= */
function table_exists(mysqli $conn, string $table): bool{
  $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $esc = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '{$esc}'");
  if(!$res) return false;
  $ok = ($res->num_rows > 0);
  $res->free();
  return $ok;
}

function table_has_col(mysqli $conn, string $table, string $col): bool{
  static $cache = [];
  $k = $table.'.'.$col;
  if(isset($cache[$k])) return $cache[$k];

  $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/', '', $col);

  if(!table_exists($conn, $table)){
    return $cache[$k] = false;
  }

  $escCol = $conn->real_escape_string($col);
  $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$escCol}'");
  if(!$res) return $cache[$k] = false;

  $has = ($res->num_rows > 0);
  $res->free();
  return $cache[$k] = $has;
}

function safe_prepare(mysqli $conn, string $sql){
  $stmt = $conn->prepare($sql);
  return $stmt ?: null;
}

/* =========================
   Inputs
========================= */
$from = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : date('Y-m-01');
$to   = isset($_GET['to'])   && $_GET['to'] !== ''   ? $_GET['to']   : date('Y-m-t');
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$q = trim((string)($_GET['q'] ?? ''));
$export = (isset($_GET['export']) && $_GET['export'] === 'csv');

if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-t');

/* =========================
   Table checks
========================= */
if(!table_exists($conn, 'products')){
  SL_FAIL('SL05', 'Missing table: products');
}
$hasMoves = table_exists($conn, 'stock_movements');

/* =========================
   Products columns
========================= */
$P_NAME = table_has_col($conn,'products','name') ? 'name' : (table_has_col($conn,'products','product_name') ? 'product_name' : 'name');
$P_SKU  = table_has_col($conn,'products','sku') ? 'sku' : (table_has_col($conn,'products','barcode') ? 'barcode' : (table_has_col($conn,'products','code') ? 'code' : ''));

/* =========================
   Products dropdown list
========================= */
$products = [];
if($q !== ''){
  $like = "%".$q."%";
  if($P_SKU !== ''){
    $stmt = safe_prepare($conn, "SELECT id, `$P_NAME` AS pname, `$P_SKU` AS psku
                                FROM products
                                WHERE `$P_NAME` LIKE ? OR `$P_SKU` LIKE ?
                                ORDER BY `$P_NAME` ASC
                                LIMIT 250");
    if(!$stmt) SL_FAIL('SL07', 'Prepare products search failed: '.$conn->error);

    $stmt->bind_param("ss", $like, $like);
    if(!$stmt->execute()) SL_FAIL('SL07', 'Execute products search failed: '.$stmt->error);

    $stmt->bind_result($pid,$pname,$psku);
    while($stmt->fetch()){
      $products[] = ['id'=>$pid,'pname'=>$pname,'psku'=>$psku];
    }
    $stmt->close();
  } else {
    $stmt = safe_prepare($conn, "SELECT id, `$P_NAME` AS pname
                                FROM products
                                WHERE `$P_NAME` LIKE ?
                                ORDER BY `$P_NAME` ASC
                                LIMIT 250");
    if(!$stmt) SL_FAIL('SL07', 'Prepare products search failed: '.$conn->error);

    $stmt->bind_param("s", $like);
    if(!$stmt->execute()) SL_FAIL('SL07', 'Execute products search failed: '.$stmt->error);

    $stmt->bind_result($pid,$pname);
    while($stmt->fetch()){
      $products[] = ['id'=>$pid,'pname'=>$pname,'psku'=>''];
    }
    $stmt->close();
  }
} else {
  $sql = ($P_SKU !== '')
    ? "SELECT id, `$P_NAME` AS pname, `$P_SKU` AS psku FROM products ORDER BY `$P_NAME` ASC LIMIT 300"
    : "SELECT id, `$P_NAME` AS pname, '' AS psku FROM products ORDER BY `$P_NAME` ASC LIMIT 300";

  $res = $conn->query($sql);
  if(!$res) SL_FAIL('SL07', 'Products list query failed: '.$conn->error);
  while($row = $res->fetch_assoc()) $products[] = $row;
  $res->free();
}

/* =========================
   Ledger
========================= */
$opening=0.0; $sumIn=0.0; $sumOut=0.0; $net=0.0;
$rows=[];

if($product_id > 0 && $hasMoves){

  // Detect movement columns
  $dateCol = table_has_col($conn,'stock_movements','created_at') ? 'created_at' : (table_has_col($conn,'stock_movements','date') ? 'date' : 'created_at');
  $hasQty  = table_has_col($conn,'stock_movements','qty');
  $hasIn   = table_has_col($conn,'stock_movements','qty_in');
  $hasOut  = table_has_col($conn,'stock_movements','qty_out');
  $typeCol = table_has_col($conn,'stock_movements','movement_type') ? 'movement_type' : (table_has_col($conn,'stock_movements','type') ? 'type' : '');

  // Build delta
  if($hasIn || $hasOut){
    $delta = "(" . ($hasIn?"COALESCE(qty_in,0)":"0") . " - " . ($hasOut?"COALESCE(qty_out,0)":"0") . ")";
  } else if($hasQty && $typeCol !== ''){
    $delta = "CASE
      WHEN LOWER(TRIM(`$typeCol`)) IN ('in','stock_in','purchase','add') THEN COALESCE(qty,0)
      WHEN LOWER(TRIM(`$typeCol`)) IN ('out','stock_out','sale','remove') THEN -COALESCE(qty,0)
      ELSE COALESCE(qty,0)
    END";
  } else if($hasQty){
    $delta = "COALESCE(qty,0)"; // signed qty
  } else {
    SL_FAIL('SL10', 'stock_movements has no qty/qty_in/qty_out columns');
  }

  $fromDT = $from." 00:00:00";
  $toDT   = $to." 23:59:59";

  // Opening
  $stmt = safe_prepare($conn, "SELECT SUM($delta) AS opq
                               FROM stock_movements
                               WHERE product_id=? AND `$dateCol` < ?");
  if(!$stmt) SL_FAIL('SL11', 'Prepare opening failed: '.$conn->error);

  $stmt->bind_param("is", $product_id, $fromDT);
  if(!$stmt->execute()) SL_FAIL('SL11', 'Execute opening failed: '.$stmt->error);

  $stmt->bind_result($opq);
  $stmt->fetch();
  $opening = (float)($opq ?? 0);
  $stmt->close();

  // Rows
  $stmt = safe_prepare($conn, "SELECT id, `$dateCol` AS mdate, $delta AS delta
                               FROM stock_movements
                               WHERE product_id=? AND `$dateCol` BETWEEN ? AND ?
                               ORDER BY `$dateCol` ASC, id ASC");
  if(!$stmt) SL_FAIL('SL12', 'Prepare rows failed: '.$conn->error);

  $stmt->bind_param("iss", $product_id, $fromDT, $toDT);
  if(!$stmt->execute()) SL_FAIL('SL12', 'Execute rows failed: '.$stmt->error);

  $stmt->bind_result($mid, $mdate, $d);
  while($stmt->fetch()){
    $d = (float)$d;
    if($d>=0) $sumIn += $d; else $sumOut += abs($d);
    $net += $d;
    $rows[] = ['mdate'=>$mdate, 'delta'=>$d];
  }
  $stmt->close();
}

$closing = ($product_id>0) ? ($opening + $net) : 0.0;

/* =========================
   Export CSV
========================= */
if($export){
  if($product_id<=0) SL_FAIL('SL20','Select product before export');

  $filename = "stock_ledger_{$product_id}_{$from}_to_{$to}.csv";
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['From','To','Opening','In','Out','Closing']);
  fputcsv($out, [$from,$to,$opening,$sumIn,$sumOut,$closing]);
  fputcsv($out, []);
  fputcsv($out, ['Date','In','Out','Balance']);

  $bal = $opening;
  foreach($rows as $r){
    $d = (float)$r['delta'];
    $in = $d>0 ? $d : 0;
    $outq = $d<0 ? abs($d) : 0;
    $bal += $d;
    fputcsv($out, [$r['mdate'], $in, $outq, $bal]);
  }
  fclose($out);
  exit;
}

ob_end_flush();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stock Ledger</title>
<style>
body{font-family:Arial;margin:24px;background:#f3fbf5}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px;max-width:1100px;margin:0 auto}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
label{font-size:12px;color:#64748b;display:block;margin-bottom:6px}
select,input{padding:10px;border:1px solid #e5e7eb;border-radius:12px}
button,a.btn{padding:10px 14px;border-radius:12px;border:none;background:#16a34a;color:#fff;font-weight:800;text-decoration:none;cursor:pointer}
a.ghost{background:#fff;color:#0f172a;border:1px solid #e5e7eb}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px}
.kpi{border:1px solid #dcfce7;background:#f7fff9;border-radius:14px;padding:12px}
.kpi .t{font-size:12px;color:#64748b}
.kpi .v{font-size:18px;font-weight:900;margin-top:6px}
@media(max-width:900px){.kpis{grid-template-columns:1fr 1fr}}
@media(max-width:520px){.kpis{grid-template-columns:1fr}}
table{width:100%;border-collapse:collapse;margin-top:14px}
th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left}
th{background:#f7fff9;color:#64748b;font-size:12px}
.badge{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:800;font-size:12px}
.in{background:#dcfce7;color:#166534}
.out{background:#fee2e2;color:#991b1b}
</style>
</head>
<body>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Stock Ledger</h2>
    <a class="btn ghost" href="dashboard.php">Back</a>
  </div>

  <form method="get" style="margin-top:12px">
    <div class="row">
      <div>
        <label>Product</label>
        <select name="product_id">
          <option value="0">-- Select --</option>
          <?php foreach($products as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= ($product_id==(int)$p['id']?'selected':'') ?>>
              <?= h($p['pname']) ?><?= ($p['psku']!==''?' • '.h($p['psku']):'') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>From</label>
        <input type="date" name="from" value="<?= h($from) ?>">
      </div>
      <div>
        <label>To</label>
        <input type="date" name="to" value="<?= h($to) ?>">
      </div>
      <div>
        <label>Search</label>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Name / SKU">
      </div>
      <div>
        <button type="submit">Apply</button>
        <?php if($product_id>0): ?>
          <a class="btn" href="?product_id=<?= (int)$product_id ?>&from=<?= h($from) ?>&to=<?= h($to) ?>&export=csv">Export CSV</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if(!$hasMoves): ?>
    <p style="margin-top:12px;color:#7c2d12;background:#fff7ed;padding:10px;border-radius:12px;border:1px solid #fed7aa">
      <b>Notice:</b> Table <code>stock_movements</code> not found.
    </p>
  <?php endif; ?>

  <div class="kpis">
    <div class="kpi"><div class="t">Opening</div><div class="v"><?= number_format($opening,2) ?></div></div>
    <div class="kpi"><div class="t">Total In</div><div class="v"><?= number_format($sumIn,2) ?></div></div>
    <div class="kpi"><div class="t">Total Out</div><div class="v"><?= number_format($sumOut,2) ?></div></div>
    <div class="kpi"><div class="t">Closing</div><div class="v"><?= number_format($closing,2) ?></div></div>
  </div>

  <table>
    <thead>
      <tr><th>Date</th><th>In</th><th>Out</th><th>Balance</th></tr>
    </thead>
    <tbody>
      <?php if($product_id<=0): ?>
        <tr><td colspan="4">Select product</td></tr>
      <?php elseif(empty($rows)): ?>
        <tr><td colspan="4">No movements found</td></tr>
      <?php else: ?>
        <?php $bal=$opening; foreach($rows as $r):
          $d=(float)$r['delta'];
          $in = $d>0 ? $d : 0;
          $out= $d<0 ? abs($d) : 0;
          $bal += $d;
        ?>
        <tr>
          <td><?= h($r['mdate']) ?></td>
          <td><?= $in>0 ? '<span class="badge in">'.number_format($in,2).'</span>' : '-' ?></td>
          <td><?= $out>0 ? '<span class="badge out">'.number_format($out,2).'</span>' : '-' ?></td>
          <td><b><?= number_format($bal,2) ?></b></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>