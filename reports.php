<?php
// public_html/grocery/reports.php
session_start();
require __DIR__ . "/config/db.php";

error_reporting(E_ALL & ~E_NOTICE);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$active = 'reports';
$pageTitle = "Reports";

/* helpers */
if(!function_exists('h')){
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function money($n){ return "₹" . number_format((float)$n, 2); }

$from = trim($_GET['from'] ?? date('Y-m-01'));
$to   = trim($_GET['to']   ?? date('Y-m-t'));

if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-t');

$fromDT = $from . " 00:00:00";
$toDT   = $to   . " 23:59:59";

/* --------------------------
   Quick summary cards
-------------------------- */
function sum_sales($conn, $type, $fromDT, $toDT){
  $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_type=? AND sale_date BETWEEN ? AND ?");
  $stmt->bind_param("sss", $type, $fromDT, $toDT);
  $stmt->execute();
  $stmt->bind_result($sum);
  $stmt->fetch();
  $stmt->close();
  return (float)$sum;
}
function sum_paid($conn, $type, $fromDT, $toDT){
  $stmt = $conn->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM sales WHERE sale_type=? AND sale_date BETWEEN ? AND ?");
  $stmt->bind_param("sss", $type, $fromDT, $toDT);
  $stmt->execute();
  $stmt->bind_result($sum);
  $stmt->fetch();
  $stmt->close();
  return (float)$sum;
}
function sum_purchases($conn, $fromDT, $toDT){
  // purchases.total_amount exists in your purchases module
  $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE purchase_date BETWEEN ? AND ?");
  $stmt->bind_param("ss", $fromDT, $toDT);
  $stmt->execute();
  $stmt->bind_result($sum);
  $stmt->fetch();
  $stmt->close();
  return (float)$sum;
}

$retailTotal   = sum_sales($conn, 'retail',   $fromDT, $toDT);
$wholesaleTotal= sum_sales($conn, 'wholesale',$fromDT, $toDT);
$retailPaid    = sum_paid($conn,  'retail',   $fromDT, $toDT);
$wholesalePaid = sum_paid($conn,  'wholesale',$fromDT, $toDT);

$totalSales    = $retailTotal + $wholesaleTotal;
$totalPaid     = $retailPaid + $wholesalePaid;
$totalDue      = max(0, $totalSales - $totalPaid);

$totalPurchases= sum_purchases($conn, $fromDT, $toDT);

/* --------------------------
   Payment mode split
-------------------------- */
$paySplit = [];
$stmt = $conn->prepare("
  SELECT payment_mode, COALESCE(SUM(paid_amount),0) AS amt
  FROM sales
  WHERE sale_date BETWEEN ? AND ?
  GROUP BY payment_mode
  ORDER BY amt DESC
");
$stmt->bind_param("ss", $fromDT, $toDT);
$stmt->execute();
$rs = $stmt->get_result();
while($r = $rs->fetch_assoc()){
  $pm = strtolower(trim((string)$r['payment_mode']));
  if($pm === '') $pm = 'unknown';
  $paySplit[] = ['mode'=>$pm, 'amt'=>(float)$r['amt']];
}
$stmt->close();

/* --------------------------
   Top selling products
-------------------------- */
$topItems = [];
$stmt = $conn->prepare("
  SELECT p.name,
         COALESCE(SUM(si.qty),0) AS qty_sold,
         COALESCE(SUM(si.line_total),0) AS amount
  FROM sale_items si
  JOIN sales s ON s.id = si.sale_id
  LEFT JOIN products p ON p.id = si.product_id
  WHERE s.sale_date BETWEEN ? AND ?
  GROUP BY si.product_id
  ORDER BY amount DESC
  LIMIT 15
");
$stmt->bind_param("ss", $fromDT, $toDT);
$stmt->execute();
$rs = $stmt->get_result();
while($r = $rs->fetch_assoc()){
  $topItems[] = $r;
}
$stmt->close();

/* --------------------------
   Recent invoices
-------------------------- */
$recent = [];
$stmt = $conn->prepare("
  SELECT id, sale_type, sale_date, total_amount, paid_amount, payment_mode, note
  FROM sales
  WHERE sale_date BETWEEN ? AND ?
  ORDER BY sale_date DESC
  LIMIT 20
");
$stmt->bind_param("ss", $fromDT, $toDT);
$stmt->execute();
$rs = $stmt->get_result();
while($r = $rs->fetch_assoc()){
  $recent[] = $r;
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
  .topline{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap}
  .h1{font-size:20px;font-weight:1000;margin:0}
  .muted{color:var(--muted);font-size:12px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);overflow:hidden}
  .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:14px}
  @media(max-width:1050px){ .grid{grid-template-columns:repeat(2,1fr);} }
  @media(max-width:560px){ .grid{grid-template-columns:1fr;} }
  .stat{padding:14px}
  .stat .k{font-size:12px;color:var(--muted)}
  .stat .v{font-size:20px;font-weight:1000;margin-top:6px}
  .stat .s{margin-top:4px;font-size:12px;color:var(--muted)}
  .filters{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  input{border:1px solid var(--line);border-radius:12px;padding:10px 12px;outline:none;background:#fff;font-size:14px}
  .btn{border:0;border-radius:12px;padding:10px 14px;font-weight:900;cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:8px}
  .btn-primary{background:linear-gradient(135deg,var(--g),var(--g2));color:#fff;box-shadow:0 10px 18px rgba(31,122,63,.22)}
  .btn-ghost{background:#fff;border:1px solid var(--line);color:var(--text)}
  .mini{font-size:12px;padding:8px 10px;border-radius:10px}
  .two{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}
  @media(max-width:980px){ .two{grid-template-columns:1fr} }
  .card-h{padding:12px 14px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
  .card-b{padding:14px}
  .table-wrap{overflow:auto;max-height:65vh}
  table{width:100%;border-collapse:separate;border-spacing:0}
  th,td{padding:10px 10px;border-bottom:1px solid var(--line);white-space:nowrap;vertical-align:middle;font-size:14px}
  th{background:#f6fff6;font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;position:sticky;top:0;z-index:1}
  .right{text-align:right}
  .badge{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:900;padding:6px 10px;border-radius:999px;border:1px solid var(--line);background:#fff}
  .b-ret{background:#f0fdf4;border-color:#bbf7d0}
  .b-wh{background:#eff6ff;border-color:#bfdbfe}
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
            <h1 class="h1">📊 Reports</h1>
            <div class="muted">Sales, payments, purchases summary for selected date range.</div>
          </div>

          <form class="filters" method="get" action="reports.php">
            <div>
              <div class="muted" style="margin-bottom:6px">From</div>
              <input type="date" name="from" value="<?php echo h($from); ?>">
            </div>
            <div>
              <div class="muted" style="margin-bottom:6px">To</div>
              <input type="date" name="to" value="<?php echo h($to); ?>">
            </div>
            <button class="btn btn-primary mini" type="submit">Apply</button>
            <a class="btn btn-ghost mini" href="reports.php">This Month</a>
          </form>
        </div>

        <!-- summary -->
        <div class="grid">
          <div class="card stat">
            <div class="k">Total Sales</div>
            <div class="v"><?php echo money($totalSales); ?></div>
            <div class="s">Retail + Wholesale</div>
          </div>
          <div class="card stat">
            <div class="k">Total Paid</div>
            <div class="v"><?php echo money($totalPaid); ?></div>
            <div class="s">Cash/UPI/Bank</div>
          </div>
          <div class="card stat">
            <div class="k">Total Due</div>
            <div class="v"><?php echo money($totalDue); ?></div>
            <div class="s">Sales − Paid</div>
          </div>
          <div class="card stat">
            <div class="k">Total Purchases</div>
            <div class="v"><?php echo money($totalPurchases); ?></div>
            <div class="s">Stock In (Bills)</div>
          </div>
        </div>

        <div class="two">
          <!-- payment split -->
          <div class="card">
            <div class="card-h">
              <div style="font-weight:1000">💳 Payments Split</div>
              <div class="muted">Based on paid_amount</div>
            </div>
            <div class="card-b">
              <?php if(!$paySplit): ?>
                <div class="muted">No payments in this range.</div>
              <?php else: ?>
                <div class="table-wrap" style="max-height:320px">
                  <table>
                    <thead>
                      <tr>
                        <th>Mode</th>
                        <th class="right">Amount</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach($paySplit as $p): ?>
                        <tr>
                          <td><b><?php echo h(strtoupper($p['mode'])); ?></b></td>
                          <td class="right"><?php echo money($p['amt']); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- top products -->
          <div class="card">
            <div class="card-h">
              <div style="font-weight:1000">🔥 Top Selling Products</div>
              <div class="muted">By sales amount</div>
            </div>
            <div class="card-b">
              <?php if(!$topItems): ?>
                <div class="muted">No items sold in this range.</div>
              <?php else: ?>
                <div class="table-wrap" style="max-height:320px">
                  <table>
                    <thead>
                      <tr>
                        <th>Product</th>
                        <th class="right">Qty</th>
                        <th class="right">Amount</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach($topItems as $t): ?>
                        <tr>
                          <td><b><?php echo h($t['name'] ?? 'Unknown'); ?></b></td>
                          <td class="right"><?php echo number_format((float)$t['qty_sold'], 2); ?></td>
                          <td class="right"><?php echo money((float)$t['amount']); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- recent invoices -->
        <div class="card" style="margin-top:12px">
          <div class="card-h">
            <div style="font-weight:1000">🧾 Recent Invoices</div>
            <div class="muted">Latest 20 in selected range</div>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Type</th>
                  <th>Date</th>
                  <th class="right">Total</th>
                  <th class="right">Paid</th>
                  <th>Mode</th>
                  <th>Note</th>
                </tr>
              </thead>
              <tbody>
                <?php if(!$recent): ?>
                  <tr><td colspan="7" class="muted" style="padding:16px">No invoices in this range.</td></tr>
                <?php else: foreach($recent as $r):
                  $isRetail = ($r['sale_type'] ?? '') === 'retail';
                  $badge = $isRetail
                    ? '<span class="badge b-ret">Retail</span>'
                    : '<span class="badge b-wh">Wholesale</span>';
                ?>
                  <tr>
                    <td><b>#<?php echo (int)$r['id']; ?></b></td>
                    <td><?php echo $badge; ?></td>
                    <td><?php echo h($r['sale_date']); ?></td>
                    <td class="right"><?php echo money((float)$r['total_amount']); ?></td>
                    <td class="right"><?php echo money((float)$r['paid_amount']); ?></td>
                    <td><?php echo h(strtoupper((string)$r['payment_mode'])); ?></td>
                    <td><?php echo h((string)$r['note']); ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <div class="card-b">
            <div class="muted">
              Next: we can add CSV export buttons + profit report (sales − purchase costs) using purchase_items.unit_cost.
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