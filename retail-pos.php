<?php
// public_html/grocery/retail-pos.php
session_start();
require __DIR__ . "/config/db.php";

error_reporting(E_ALL & ~E_NOTICE);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$active = 'retail';
$pageTitle = "Retail POS";

/* ---------- helpers ---------- */
if(!function_exists('h')){
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function post($k, $d=''){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function money($n){ return "₹" . number_format((float)$n, 2); }

/* ---------- CSRF ---------- */
if(empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ---------- Flash ---------- */
$okMsg  = $_SESSION['okMsg'] ?? '';
$errMsg = $_SESSION['errMsg'] ?? '';
unset($_SESSION['okMsg'], $_SESSION['errMsg']);

function flash_ok($m){ $_SESSION['okMsg']=$m; header("Location: retail-pos.php"); exit; }
function flash_err($m){ $_SESSION['errMsg']=$m; header("Location: retail-pos.php"); exit; }

/* ---------- Load products (Active) ---------- */
$products = [];
$q = $conn->query("
  SELECT p.id, p.name,
         COALESCE(pp.retail_price, 0) AS retail_price
  FROM products p
  LEFT JOIN product_prices pp ON pp.product_id = p.id
  WHERE COALESCE(p.is_active,1)=1
  ORDER BY p.name ASC
  LIMIT 2000
");
while($r = $q->fetch_assoc()){
  $products[] = $r;
}
$q->free();

/* ---------- Handle sale ---------- */
if($_SERVER['REQUEST_METHOD'] === 'POST'){
  $token = post('csrf');
  if(!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)){
    flash_err("Security check failed. Please refresh and try again.");
  }

  $payment_mode = post('payment_mode', 'cash');
  if(!in_array($payment_mode, ['cash','upi','bank'], true)) $payment_mode = 'cash';

  $paid_amount = preg_replace('/[^0-9.\-]/', '', post('paid_amount','0'));
  if($paid_amount === '' || !is_numeric($paid_amount)) $paid_amount = 0;
  $paid_amount = (float)$paid_amount;

  $note = post('note','');
  $items_json = post('items_json','');

  $items = json_decode($items_json, true);
  if(!is_array($items) || count($items) === 0){
    flash_err("Add at least 1 item.");
  }

  // validate items & compute totals
  $clean = [];
  $total = 0.0;

  foreach($items as $it){
    $pid = (int)($it['product_id'] ?? 0);
    $qty = (float)($it['qty'] ?? 0);
    $price = (float)($it['price'] ?? 0);

    if($pid <= 0 || $qty <= 0) continue;
    if($price < 0) $price = 0;

    $line = round($qty * $price, 2);
    $total += $line;

    $clean[] = [
      'product_id' => $pid,
      'qty' => $qty,
      'price' => $price,
      'line_total' => $line
    ];
  }

  $total = round($total, 2);

  if(count($clean) === 0 || $total <= 0){
    flash_err("Invalid items / total. Please add items with qty and price.");
  }

  if($paid_amount < 0) $paid_amount = 0;
  if($paid_amount > $total) $paid_amount = $total;

  // transaction
  $conn->begin_transaction();
  try{
    // insert sale
    $sale_type = 'retail';
    $sale_date = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("
      INSERT INTO sales (sale_type, sale_date, total_amount, paid_amount, payment_mode, note)
      VALUES (?,?,?,?,?,?)
    ");
    $stmt->bind_param("ssddss", $sale_type, $sale_date, $total, $paid_amount, $payment_mode, $note);
    $stmt->execute();
    $sale_id = (int)$stmt->insert_id;
    $stmt->close();

    // insert items
    $stmtItem = $conn->prepare("
      INSERT INTO sale_items (sale_id, product_id, qty, price, line_total)
      VALUES (?,?,?,?,?)
    ");

    // stock movement (OUT)
    $stmtMove = $conn->prepare("
      INSERT INTO stock_movements
        (product_id, move_type, qty_in, qty_out, move_date, ref_table, ref_id, note, created_at)
      VALUES
        (?, 'sale', 0, ?, NOW(), 'sales', ?, ?, NOW())
    ");

    foreach($clean as $it){
      $pid   = (int)$it['product_id'];
      $qty   = (float)$it['qty'];
      $price = (float)$it['price'];
      $line  = (float)$it['line_total'];

      // sale_items
      $stmtItem->bind_param("iiddd", $sale_id, $pid, $qty, $price, $line);
      $stmtItem->execute();

      // stock_movements
      $mvNote = "Retail sale #".$sale_id;
      // product_id (i), qty_out (d), ref_id (i), note (s)
      $stmtMove->bind_param("idis", $pid, $qty, $sale_id, $mvNote);
      $stmtMove->execute();
    }

    $stmtItem->close();
    $stmtMove->close();

    $conn->commit();
    flash_ok("Retail sale saved! Invoice #".$sale_id." • Total ".money($total));
  } catch(Throwable $e){
    $conn->rollback();
    flash_err("Error saving sale: ".$e->getMessage());
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
  /* page-only small add-ons (keeps UniMart theme) */
  .pos-wrap{max-width:1200px;margin:0 auto;padding:18px 16px 30px}
  .pos-grid{display:grid;grid-template-columns: 1.1fr .9fr;gap:14px;align-items:start}
  @media (max-width: 980px){ .pos-grid{grid-template-columns:1fr} }

  .pos-card{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);overflow:hidden}
  .pos-h{padding:14px 14px 12px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:10px}
  .pos-h .ttl{font-weight:900}
  .pos-b{padding:14px}
  .row{display:grid;grid-template-columns: 1.6fr .6fr .7fr .3fr;gap:10px;align-items:end}
  @media (max-width: 720px){ .row{grid-template-columns:1fr 1fr} }

  label{display:block;font-size:12px;color:var(--muted);margin:0 0 6px}
  select,input,textarea{
    width:100%;border:1px solid var(--line);border-radius:12px;padding:10px 12px;outline:none;background:#fff;font-size:14px
  }
  textarea{min-height:70px;resize:vertical}

  .btn{
    border:0;border-radius:12px;padding:10px 14px;font-weight:900;cursor:pointer;transition:.15s;
    display:inline-flex;align-items:center;gap:8px
  }
  .btn-primary{background:linear-gradient(135deg,var(--g),var(--g2));color:#fff;box-shadow:0 10px 18px rgba(31,122,63,.22)}
  .btn-primary:hover{transform:translateY(-1px)}
  .btn-ghost{background:#fff;border:1px solid var(--line);color:var(--text)}
  .mini{font-size:12px;padding:8px 10px;border-radius:10px}

  .table-wrap{overflow:auto;max-height:55vh}
  table{width:100%;border-collapse:separate;border-spacing:0}
  th,td{padding:10px 10px;border-bottom:1px solid var(--line);white-space:nowrap;vertical-align:middle;font-size:14px}
  th{background:#f6fff6;font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;position:sticky;top:0;z-index:1}
  .right{text-align:right}
  .muted{color:var(--muted);font-size:12px}
  .sum{display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:10px}
  .sum .big{font-weight:900;font-size:18px}
  .alert{margin-bottom:12px;padding:12px 14px;border-radius:14px;border:1px solid var(--line);background:#fff;box-shadow:0 6px 14px rgba(2,6,23,.06);font-size:14px}
  .alert.ok{border-color:#bbf7d0;background:#f0fdf4}
  .alert.err{border-color:#fecaca;background:#fff1f2}
</style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . "/partials/sidebar.php"; ?>

  <div class="main">
    <?php include __DIR__ . "/partials/topbar.php"; ?>

    <div class="content">
      <div class="pos-wrap">

        <?php if($okMsg): ?><div class="alert ok"><?php echo h($okMsg); ?></div><?php endif; ?>
        <?php if($errMsg): ?><div class="alert err"><?php echo h($errMsg); ?></div><?php endif; ?>

        <div class="pos-grid">

          <!-- LEFT: Add items -->
          <div class="pos-card">
            <div class="pos-h">
              <div class="ttl">🧾 Retail POS • Fast Billing</div>
              <a class="btn btn-ghost mini" href="dashboard.php">Back</a>
            </div>

            <div class="pos-b">
              <div class="row">
                <div>
                  <label>Product</label>
                  <select id="p_product">
                    <option value="">Select product...</option>
                    <?php foreach($products as $p): ?>
                      <option value="<?php echo (int)$p['id']; ?>" data-price="<?php echo h($p['retail_price']); ?>">
                        <?php echo h($p['name']); ?> • <?php echo money($p['retail_price']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div>
                  <label>Qty</label>
                  <input id="p_qty" type="number" step="0.01" min="0" value="1">
                </div>

                <div>
                  <label>Price</label>
                  <input id="p_price" type="number" step="0.01" min="0" value="0">
                </div>

                <div>
                  <button class="btn btn-primary" style="width:100%;margin-top:22px" type="button" onclick="addItem()">Add</button>
                </div>
              </div>

              <div class="table-wrap" style="margin-top:14px">
                <table>
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th class="right">Qty</th>
                      <th class="right">Price</th>
                      <th class="right">Total</th>
                      <th class="right">Remove</th>
                    </tr>
                  </thead>
                  <tbody id="cartBody">
                    <tr><td colspan="5" class="muted" style="padding:16px">No items yet. Add product to cart.</td></tr>
                  </tbody>
                </table>
              </div>

              <div class="sum">
                <div class="muted">Items: <b id="itemCount">0</b></div>
                <div class="big">Grand Total: <span id="grandTotal"><?php echo money(0); ?></span></div>
              </div>
            </div>
          </div>

          <!-- RIGHT: Payment + Save -->
          <div class="pos-card">
            <div class="pos-h">
              <div class="ttl">💳 Payment</div>
              <a class="btn btn-ghost mini" href="stock-ledger.php">Stock Ledger</a>
            </div>
            <div class="pos-b">
              <form method="post" action="retail-pos.php" onsubmit="return beforeSubmit();">
                <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                <input type="hidden" name="items_json" id="items_json" value="">

                <div style="margin-bottom:10px">
                  <label>Payment Mode</label>
                  <select name="payment_mode" id="payment_mode">
                    <option value="cash">Cash</option>
                    <option value="upi">UPI</option>
                    <option value="bank">Bank</option>
                  </select>
                </div>

                <div style="margin-bottom:10px">
                  <label>Paid Amount</label>
                  <input name="paid_amount" id="paid_amount" type="number" step="0.01" min="0" value="0">
                  <div class="muted" style="margin-top:6px">Tip: keep paid = total for fully paid invoice.</div>
                </div>

                <div style="margin-bottom:10px">
                  <label>Note (optional)</label>
                  <textarea name="note" placeholder="Customer / remark..."></textarea>
                </div>

                <div class="sum" style="margin-top:12px">
                  <button class="btn btn-ghost" type="button" onclick="fillPaid()">Paid = Total</button>
                  <button class="btn btn-primary" type="submit">Save Invoice</button>
                </div>

                <div class="muted" style="margin-top:10px">
                  After save: Dashboard updates + Stock deducted + Ledger shows OUT entry.
                </div>
              </form>
            </div>
          </div>

        </div>

      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>
let cart = [];

const pProduct = document.getElementById('p_product');
const pQty     = document.getElementById('p_qty');
const pPrice   = document.getElementById('p_price');

pProduct.addEventListener('change', () => {
  const opt = pProduct.options[pProduct.selectedIndex];
  const price = opt ? opt.getAttribute('data-price') : "0";
  if(price !== null && price !== "") pPrice.value = price;
});

function money(n){
  n = Number(n || 0);
  return "₹" + n.toFixed(2);
}

function renderCart(){
  const body = document.getElementById('cartBody');
  const itemCount = document.getElementById('itemCount');
  const grandTotalEl = document.getElementById('grandTotal');

  if(cart.length === 0){
    body.innerHTML = '<tr><td colspan="5" class="muted" style="padding:16px">No items yet. Add product to cart.</td></tr>';
    itemCount.textContent = "0";
    grandTotalEl.textContent = money(0);
    return;
  }

  let html = '';
  let total = 0;
  cart.forEach((it, idx) => {
    total += it.line_total;
    html += `
      <tr>
        <td><b>${escapeHtml(it.name)}</b></td>
        <td class="right">${it.qty}</td>
        <td class="right">${money(it.price)}</td>
        <td class="right"><b>${money(it.line_total)}</b></td>
        <td class="right"><button type="button" class="btn btn-ghost mini" onclick="removeItem(${idx})">✖</button></td>
      </tr>
    `;
  });

  body.innerHTML = html;
  itemCount.textContent = String(cart.length);
  grandTotalEl.textContent = money(total);

  // auto update paid suggestion if paid is 0
  const paid = document.getElementById('paid_amount');
  if(Number(paid.value || 0) === 0){
    paid.value = total.toFixed(2);
  }
}

function addItem(){
  const pid = Number(pProduct.value || 0);
  if(!pid){
    alert("Select a product.");
    return;
  }
  const opt = pProduct.options[pProduct.selectedIndex];
  const name = opt ? opt.textContent.trim() : "Item";

  const qty = Number(pQty.value || 0);
  const price = Number(pPrice.value || 0);

  if(qty <= 0){
    alert("Qty must be > 0");
    return;
  }
  if(price < 0){
    alert("Price cannot be negative.");
    return;
  }

  // merge same product
  const existing = cart.find(x => x.product_id === pid && Number(x.price) === Number(price));
  if(existing){
    existing.qty = Number(existing.qty) + qty;
    existing.line_total = Number(existing.qty) * Number(existing.price);
  }else{
    cart.push({
      product_id: pid,
      name,
      qty,
      price,
      line_total: qty * price
    });
  }

  renderCart();
}

function removeItem(i){
  cart.splice(i, 1);
  renderCart();
}

function fillPaid(){
  let total = cart.reduce((s,it)=> s + Number(it.line_total||0), 0);
  document.getElementById('paid_amount').value = total.toFixed(2);
}

function beforeSubmit(){
  if(cart.length === 0){
    alert("Add at least 1 item.");
    return false;
  }
  // pack minimal payload for server
  const payload = cart.map(it => ({
    product_id: it.product_id,
    qty: it.qty,
    price: it.price
  }));
  document.getElementById('items_json').value = JSON.stringify(payload);
  return true;
}

function escapeHtml(str){
  return String(str).replace(/[&<>"']/g, (m) => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[m]));
}

renderCart();
</script>
</body>
</html>