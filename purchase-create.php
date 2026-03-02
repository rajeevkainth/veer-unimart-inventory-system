<?php
// public_html/grocery/purchase-create.php
session_start();
require __DIR__ . "/config/db.php";

/* ===== Debug log for 500 errors (safe) ===== */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/purchase_create_error.log');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$active = 'purchases';
$pageTitle = "New Purchase";

/* =========================
   Helpers
========================= */
if(!function_exists('h')){
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function money($n){ return "€" . number_format((float)$n, 2); }

function table_exists(mysqli $conn, string $table): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $table);
  $stmt->execute();
  $stmt->store_result();
  $ok = ($stmt->num_rows > 0);
  $stmt->close();
  return $ok;
}

function table_has_column(mysqli $conn, string $table, string $col): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss", $table, $col);
  $stmt->execute();
  $stmt->store_result();
  $ok = ($stmt->num_rows > 0);
  $stmt->close();
  return $ok;
}

function get_table_columns(mysqli $conn, string $table): array {
  $cols = [];
  $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $table);
  $stmt->execute();
  $res = $stmt->get_result();
  while($r = $res->fetch_assoc()){
    $cols[] = $r['COLUMN_NAME'];
  }
  $stmt->close();
  return $cols;
}

/* =========================
   CSRF
========================= */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$msgErr = '';
$msgOk  = '';

/* =========================
   Load Suppliers (auto-detect)
========================= */
$suppliers = [];
$supplierNameMap = []; // id => name (for snapshot in purchases.supplier_name)

if (table_exists($conn, 'suppliers') && table_has_column($conn, 'suppliers', 'id')) {

  $scols = get_table_columns($conn, 'suppliers');
  $nameCandidates   = ['supplier_name','name','vendor_name','company_name','title'];
  $phoneCandidates  = ['phone','mobile','contact','contact_no','phone_no'];
  $activeCandidates = ['is_active','active','status'];

  $nameCol = null; $phoneCol = null; $activeCol = null;
  foreach($nameCandidates as $c){ if(in_array($c, $scols, true)){ $nameCol = $c; break; } }
  foreach($phoneCandidates as $c){ if(in_array($c, $scols, true)){ $phoneCol = $c; break; } }
  foreach($activeCandidates as $c){ if(in_array($c, $scols, true)){ $activeCol = $c; break; } }

  $select = "id";
  $select .= $nameCol  ? ", `$nameCol` AS supplier_name" : ", '' AS supplier_name";
  $select .= $phoneCol ? ", `$phoneCol` AS phone" : ", '' AS phone";
  $select .= $activeCol? ", `$activeCol` AS is_active" : ", 1 AS is_active";

  $q = $conn->query("SELECT $select FROM suppliers ORDER BY supplier_name ASC, id DESC");
  while($r = $q->fetch_assoc()){
    $ia = 1;
    if(isset($r['is_active'])){
      $ia = is_numeric($r['is_active'])
        ? (int)$r['is_active']
        : ((strtolower((string)$r['is_active']) === 'active') ? 1 : 0);
    }
    $sid = (int)$r['id'];
    $nm  = trim((string)($r['supplier_name'] ?? ''));
    $suppliers[] = [
      'id' => $sid,
      'supplier_name' => $nm,
      'phone' => (string)($r['phone'] ?? ''),
      'is_active' => $ia ? 1 : 0,
    ];
    $supplierNameMap[$sid] = ($nm !== '' ? $nm : ('Supplier #'.$sid));
  }
}

/* =========================
   Supplier Due Map
========================= */
$supplierDueMap = [];
if(table_exists($conn, 'purchases')
  && table_has_column($conn,'purchases','supplier_id')
  && table_has_column($conn,'purchases','total_amount')
){
  $hasPaid = table_has_column($conn,'purchases','paid_amount');
  $sql = "
    SELECT supplier_id,
           COALESCE(SUM(total_amount),0) AS tot,
           COALESCE(SUM(".($hasPaid ? "paid_amount" : "0")."),0) AS paid
    FROM purchases
    WHERE supplier_id IS NOT NULL AND supplier_id > 0
    GROUP BY supplier_id
  ";
  $q = $conn->query($sql);
  while($r = $q->fetch_assoc()){
    $sid = (int)$r['supplier_id'];
    $due = (float)$r['tot'] - (float)$r['paid'];
    if($due < 0) $due = 0;
    $supplierDueMap[$sid] = $due;
  }
}

/* =========================
   Load Products
========================= */
$products = [];
if (table_exists($conn, 'products')
  && table_has_column($conn, 'products', 'id')
  && table_has_column($conn, 'products', 'name')
){
  $hasActive = table_has_column($conn, 'products', 'is_active');
  $sql = "SELECT id, name, ".($hasActive ? "COALESCE(is_active,1) AS is_active" : "1 AS is_active")."
          FROM products ".($hasActive ? "WHERE COALESCE(is_active,1)=1" : "")."
          ORDER BY name ASC";
  $q = $conn->query($sql);
  while($r = $q->fetch_assoc()){
    $products[] = ['id'=>(int)$r['id'], 'name'=>(string)$r['name'], 'is_active'=>(int)$r['is_active']];
  }
}

/* =========================
   POST (Save Purchase)
========================= */
if($_SERVER['REQUEST_METHOD'] === 'POST'){
  try{
    if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')){
      throw new Exception("Security token mismatch.");
    }

    $invoice_no    = trim((string)($_POST['purchase_no'] ?? ''));
    $supplier_id   = (int)($_POST['supplier_id'] ?? 0);
    $purchase_date = trim((string)($_POST['purchase_date'] ?? date('Y-m-d')));
    $payment_mode  = trim((string)($_POST['payment_mode'] ?? 'Cash'));
    $tax_amount    = (float)($_POST['tax_amount'] ?? 0);
    $discount_amt  = (float)($_POST['discount_amount'] ?? 0);
    $paid_amount   = (float)($_POST['paid_amount'] ?? 0);
    $note          = trim((string)($_POST['note'] ?? ''));

    if($invoice_no === ''){
      throw new Exception("Purchase No is required.");
    }

    // normalize date to datetime (purchases.purchase_date is datetime)
    if(strlen($purchase_date) === 10){
      $purchase_date .= " 00:00:00";
    }

    /* ===== items_json -> cleanItems + subtotal ===== */
    $items = [];
    if(!empty($_POST['items_json'])){
      $decoded = json_decode($_POST['items_json'], true);
      if(is_array($decoded)) $items = $decoded;
    }

    $cleanItems = [];
    $sub_total = 0.0;

    foreach($items as $it){
      $pid  = (int)($it['product_id'] ?? 0);
      $qty  = (float)($it['qty'] ?? 0);
      $cost = (float)($it['unit_cost'] ?? 0);

      if($pid > 0 && $qty > 0 && $cost >= 0){
        $line = $qty * $cost;

        $cleanItems[] = [
          'product_id' => $pid,
          'qty'        => $qty,
          'unit_cost'  => $cost,
          'cost_rate'  => (float)($it['cost_rate'] ?? 0),
          'line_total' => $line,
          'batch'      => trim((string)($it['batch'] ?? '')),
          'expiry'     => ($it['expiry'] ?? null),
        ];

        $sub_total += $line;
      }
    }

    if(count($cleanItems) === 0){
      throw new Exception("Please add at least one item with Qty and Unit Cost.");
    }

    /* ===== totals ===== */
    $sub_total    = round($sub_total, 2);
    $tax_amount   = round(max(0,$tax_amount), 2);
    $discount_amt = round(max(0,$discount_amt), 2);

    $grand_total = round($sub_total + $tax_amount - $discount_amt, 2);
    if($grand_total < 0) $grand_total = 0.00;

    $paid_amount = round(max(0,$paid_amount), 2);
    if($paid_amount > $grand_total) $paid_amount = $grand_total;

    $due_amount = round($grand_total - $paid_amount, 2);
    if($due_amount < 0) $due_amount = 0.00;

    /* ===== transaction ===== */
    $conn->begin_transaction();

    /* ===== insert purchases ===== */
    $has_due  = table_has_column($conn, 'purchases', 'due_amount');
    $has_tax  = table_has_column($conn, 'purchases', 'tax_amount');
    $has_pm   = table_has_column($conn, 'purchases', 'payment_mode');
    $has_sn   = table_has_column($conn, 'purchases', 'supplier_name');

    $supplier_name = ($supplier_id > 0 && isset($supplierNameMap[$supplier_id])) ? $supplierNameMap[$supplier_id] : 'Cash Supplier';

    // build dynamic insert (safe with your schema)
    $cols = ["supplier_id","purchase_date","invoice_no","total_amount","discount_amount","paid_amount","notes","created_at"];
    $vals = ["?","?","?","?","?","?","?","NOW()"];
    $types = "issddds";
    $bind  = [$supplier_id,$purchase_date,$invoice_no,$grand_total,$discount_amt,$paid_amount,$note];

    if($has_due){
      $cols[] = "due_amount"; $vals[] = "?"; $types .= "d"; $bind[] = $due_amount;
    }
    if($has_tax){
      $cols[] = "tax_amount"; $vals[] = "?"; $types .= "d"; $bind[] = $tax_amount;
    }
    if($has_pm){
      $cols[] = "payment_mode"; $vals[] = "?"; $types .= "s"; $bind[] = $payment_mode;
    }
    if($has_sn){
      $cols[] = "supplier_name"; $vals[] = "?"; $types .= "s"; $bind[] = $supplier_name;
    }

    $sql = "INSERT INTO purchases (".implode(",",$cols).") VALUES (".implode(",",$vals).")";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $purchase_id = (int)$conn->insert_id;
    $stmt->close();

    /* ===== insert purchase_items + stock_movements ===== */
    $created_at = date('Y-m-d H:i:s');

    foreach($cleanItems as $it){
      // purchase_items
     /* ===== purchase_items (schema-safe) ===== */
$pi_has_batch  = table_has_column($conn, 'purchase_items', 'batch');
$pi_has_expiry = table_has_column($conn, 'purchase_items', 'expiry');
$pi_has_cr     = table_has_column($conn, 'purchase_items', 'created_at');

$pi_cols  = ["purchase_id","product_id","qty","unit_cost","cost_rate","line_total"];
$pi_vals  = ["?","?","?","?","?","?"];
$pi_types = "iidddd";
$pi_bind  = [$purchase_id, $it['product_id'], $it['qty'], $it['unit_cost'], $it['cost_rate'], $it['line_total']];

if($pi_has_batch){
  $pi_cols[]  = "batch";
  $pi_vals[]  = "?";
  $pi_types  .= "s";
  $pi_bind[]  = (string)$it['batch'];
}
if($pi_has_expiry){
  $pi_cols[]  = "expiry";
  $pi_vals[]  = "?";
  $pi_types  .= "s";
  $pi_bind[]  = ($it['expiry'] ? (string)$it['expiry'] : null);
}
if($pi_has_cr){
  $pi_cols[]  = "created_at";
  $pi_vals[]  = "?";
  $pi_types  .= "s";
  $pi_bind[]  = $created_at;
}

$sql = "INSERT INTO purchase_items (".implode(",",$pi_cols).") VALUES (".implode(",",$pi_vals).")";
$stmt = $conn->prepare($sql);
$stmt->bind_param($pi_types, ...$pi_bind);
$stmt->execute();
$stmt->close();

      // stock_movements
      $sql = "INSERT INTO stock_movements
              (product_id, move_type, qty_in, qty_out, move_date, ref_table, ref_id, note, created_at)
              VALUES (?,?,?,?,?,?,?,?,?)";
      $stmt = $conn->prepare($sql);

      $move_type = 'purchase';
      $qty_out = 0.0;
      $ref_table = 'purchases';
      $note_move = "Purchase ".$invoice_no;

      $stmt->bind_param(
        "isddssiss",
        $it['product_id'],
        $move_type,
        $it['qty'],
        $qty_out,
        $purchase_date,
        $ref_table,
        $purchase_id,
        $note_move,
        $created_at
      );
      $stmt->execute();
      $stmt->close();
    }

    /* ===== supplier_payments record ===== */
    if($supplier_id > 0 && $paid_amount > 0){
      $sql = "INSERT INTO supplier_payments
              (supplier_id, purchase_id, amount, payment_mode, pay_date, note)
              VALUES (?,?,?,?,?,?)";
      $stmt = $conn->prepare($sql);
      $pay_note = "Payment for ".$invoice_no;
      $stmt->bind_param("iidsss", $supplier_id, $purchase_id, $paid_amount, $payment_mode, $purchase_date, $pay_note);
      $stmt->execute();
      $stmt->close();
    }

    $conn->commit();
    header("Location: purchases.php?ok=1");
    exit;

  } catch(Throwable $e){
    if(isset($conn) && $conn instanceof mysqli){
      $conn->rollback();
    }
    $msgErr = $e->getMessage();
    error_log("PURCHASE CREATE ERROR: ".$msgErr);
  }
}

/* default purchase no */
$defaultNo = "PB-" . str_pad((string)rand(1, 99999), 5, "0", STR_PAD_LEFT);
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
  .h1{font-size:20px;font-weight:1000;margin:0}
  .muted{color:var(--muted);font-size:12px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);overflow:hidden;margin-top:14px}
  .card-h{padding:12px 14px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
  .card-b{padding:14px}
  .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:12px}
  .col-3{grid-column:span 3}
  .col-4{grid-column:span 4}
  .col-12{grid-column:span 12}
  label{display:block;font-size:12px;color:var(--muted);font-weight:900;margin:0 0 6px}
  input,select,textarea{
    width:100%;
    border:1px solid var(--line);
    border-radius:12px;
    padding:10px 12px;
    outline:none;
    background:#fff;
    color:var(--text);
    font-size:14px;
  }
  select{appearance:auto}
  select option{background:#fff;color:var(--text)}
  textarea{min-height:40px;resize:vertical}
  .btn{border:0;border-radius:12px;padding:10px 14px;font-weight:900;cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:8px}
  .btn-primary{background:linear-gradient(135deg,var(--g),var(--g2));color:#fff;box-shadow:0 10px 18px rgba(31,122,63,.22)}
  .btn-ghost{background:#fff;border:1px solid var(--line);color:var(--text)}
  .btn-danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b}
  .mini{font-size:12px;padding:8px 10px;border-radius:10px}
  .alert{padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:#fff;margin:10px 0;font-weight:800}
  .err{background:#fff1f2;border-color:#fecaca;color:#991b1b}
  .ok{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
  .items{width:100%;border-collapse:separate;border-spacing:0}
  .items th,.items td{padding:10px 10px;border-bottom:1px solid var(--line);white-space:nowrap;vertical-align:middle;font-size:14px}
  .items th{background:#f6fff6;font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}
  .right{text-align:right}
  .rowbox{display:flex;gap:10px;align-items:center}
  .addbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
  .table-wrap{overflow:auto}
  .totals{display:flex;justify-content:flex-end}
  .totbox{min-width:320px}
  .totline{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px dashed var(--line)}
  .totline b{font-weight:1000}
  @media(max-width:900px){
    .col-3,.col-4{grid-column:span 12}
    .totbox{min-width:100%}
  }
</style>
</head>
<body>

<div class="app">
  <?php include __DIR__ . "/partials/sidebar.php"; ?>
  <div class="main">
    <?php include __DIR__ . "/partials/topbar.php"; ?>

    <div class="content">
      <div class="wrap">

        <div class="addbar">
          <div>
            <h1 class="h1">🧾 New Purchase</h1>
            <div class="muted">Create supplier bill + stock-in entries. Batch/Expiry optional.</div>
          </div>
          <div class="rowbox">
            <a class="btn btn-ghost mini" href="purchases.php">← Back</a>
          </div>
        </div>

        <?php if($msgErr): ?>
          <div class="alert err"><?php echo h($msgErr); ?></div>
        <?php endif; ?>
        <?php if($msgOk): ?>
          <div class="alert ok"><?php echo h($msgOk); ?></div>
        <?php endif; ?>

        <form method="post" action="purchase-create.php" class="card">
          <div class="card-b">
            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="items_json" id="items_json" value="">

            <div class="grid">
              <div class="col-4">
                <label>Purchase No</label>
                <input name="purchase_no" value="<?php echo h($_POST['purchase_no'] ?? $defaultNo); ?>">
              </div>

              <div class="col-4">
                <label>Purchase Date</label>
                <input type="date" name="purchase_date" value="<?php echo h($_POST['purchase_date'] ?? date('Y-m-d')); ?>">
              </div>

              <div class="col-4">
                <label>Supplier</label>
                <select name="supplier_id">
                  <option value="0">— Select supplier (optional)</option>
                  <?php foreach($suppliers as $s):
                    if((int)$s['is_active'] !== 1) continue;
                    $sel = ((int)($_POST['supplier_id'] ?? 0) === (int)$s['id']) ? 'selected' : '';
                    $txt = trim($s['supplier_name']) !== '' ? $s['supplier_name'] : ('Supplier #'.$s['id']);
                  ?>
                    <option value="<?php echo (int)$s['id']; ?>" <?php echo $sel; ?>>
                      <?php echo h($txt); ?><?php echo $s['phone'] ? ' • '.$s['phone'] : ''; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-4">
                <label>Supplier Previous Balance</label>
                <input id="prevDue" value="€0.00" readonly>
              </div>

              <div class="col-4">
                <label>Pending (This Bill)</label>
                <input id="billPending" value="€0.00" readonly>
              </div>

              <div class="col-4">
                <label>New Balance After Purchase</label>
                <input id="newDue" value="€0.00" readonly>
              </div>

              <div class="col-3">
                <label>Tax Amount</label>
                <input name="tax_amount" value="<?php echo h($_POST['tax_amount'] ?? '0'); ?>">
              </div>

              <div class="col-3">
                <label>Discount Amount</label>
                <input name="discount_amount" value="<?php echo h($_POST['discount_amount'] ?? '0'); ?>">
              </div>

              <div class="col-3">
                <label>Paid Amount</label>
                <input name="paid_amount" value="<?php echo h($_POST['paid_amount'] ?? '0'); ?>">
              </div>

              <div class="col-3">
                <label>Payment Mode</label>
                <select name="payment_mode">
                  <?php
                    $pm = $_POST['payment_mode'] ?? 'Cash';
                    $modes = ['Cash','UPI','Card','Bank','Other'];
                    foreach($modes as $m){
                      $sel = ($pm === $m) ? 'selected' : '';
                      echo '<option '.$sel.' value="'.h($m).'">'.h($m).'</option>';
                    }
                  ?>
                </select>
              </div>

              <div class="col-12">
                <label>Note (optional)</label>
                <textarea name="note" placeholder="Any remark..."><?php echo h($_POST['note'] ?? ''); ?></textarea>
              </div>
            </div>
          </div>

          <div class="card-h">
            <div style="font-weight:1000">Items</div>
            <button type="button" class="btn btn-ghost mini" id="addItem">+ Add Item</button>
          </div>

          <div class="card-b table-wrap">
            <table class="items" id="itemsTable">
              <thead>
                <tr>
                  <th style="min-width:220px">Product</th>
                  <th class="right" style="min-width:90px">Qty</th>
                  <th class="right" style="min-width:110px">Unit Cost</th>
                  <th class="right" style="min-width:110px">Cost Rate</th>
                  <th style="min-width:120px">Batch</th>
                  <th style="min-width:150px">Expiry</th>
                  <th class="right" style="min-width:110px">Line Total</th>
                  <th style="width:70px"></th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>

            <div class="totals" style="margin-top:10px">
              <div class="totbox">
                <div class="totline"><span>Sub Total</span> <b id="subTotal">€0.00</b></div>
                <div class="totline"><span>Grand Total</span> <b id="grandTotal">€0.00</b></div>
                <div class="muted" style="padding-top:8px">Tax & discount are applied above.</div>
              </div>
            </div>

            <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">
              <button class="btn btn-primary" type="submit">✅ Save Purchase</button>
              <a class="btn btn-ghost" href="purchases.php">Cancel</a>
            </div>
          </div>
        </form>

      </div>
    </div>

  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>
const PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;
const SUPPLIER_DUE = <?php echo json_encode($supplierDueMap ?? [], JSON_UNESCAPED_UNICODE); ?>;

function fmtMoney(n){ n = Number(n || 0); return "€" + n.toFixed(2); }
function escapeHtml(s){
  return String(s||'')
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

function rowTemplate(){
  const opts = ['<option value="0">Select product</option>']
    .concat(PRODUCTS.map(p => `<option value="${p.id}">${escapeHtml(p.name)}</option>`))
    .join('');
  return `
    <tr>
      <td><select class="prodSel">${opts}</select></td>
      <td class="right"><input class="qty" type="number" step="0.01" min="0" value="1"></td>
      <td class="right"><input class="unit" type="number" step="0.01" min="0" value="0"></td>
      <td class="right"><input class="rate" type="number" step="0.01" min="0" value="0"></td>
      <td><input class="batch" placeholder="optional"></td>
      <td><input class="expiry" type="date"></td>
      <td class="right"><b class="line">€0.00</b></td>
      <td class="right"><button type="button" class="btn btn-danger mini del">Del</button></td>
    </tr>
  `;
}

function updateSupplierDue(){
  const supSel = document.querySelector('select[name="supplier_id"]');
  const sid = Number(supSel ? supSel.value : 0);
  const prev = Number((SUPPLIER_DUE && SUPPLIER_DUE[sid]) ? SUPPLIER_DUE[sid] : 0);

  const rows = document.querySelectorAll('#itemsTable tbody tr');
  let sub = 0;
  rows.forEach(tr => {
    const qty  = Number(tr.querySelector('.qty').value || 0);
    const unit = Number(tr.querySelector('.unit').value || 0);
    sub += qty * unit;
  });

  const tax  = Number(document.querySelector('input[name="tax_amount"]').value || 0);
  const dis  = Number(document.querySelector('input[name="discount_amount"]').value || 0);
  const paid = Number(document.querySelector('input[name="paid_amount"]').value || 0);

  let grand = sub + tax - dis; if(grand < 0) grand = 0;
  let pending = grand - paid; if(pending < 0) pending = 0;

  const newDue = prev + pending;
  document.getElementById('prevDue').value = fmtMoney(prev);
  document.getElementById('billPending').value = fmtMoney(pending);
  document.getElementById('newDue').value = fmtMoney(newDue);
}

function recalc(){
  const rows = document.querySelectorAll('#itemsTable tbody tr');
  let sub = 0;
  rows.forEach(tr => {
    const qty  = Number(tr.querySelector('.qty').value || 0);
    const unit = Number(tr.querySelector('.unit').value || 0);
    const line = qty * unit;
    sub += line;
    tr.querySelector('.line').textContent = fmtMoney(line);
  });

  const tax = Number(document.querySelector('input[name="tax_amount"]').value || 0);
  const dis = Number(document.querySelector('input[name="discount_amount"]').value || 0);
  let grand = sub + tax - dis; if(grand < 0) grand = 0;

  document.getElementById('subTotal').textContent = fmtMoney(sub);
  document.getElementById('grandTotal').textContent = fmtMoney(grand);

  updateSupplierDue();
}

function addRow(){
  document.querySelector('#itemsTable tbody').insertAdjacentHTML('beforeend', rowTemplate());
  recalc();
}

document.getElementById('addItem').addEventListener('click', addRow);

document.addEventListener('input', (e) => {
  if(e.target.closest('#itemsTable') || e.target.name === 'tax_amount' || e.target.name === 'discount_amount' || e.target.name === 'paid_amount'){
    recalc();
  }
});
document.addEventListener('change', (e) => {
  if(e.target && e.target.name === 'supplier_id'){
    updateSupplierDue();
  }
});
document.addEventListener('click', (e) => {
  if(e.target.classList.contains('del')){
    e.target.closest('tr').remove();
    recalc();
  }
});

/* ✅ IMPORTANT: build items_json from table rows */
document.querySelector("form").addEventListener("submit", function(e){
  const rows = document.querySelectorAll('#itemsTable tbody tr');
  const cleaned = [];

  rows.forEach(row => {
    const pid  = Number(row.querySelector('.prodSel')?.value || 0);
    const qty  = Number(row.querySelector('.qty')?.value || 0);
    const cost = Number(row.querySelector('.unit')?.value || 0);
    const rate = Number(row.querySelector('.rate')?.value || 0);
    const batch = (row.querySelector('.batch')?.value || '').trim();
    const expiry = row.querySelector('.expiry')?.value || '';

    if(pid > 0 && qty > 0 && cost >= 0){
      cleaned.push({product_id:pid, qty:qty, unit_cost:cost, cost_rate:rate, batch:batch, expiry:expiry});
    }
  });

  if(cleaned.length === 0){
    alert("Please add at least one product with quantity and unit cost.");
    e.preventDefault();
    return;
  }

  document.getElementById("items_json").value = JSON.stringify(cleaned);
});

// At least 1 row
addRow();
updateSupplierDue();
</script>

</body>
</html>