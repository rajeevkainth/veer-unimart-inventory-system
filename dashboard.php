<?php
session_start();
require __DIR__ . "/config/db.php";

$active = 'dashboard';
$pageTitle = 'Dashboard';

$todayStart = date('Y-m-d 00:00:00');
$todayEnd   = date('Y-m-d 23:59:59');

/* Today Retail Sales */
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_type='retail' AND sale_date BETWEEN ? AND ?");
$stmt->bind_param("ss", $todayStart, $todayEnd);
$stmt->execute();
$stmt->bind_result($todayRetail);
$stmt->fetch();
$stmt->close();

/* Today Wholesale Sales */
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_type='wholesale' AND sale_date BETWEEN ? AND ?");
$stmt->bind_param("ss", $todayStart, $todayEnd);
$stmt->execute();
$stmt->bind_result($todayWholesale);
$stmt->fetch();
$stmt->close();

/* Payments Split */
function sumByMode($conn, $mode, $start, $end){
  $stmt = $conn->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM sales WHERE payment_mode=? AND sale_date BETWEEN ? AND ?");
  $stmt->bind_param("sss", $mode, $start, $end);
  $stmt->execute();
  $stmt->bind_result($v);
  $stmt->fetch();
  $stmt->close();
  return (float)$v;
}
$cash = sumByMode($conn, 'cash', $todayStart, $todayEnd);
$upi  = sumByMode($conn, 'upi',  $todayStart, $todayEnd);
$bank = sumByMode($conn, 'bank', $todayStart, $todayEnd);

/* Helpers */
function money($n){ return "₹" . number_format((float)$n, 2); }
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* =========================
   Low Stock (Top 10) - FIXED
   - Treat NULL is_active as active
   - Treat NULL min_stock as 0
   - Stable ordering
   - GROUP BY safe for strict SQL modes
========================= */
$lowStock = [];
$q = $conn->query("
  SELECT
    p.id,
    p.name,
    COALESCE(p.min_stock,0) AS min_stock,
    COALESCE(SUM(COALESCE(sm.qty_in,0) - COALESCE(sm.qty_out,0)),0) AS current_stock
  FROM products p
  LEFT JOIN stock_movements sm ON sm.product_id = p.id
  WHERE COALESCE(p.is_active,1)=1
  GROUP BY p.id, p.name, p.min_stock
  HAVING current_stock <= COALESCE(p.min_stock,0)
  ORDER BY current_stock ASC, p.name ASC
  LIMIT 10
");
if($q){
  while($r = $q->fetch_assoc()){ $lowStock[] = $r; }
  $q->free();
}

/* Expiry (Top 10 within 30 days) */
$expiry = [];
$q = $conn->query("
  SELECT p.name, sb.batch_no, sb.expiry_date,
         DATEDIFF(sb.expiry_date, CURDATE()) AS days_left,
         sb.qty_available
  FROM stock_batches sb
  JOIN products p ON p.id = sb.product_id
  WHERE sb.expiry_date IS NOT NULL
    AND sb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
  ORDER BY sb.expiry_date ASC
  LIMIT 10
");
if($q){
  while($r = $q->fetch_assoc()){ $expiry[] = $r; }
  $q->free();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Unimart International • Dashboard</title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="app">
  <?php include __DIR__ . "/partials/sidebar.php"; ?>

  <div class="main">
    <?php include __DIR__ . "/partials/topbar.php"; ?>

    <div class="content">
      <div class="container">

        <div class="grid">
          <div class="card kpi">
            <div class="label">Today Retail Sales</div>
            <div class="val"><?php echo money($todayRetail); ?></div>
          </div>

          <div class="card kpi">
            <div class="label">Today Wholesale Sales</div>
            <div class="val"><?php echo money($todayWholesale); ?></div>
          </div>

          <div class="card kpi">
            <div class="label">Cash / UPI / Bank</div>
            <div class="val" style="font-size:18px">
              <?php echo money($cash); ?> <span class="muted">•</span>
              <?php echo money($upi); ?> <span class="muted">•</span>
              <?php echo money($bank); ?>
            </div>
          </div>

          <div class="card section">
            <h2>Low Stock Alerts</h2>
            <?php if(empty($lowStock)): ?>
              <div class="muted">No low stock items ✅</div>
            <?php else: ?>
            <table>
              <thead><tr><th>Product</th><th>Stock</th><th>Min</th></tr></thead>
              <tbody>
              <?php foreach($lowStock as $r): ?>
                <tr>
                  <td><?php echo h($r['name']); ?></td>
                  <td><b><?php echo (float)$r['current_stock']; ?></b></td>
                  <td class="muted"><?php echo (float)$r['min_stock']; ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </div>

          <div class="card section">
            <h2>Expiry in Next 30 Days</h2>
            <?php if(empty($expiry)): ?>
              <div class="muted">No expiry alerts ✅</div>
            <?php else: ?>
            <table>
              <thead><tr><th>Product</th><th>Batch</th><th>Expiry</th><th>Days</th><th>Qty</th></tr></thead>
              <tbody>
              <?php foreach($expiry as $r): ?>
                <tr>
                  <td><?php echo h($r['name']); ?></td>
                  <td class="muted"><?php echo h($r['batch_no'] ?? '-'); ?></td>
                  <td><b><?php echo h($r['expiry_date']); ?></b></td>
                  <td><?php echo (int)$r['days_left']; ?></td>
                  <td><?php echo (float)$r['qty_available']; ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </div>

        </div>

        <div style="margin-top:14px" class="muted">
          Tip: Next we’ll build <b style="color:var(--g2)">Products</b> so dashboard starts showing real data.
        </div>

      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>