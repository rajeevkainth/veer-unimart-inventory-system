<?php
// public_html/grocery/invoice-view.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

require_once __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$store_id = (int)($_SESSION['store_id'] ?? 1);

/* =========================
   COMPANY SETTINGS (edit once)
========================= */
$COMPANY_NAME    = "UniMart International";
$COMPANY_TAGLINE = "Inventory Cloud • Invoice";
$COMPANY_LINE1   = "unimartinternational.com";
$COMPANY_LINE2   = ""; // optional: address / phone
$LOGO_PATH       = "assets/img/logo.png"; // upload permanent logo here
$CURRENCY        = "₹"; // change to "€" if you want

/* =========================
   Helpers
========================= */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2); }

function table_exists(mysqli $conn, string $table): bool {
  try{
    $t = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
  }catch(Throwable $e){ return false; }
}

if(!table_exists($conn,'invoices') || !table_exists($conn,'invoice_items') || !table_exists($conn,'customers')){
  die("Required tables missing.");
}

$inv_id = 0;
if(isset($_GET['id']) && ctype_digit($_GET['id'])) $inv_id = (int)$_GET['id'];
if($inv_id<=0) die("Invoice id missing");

/* =========================
   Fetch invoice + customer
========================= */
$stmt = $conn->prepare("
  SELECT i.*, c.name AS customer_name, c.mobile, c.address, c.email
  FROM invoices i
  LEFT JOIN customers c ON c.id=i.customer_id
  WHERE i.id=? AND i.store_id=?
  LIMIT 1
");
$stmt->bind_param("ii", $inv_id, $store_id);
$stmt->execute();
$res = $stmt->get_result();
$inv = $res ? $res->fetch_assoc() : null;
$stmt->close();

if(!$inv) die("Invoice not found");

/* =========================
   Fetch items
========================= */
$items = [];
$stmt = $conn->prepare("
  SELECT item_name, qty, rate, line_total
  FROM invoice_items
  WHERE invoice_id=?
  ORDER BY id ASC
");
$stmt->bind_param("i", $inv_id);
$stmt->execute();
$rs = $stmt->get_result();
while($rs && ($r=$rs->fetch_assoc())) $items[] = $r;
$stmt->close();

/* status badge */
$st = strtolower((string)($inv['status'] ?? 'due'));
$badge = ($st==='paid') ? 'paid' : (($st==='partial') ? 'partial' : 'due');

/* note fallback (note OR notes) */
$noteTxt = $inv['note'] ?? ($inv['notes'] ?? '');
$noteTxt = trim((string)$noteTxt);

/* logo existence (avoid broken image) */
$logoAbs = __DIR__ . '/' . ltrim($LOGO_PATH,'/');
$hasLogo = is_file($logoAbs);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice <?=h($inv['invoice_no'] ?? $inv_id)?></title>

<style>
:root{
  --bg:#f6faf7; --card:#ffffff; --text:#0f172a; --muted:#64748b; --line:#e5e7eb;
  --brand:#16a34a; --brand2:#22c55e; --warn:#f59e0b; --info:#3b82f6;
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
.wrap{max-width:1050px;margin:0 auto;padding:18px 16px 32px}

/* top bar */
.topbar{
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:14px 14px;border:1px solid rgba(22,163,74,.18);
  background:linear-gradient(135deg, rgba(22,163,74,.10), rgba(34,197,94,.06));
  border-radius:20px; box-shadow:var(--shadow2);
  position:sticky;top:10px;z-index:30;
}
.brand{display:flex;align-items:center;gap:10px;min-width:0}
.logoDot{
  width:38px;height:38px;border-radius:14px;
  background:linear-gradient(135deg,var(--brand),var(--brand2));
  box-shadow:0 10px 24px rgba(22,163,74,.25);
  flex:0 0 auto;
}
.h1{font-size:18px;font-weight:900;margin:0;line-height:1.1}
.sub{font-size:12px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:520px}
.btn{border:none;cursor:pointer;border-radius:14px;padding:10px 12px;font-weight:900;font-size:13px}
.btn-soft{background:rgba(22,163,74,.10);color:#0f172a;border:1px solid rgba(22,163,74,.20);text-decoration:none;display:inline-flex;align-items:center}
.btn-primary{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;box-shadow:0 12px 24px rgba(22,163,74,.22)}
.btn-mini{padding:8px 10px;border-radius:12px;font-size:12px}

/* card */
.card{margin-top:14px;background:var(--card);border:1px solid rgba(2,6,23,.08);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
.card-h{padding:14px;border-bottom:1px solid rgba(2,6,23,.06);display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.p{padding:14px}
.grid2{display:grid;grid-template-columns:1.2fr .8fr;gap:14px}
@media(max-width:980px){.grid2{grid-template-columns:1fr}}
.small{font-size:12px;color:var(--muted)}
.badge{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900;border:1px solid rgba(2,6,23,.10);background:rgba(2,6,23,.03);text-transform:capitalize}
.badge.due{background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.25)}
.badge.paid{background:rgba(22,163,74,.10);border-color:rgba(22,163,74,.22)}
.badge.partial{background:rgba(59,130,246,.10);border-color:rgba(59,130,246,.22)}

.table-wrap{overflow:auto;border-radius:16px;border:1px solid rgba(2,6,23,.06)}
table{width:100%;border-collapse:separate;border-spacing:0}
th,td{padding:11px 12px;border-bottom:1px solid rgba(2,6,23,.06);font-size:13px;text-align:left}
th{background:linear-gradient(180deg,#ffffff,#fbfffc);font-size:12px;color:var(--muted);position:sticky;top:0}
td.r,th.r{text-align:right}
td.r{font-variant-numeric: tabular-nums}

.totalBox{
  border:1px solid rgba(2,6,23,.08);
  border-radius:16px; padding:12px;
  background:linear-gradient(180deg,#fff,#fbfffc);
}
.totalRow{
  display:flex;justify-content:space-between;gap:10px;
  margin:8px 0; align-items:center;
}
.totalRow .label{font-size:12px;color:var(--muted)}
.totalRow .val{
  font-weight:950; font-size:14px;
  font-variant-numeric: tabular-nums;
  text-align:right;
}
hr{border:none;border-top:1px solid rgba(2,6,23,.08);margin:10px 0}

/* PRINT HEADER (Professional) */
.printHead{
  display:none;
  padding:0 0 10px 0;
  border-bottom:2px solid rgba(2,6,23,.12);
  margin-bottom:12px;
}
.printLogo{
  width:100%;
  display:flex;justify-content:center;align-items:center;
  padding:6px 0 10px 0;
}
.printLogo img{
  width:auto;              /* IMPORTANT: stop stretching */
  max-width:260px;         /* logo size like supplier invoice */
  max-height:80px;         /* keep height controlled */
  height:auto;
  object-fit:contain;
  display:block;
}
.printCompany{
  text-align:center;
  margin-top:2px;
}
.printCompany .nm{font-weight:1000;font-size:20px;letter-spacing:.2px}
.printCompany .tg{font-size:12px;color:#334155;margin-top:2px}
.printCompany .ln{font-size:12px;color:#334155;margin-top:2px}

/* PRINT */
@media print{
 @page{
  size: A4;
  margin: 12mm;
}

@media print{
  body{background:#fff}
  .topbar{display:none}

  .wrap{
    max-width:none;
    padding:0;
    margin:0;
  }

  .card{
    box-shadow:none;
    border:none;
  }

  .printHead{display:block}
  .table-wrap{border:1px solid #ddd}
  th{position:static}
}
}
</style>
</head>

<body>
<div class="wrap">

  <div class="topbar">
    <div class="brand">
      <div class="logoDot"></div>
      <div style="min-width:0">
        <p class="h1">Invoice <?=h($inv['invoice_no'] ?? $inv_id)?></p>
        <div class="sub"><?=h($inv['customer_name'] ?? '-')?><?= ($inv['mobile'] ? ' • '.h($inv['mobile']) : '') ?></div>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
      <?php if(!empty($inv['customer_id'])): ?>
        <a class="btn btn-soft btn-mini" href="customer-view.php?id=<?= (int)$inv['customer_id'] ?>">← Customer</a>
      <?php else: ?>
        <span class="btn btn-soft btn-mini" style="opacity:.5;cursor:not-allowed">← Customer</span>
      <?php endif; ?>
      <a class="btn btn-soft btn-mini" href="orders.php">+ New Order</a>
      <button class="btn btn-primary btn-mini" onclick="window.print()">Print</button>
    </div>
  </div>

  <div class="card">
    <div class="p printHead">
      <div class="printLogo">
        <?php if($hasLogo): ?>
          <img src="<?=h($LOGO_PATH)?>" alt="Logo">
        <?php else: ?>
          <div style="font-weight:900;color:#0f172a;font-size:20px"><?=h($COMPANY_NAME)?></div>
        <?php endif; ?>
      </div>

      <div class="printCompany">
        <div class="nm"><?=h($COMPANY_NAME)?></div>
        <div class="tg"><?=h($COMPANY_TAGLINE)?></div>
        <div class="ln"><?=h($COMPANY_LINE1)?><?= $COMPANY_LINE2 ? " • ".h($COMPANY_LINE2) : "" ?></div>
      </div>
    </div>

    <div class="card-h">
      <div>
        <div style="font-weight:900;font-size:14px"><?=h($inv['customer_name'] ?? '-')?></div>
        <div class="small"><?=h($inv['mobile'] ?? '')?></div>
        <div class="small"><?=h($inv['address'] ?? '')?></div>
        <div class="small"><?=h($inv['email'] ?? '')?></div>
      </div>

      <div style="text-align:right">
        <div class="small"><b>Invoice:</b> <?=h($inv['invoice_no'] ?? $inv_id)?></div>
        <div class="small"><b>Date:</b> <?= !empty($inv['invoice_date']) ? date('d M Y, h:i A', strtotime($inv['invoice_date'])) : '-' ?></div>
        <div style="margin-top:8px"><span class="badge <?=h($badge)?>"><?=h($inv['status'] ?? 'due')?></span></div>
      </div>
    </div>

    <div class="p grid2">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Item</th>
              <th class="r">Qty</th>
              <th class="r">Rate</th>
              <th class="r">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php if(!empty($items)): ?>
              <?php foreach($items as $it): ?>
                <tr>
                  <td><?=h($it['item_name'])?></td>
                  <td class="r"><?=money($it['qty'])?></td>
                  <td class="r"><?=h($CURRENCY)?><?=money($it['rate'])?></td>
                  <td class="r"><?=h($CURRENCY)?><?=money($it['line_total'])?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="4" class="small">No items found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="totalBox">
        <div class="totalRow">
          <span class="label">Grand Total</span>
          <span class="val"><?=h($CURRENCY)?><?=money($inv['grand_total'] ?? 0)?></span>
        </div>
        <div class="totalRow">
          <span class="label">Paid</span>
          <span class="val"><?=h($CURRENCY)?><?=money($inv['paid_total'] ?? 0)?></span>
        </div>
        <div class="totalRow">
          <span class="label">Due</span>
          <span class="val"><?=h($CURRENCY)?><?=money($inv['due_total'] ?? 0)?></span>
        </div>
        <hr>
        <div class="small"><b>Note:</b> <?= $noteTxt!=='' ? h($noteTxt) : '-' ?></div>
      </div>
    </div>
  </div>

</div>
</body>
</html>