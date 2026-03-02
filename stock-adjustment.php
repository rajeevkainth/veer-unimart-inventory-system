<?php
// public_html/grocery/stock-adjustment.php
ob_start();

session_start();
require_once __DIR__ . '/config/db.php';

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// for sidebar active
$active = 'stock_adjustment';
$pageTitle = "Stock Adjustment";

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
  http_response_code(500);
  die("DB connection failed.");
}

if(!function_exists('h')){
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

function safe_prepare(mysqli $conn, string $sql){
  $stmt = $conn->prepare($sql);
  return $stmt ?: null;
}

// MariaDB-safe SHOW helpers (NO '?')
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
  if(!table_exists($conn, $table)) return $cache[$k]=false;

  $escCol = $conn->real_escape_string($col);
  $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$escCol}'");
  if(!$res) return $cache[$k]=false;
  $has = ($res->num_rows > 0);
  $res->free();
  return $cache[$k]=$has;
}

/* =========================
   Ensure required tables
========================= */
if(!table_exists($conn,'products')) die("Missing table: products");
$hasMoves = table_exists($conn,'stock_movements');
if(!$hasMoves) die("Missing table: stock_movements");

/* =========================
   Detect columns
========================= */
$P_NAME = table_has_col($conn,'products','name') ? 'name' : (table_has_col($conn,'products','product_name') ? 'product_name' : 'name');
$P_SKU  = table_has_col($conn,'products','sku') ? 'sku' : (table_has_col($conn,'products','barcode') ? 'barcode' : (table_has_col($conn,'products','code') ? 'code' : ''));

$SM_DATE = table_has_col($conn,'stock_movements','created_at') ? 'created_at' : (table_has_col($conn,'stock_movements','date') ? 'date' : 'created_at');
$SM_HAS_QTY    = table_has_col($conn,'stock_movements','qty');
$SM_HAS_QTYIN  = table_has_col($conn,'stock_movements','qty_in');
$SM_HAS_QTYOUT = table_has_col($conn,'stock_movements','qty_out');

$SM_TYPECOL = '';
if(table_has_col($conn,'stock_movements','movement_type')) $SM_TYPECOL = 'movement_type';
else if(table_has_col($conn,'stock_movements','type')) $SM_TYPECOL = 'type';

$SM_NOTECOL = '';
if(table_has_col($conn,'stock_movements','note')) $SM_NOTECOL = 'note';
else if(table_has_col($conn,'stock_movements','remarks')) $SM_NOTECOL = 'remarks';
else if(table_has_col($conn,'stock_movements','notes')) $SM_NOTECOL = 'notes';

$SM_HAS_REF_TYPE = table_has_col($conn,'stock_movements','ref_type');
$SM_HAS_REF_ID   = table_has_col($conn,'stock_movements','ref_id');

// Build delta expression for stock calc
if($SM_HAS_QTYIN || $SM_HAS_QTYOUT){
  $deltaExpr = "(" . ($SM_HAS_QTYIN?"COALESCE(qty_in,0)":"0") . " - " . ($SM_HAS_QTYOUT?"COALESCE(qty_out,0)":"0") . ")";
} else if($SM_HAS_QTY && $SM_TYPECOL!==''){
  $deltaExpr = "CASE
    WHEN LOWER(TRIM(`$SM_TYPECOL`)) IN ('in','stock_in','purchase','add','credit','return') THEN COALESCE(qty,0)
    WHEN LOWER(TRIM(`$SM_TYPECOL`)) IN ('out','stock_out','sale','remove','debit','damage','expired') THEN -COALESCE(qty,0)
    ELSE COALESCE(qty,0)
  END";
} else if($SM_HAS_QTY){
  $deltaExpr = "COALESCE(qty,0)";
} else {
  die("stock_movements missing qty columns.");
}

/* =========================
   Load products
========================= */
$products = [];
$res = $conn->query(
  ($P_SKU!=='')
  ? "SELECT id, `$P_NAME` AS pname, `$P_SKU` AS psku FROM products ORDER BY `$P_NAME` ASC LIMIT 1000"
  : "SELECT id, `$P_NAME` AS pname, '' AS psku FROM products ORDER BY `$P_NAME` ASC LIMIT 1000"
);
if($res){
  while($row=$res->fetch_assoc()) $products[]=$row;
  $res->free();
}

/* =========================
   Current stock calculator
========================= */
function get_current_stock(mysqli $conn, int $product_id, string $deltaExpr): float{
  $stmt = $conn->prepare("SELECT COALESCE(SUM($deltaExpr),0) AS bal FROM stock_movements WHERE product_id=?");
  if(!$stmt) return 0.0;
  $stmt->bind_param("i", $product_id);
  $stmt->execute();
  $stmt->bind_result($bal);
  $stmt->fetch();
  $stmt->close();
  return (float)$bal;
}

/* =========================
   Handle POST (Save Adjustment)
========================= */
$okMsg = $errMsg = '';
$selected_product_id = 0;

if($_SERVER['REQUEST_METHOD']==='POST'){
  $product_id = (int)($_POST['product_id'] ?? 0);
  $selected_product_id = $product_id;

  $action = trim((string)($_POST['action'] ?? 'in'));  // in/out
  $qty    = (float)($_POST['qty'] ?? 0);
  $reason = trim((string)($_POST['reason'] ?? 'Correction'));
  $note   = trim((string)($_POST['note'] ?? ''));
  $at     = trim((string)($_POST['at'] ?? ''));

  if($product_id<=0) $errMsg = "Select a product.";
  else if($qty<=0) $errMsg = "Quantity must be greater than 0.";
  else if($action!=='in' && $action!=='out') $errMsg = "Invalid action.";

  // Validate product exists
  if($errMsg===''){
    $stmt = safe_prepare($conn, "SELECT id FROM products WHERE id=? LIMIT 1");
    if(!$stmt) $errMsg="DB error (product check).";
    else{
      $stmt->bind_param("i",$product_id);
      $stmt->execute();
      $stmt->store_result();
      if($stmt->num_rows<=0) $errMsg="Product not found.";
      $stmt->close();
    }
  }

  // date/time
  if($errMsg===''){
    if($at===''){
      $at = date('Y-m-d H:i:s');
    } else {
      $at = str_replace('T',' ',$at);
      if(!preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}(:\d{2})?$/',$at)){
        $errMsg = "Invalid date/time.";
      } else {
        if(strlen($at)===16) $at .= ":00";
      }
    }
  }

  // Prevent negative stock on OUT
  if($errMsg==='' && $action==='out'){
    $current = get_current_stock($conn, $product_id, $deltaExpr);
    if($qty > $current){
      $errMsg = "Cannot deduct $qty. Current stock is only ".number_format($current,2).".";
    }
  }

  // Insert note builder
  if($errMsg===''){
    $finalNote = trim($reason.($note!=='' ? " — ".$note : ""));
    $refType = 'adjustment';
    $typeVal = ($action==='in') ? 'in' : 'out';

    if($SM_HAS_QTYIN || $SM_HAS_QTYOUT){
      $qin  = ($action==='in')  ? $qty : 0;
      $qout = ($action==='out') ? $qty : 0;

      $cols = "product_id, `$SM_DATE`";
      $vals = "?, ?";
      $types = "is";
      $bind = [$product_id, $at];

      if($SM_HAS_QTYIN){  $cols.=", qty_in";  $vals.=", ?"; $types.="d"; $bind[]=$qin; }
      if($SM_HAS_QTYOUT){ $cols.=", qty_out"; $vals.=", ?"; $types.="d"; $bind[]=$qout; }
      if($SM_TYPECOL!==''){ $cols.=", `$SM_TYPECOL`"; $vals.=", ?"; $types.="s"; $bind[]=$typeVal; }
      if($SM_NOTECOL!==''){ $cols.=", `$SM_NOTECOL`"; $vals.=", ?"; $types.="s"; $bind[]=$finalNote; }
      if($SM_HAS_REF_TYPE){ $cols.=", ref_type"; $vals.=", ?"; $types.="s"; $bind[]=$refType; }

      $sql = "INSERT INTO stock_movements ($cols) VALUES ($vals)";
      $stmt = safe_prepare($conn, $sql);
      if(!$stmt) $errMsg = "DB error (prepare insert).";
      else{
        $stmt->bind_param($types, ...$bind);
        if(!$stmt->execute()){
          $errMsg = "Insert failed: ".$stmt->error;
        } else {
          $okMsg = "Adjustment saved successfully.";
        }
        $stmt->close();
      }

    } else {
      if(!$SM_HAS_QTY){
        $errMsg = "stock_movements missing qty columns.";
      } else {
        $qtySigned = ($action==='in') ? $qty : (-1*$qty);

        $cols = "product_id, `$SM_DATE`, qty";
        $vals = "?, ?, ?";
        $types = "isd";
        $bind = [$product_id, $at, $qtySigned];

        if($SM_TYPECOL!==''){ $cols.=", `$SM_TYPECOL`"; $vals.=", ?"; $types.="s"; $bind[]=$typeVal; }
        if($SM_NOTECOL!==''){ $cols.=", `$SM_NOTECOL`"; $vals.=", ?"; $types.="s"; $bind[]=$finalNote; }
        if($SM_HAS_REF_TYPE){ $cols.=", ref_type"; $vals.=", ?"; $types.="s"; $bind[]=$refType; }

        $sql = "INSERT INTO stock_movements ($cols) VALUES ($vals)";
        $stmt = safe_prepare($conn, $sql);
        if(!$stmt) $errMsg = "DB error (prepare insert).";
        else{
          $stmt->bind_param($types, ...$bind);
          if(!$stmt->execute()){
            $errMsg = "Insert failed: ".$stmt->error;
          } else {
            $okMsg = "Adjustment saved successfully.";
          }
          $stmt->close();
        }
      }
    }
  }
}

/* =========================
   Recent entries
========================= */
$recent = [];
$noteSel = ($SM_NOTECOL!=='') ? "sm.`$SM_NOTECOL` AS note" : "'' AS note";
$typeSel = ($SM_TYPECOL!=='') ? "sm.`$SM_TYPECOL` AS type" : "'' AS type";

$sql = "SELECT sm.id, sm.product_id, sm.`$SM_DATE` AS mdate,
               ".($SM_HAS_QTY ? "sm.qty AS qty" : "0 AS qty").",
               ".($SM_HAS_QTYIN ? "sm.qty_in AS qty_in" : "0 AS qty_in").",
               ".($SM_HAS_QTYOUT ? "sm.qty_out AS qty_out" : "0 AS qty_out").",
               $typeSel, $noteSel,
               p.`$P_NAME` AS pname
        FROM stock_movements sm
        LEFT JOIN products p ON p.id = sm.product_id
        ORDER BY sm.`$SM_DATE` DESC, sm.id DESC
        LIMIT 30";

$res = $conn->query($sql);
if($res){
  while($r=$res->fetch_assoc()) $recent[]=$r;
  $res->free();
}

// If product selected, compute current stock
$currentStock = null;
if($selected_product_id > 0){
  $currentStock = get_current_stock($conn, $selected_product_id, $deltaExpr);
}

ob_end_flush();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($pageTitle); ?> • UniMart</title>
<link rel="stylesheet" href="assets/css/app.css">
<style>
/* keep your nice UI but inside UniMart app */
.page-wrap{max-width:1100px; margin:18px auto; padding:0 14px;}
.cardLocal{background:#fff;border:1px solid rgba(15,23,42,.06);box-shadow:var(--shadow);border-radius:18px;overflow:hidden;}
.hd{padding:14px 16px;border-bottom:1px solid var(--line);background: linear-gradient(180deg, rgba(34,197,94,.10), rgba(255,255,255,0));}
.form{padding:14px 16px;}
.grid{display:grid;grid-template-columns: 1.2fr 160px 160px 1fr;gap:10px;}
@media(max-width:900px){.grid{grid-template-columns:1fr 1fr;}}
label{font-size:12px;color:var(--muted);display:block;margin-bottom:6px;}
input, select, textarea{width:100%;padding:10px 11px;border:1px solid var(--line);border-radius:12px;outline:none;background:#fff;font-size:14px;}
textarea{min-height:44px;resize:vertical}
.actions{display:flex;gap:10px;align-items:center;justify-content:flex-end;margin-top:10px;flex-wrap:wrap;}
.msg{padding:10px 12px;border-radius:12px;margin:10px 16px 0;font-weight:800;}
.ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.25);color:#0f5132;}
.err{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.25);color:#7a1b1b;}
.tbl{overflow:auto;border-top:1px solid var(--line);}
table{width:100%;border-collapse:collapse;min-width:920px;}
th,td{padding:12px;border-bottom:1px solid var(--line);font-size:14px;text-align:left;vertical-align:top;}
th{font-size:12px;color:var(--muted);background:#f7fff9;position:sticky;top:0;z-index:1;}
.badge{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:900;font-size:12px}
.in{background:#dcfce7;color:#166534}
.out{background:#fee2e2;color:#991b1b}
.muted{color:var(--muted);font-size:13px;}
.right{text-align:right;}
.pbox{border:1px solid rgba(34,197,94,.18);background:linear-gradient(180deg, rgba(34,197,94,.10), rgba(255,255,255,0));border-radius:16px;padding:12px 12px;}
.pbox .k{font-size:12px;color:var(--muted);}
.pbox .v{font-size:18px;font-weight:900;margin-top:6px;}
.split{display:grid;grid-template-columns: 1fr 320px;gap:12px;align-items:start;}
@media(max-width:900px){.split{grid-template-columns:1fr;}}
.small{font-size:12px;color:var(--muted);}
</style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . "/partials/sidebar.php"; ?>
  <div class="main">
    <?php include __DIR__ . "/partials/topbar.php"; ?>

    <div class="content">
      <div class="page-wrap">

        <div class="cardLocal">
          <div class="hd">
            <b>Stock Adjustment</b>
            <div class="small" style="margin-top:6px;">Add/Deduct stock with reason + note. Negative stock blocked on OUT.</div>
          </div>

          <?php if($okMsg): ?><div class="msg ok"><?= h($okMsg) ?></div><?php endif; ?>
          <?php if($errMsg): ?><div class="msg err"><?= h($errMsg) ?></div><?php endif; ?>

          <div class="form">
            <div class="split">
              <form method="post" id="adjForm">
                <div class="grid">
                  <div>
                    <label>Product Search</label>
                    <input type="text" id="pSearch" placeholder="Type product name / SKU">
                    <div class="small" style="margin-top:6px;">Start typing to filter the dropdown.</div>
                  </div>

                  <div>
                    <label>Action</label>
                    <select name="action" id="action">
                      <option value="in">Add Stock (IN)</option>
                      <option value="out">Deduct Stock (OUT)</option>
                    </select>
                  </div>

                  <div>
                    <label>Quantity</label>
                    <input type="number" name="qty" id="qty" step="0.01" min="0.01" required placeholder="e.g. 5">
                  </div>

                  <div>
                    <label>Date/Time (optional)</label>
                    <input type="datetime-local" name="at" id="at">
                  </div>

                  <div>
                    <label>Product</label>
                    <select name="product_id" id="product_id" required>
                      <option value="">— Select Product —</option>
                      <?php foreach($products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= ($selected_product_id==(int)$p['id']?'selected':'') ?>
                                data-name="<?= h($p['pname']) ?>" data-sku="<?= h($p['psku']) ?>">
                          <?= h($p['pname']) ?><?= (!empty($p['psku'])?' • '.h($p['psku']):'') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div>
                    <label>Reason</label>
                    <select name="reason" id="reason">
                      <option>Correction</option>
                      <option>Damage</option>
                      <option>Expired</option>
                      <option>Purchase</option>
                      <option>Return</option>
                      <option>Other</option>
                    </select>
                  </div>

                  <div style="grid-column:1/-1;">
                    <label>Note (optional)</label>
                    <textarea name="note" id="note" placeholder="Example: packet torn / shelf expired / manual recount / supplier purchase"></textarea>
                  </div>
                </div>

                <div class="actions">
                  <button class="btn btn-primary" type="submit">Save Adjustment</button>
                  <a class="btn btn-ghost" href="stock-ledger.php">Stock Ledger</a>
                </div>
              </form>

              <div class="pbox" id="previewBox">
                <div class="k">Preview</div>

                <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
                  <a class="btn btn-ghost" id="openLedgerBtn" href="stock-ledger.php" style="pointer-events:none; opacity:.55;">
                    Open Ledger
                  </a>
                </div>

                <div class="v" id="pvTitle">Select product</div>
                <div class="small" id="pvMeta" style="margin-top:6px;">—</div>
                <hr style="border:none;border-top:1px solid rgba(15,23,42,.08);margin:10px 0;">
                <div class="small"><b>Current Stock:</b> <span id="pvStock"><?= $currentStock===null?'—':number_format($currentStock,2) ?></span></div>
                <div class="small" style="margin-top:6px;"><b>Action:</b> <span id="pvAction">—</span></div>
                <div class="small" style="margin-top:6px;"><b>Qty:</b> <span id="pvQty">—</span></div>
                <div class="small" style="margin-top:6px;"><b>Reason:</b> <span id="pvReason">—</span></div>
                <div class="small" style="margin-top:6px;"><b>Final Note:</b> <span id="pvNote">—</span></div>
                <div class="small" style="margin-top:10px;color:#7c2d12;" id="pvWarn"></div>
                <div class="small" style="margin-top:10px;color:#0f5132;" id="pvOk"></div>
              </div>
            </div>
          </div>

          <div class="hd" style="border-top:1px solid var(--line);">
            <b>Recent Entries</b>
            <div class="small" style="margin-top:6px;">Last 30 stock movement rows.</div>
          </div>

          <div class="tbl">
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Product</th>
                  <th>Movement</th>
                  <th class="right">Qty In</th>
                  <th class="right">Qty Out</th>
                  <th>Note</th>
                </tr>
              </thead>
              <tbody>
                <?php if(empty($recent)): ?>
                  <tr><td colspan="6" class="muted">No entries yet.</td></tr>
                <?php else: ?>
                  <?php foreach($recent as $r):
                    $delta = 0.0; $qin=0.0; $qout=0.0;
                    if($SM_HAS_QTYIN || $SM_HAS_QTYOUT){
                      $qin  = (float)($r['qty_in'] ?? 0);
                      $qout = (float)($r['qty_out'] ?? 0);
                      $delta = $qin - $qout;
                    } else {
                      $delta = (float)($r['qty'] ?? 0);
                      if($delta >= 0) $qin = $delta; else $qout = abs($delta);
                    }
                    $isIn = ($delta >= 0);
                  ?>
                    <tr>
                      <td><?= h(date('d M Y, h:i A', strtotime($r['mdate']))) ?></td>
                      <td><?= h($r['pname'] ?? '') ?></td>
                      <td><span class="badge <?= $isIn?'in':'out' ?>"><?= $isIn?'IN':'OUT' ?></span></td>
                      <td class="right"><?= $qin > 0 ? number_format($qin,2) : '-' ?></td>
                      <td class="right"><?= $qout > 0 ? number_format($qout,2) : '-' ?></td>
                      <td class="muted"><?= h($r['note'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        </div><!-- /cardLocal -->

      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>
const pSearch = document.getElementById('pSearch');
const select  = document.getElementById('product_id');
const action  = document.getElementById('action');
const qty     = document.getElementById('qty');
const reason  = document.getElementById('reason');
const note    = document.getElementById('note');

const pvTitle = document.getElementById('pvTitle');
const pvMeta  = document.getElementById('pvMeta');
const pvAction= document.getElementById('pvAction');
const pvQty   = document.getElementById('pvQty');
const pvReason= document.getElementById('pvReason');
const pvNote  = document.getElementById('pvNote');
const pvWarn  = document.getElementById('pvWarn');
const pvOk    = document.getElementById('pvOk');

const openLedgerBtn = document.getElementById('openLedgerBtn');
const stockVal = parseFloat((document.getElementById('pvStock').innerText || '').replace(/,/g,'')) || null;

function updatePreview(){
  const opt = select.options[select.selectedIndex];
  const pname = opt ? (opt.getAttribute('data-name') || opt.text) : '';
  const psku  = opt ? (opt.getAttribute('data-sku') || '') : '';

  pvTitle.innerText = pname && pname!=='— Select Product —' ? pname : 'Select product';
  pvMeta.innerText  = psku ? ('SKU: ' + psku) : '—';

  const a = action.value === 'in' ? 'Add Stock (IN)' : 'Deduct Stock (OUT)';
  pvAction.innerText = a;
  pvQty.innerText = qty.value ? qty.value : '—';
  pvReason.innerText = reason.value || '—';

  const finalNote = (reason.value || '') + (note.value.trim() ? (' — ' + note.value.trim()) : '');
  pvNote.innerText = finalNote || '—';

  pvWarn.innerText = '';
  pvOk.innerText = '';

  if(action.value==='out' && qty.value && stockVal !== null){
    const q = parseFloat(qty.value);
    if(q > stockVal){
      pvWarn.innerText = 'Warning: Qty is greater than current stock. Server will block this.';
    } else {
      pvOk.innerText = 'OK: Stock will remain non-negative.';
    }
  }

  // open ledger button for product
  const pid = select.value;
  if(pid){
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth()+1).padStart(2,'0');
    const dd = String(now.getDate()).padStart(2,'0');
    const from = `${yyyy}-${mm}-01`;
    const to   = `${yyyy}-${mm}-${dd}`;

    openLedgerBtn.href = `stock-ledger.php?product_id=${encodeURIComponent(pid)}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
    openLedgerBtn.style.pointerEvents = 'auto';
    openLedgerBtn.style.opacity = '1';
  } else {
    openLedgerBtn.href = 'stock-ledger.php';
    openLedgerBtn.style.pointerEvents = 'none';
    openLedgerBtn.style.opacity = '.55';
  }
}

function filterProducts(){
  const q = (pSearch.value || '').toLowerCase().trim();
  for(let i=0; i<select.options.length; i++){
    const opt = select.options[i];
    if(!opt.value) continue;
    const name = (opt.getAttribute('data-name') || opt.text || '').toLowerCase();
    const sku  = (opt.getAttribute('data-sku') || '').toLowerCase();
    opt.hidden = (q && !(name.includes(q) || sku.includes(q)));
  }
}

[pSearch, select, action, qty, reason, note].forEach(el=>{
  el.addEventListener('input', ()=>{ filterProducts(); updatePreview(); });
  el.addEventListener('change', ()=>{ filterProducts(); updatePreview(); });
});

filterProducts();
updatePreview();
</script>
</body>
</html>