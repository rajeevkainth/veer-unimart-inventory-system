<?php
// public_html/grocery/purchase-view.php
session_start();
require __DIR__ . "/config/db.php";

error_reporting(E_ALL & ~E_NOTICE);

/* ===== Page meta ===== */
$active = 'purchases';
$pageTitle = "Purchase Invoice";

/* ===== Company Settings (edit once) ===== */
$COMPANY_NAME    = "UniMart International";
$COMPANY_TAGLINE = "Inventory Cloud • Purchases";
$COMPANY_LINE1   = "unimartinternational.com";
$COMPANY_LINE2   = ""; // optional: address / phone
$LOGO_PATH       = "assets/img/logo.png"; // upload your logo here

/* ===== Helpers ===== */
if(!function_exists('h')){
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function money($n){ return "€ " . number_format((float)$n, 2); }

function table_has_column(mysqli $conn, string $table, string $col): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss", $table, $col);
  $stmt->execute();
  $stmt->store_result();
  $ok = ($stmt->num_rows > 0);
  $stmt->close();
  return $ok;
}

function fmt_dt($dt){
  if(!$dt) return '-';
  $t = strtotime($dt);
  return $t ? date('d M Y, h:i A', $t) : (string)$dt;
}

/* ===== Input ===== */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id <= 0){
  die("Invalid Purchase ID");
}

/* ===== Detect columns safely ===== */
$hasSupplierName = table_has_column($conn,'purchases','supplier_name');
$hasTax          = table_has_column($conn,'purchases','tax_amount');
$hasDue          = table_has_column($conn,'purchases','due_amount');
$hasPayMode      = table_has_column($conn,'purchases','payment_mode');

$hasPI_qty       = table_has_column($conn,'purchase_items','qty');
$hasPI_unit      = table_has_column($conn,'purchase_items','unit_cost');
$hasPI_line      = table_has_column($conn,'purchase_items','line_total');

/* ===== Load purchase ===== */
$pCols = "id, invoice_no, purchase_date, supplier_id, total_amount, discount_amount, paid_amount, notes";
if($hasSupplierName) $pCols .= ", supplier_name";
if($hasTax)          $pCols .= ", tax_amount";
if($hasDue)          $pCols .= ", due_amount";
if($hasPayMode)      $pCols .= ", payment_mode";

$stmt = $conn->prepare("SELECT $pCols FROM purchases WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$p = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$p){
  die("Purchase not found");
}

/* ===== Supplier display fallback ===== */
$supplierDisplay = trim((string)($p['supplier_name'] ?? ''));
if($supplierDisplay === '' && !empty($p['supplier_id'])){
  $supplierDisplay = "Supplier #".(int)$p['supplier_id'];
}
if($supplierDisplay === ''){
  $supplierDisplay = "Cash Supplier";
}

/* ===== Load items ===== */
$itemCols = "pi.product_id";
$itemCols .= $hasPI_qty  ? ", pi.qty"       : ", 0 AS qty";
$itemCols .= $hasPI_unit ? ", pi.unit_cost" : ", 0 AS unit_cost";
$itemCols .= $hasPI_line ? ", pi.line_total": ", NULL AS line_total";

$sql = "SELECT $itemCols, p.name AS product_name
        FROM purchase_items pi
        LEFT JOIN products p ON p.id = pi.product_id
        WHERE pi.purchase_id=?
        ORDER BY pi.id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$items = [];
while($r = $res->fetch_assoc()){
  $items[] = $r;
}
$stmt->close();

/* ===== Calculate totals (Fix Sub Total = 0) ===== */
$subTotal = 0.0;
foreach($items as $it){
  $qty  = (float)($it['qty'] ?? 0);
  $unit = (float)($it['unit_cost'] ?? 0);
  $line = (isset($it['line_total']) && $it['line_total'] !== null)
        ? (float)$it['line_total']
        : ($qty * $unit);
  $subTotal += $line;
}
$subTotal = round($subTotal, 2);

$tax      = $hasTax ? (float)($p['tax_amount'] ?? 0) : 0.0;
$discount = (float)($p['discount_amount'] ?? 0);
$grand    = (float)($p['total_amount'] ?? 0);
$paid     = (float)($p['paid_amount'] ?? 0);
$due      = $hasDue ? (float)($p['due_amount'] ?? 0) : max(0, $grand - $paid);

$invoiceNo = trim((string)($p['invoice_no'] ?? ''));
if($invoiceNo === '') $invoiceNo = "PB-".$p['id'];

$status = ($due <= 0.00001) ? "PAID" : (($paid > 0) ? "PARTIAL" : "OPEN");
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($invoiceNo); ?> • Purchase Invoice • UniMart</title>
<link rel="stylesheet" href="assets/css/app.css">

<style>
:root{
  --g:#1F7A3F; --g2:#14532D;
  --bg:#f7faf7; --card:#fff; --line:#e5e7eb;
  --text:#0f172a; --muted:#64748b;
  --shadow:0 14px 34px rgba(2,6,23,.10);
  --radius:18px;
}

.wrap{max-width:1100px;margin:0 auto;padding:18px 16px 34px}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}

/* ===== Invoice Header ===== */
.invoice-head{
  padding:18px;
  background:linear-gradient(135deg,#f2fff2,#ffffff);
  border-bottom:1px solid var(--line);
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:16px;
  flex-wrap:wrap;
}

.inv-left{
  flex: 1 1 520px;
  display:grid;
  grid-template-columns: 1fr 180px; /* logo | brand */
  gap:12px;
  align-items:start;
  min-width: 320px;
}

/* Logo container */
.logoBox{
  width:100%;
  height:110px;                 /* screen logo height */
  border-radius:16px;
  border:1px solid var(--line);
  background:#fff;
  overflow:hidden;
  display:flex;
  align-items:center;
  justify-content:flex-start;
  padding:10px 12px;
}
.logoBox img{
  width:100%;
  height:100%;
  object-fit:contain;           /* no crop */
  display:block;
}
.logoFallback{
  width:100%;
  height:100%;
  display:none;
  place-items:center;
  background:linear-gradient(135deg,var(--g),var(--g2));
  color:#fff;
  font-weight:1000;
  font-size:18px;
  border-radius:12px;
}

/* Brand info */
.brandInfo{
  text-align:right;
  display:flex;
  flex-direction:column;
  gap:2px;
  padding-top:6px;
}
.brandName{font-size:16px;font-weight:1000;color:var(--text);letter-spacing:.2px}
.brandSub{font-size:12px;color:var(--muted);font-weight:900}
.brandSmall{font-size:11px;color:var(--muted);font-weight:800;line-height:1.25}

.inv-right{min-width:280px;text-align:right}
.invTitle{font-size:12px;letter-spacing:.22em;color:var(--muted);font-weight:1000}
.invNo{font-size:20px;font-weight:1000;margin-top:2px}
.invMeta{margin-top:10px;display:grid;gap:6px;justify-items:end}
.invMeta div{
  display:flex;gap:10px;align-items:baseline;min-width:280px;justify-content:space-between;
}
.invMeta span{color:var(--muted);font-weight:900;font-size:12px}
.invMeta b{font-weight:1000;font-size:13px;color:var(--text)}
.statusPill{
  display:inline-flex;align-items:center;gap:8px;
  padding:7px 10px;border-radius:999px;
  background:#f6fff6;border:1px solid #d1fae5;
  font-weight:1000;font-size:12px;color:#065f46;
}
.statusDot{width:8px;height:8px;border-radius:99px;background:#16a34a}

.actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:10px}
.btn{
  border:1px solid var(--line);
  background:#fff;
  color:var(--text);
  border-radius:12px;
  padding:10px 12px;
  font-weight:900;
  cursor:pointer;
  display:inline-flex;align-items:center;gap:8px;
  text-decoration:none;
}
.btn-primary{
  border:0;
  background:linear-gradient(135deg,var(--g),var(--g2));
  color:#fff;
  box-shadow:0 10px 18px rgba(31,122,63,.22);
}

/* ===== Body ===== */
.body{padding:18px}
.grid{display:grid;grid-template-columns:1.2fr .8fr;gap:14px}
.box{
  border:1px solid var(--line);
  border-radius:16px;
  padding:14px;
  background:#fff;
}
.box h3{margin:0 0 10px;font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.12em}
.kv{display:grid;grid-template-columns:160px 1fr;gap:8px 12px;font-size:14px}
.k{color:var(--muted);font-weight:900}
.v{font-weight:1000;color:var(--text)}
.supplierBox .v{text-align:right}
.payBox .v{text-align:right;font-variant-numeric:tabular-nums}

/* ===== Items table ===== */
.table-wrap{margin-top:16px;border:1px solid var(--line);border-radius:16px;overflow:auto;background:#fff}
table{width:100%;border-collapse:collapse;min-width:760px}
th,td{padding:12px 12px;border-bottom:1px solid var(--line);font-size:14px}
th{
  background:#f6fff6;
  text-transform:uppercase;
  letter-spacing:.10em;
  font-size:12px;
  color:var(--muted);
  text-align:left;
}
td.right, th.right{text-align:right}

/* ===== Totals ===== */
.totals{margin-top:14px;display:flex;justify-content:flex-end}
.totbox{
  min-width:360px;
  border:1px solid var(--line);
  border-radius:16px;
  background:#fff;
  padding:12px 14px;
}
.line{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px dashed var(--line);font-weight:900}
.line:last-child{border-bottom:0}
.grand{font-size:16px}
.due{color:#991b1b}
.paid{color:#166534}

.note{
  margin-top:14px;
  border:1px dashed var(--line);
  border-radius:16px;
  padding:12px 14px;
  background:#fcfffc;
  color:var(--text);
  font-weight:800;
}

/* Responsive */
@media(max-width:980px){
  .grid{grid-template-columns:1fr}
  table{min-width:640px}
  .totbox{min-width:100%}
  .inv-right{min-width:100%;text-align:left}
  .invMeta{justify-items:start}
  .invMeta div{min-width:100%}
  .actions{justify-content:flex-start}
  .inv-left{grid-template-columns:1fr; }
  .brandInfo{text-align:left}
}

/* ===== A4 Print Fix ===== */
@page { size: A4; margin: 12mm; }
.noprint{display:block}

@media print{
  .noprint{display:none !important;}
  .sidebar,.topbar{display:none !important;}
  .app,.main,.content{padding:0 !important;margin:0 !important;}
  .wrap{max-width:100% !important;padding:0 !important;}
  .card{border:0 !important;box-shadow:none !important;}
  table{min-width:0 !important;}
  body{background:#fff !important;}

  /* Print: make logo height perfect + keep brand at right */
  .inv-left{
    grid-template-columns: 1fr 170px !important;
    align-items:start !important;
  }
  .logoBox{
    height:70px !important;              /* ✅ perfect print letterhead size */
    border:0 !important;
    border-radius:0 !important;
    padding:0 !important;
  }
  .logoBox img{
    width:auto !important;
    height:70px !important;
    object-fit:contain !important;
  }
  .brandInfo{
    text-align:right !important;
    padding-top:0 !important;
  }
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
        <div class="card">

          <!-- ===== Invoice Header ===== -->
          <div class="invoice-head">
            <div class="inv-left">
              <div class="logoBox">
                <img
                  src="<?php echo h($LOGO_PATH); ?>"
                  alt="Logo"
                  onerror="this.style.display='none';document.getElementById('logoFallback').style.display='grid';"
                >
                <div id="logoFallback" class="logoFallback">UM</div>
              </div>

              <div class="brandInfo">
                <div class="brandName"><?php echo h($COMPANY_NAME); ?></div>
                <div class="brandSub"><?php echo h($COMPANY_TAGLINE); ?></div>
                <div class="brandSmall">
                  <?php echo h($COMPANY_LINE1); ?><br>
                  <?php if(trim($COMPANY_LINE2) !== '') echo h($COMPANY_LINE2); ?>
                </div>
              </div>
            </div>

            <div class="inv-right">
              <div class="invTitle">PURCHASE INVOICE</div>
              <div class="invNo"><?php echo h($invoiceNo); ?></div>

              <div class="invMeta">
                <div><span>Date</span><b><?php echo h(fmt_dt($p['purchase_date'] ?? '')); ?></b></div>
                <div><span>Purchase ID</span><b>#<?php echo (int)$p['id']; ?></b></div>
                <?php if($hasPayMode && !empty($p['payment_mode'])): ?>
                  <div><span>Payment</span><b><?php echo h($p['payment_mode']); ?></b></div>
                <?php endif; ?>
                <div>
                  <span>Status</span>
                  <b><span class="statusPill"><span class="statusDot"></span><?php echo h($status); ?></span></b>
                </div>
              </div>

              <div class="actions noprint">
                <a class="btn" href="purchases.php">← Back</a>
                <button class="btn btn-primary" onclick="window.print()">🖨️ Print</button>
              </div>
            </div>
          </div>

          <!-- ===== Body ===== -->
          <div class="body">

            <div class="grid">
              <div class="box supplierBox">
                <h3>Supplier Details</h3>
                <div class="kv">
                  <div class="k">Supplier</div>
                  <div class="v"><?php echo h($supplierDisplay); ?></div>

                  <div class="k">Invoice No</div>
                  <div class="v"><?php echo h($invoiceNo); ?></div>

                  <div class="k">Notes</div>
                  <div class="v"><?php echo !empty($p['notes']) ? h($p['notes']) : '-'; ?></div>
                </div>
              </div>

              <div class="box payBox">
                <h3>Payment Summary</h3>
                <div class="kv">
                  <div class="k">Grand Total</div>
                  <div class="v"><?php echo money($grand); ?></div>

                  <div class="k">Paid</div>
                  <div class="v paid"><?php echo money($paid); ?></div>

                  <div class="k">Due</div>
                  <div class="v due"><?php echo money($due); ?></div>
                </div>
              </div>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th style="min-width:260px">Product</th>
                    <th class="right">Qty</th>
                    <th class="right">Unit Cost</th>
                    <th class="right">Line Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if(empty($items)): ?>
                    <tr>
                      <td colspan="4" style="color:var(--muted);font-weight:900">No items found for this purchase.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach($items as $it):
                      $pname = $it['product_name'] ?: ('Product #'.(int)$it['product_id']);
                      $qty   = (float)($it['qty'] ?? 0);
                      $unit  = (float)($it['unit_cost'] ?? 0);
                      $line  = (isset($it['line_total']) && $it['line_total'] !== null) ? (float)$it['line_total'] : ($qty*$unit);
                    ?>
                      <tr>
                        <td style="font-weight:1000"><?php echo h($pname); ?></td>
                        <td class="right"><?php echo rtrim(rtrim(number_format($qty,2), '0'), '.'); ?></td>
                        <td class="right"><?php echo money($unit); ?></td>
                        <td class="right" style="font-weight:1000"><?php echo money($line); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="totals">
              <div class="totbox">
                <div class="line"><span>Sub Total</span><b><?php echo money($subTotal); ?></b></div>
                <div class="line"><span>Tax</span><b><?php echo money($tax); ?></b></div>
                <div class="line"><span>Discount</span><b><?php echo money($discount); ?></b></div>
                <div class="line grand"><span>Grand Total</span><b><?php echo money($grand); ?></b></div>
                <div class="line"><span>Paid</span><b class="paid"><?php echo money($paid); ?></b></div>
                <div class="line"><span>Due</span><b class="due"><?php echo money($due); ?></b></div>
              </div>
            </div>

            <div class="note">
              <b>Declaration:</b> This is a computer generated invoice. No signature required.
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