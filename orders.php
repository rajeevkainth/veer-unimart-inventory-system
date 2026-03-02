<?php
// grocery/orders.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

require_once __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$store_id = (int)($_SESSION['store_id'] ?? 1);

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2); }

function table_exists(mysqli $conn, string $table): bool {
  try{
    $t = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
  }catch(Throwable $e){ return false; }
}

function first_col(array $cols, array $cands): ?string {
  $set = array_flip(array_map('strtolower',$cols));
  foreach($cands as $c){
    if(isset($set[strtolower($c)])) return $c;
  }
  return null;
}

$HAS_PRODUCTS = table_exists($conn, 'products');
$HAS_INVOICES = table_exists($conn, 'invoices');
$HAS_ITEMS    = table_exists($conn, 'invoice_items');
$HAS_PAYMENTS = table_exists($conn, 'customer_payments');
$HAS_ALLOC    = table_exists($conn, 'payment_allocations');

if (!$HAS_INVOICES || !$HAS_ITEMS) {
  die("Required tables missing: invoices / invoice_items");
}

$err=''; $ok='';

/* =========================
   Load customers
========================= */
$customers = [];
try{
  $st = $conn->prepare("SELECT id, name, mobile FROM customers WHERE store_id=? ORDER BY name ASC");
  $st->bind_param("i",$store_id);
  $st->execute();
  $rs = $st->get_result();
  while($rs && ($r=$rs->fetch_assoc())) $customers[]=$r;
  $st->close();
}catch(Throwable $e){
  $err = "Customers fetch error: ".$e->getMessage();
}

/* =========================
   Products (optional)
   Auto-detect columns if schema differs
========================= */
/* =========================
   Products (optional)
   Prefer product_prices join (your actual schema)
========================= */
$products = [];
if($HAS_PRODUCTS){
  try{
    $HAS_PRICES = table_exists($conn, 'product_prices');

    // detect store_id column (some setups have it, some don't)
    $colsRes = $conn->query("SHOW COLUMNS FROM products");
    $cols = [];
    while($colsRes && ($c=$colsRes->fetch_assoc())) $cols[]=$c['Field'];
    $HAS_STORE_COL = (bool)first_col($cols, ['store_id']);

    if($HAS_PRICES){
      // ✅ Your correct schema: prices in product_prices
      $sql = "
        SELECT p.id,
               p.name AS pname,
               COALESCE(pp.retail_price, pp.mrp, 0) AS pprice
        FROM products p
        LEFT JOIN product_prices pp ON pp.product_id = p.id
        WHERE COALESCE(p.is_active,1)=1
        ".($HAS_STORE_COL ? " AND p.store_id=".$store_id." " : "")."
        ORDER BY p.name ASC
      ";
      $rs = $conn->query($sql);
      while($rs && ($r=$rs->fetch_assoc())){
        $products[] = [
          'id'    => (int)$r['id'],
          'name'  => (string)$r['pname'],
          'price' => (float)$r['pprice'],
        ];
      }
    } else {
      // fallback: try to find price column in products table (old schema)
      $colName  = first_col($cols, ['name','product_name','title','item_name']);
      $colPrice = first_col($cols, ['sale_price','selling_price','price','mrp','rate']);
      if($colName && $colPrice){
        $sql = "SELECT id, `$colName` AS pname, `$colPrice` AS pprice
                FROM products
                WHERE COALESCE(is_active,1)=1
                ".($HAS_STORE_COL ? " AND store_id=".$store_id." " : "")."
                ORDER BY `$colName` ASC";
        $rs = $conn->query($sql);
        while($rs && ($r=$rs->fetch_assoc())){
          $products[] = ['id'=>(int)$r['id'], 'name'=>$r['pname'], 'price'=>(float)$r['pprice']];
        }
      }
    }
  }catch(Throwable $e){
    // ignore; manual entry still works
  }
}

/* =========================
   Create invoice
========================= */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_invoice'])){
  $customer_id = (int)($_POST['customer_id'] ?? 0);
  $invoice_date = trim((string)($_POST['invoice_date'] ?? ''));
  $note = trim((string)($_POST['note'] ?? ''));
  $paid_amount = (float)($_POST['paid_amount'] ?? 0);
  $pay_mode = (string)($_POST['pay_mode'] ?? 'cash');

  $item_pid  = $_POST['pid']  ?? [];
  $item_name = $_POST['iname']?? [];
  $item_qty  = $_POST['qty']  ?? [];
  $item_rate = $_POST['rate'] ?? [];

  if($customer_id<=0){
    $err="Select customer.";
  } else {
    if($invoice_date==='') $invoice_date = date('Y-m-d H:i:s');
    // build items server-side
    $items = [];
    $grand = 0.0;

    $count = max(count($item_name), count($item_qty), count($item_rate), count($item_pid));
    for($i=0;$i<$count;$i++){
      $pid = isset($item_pid[$i]) ? (int)$item_pid[$i] : 0;
      $nm  = trim((string)($item_name[$i] ?? ''));
      $qty = (float)($item_qty[$i] ?? 0);
      $rate= (float)($item_rate[$i] ?? 0);

      if($qty<=0 || $rate<0) continue;
      if($nm==='') $nm = ($pid>0 ? "Product #".$pid : "");

      if($nm==='') continue;

      $line = round($qty*$rate, 2);
      $grand += $line;
      $items[] = ['pid'=>$pid?:null, 'name'=>$nm, 'qty'=>$qty, 'rate'=>$rate, 'line'=>$line];
    }

    if(empty($items)){
      $err="Add at least 1 item.";
    } else {
      if($paid_amount < 0) $paid_amount = 0;
      if($paid_amount > $grand) $paid_amount = $grand;

      $due = round($grand - $paid_amount, 2);
      $status = ($due<=0.009) ? 'paid' : (($paid_amount>0.009) ? 'partial' : 'due');

      try{
        $conn->begin_transaction();

        // invoice number
        $invNo = "INV".date('Ymd')."-".str_pad((string)random_int(1,9999), 4, "0", STR_PAD_LEFT);

        $st = $conn->prepare("INSERT INTO invoices (store_id, customer_id, invoice_no, invoice_date, grand_total, paid_total, due_total, status, note)
                              VALUES (?,?,?,?,?,?,?,?,?)");
        $st->bind_param("iissddsss", $store_id, $customer_id, $invNo, $invoice_date, $grand, $paid_amount, $due, $status, $note);
        $st->execute();
        $invoice_id = (int)$st->insert_id;
        $st->close();

        $st = $conn->prepare("INSERT INTO invoice_items (invoice_id, product_id, item_name, qty, rate, line_total)
                              VALUES (?,?,?,?,?,?)");
        foreach($items as $it){
          $pid = $it['pid']; // nullable
          $nm  = $it['name'];
          $qty = $it['qty'];
          $rate= $it['rate'];
          $line= $it['line'];

          // bind product_id as int or null
          if($pid===null){
            $pidParam = null;
            $st->bind_param("iisddd", $invoice_id, $pidParam, $nm, $qty, $rate, $line);
          } else {
            $pidParam = (int)$pid;
            $st->bind_param("iisddd", $invoice_id, $pidParam, $nm, $qty, $rate, $line);
          }
          $st->execute();
        }
        $st->close();

        // record payment (optional)
        if($paid_amount>0.009 && $HAS_PAYMENTS && $HAS_ALLOC){
          if(!in_array($pay_mode, ['cash','upi','card','bank','other'], true)) $pay_mode='cash';
          $pdate = $invoice_date;

          $st = $conn->prepare("INSERT INTO customer_payments (store_id, customer_id, payment_date, amount, mode, note)
                                VALUES (?,?,?,?,?,?)");
          $pnote = "Invoice ".$invNo;
          $st->bind_param("iisdss", $store_id, $customer_id, $pdate, $paid_amount, $pay_mode, $pnote);
          $st->execute();
          $pay_id = (int)$st->insert_id;
          $st->close();

          $st = $conn->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount_applied)
                                VALUES (?,?,?)");
          $st->bind_param("iid", $pay_id, $invoice_id, $paid_amount);
          $st->execute();
          $st->close();
        }

        $conn->commit();
        $ok = "Invoice created: ".$invNo;

      }catch(Throwable $e){
        try{ $conn->rollback(); }catch(Throwable $ex){}
        $err = "Save error: ".$e->getMessage();
      }
    }
  }
}

/* =========================
   Recent invoices list
========================= */
$recent = [];
try{
  $st = $conn->prepare("
    SELECT i.id, i.invoice_no, i.invoice_date, i.grand_total, i.paid_total, i.due_total, i.status,
           c.name AS customer_name, c.mobile
    FROM invoices i
    LEFT JOIN customers c ON c.id=i.customer_id
    WHERE i.store_id=?
    ORDER BY i.id DESC
    LIMIT 20
  ");
  $st->bind_param("i",$store_id);
  $st->execute();
  $rs = $st->get_result();
  while($rs && ($r=$rs->fetch_assoc())) $recent[]=$r;
  $st->close();
}catch(Throwable $e){}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Orders / Billing</title>
<style>
:root{
  --bg:#f6faf7; --card:#ffffff; --text:#0f172a; --muted:#64748b; --line:#e5e7eb;
  --brand:#16a34a; --brand2:#22c55e; --warn:#f59e0b; --bad:#ef4444;
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
.grid{margin-top:16px;display:grid;grid-template-columns:520px minmax(0,1fr);gap:14px}
.card{background:var(--card);border:1px solid rgba(2,6,23,.08);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;isolation:isolate}
.card-h{padding:14px 14px 10px;border-bottom:1px solid rgba(2,6,23,.06);display:flex;align-items:center;justify-content:space-between;gap:10px}
.card-h h2{margin:0;font-size:14px}
.p{padding:14px}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
label{font-size:12px;color:var(--muted)}
input,select,textarea{width:100%;border:1px solid rgba(2,6,23,.12);border-radius:14px;padding:10px 11px;font-size:13px;outline:none;background:#fff}
textarea{min-height:60px;resize:vertical}
.row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.btn{border:none;cursor:pointer;border-radius:14px;padding:10px 12px;font-weight:900;font-size:13px}
.btn-primary{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;box-shadow:0 12px 24px rgba(22,163,74,.22)}
.btn-soft{background:rgba(22,163,74,.10);color:#0f172a;border:1px solid rgba(22,163,74,.20);text-decoration:none;display:inline-flex;align-items:center}
.btn-mini{padding:8px 10px;border-radius:12px;font-size:12px}
.msg{margin:10px 0 0;padding:10px 12px;border-radius:14px;font-size:13px;border:1px solid rgba(2,6,23,.08)}
.msg.ok{background:rgba(22,163,74,.09);border-color:rgba(22,163,74,.25)}
.msg.err{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.25)}
.table-wrap{overflow:auto;border-radius:16px}
.card table{width:100%;border-collapse:separate;border-spacing:0}
.card th,.card td{padding:11px 12px;border-bottom:1px solid rgba(2,6,23,.06);font-size:13px;white-space:nowrap;text-align:left}
.card th{background:linear-gradient(180deg,#ffffff,#fbfffc);font-size:12px;color:var(--muted);position:sticky;top:0;z-index:2}
.badge{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900;border:1px solid rgba(2,6,23,.10);background:rgba(2,6,23,.03)}
.badge.due{background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.25)}
.badge.paid{background:rgba(22,163,74,.10);border-color:rgba(22,163,74,.22)}
.badge.partial{background:rgba(59,130,246,.10);border-color:rgba(59,130,246,.22)}
.small{font-size:12px;color:var(--muted)}
.items{border:1px solid rgba(2,6,23,.10);border-radius:16px;overflow:hidden}
.items .head{display:grid;grid-template-columns: 1.3fr .6fr .6fr .4fr;gap:0;border-bottom:1px solid rgba(2,6,23,.06);background:#fbfffc}
.items .head div,.items .row{padding:10px 12px;font-size:12px;color:var(--muted)}
.items .r{display:grid;grid-template-columns: 1.3fr .6fr .6fr .4fr;gap:0;border-bottom:1px solid rgba(2,6,23,.06);align-items:center}
.items .r:last-child{border-bottom:none}
.items input,.items select{border-radius:12px;padding:9px 10px}
.items .rm{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.20);border-radius:12px;padding:9px 10px;cursor:pointer;font-weight:900}
@media(max-width:980px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div class="brand">
      <div class="logo"></div>
      <div>
        <p class="h1">Orders / Billing</p>
        <div class="sub">Create invoice • Partial payment • Due tracking</div>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
      <a class="btn btn-soft btn-mini" href="customers.php">Customers</a>
      <a class="btn btn-soft btn-mini" href="orders.php">Refresh</a>
    </div>
  </div>

  <?php if($ok): ?><div class="msg ok"><?=h($ok)?></div><?php endif; ?>
  <?php if($err): ?><div class="msg err"><?=h($err)?></div><?php endif; ?>

  <div class="grid">
    <!-- CREATE INVOICE -->
    <div class="card">
      <div class="card-h"><h2>Create Invoice</h2><span class="small"><?= $HAS_PRODUCTS ? "Products linked" : "Manual items" ?></span></div>
      <div class="p">
        <form method="post" id="invForm" autocomplete="off">
          <input type="hidden" name="create_invoice" value="1">

          <div class="field">
            <label>Customer *</label>
            <select name="customer_id" required>
              <option value="">Select customer</option>
              <?php foreach($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?> (<?= h($c['mobile']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row">
            <div class="field">
              <label>Invoice Date</label>
              <input name="invoice_date" value="<?= h(date('Y-m-d H:i:s')) ?>">
            </div>
            <div class="field">
              <label>Paid Amount</label>
              <input name="paid_amount" id="paid_amount" type="number" step="0.01" value="0">
            </div>
          </div>

          <div class="row">
            <div class="field">
              <label>Payment Mode</label>
              <select name="pay_mode">
                <option value="cash">Cash</option>
                <option value="upi">UPI</option>
                <option value="card">Card</option>
                <option value="bank">Bank</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="field">
              <label>Note</label>
              <input name="note" placeholder="Optional">
            </div>
          </div>

          <div class="field">
            <label>Items *</label>

            <div class="items" id="itemsBox">
              <div class="head">
                <div>Item</div><div>Qty</div><div>Rate</div><div></div>
              </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:10px;flex-wrap:wrap">
              <button type="button" class="btn btn-soft btn-mini" onclick="addRow()">+ Add Item</button>
              <div class="small">Grand Total: <b>₹<span id="gt">0.00</span></b> • Due: <b>₹<span id="due">0.00</span></b></div>
            </div>
          </div>

          <button class="btn btn-primary" type="submit">Save Invoice</button>
        </form>
      </div>
    </div>

    <!-- RECENT INVOICES -->
    <div class="card">
      <div class="card-h"><h2>Recent Invoices</h2><span class="small">Last 20</span></div>
      <div class="p" style="padding-top:10px">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Invoice</th>
                <th>Customer</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Due</th>
                <th>Status</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
            <?php if(!empty($recent)): ?>
              <?php foreach($recent as $r): ?>
                <?php
                  $st = strtolower((string)$r['status']);
                  $cls = ($st==='paid')?'paid':(($st==='partial')?'partial':'due');
                ?>
                <tr>
                  <td style="font-weight:900"><?=h($r['invoice_no'])?></td>
                  <td><?=h($r['customer_name'] ?? '-')?><div class="small"><?=h($r['mobile'] ?? '')?></div></td>
                  <td>₹<?=money($r['grand_total'])?></td>
                  <td>₹<?=money($r['paid_total'])?></td>
                  <td>₹<?=money($r['due_total'])?></td>
                  <td><span class="badge <?=h($cls)?>"><?=h($r['status'])?></span></td>
                  <td class="small"><?= $r['invoice_date'] ? date('d M Y, h:i A', strtotime($r['invoice_date'])) : '-' ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="7" class="small">No invoices yet.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="small" style="margin-top:10px">
          After you create invoices, open <b>Customers</b> page — totals will update automatically ✅
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const HAS_PRODUCTS = <?= $HAS_PRODUCTS ? 'true' : 'false' ?>;
const products = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;

function money2(n){ return (Math.round(n*100)/100).toFixed(2); }

function rowHtml(){
  if(HAS_PRODUCTS && products.length){
    const opts = ['<option value="">Select product</option>']
      .concat(products.map(p => `<option value="${p.id}" data-price="${p.price}">${escapeHtml(p.name)} (₹${money2(parseFloat(p.price||0))})</option>`));
    return `
      <div class="r">
        <div>
          <select name="pid[]" class="pid" onchange="syncProduct(this)">${opts.join('')}</select>
          <input name="iname[]" class="iname" placeholder="Item name (auto)" style="margin-top:6px" readonly />
        </div>
        <div><input name="qty[]" class="qty" type="number" step="0.01" value="1" oninput="calc()" /></div>
        <div><input name="rate[]" class="rate" type="number" step="0.01" value="0" oninput="calc()" /></div>
        <div><button type="button" class="rm" onclick="removeRow(this)">×</button></div>
      </div>
    `;
  }
  return `
    <div class="r">
      <div><input name="iname[]" class="iname" placeholder="Item name" /></div>
      <div><input name="qty[]" class="qty" type="number" step="0.01" value="1" oninput="calc()" /></div>
      <div><input name="rate[]" class="rate" type="number" step="0.01" value="0" oninput="calc()" /></div>
      <div><button type="button" class="rm" onclick="removeRow(this)">×</button></div>
    </div>
  `;
}

function escapeHtml(s){
  return String(s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

function addRow(){
  const box = document.getElementById('itemsBox');
  box.insertAdjacentHTML('beforeend', rowHtml());
  calc();
}
function removeRow(btn){
  btn.closest('.r').remove();
  calc();
}
function syncProduct(sel){
  const opt = sel.options[sel.selectedIndex];
  const price = parseFloat(opt.getAttribute('data-price') || '0');
  const wrap = sel.closest('.r');
  const nm = wrap.querySelector('.iname');
  const rate = wrap.querySelector('.rate');
  if(nm && !nm.value) nm.value = opt.textContent.replace(/\s*\(₹.*?\)\s*$/,'');
  if(rate) rate.value = money2(price);
  calc();
}

function calc(){
  let gt = 0;
  document.querySelectorAll('.r').forEach(r=>{
    const qty = parseFloat(r.querySelector('.qty')?.value || '0');
    const rate= parseFloat(r.querySelector('.rate')?.value || '0');
    if(qty>0 && rate>=0) gt += qty*rate;
  });
  const paid = parseFloat(document.getElementById('paid_amount')?.value || '0');
  const due = Math.max(0, gt - (paid>0?paid:0));
  document.getElementById('gt').textContent = money2(gt);
  document.getElementById('due').textContent = money2(due);
}
document.getElementById('paid_amount')?.addEventListener('input', calc);

// start with 2 rows
addRow(); addRow();
</script>
</body>
</html>