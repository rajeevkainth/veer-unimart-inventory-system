<?php
// public_html/grocery/stock.php
session_start();
require __DIR__ . "/config/db.php";

error_reporting(E_ALL & ~E_NOTICE);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$active = 'stock';
$pageTitle = "Stock";

/* helpers */
if(!function_exists('h')){
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function money($n){ return "₹" . number_format((float)$n, 2); } // not used much here

$q  = trim($_GET['q'] ?? '');
$flt= trim($_GET['flt'] ?? 'all'); // all | low | out
if(!in_array($flt, ['all','low','out'], true)) $flt = 'all';

/* build query */
$sql = "
  SELECT
    p.id,
    p.name,
    COALESCE(p.min_stock, 0) AS min_stock,
    COALESCE(p.is_active,1) AS is_active,
    COALESCE(SUM(sm.qty_in),0)  AS in_qty,
    COALESCE(SUM(sm.qty_out),0) AS out_qty,
    (COALESCE(SUM(sm.qty_in),0) - COALESCE(SUM(sm.qty_out),0)) AS current_stock
  FROM products p
  LEFT JOIN stock_movements sm ON sm.product_id = p.id
  WHERE COALESCE(p.is_active,1)=1
";

$params = [];
$types  = "";

if($q !== ''){
  $sql .= " AND p.name LIKE ? ";
  $params[] = "%".$q."%";
  $types .= "s";
}

$sql .= " GROUP BY p.id ";

if($flt === 'low'){
  $sql .= " HAVING current_stock <= min_stock AND current_stock > 0 ";
} elseif($flt === 'out'){
  $sql .= " HAVING current_stock <= 0 ";
}

$sql .= " ORDER BY p.name ASC LIMIT 5000 ";

$stmt = $conn->prepare($sql);
if($types !== ''){
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$lowCount = 0;
$outCount = 0;
$totalProducts = 0;

while($r = $res->fetch_assoc()){
  $rows[] = $r;
  $totalProducts++;
  if((float)$r['current_stock'] <= 0) $outCount++;
  else if((float)$r['current_stock'] <= (float)$r['min_stock']) $lowCount++;
}
$stmt->close();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($pageTitle); ?> • UniMart</title>
<link rel="stylesheet" href="assets/css/app.css">
<style>
  .wrap{max-width:1200px;margin:0 auto;padding:18px 16px 30px}
  .topline{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
  .h1{font-size:20px;font-weight:1000;margin:0}
  .pill{display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;border:1px solid var(--line);background:#fff}
  .stats{display:flex;gap:10px;flex-wrap:wrap}
  .stat{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);padding:12px 14px;min-width:160px}
  .stat .k{font-size:12px;color:var(--muted)}
  .stat .v{font-size:18px;font-weight:1000;margin-top:4px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);overflow:hidden;margin-top:14px}
  .card-h{padding:12px 14px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
  .card-b{padding:14px}
  .btn{border:0;border-radius:12px;padding:10px 14px;font-weight:900;cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:8px}
  .btn-primary{background:linear-gradient(135deg,var(--g),var(--g2));color:#fff;box-shadow:0 10px 18px rgba(31,122,63,.22)}
  .btn-ghost{background:#fff;border:1px solid var(--line);color:var(--text)}
  .mini{font-size:12px;padding:8px 10px;border-radius:10px}
  .filters{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  input,select{border:1px solid var(--line);border-radius:12px;padding:10px 12px;outline:none;background:#fff;font-size:14px}
  .table-wrap{overflow:auto;max-height:70vh}
  table{width:100%;border-collapse:separate;border-spacing:0}
  th,td{padding:10px 10px;border-bottom:1px solid var(--line);white-space:nowrap;vertical-align:middle;font-size:14px}
  th{background:#f6fff6;font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;position:sticky;top:0;z-index:1}
  .right{text-align:right}
  .badge{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:900;padding:6px 10px;border-radius:999px;border:1px solid var(--line);background:#fff}
  .b-ok{background:#f0fdf4;border-color:#bbf7d0}
  .b-low{background:#fffbeb;border-color:#fde68a}
  .b-out{background:#fff1f2;border-color:#fecaca}
  .muted{color:var(--muted);font-size:12px}
</style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . "/partials/sidebar.php"; ?>
  <div class="main">
    <?php include __DIR__ . "/partials/topbar.php"; ?>

    <div class="content">
      <div class="wrap">

        <div class="topline">
          <div>
            <h1 class="h1">📦 Stock</h1>
            <div class="muted">Live stock from Stock Movements (IN − OUT). Active products only.</div>
          </div>

          <div class="stats">
            <div class="stat">
              <div class="k">Products</div>
              <div class="v"><?php echo (int)$totalProducts; ?></div>
            </div>
            <div class="stat">
              <div class="k">Low Stock</div>
              <div class="v"><?php echo (int)$lowCount; ?></div>
            </div>
            <div class="stat">
              <div class="k">Out of Stock</div>
              <div class="v"><?php echo (int)$outCount; ?></div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-h">
            <form class="filters" method="get" action="stock.php">
              <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search product…">
              <select name="flt">
                <option value="all" <?php echo $flt==='all'?'selected':''; ?>>All</option>
                <option value="low" <?php echo $flt==='low'?'selected':''; ?>>Low Stock</option>
                <option value="out" <?php echo $flt==='out'?'selected':''; ?>>Out of Stock</option>
              </select>
              <button class="btn btn-primary mini" type="submit">Search</button>
              <a class="btn btn-ghost mini" href="stock.php">Reset</a>
            </form>

            <div class="filters">
              <a class="btn btn-ghost mini" href="stock-ledger.php">Stock Ledger</a>
              <a class="btn btn-ghost mini" href="stock-adjustment.php">Stock Adjustment</a>
            </div>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Product</th>
                  <th class="right">Min</th>
                  <th class="right">IN</th>
                  <th class="right">OUT</th>
                  <th class="right">Current</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if(!$rows): ?>
                  <tr><td colspan="6" class="muted" style="padding:16px">No products found.</td></tr>
                <?php else: foreach($rows as $r):
                  $cur = (float)$r['current_stock'];
                  $min = (float)$r['min_stock'];

                  if($cur <= 0){
                    $badge = '<span class="badge b-out">⛔ Out</span>';
                  } elseif($cur <= $min){
                    $badge = '<span class="badge b-low">⚠ Low</span>';
                  } else {
                    $badge = '<span class="badge b-ok">✅ OK</span>';
                  }
                ?>
                  <tr>
                    <td><b><?php echo h($r['name']); ?></b></td>
                    <td class="right"><?php echo number_format($min, 2); ?></td>
                    <td class="right"><?php echo number_format((float)$r['in_qty'], 2); ?></td>
                    <td class="right"><?php echo number_format((float)$r['out_qty'], 2); ?></td>
                    <td class="right"><b><?php echo number_format($cur, 2); ?></b></td>
                    <td><?php echo $badge; ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <div class="card-b">
            <div class="muted">
              Tip: To correct wrong stock, use <b>Stock Adjustment</b> (it creates adjustment movements, not direct edits).
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>