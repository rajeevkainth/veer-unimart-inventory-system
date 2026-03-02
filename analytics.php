<?php
// public_html/grocery/analytics.php
session_start();
require __DIR__ . "/config/db.php";

error_reporting(E_ALL & ~E_NOTICE);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$active = 'analytics';
$pageTitle = 'Analytics';

/* Helpers */
if(!function_exists('h')){
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function money($n){ return "₹" . number_format((float)$n, 2); }
function n0($v){ return is_numeric($v) ? (float)$v : 0.0; }

/* MariaDB-safe SHOW helpers */
function table_exists(mysqli $conn, string $table): bool{
  $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $esc = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '{$esc}'");
  return ($res && $res->num_rows > 0);
}

/* ===== Filters ===== */
$to   = trim($_GET['to'] ?? date('Y-m-d'));
$from = trim($_GET['from'] ?? date('Y-m-d', strtotime('-29 days')));

$view = trim($_GET['view'] ?? 'daily'); // daily | monthly
if(!in_array($view, ['daily','monthly'], true)) $view = 'daily';

$fromTS = strtotime($from) ?: strtotime('-29 days');
$toTS   = strtotime($to)   ?: time();
if($fromTS > $toTS){ $tmp = $fromTS; $fromTS = $toTS; $toTS = $tmp; }

$from = date('Y-m-d', $fromTS);
$to   = date('Y-m-d', $toTS);

/* ===== Build Labels (Daily/Monthly) ===== */
$labels = [];
$mapIndex = [];

if($view === 'monthly'){
  $start = date('Y-m-01', strtotime($from));
  $end   = date('Y-m-01', strtotime($to));
  $cur = strtotime($start);
  $endTs = strtotime($end);

  $i=0;
  while($cur <= $endTs){
    $k = date('Y-m', $cur);
    $labels[] = $k;
    $mapIndex[$k] = $i;
    $i++;
    $cur = strtotime('+1 month', $cur);
  }
}else{
  $cur = $fromTS; $i=0;
  while($cur <= $toTS){
    $d = date('Y-m-d', $cur);
    $labels[] = $d;
    $mapIndex[$d] = $i;
    $i++;
    $cur = strtotime('+1 day', $cur);
  }
}

/* ===== KPIs ===== */
$kpi = [
  'sales_total' => 0, 'sales_retail'=> 0, 'sales_whole'=> 0,
  'sales_paid'  => 0, 'sales_due'   => 0,
  'pur_total'   => 0, 'pur_paid'    => 0, 'pur_due'     => 0,
];

/* Sales totals */
$stmt = $conn->prepare("
  SELECT
    COALESCE(SUM(total_amount),0) AS total,
    COALESCE(SUM(CASE WHEN sale_type='retail' THEN total_amount ELSE 0 END),0) AS retail_total,
    COALESCE(SUM(CASE WHEN sale_type='wholesale' THEN total_amount ELSE 0 END),0) AS wholesale_total,
    COALESCE(SUM(paid_amount),0) AS paid_total,
    COALESCE(SUM(GREATEST(total_amount - COALESCE(paid_amount,0),0)),0) AS due_total
  FROM sales
  WHERE DATE(sale_date) BETWEEN ? AND ?
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$stmt->bind_result($kpi['sales_total'],$kpi['sales_retail'],$kpi['sales_whole'],$kpi['sales_paid'],$kpi['sales_due']);
$stmt->fetch();
$stmt->close();

/* Purchases totals */
$stmt = $conn->prepare("
  SELECT
    COALESCE(SUM(total_amount),0) AS total,
    COALESCE(SUM(paid_amount),0) AS paid_total,
    COALESCE(SUM(GREATEST(total_amount - COALESCE(paid_amount,0),0)),0) AS due_total
  FROM purchases
  WHERE DATE(COALESCE(purchase_date, created_at)) BETWEEN ? AND ?
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$stmt->bind_result($kpi['pur_total'],$kpi['pur_paid'],$kpi['pur_due']);
$stmt->fetch();
$stmt->close();

/* Payment mode split (Sales) */
$payLabels = [];
$payValues = [];
$q = $conn->prepare("
  SELECT COALESCE(payment_mode,'unknown') AS pm, COALESCE(SUM(paid_amount),0) AS amt
  FROM sales
  WHERE DATE(sale_date) BETWEEN ? AND ?
  GROUP BY pm
  ORDER BY amt DESC
");
$q->bind_param("ss", $from, $to);
$q->execute();
$r = $q->get_result();
if($r){
  while($row = $r->fetch_assoc()){
    $payLabels[] = (string)$row['pm'];
    $payValues[] = (float)$row['amt'];
  }
}
$q->close();

/* Trends */
$salesTrend = array_fill(0, count($labels), 0.0);
$purTrend   = array_fill(0, count($labels), 0.0);

/* Sales trend (daily/monthly) */
if($view === 'monthly'){
  $q = $conn->prepare("
    SELECT DATE_FORMAT(sale_date, '%Y-%m') AS k, COALESCE(SUM(total_amount),0) AS v
    FROM sales
    WHERE DATE(sale_date) BETWEEN ? AND ?
    GROUP BY k
    ORDER BY k ASC
  ");
}else{
  $q = $conn->prepare("
    SELECT DATE(sale_date) AS k, COALESCE(SUM(total_amount),0) AS v
    FROM sales
    WHERE DATE(sale_date) BETWEEN ? AND ?
    GROUP BY k
    ORDER BY k ASC
  ");
}
$q->bind_param("ss", $from, $to);
$q->execute();
$r = $q->get_result();
if($r){
  while($row = $r->fetch_assoc()){
    $k = (string)$row['k'];
    if(isset($mapIndex[$k])) $salesTrend[$mapIndex[$k]] = (float)$row['v'];
  }
}
$q->close();

/* Purchase trend (daily/monthly) */
if($view === 'monthly'){
  $q = $conn->prepare("
    SELECT DATE_FORMAT(COALESCE(purchase_date, created_at), '%Y-%m') AS k, COALESCE(SUM(total_amount),0) AS v
    FROM purchases
    WHERE DATE(COALESCE(purchase_date, created_at)) BETWEEN ? AND ?
    GROUP BY k
    ORDER BY k ASC
  ");
}else{
  $q = $conn->prepare("
    SELECT DATE(COALESCE(purchase_date, created_at)) AS k, COALESCE(SUM(total_amount),0) AS v
    FROM purchases
    WHERE DATE(COALESCE(purchase_date, created_at)) BETWEEN ? AND ?
    GROUP BY k
    ORDER BY k ASC
  ");
}
$q->bind_param("ss", $from, $to);
$q->execute();
$r = $q->get_result();
if($r){
  while($row = $r->fetch_assoc()){
    $k = (string)$row['k'];
    if(isset($mapIndex[$k])) $purTrend[$mapIndex[$k]] = (float)$row['v'];
  }
}
$q->close();

/* Donut: Retail vs Wholesale */
$rwLabels = ['Retail','Wholesale'];
$rwValues = [(float)$kpi['sales_retail'], (float)$kpi['sales_whole']];

/* Bar: Paid vs Due */
$paidDueLabels = ['Sales Paid','Sales Due','Purchase Paid','Purchase Due'];
$paidDueValues = [(float)$kpi['sales_paid'], (float)$kpi['sales_due'], (float)$kpi['pur_paid'], (float)$kpi['pur_due']];

/* Top products sold (optional) */
$topProdLabels = [];
$topProdValues = [];
$hasInvoices = table_exists($conn, 'invoices') && table_exists($conn, 'invoice_items');

if($hasInvoices){
  $q = $conn->prepare("
    SELECT p.name AS product_name, COALESCE(SUM(ii.qty),0) AS total_qty
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    JOIN products p ON p.id = ii.product_id
    WHERE DATE(i.invoice_date) BETWEEN ? AND ?
    GROUP BY p.id, p.name
    ORDER BY total_qty DESC
    LIMIT 10
  ");
  $q->bind_param("ss", $from, $to);
  $q->execute();
  $r = $q->get_result();
  if($r){
    while($row = $r->fetch_assoc()){
      $topProdLabels[] = (string)$row['product_name'];
      $topProdValues[] = (float)$row['total_qty'];
    }
  }
  $q->close();
}

/* Smart metrics */
$daysCount = max(1, count($labels));
$avgSales  = $kpi['sales_total'] / $daysCount;
$avgPur    = $kpi['pur_total'] / $daysCount;
$collect   = ($kpi['sales_total'] > 0) ? (100 * $kpi['sales_paid'] / $kpi['sales_total']) : 0;
$netFlow   = (float)$kpi['sales_total'] - (float)$kpi['pur_total']; // cashflow-ish, not true profit
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>UniMart International • Analytics</title>
<link rel="stylesheet" href="assets/css/app.css">

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
/* ===== Match your UniMart Website (Green/White Premium) ===== */
:root{
  --um-g: #1F7A3F;     /* main green */
  --um-g2:#14532D;     /* deep green */
  --um-bg:#f7fbf7;     /* soft green-white */
  --um-card:#ffffff;
  --um-line:#e6efe6;
  --um-text:#0f172a;
  --um-muted:#64748b;
}

.container{ max-width: 1220px; }

/* Header row */
.a-head{
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  margin-bottom: 14px;
}
.a-title{
  display:flex; align-items:center; gap:10px; flex-wrap:wrap;
}
.a-title h1{
  margin:0;
  font-size: 18px;
  font-weight: 1000;
  color: var(--um-g2);
}
.a-pill{
  font-size: 12px;
  font-weight: 900;
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid var(--um-line);
  background: #fff;
  color: var(--um-text);
}
.a-filter{
  display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;
  padding: 10px;
  border:1px solid var(--um-line);
  border-radius: 16px;
  background: rgba(255,255,255,.85);
  backdrop-filter: blur(8px);
}
.a-filter label{ font-size:12px; color:var(--um-muted); font-weight:800; }
.a-filter input{
  height: 36px;
  border:1px solid var(--um-line);
  border-radius: 12px;
  padding: 0 10px;
  background:#fff;
  outline:none;
}

/* Premium toggle pills */
.toggle{
  display:flex; gap:8px;
}
.tbtn{
  height: 36px;
  padding: 0 14px;
  border-radius: 999px;
  border: 1px solid var(--um-line);
  background:#fff;
  font-weight: 1000;
  color: var(--um-text);
  cursor:pointer;
}
.tbtn.active{
  border-color: transparent;
  color:#fff;
  background: linear-gradient(135deg, var(--um-g), var(--um-g2));
}

/* KPI row */
.kpi-row{
  display:grid;
  grid-template-columns: repeat(4, minmax(0,1fr));
  gap: 12px;
  margin-bottom: 12px;
}
.kpi{
  border-radius: 18px;
  border: 1px solid var(--um-line);
  background: var(--um-card);
  box-shadow: 0 12px 30px rgba(0,0,0,.06);
  padding: 14px;
}
.kpi .lab{ font-size: 12px; color:var(--um-muted); font-weight:900; }
.kpi .val{ font-size: 22px; font-weight: 1000; color:var(--um-text); margin-top: 6px; }
.kpi .sub{ margin-top: 6px; font-size: 12px; color:var(--um-muted); }

/* Chart cards */
.grid2{
  display:grid;
  grid-template-columns: 1.25fr .75fr;
  gap: 12px;
}
.grid3{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-top: 12px;
}
.card{
  border-radius: 18px;
  border:1px solid var(--um-line);
  background: var(--um-card);
  box-shadow: 0 12px 30px rgba(0,0,0,.06);
  overflow:hidden;
}
.card-head{
  padding: 12px 14px;
  border-bottom:1px solid var(--um-line);
  background: linear-gradient(180deg, #f6fbf6, #ffffff);
  display:flex; align-items:center; justify-content:space-between; gap:10px;
}
.card-head .t{ font-weight: 1000; color:var(--um-text); }
.card-head .hint{ font-size:12px; color:var(--um-muted); font-weight:800; }
.card-body{ padding: 12px 14px; }
.card-body canvas{ width:100% !important; height: 280px !important; }

.note{
  border: 1px dashed #cfe5cf;
  border-radius: 16px;
  padding: 12px;
  background: #fbfffb;
  color: var(--um-muted);
  font-size: 12px;
  line-height: 1.55;
}

@media(max-width:1100px){
  .kpi-row{ grid-template-columns: repeat(2, minmax(0,1fr)); }
  .grid2{ grid-template-columns: 1fr; }
  .grid3{ grid-template-columns: 1fr; }
}
</style>
</head>

<body>
<div class="app">
  <?php include __DIR__ . "/partials/sidebar.php"; ?>

  <div class="main">
    <?php include __DIR__ . "/partials/topbar.php"; ?>

    <div class="content">
      <div class="container">

        <div class="a-head">
          <div class="a-title">
            <h1>Analytics</h1>
            <span class="a-pill"><?php echo h(date('d M Y')); ?></span>
            <span class="a-pill">Range: <?php echo h($from); ?> → <?php echo h($to); ?></span>
            <span class="a-pill">View: <?php echo $view === 'monthly' ? 'Monthly' : 'Daily'; ?></span>
          </div>

          <form class="a-filter" method="get" action="analytics.php">
            <input type="hidden" name="view" value="<?php echo h($view); ?>">
            <div>
              <label>From</label><br>
              <input type="date" name="from" value="<?php echo h($from); ?>">
            </div>
            <div>
              <label>To</label><br>
              <input type="date" name="to" value="<?php echo h($to); ?>">
            </div>

            <div class="toggle">
              <button class="tbtn <?php echo $view==='daily'?'active':''; ?>" type="submit" name="view" value="daily">Daily</button>
              <button class="tbtn <?php echo $view==='monthly'?'active':''; ?>" type="submit" name="view" value="monthly">Monthly</button>
            </div>

            <div>
              <button class="tbtn active" type="submit" style="border-radius:14px">Apply</button>
            </div>
          </form>
        </div>

        <!-- KPIs -->
        <div class="kpi-row">
          <div class="kpi">
            <div class="lab">Total Sales</div>
            <div class="val"><?php echo money($kpi['sales_total']); ?></div>
            <div class="sub">Retail <?php echo money($kpi['sales_retail']); ?> • Wholesale <?php echo money($kpi['sales_whole']); ?></div>
          </div>
          <div class="kpi">
            <div class="lab">Total Purchases</div>
            <div class="val"><?php echo money($kpi['pur_total']); ?></div>
            <div class="sub">Paid <?php echo money($kpi['pur_paid']); ?> • Due <?php echo money($kpi['pur_due']); ?></div>
          </div>
          <div class="kpi">
            <div class="lab">Sales Paid / Due</div>
            <div class="val"><?php echo money($kpi['sales_paid']); ?></div>
            <div class="sub">Due <?php echo money($kpi['sales_due']); ?></div>
          </div>
          <div class="kpi">
            <div class="lab">Net Flow (Sales - Purchases)</div>
            <div class="val"><?php echo money($netFlow); ?></div>
            <div class="sub">Useful cashflow view (not true profit).</div>
          </div>
        </div>

        <!-- Charts Row 1 -->
        <div class="grid2">
          <div class="card">
            <div class="card-head">
              <div class="t">Sales vs Purchases Trend</div>
              <div class="hint"><?php echo $view==='monthly' ? 'Monthly totals' : 'Daily totals'; ?></div>
            </div>
            <div class="card-body">
              <canvas id="trendChart"></canvas>
            </div>
          </div>

          <div class="card">
            <div class="card-head">
              <div class="t">Retail vs Wholesale</div>
              <div class="hint">Sales split</div>
            </div>
            <div class="card-body">
              <canvas id="rwChart"></canvas>
            </div>
          </div>
        </div>

        <!-- Charts Row 2 -->
        <div class="grid3">
          <div class="card">
            <div class="card-head">
              <div class="t">Payment Modes</div>
              <div class="hint">Cash / UPI / Bank etc.</div>
            </div>
            <div class="card-body">
              <canvas id="payChart"></canvas>
            </div>
          </div>

          <div class="card">
            <div class="card-head">
              <div class="t">Paid vs Due</div>
              <div class="hint">Sales & Purchases</div>
            </div>
            <div class="card-body">
              <canvas id="paidDueChart"></canvas>
            </div>
          </div>
        </div>

        <!-- Charts Row 3 -->
        <div class="grid3">
          <div class="card">
            <div class="card-head">
              <div class="t">Top Products Sold</div>
              <div class="hint"><?php echo $hasInvoices ? "From invoices" : "No invoice_items data"; ?></div>
            </div>
            <div class="card-body">
              <canvas id="topProductsChart"></canvas>
              <?php if(!$hasInvoices): ?>
                <div class="note" style="margin-top:10px">
                  This chart needs <b>invoices</b> and <b>invoice_items</b> tables (Orders/Billing module).
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="card">
            <div class="card-head">
              <div class="t">Smart Insights</div>
              <div class="hint">Quick summary</div>
            </div>
            <div class="card-body" style="height:auto">
              <div class="note">
                <b>Avg Sales / Period:</b> <?php echo money($avgSales); ?><br>
                <b>Avg Purchases / Period:</b> <?php echo money($avgPur); ?><br>
                <b>Collection Rate:</b> <?php echo number_format($collect, 1); ?>%<br><br>
                <b>Tip:</b> Next we can add true <b>Profit Analytics</b> by linking sold items with purchase cost (COGS).
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>
/* PHP -> JS data */
const LABELS      = <?php echo json_encode($labels, JSON_UNESCAPED_UNICODE); ?>;
const SALES_T     = <?php echo json_encode($salesTrend, JSON_UNESCAPED_UNICODE); ?>;
const PUR_T       = <?php echo json_encode($purTrend, JSON_UNESCAPED_UNICODE); ?>;

const RW_LABELS   = <?php echo json_encode($rwLabels, JSON_UNESCAPED_UNICODE); ?>;
const RW_VALUES   = <?php echo json_encode($rwValues, JSON_UNESCAPED_UNICODE); ?>;

const PAY_LABELS  = <?php echo json_encode($payLabels, JSON_UNESCAPED_UNICODE); ?>;
const PAY_VALUES  = <?php echo json_encode($payValues, JSON_UNESCAPED_UNICODE); ?>;

const PD_LABELS   = <?php echo json_encode($paidDueLabels, JSON_UNESCAPED_UNICODE); ?>;
const PD_VALUES   = <?php echo json_encode($paidDueValues, JSON_UNESCAPED_UNICODE); ?>;

const TP_LABELS   = <?php echo json_encode($topProdLabels, JSON_UNESCAPED_UNICODE); ?>;
const TP_VALUES   = <?php echo json_encode($topProdValues, JSON_UNESCAPED_UNICODE); ?>;

/* Use UniMart matching chart colors */
const UM = {
  green:  '#1F7A3F',
  green2: '#14532D',
  mint:   '#bfe7c7',
  blue:   '#1D4ED8',
  orange: '#b45309',
  red:    '#ef4444',
  gray:   '#64748b'
};

/* 1) Line: Sales vs Purchases */
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: LABELS,
    datasets: [
      { label: 'Sales', data: SALES_T, borderColor: UM.green, backgroundColor: UM.mint, tension: 0.35, fill: false },
      { label: 'Purchases', data: PUR_T, borderColor: UM.orange, backgroundColor: '#ffd9bf', tension: 0.35, fill: false }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { position: 'top' },
      tooltip: { callbacks: {
        label: (ctx) => `${ctx.dataset.label}: ₹${Number(ctx.raw||0).toFixed(2)}`
      }}
    },
    scales: {
      y: { ticks: { callback: (v)=> '₹' + v } }
    }
  }
});

/* 2) Donut: Retail vs Wholesale */
new Chart(document.getElementById('rwChart'), {
  type: 'doughnut',
  data: {
    labels: RW_LABELS,
    datasets: [{ data: RW_VALUES, backgroundColor: [UM.green, UM.blue] }]
  },
  options: {
    responsive:true,
    maintainAspectRatio:false,
    plugins:{ legend:{ position:'bottom' } }
  }
});

/* 3) Pie: Payment Modes */
const pLabels = (PAY_LABELS && PAY_LABELS.length) ? PAY_LABELS : ['No payments'];
const pValues = (PAY_VALUES && PAY_VALUES.length) ? PAY_VALUES : [1];

new Chart(document.getElementById('payChart'), {
  type: 'pie',
  data: {
    labels: pLabels,
    datasets: [{
      data: pValues,
      backgroundColor: [UM.green, UM.blue, UM.orange, UM.red, UM.mint, '#94a3b8']
    }]
  },
  options: {
    responsive:true,
    maintainAspectRatio:false,
    plugins:{
      legend:{ position:'bottom' },
      tooltip:{ callbacks:{
        label:(ctx)=> `${ctx.label}: ₹${Number(ctx.raw||0).toFixed(2)}`
      }}
    }
  }
});

/* 4) Bar: Paid vs Due */
new Chart(document.getElementById('paidDueChart'), {
  type: 'bar',
  data: {
    labels: PD_LABELS,
    datasets: [{
      label: 'Amount',
      data: PD_VALUES,
      backgroundColor: [UM.green, UM.orange, UM.green2, UM.red]
    }]
  },
  options: {
    responsive:true,
    maintainAspectRatio:false,
    plugins:{
      legend:{ display:false },
      tooltip:{ callbacks:{ label:(ctx)=> `₹${Number(ctx.raw||0).toFixed(2)}` } }
    },
    scales:{
      y:{ ticks:{ callback:(v)=> '₹' + v } }
    }
  }
});

/* 5) Bar: Top Products */
const tpLabels = (TP_LABELS && TP_LABELS.length) ? TP_LABELS : ['No data'];
const tpValues = (TP_VALUES && TP_VALUES.length) ? TP_VALUES : [0];

new Chart(document.getElementById('topProductsChart'), {
  type: 'bar',
  data: {
    labels: tpLabels,
    datasets: [{ label: 'Qty Sold', data: tpValues, backgroundColor: UM.green }]
  },
  options: {
    responsive:true,
    maintainAspectRatio:false,
    plugins:{ legend:{ display:false } },
    scales:{ y:{ beginAtZero:true } }
  }
});
</script>
</body>
</html>