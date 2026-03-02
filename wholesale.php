<?php
// public_html/grocery/wholesale.php
session_start();
require __DIR__ . "/config/db.php";

error_reporting(E_ALL & ~E_NOTICE);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$active = 'wholesale';
$pageTitle = "Wholesale";

/* Helpers */
if(!function_exists('h')){
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function money($n){ return "€ " . number_format((float)$n, 2); }

/* DB structure helpers */
function table_exists(mysqli $conn, string $table): bool{
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $esc = $conn->real_escape_string($table);
  $rs = $conn->query("SHOW TABLES LIKE '{$esc}'");
  return ($rs && $rs->num_rows > 0);
}
function col_exists(mysqli $conn, string $table, string $col): bool{
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $rs = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return ($rs && $rs->num_rows > 0);
}

/* Filters */
$q    = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

if($from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
if($to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = '';
$fromDT = $from ? ($from . " 00:00:00") : null;
$toDT   = $to   ? ($to   . " 23:59:59") : null;

/* Load */
$rows = [];
$errMsg = '';
$debugMsg = '';

try{
  if(!table_exists($conn, 'sales')){
    $errMsg = "Table <b>sales</b> not found.";
    throw new Exception("sales table missing");
  }

  // Detect columns
  $has_sale_type     = col_exists($conn,'sales','sale_type');
  $has_sale_date     = col_exists($conn,'sales','sale_date');
  $has_created_at    = col_exists($conn,'sales','created_at');
  $has_invoice_no    = col_exists($conn,'sales','invoice_no');
  $has_customer_name = col_exists($conn,'sales','customer_name');
  $has_customer_id   = col_exists($conn,'sales','customer_id');
  $has_total         = col_exists($conn,'sales','total_amount');
  $has_paid          = col_exists($conn,'sales','paid_amount');
  $has_due           = col_exists($conn,'sales','due_amount');
  $has_mode          = col_exists($conn,'sales','payment_mode');
  $has_status        = col_exists($conn,'sales','status');
  $has_note          = col_exists($conn,'sales','notes') || col_exists($conn,'sales','note');

  $dateCol = $has_sale_date ? 'sale_date' : ($has_created_at ? 'created_at' : '');

  // Build SELECT safely
  $select = [];
  $select[] = "s.id AS id";
  $select[] = $dateCol ? "s.`{$dateCol}` AS sale_date" : "NULL AS sale_date";
  $select[] = $has_invoice_no ? "s.invoice_no AS invoice_no" : "NULL AS invoice_no";

  // Customer display: use customer_name if exists, else fallback to customer_id, else '-'
  if($has_customer_name){
    $select[] = "s.customer_name AS customer_name";
  } elseif($has_customer_id){
    $select[] = "CAST(s.customer_id AS CHAR) AS customer_name";
  } else {
    $select[] = "NULL AS customer_name";
  }

  $select[] = $has_total ? "s.total_amount AS total_amount" : "0 AS total_amount";
  $select[] = $has_paid  ? "s.paid_amount  AS paid_amount"  : "0 AS paid_amount";

  // Due: use due_amount if exists else (total - paid)
  if($has_due){
    $select[] = "s.due_amount AS due_amount";
  } else {
    $select[] = "(" .
      ($has_total ? "COALESCE(s.total_amount,0)" : "0") .
      " - " .
      ($has_paid ? "COALESCE(s.paid_amount,0)" : "0") .
    ") AS due_amount";
  }

  $select[] = $has_mode   ? "s.payment_mode AS payment_mode" : "NULL AS payment_mode";
  $select[] = $has_status ? "s.status AS status" : "NULL AS status";

  // WHERE
  $where = [];
  $types = "";
  $params = [];

  if($has_sale_type){
    $where[] = "s.sale_type = 'wholesale'";
  } else {
    // If there is no sale_type column, we still show all sales (better than breaking)
    // You can later tell me your alternative "type" column.
  }

  if($q !== ''){
    $or = [];
    $like = "%".$q."%";

    if($has_invoice_no){    $or[] = "s.invoice_no LIKE ?";      $types.="s"; $params[]=$like; }
    if($has_customer_name){ $or[] = "s.customer_name LIKE ?";   $types.="s"; $params[]=$like; }
    if($has_mode){          $or[] = "s.payment_mode LIKE ?";    $types.="s"; $params[]=$like; }
    if($has_status){        $or[] = "s.status LIKE ?";          $types.="s"; $params[]=$like; }
    if($has_customer_id){   $or[] = "CAST(s.customer_id AS CHAR) LIKE ?"; $types.="s"; $params[]=$like; }

    if(!empty($or)){
      $where[] = "(".implode(" OR ", $or).")";
    }
  }

  if($dateCol){
    if($fromDT){ $where[] = "s.`{$dateCol}` >= ?"; $types.="s"; $params[]=$fromDT; }
    if($toDT){   $where[] = "s.`{$dateCol}` <= ?"; $types.="s"; $params[]=$toDT; }
  }

  $sql = "SELECT ".implode(", ", $select)." FROM sales s";
  if(!empty($where)) $sql .= " WHERE ".implode(" AND ", $where);

  $sql .= $dateCol ? " ORDER BY s.`{$dateCol}` DESC, s.id DESC" : " ORDER BY s.id DESC";
  $sql .= " LIMIT 200";

  $stmt = $conn->prepare($sql);
  if(!empty($params)){
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  while($r = $res->fetch_assoc()){
    $rows[] = $r;
  }
  $stmt->close();

}catch(Throwable $e){
  if(!$errMsg) $errMsg = "Wholesale page error. Please check server logs.";
  $debugMsg = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Unimart International • Wholesale</title>
<link rel="stylesheet" href="assets/css/app.css">
<style>
.page-head{display:flex;gap:12px;align-items:flex-end;justify-content:space-between;margin-bottom:12px}
.page-head h1{margin:0;font-size:18px}
.page-head .muted{color:var(--muted);font-size:12px}
.filters{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.input{border:1px solid var(--line);background:#fff;padding:10px 12px;border-radius:14px;min-width:220px}
.input.small{min-width:160px}
.btn{border:1px solid var(--line);background:#fff;padding:10px 12px;border-radius:14px;cursor:pointer;font-weight:700;text-decoration:none;display:inline-flex;align-items:center}
.btn.primary{background:var(--g);border-color:var(--g);color:#fff}
.table-wrap{overflow:auto}
.table-wrap table{min-width:980px}
td.num,th.num{text-align:right}
.badge{display:inline-flex;padding:5px 10px;border-radius:999px;border:1px solid var(--line);background:#fff;font-size:12px;font-weight:800}
.badge.green{border-color:rgba(31,122,63,.25);background:rgba(31,122,63,.08);color:var(--g2)}
.badge.gray{color:var(--muted);background:#f8fafc}
.badge.red{border-color:rgba(239,68,68,.25);background:rgba(239,68,68,.08);color:#991b1b}
.pill{display:inline-flex;gap:6px;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid var(--line);background:#fff;font-size:12px;font-weight:800}
.pill.ok{border-color:rgba(31,122,63,.25);background:rgba(31,122,63,.08);color:var(--g2)}
</style>
</head>
<body>

<div class="app">
  <?php include __DIR__ . "/partials/sidebar.php"; ?>

  <div class="main">
    <?php include __DIR__ . "/partials/topbar.php"; ?>

    <div class="content">
      <div class="container">

        <div class="page-head">
          <div>
            <h1>Wholesale</h1>
            <div class="muted">Invoices & credit • Latest wholesale sales</div>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <a class="btn primary" href="orders.php">+ New Invoice</a>
            <span class="pill ok"><?php echo (int)count($rows); ?> records</span>
          </div>
        </div>

        <div class="card section">
          <form class="filters" method="get">
            <input class="input" type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search invoice / customer / mode / status" />
            <input class="input small" type="date" name="from" value="<?php echo h($from); ?>" />
            <input class="input small" type="date" name="to" value="<?php echo h($to); ?>" />
            <button class="btn" type="submit">Filter</button>
            <a class="btn" href="wholesale.php">Reset</a>
          </form>

          <?php if($errMsg): ?>
            <div style="margin-top:12px;color:#991b1b;font-weight:800;">
              <?php echo $errMsg; ?>
              <?php if(isset($_GET['debug']) && $_GET['debug']=='1' && $debugMsg): ?>
                <div class="muted" style="margin-top:6px;color:#991b1b;">
                  Debug: <?php echo h($debugMsg); ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="table-wrap" style="margin-top:12px;">
            <?php if(empty($rows)): ?>
              <div class="muted">No wholesale invoices found.</div>
            <?php else: ?>
              <table>
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th class="num">Total</th>
                    <th class="num">Paid</th>
                    <th class="num">Due</th>
                    <th>Mode</th>
                    <th>Status</th>
                    <th class="num">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($rows as $r): ?>
                    <?php
                      $due = (float)($r['due_amount'] ?? 0);
                      $badge = ($due <= 0.0001) ? 'green' : 'red';
                      $inv = trim((string)($r['invoice_no'] ?? ''));
                      if($inv === '') $inv = '#'.(int)$r['id'];
                      $cust = trim((string)($r['customer_name'] ?? ''));
                      if($cust === '') $cust = '-';
                      $mode = $r['payment_mode'] ?? '-';
                      $st = $r['status'] ?? (($due <= 0.0001) ? 'paid' : 'due');
                      $dt = $r['sale_date'] ? substr((string)$r['sale_date'],0,10) : '-';
                    ?>
                    <tr>
                      <td><?php echo h($dt); ?></td>
                      <td><b><?php echo h($inv); ?></b></td>
                      <td><?php echo h($cust); ?></td>
                      <td class="num"><b><?php echo money($r['total_amount'] ?? 0); ?></b></td>
                      <td class="num"><?php echo money($r['paid_amount'] ?? 0); ?></td>
                      <td class="num"><b><?php echo money($due); ?></b></td>
                      <td><?php echo h($mode); ?></td>
                      <td><span class="badge <?php echo $badge; ?>">
                        <?php echo ($due <= 0.0001) ? 'Paid' : 'Due'; ?>
                      </span></td>
                      <td class="num">
                        <a class="btn" href="invoice-view.php?id=<?php echo (int)$r['id']; ?>">View</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

        </div>

      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>