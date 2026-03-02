<?php
// grocery/customer-view.php
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

/* =========================
   Tables availability
========================= */
$HAS_CUSTOMERS = table_exists($conn, 'customers');
$HAS_INVOICES  = table_exists($conn, 'invoices');
$HAS_ITEMS     = table_exists($conn, 'invoice_items');
$HAS_PAYMENTS  = table_exists($conn, 'customer_payments');
$HAS_ALLOC     = table_exists($conn, 'payment_allocations');

if(!$HAS_CUSTOMERS){ die("customers table missing"); }

$paymentsEnabled = ($HAS_INVOICES && $HAS_PAYMENTS && $HAS_ALLOC);

$err=''; $ok='';

/* =========================
   Customer ID
========================= */
$id = 0;
if(isset($_GET['id']) && ctype_digit($_GET['id'])) $id = (int)$_GET['id'];
if($id<=0) die("Customer id missing");

/* =========================
   FIFO Payment Adjuster
========================= */
function add_payment_fifo(mysqli $conn, int $store_id, int $customer_id, float $amount, string $mode, string $note, string $pdate): void {
  if ($pdate === '') $pdate = date('Y-m-d H:i:s');
  if (!in_array($mode, ['cash','upi','card','bank','other'], true)) $mode='cash';
  if ($amount <= 0) throw new Exception("Payment amount must be > 0");

  $conn->begin_transaction();

  // Insert payment
  $stmt = $conn->prepare("INSERT INTO customer_payments (store_id, customer_id, payment_date, amount, mode, note)
                          VALUES (?,?,?,?,?,?)");
  $stmt->bind_param("iisdss", $store_id, $customer_id, $pdate, $amount, $mode, $note);
  $stmt->execute();
  $payment_id = (int)$stmt->insert_id;
  $stmt->close();

  // Apply oldest dues first
  $remaining = $amount;

  $stmt = $conn->prepare("SELECT id, paid_total, due_total
                          FROM invoices
                          WHERE store_id=? AND customer_id=? AND status IN ('due','partial')
                          ORDER BY invoice_date ASC, id ASC");
  $stmt->bind_param("ii", $store_id, $customer_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $stmt->close();

  while ($res && ($row = $res->fetch_assoc())) {
    if ($remaining <= 0.009) break;

    $inv_id = (int)$row['id'];
    $due = (float)$row['due_total'];
    if ($due <= 0.009) continue;

    $apply = ($remaining >= $due) ? $due : $remaining;

    $newPaid = (float)$row['paid_total'] + $apply;
    $newDue  = (float)$row['due_total'] - $apply;
    if ($newDue < 0) $newDue = 0;

    $newStatus = ($newDue <= 0.009) ? 'paid' : 'partial';

    $u = $conn->prepare("UPDATE invoices
                         SET paid_total=?, due_total=?, status=?
                         WHERE id=? AND store_id=? AND customer_id=? LIMIT 1");
    $u->bind_param("ddsiii", $newPaid, $newDue, $newStatus, $inv_id, $store_id, $customer_id);
    $u->execute();
    $u->close();

    $a = $conn->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount_applied)
                         VALUES (?,?,?)");
    $a->bind_param("iid", $payment_id, $inv_id, $apply);
    $a->execute();
    $a->close();

    $remaining -= $apply;
  }

  $conn->commit();
}

/* =========================
   Receive payment submit (on customer view)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_payment'])) {
  if(!$paymentsEnabled){
    $err = "Payments feature needs tables: invoices, customer_payments, payment_allocations.";
  } else {
    $amount = (float)($_POST['amount'] ?? 0);
    $mode   = (string)($_POST['mode'] ?? 'cash');
    $note   = trim((string)($_POST['note'] ?? ''));
    $pdate  = trim((string)($_POST['payment_date'] ?? ''));

    if($amount <= 0) $err = "Payment amount must be greater than 0.";
    else {
      try{
        add_payment_fifo($conn, $store_id, $id, $amount, $mode, $note, $pdate);
        $ok = "Payment received & dues adjusted successfully.";
      }catch(Throwable $e){
        try{ $conn->rollback(); }catch(Throwable $ex){}
        $err = "Payment error: ".$e->getMessage();
      }
    }
  }
}

/* =========================
   Date range filters
========================= */
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));
if($from==='') $from = date('Y-m-01');
if($to==='')   $to   = date('Y-m-t');

$export = (int)($_GET['export'] ?? 0);

/* =========================
   Customer info
========================= */
$cust = null;
$stmt = $conn->prepare("SELECT * FROM customers WHERE id=? AND store_id=? LIMIT 1");
$stmt->bind_param("ii", $id, $store_id);
$stmt->execute();
$res = $stmt->get_result();
$cust = $res ? $res->fetch_assoc() : null;
$stmt->close();

if(!$cust) die("Customer not found");

/* =========================
   Summary (only if invoices exists)
========================= */
$sum = [
  'total_billed'=>0, 'total_paid'=>0, 'total_due'=>0,
  'last_purchase'=>null
];

if($HAS_INVOICES){
  $stmt = $conn->prepare("
    SELECT
      COALESCE(SUM(grand_total),0) AS total_billed,
      COALESCE(SUM(paid_total),0)  AS total_paid,
      COALESCE(SUM(due_total),0)   AS total_due,
      MAX(invoice_date) AS last_purchase
    FROM invoices
    WHERE store_id=? AND customer_id=? AND status!='cancelled'
  ");
  $stmt->bind_param("ii", $store_id, $id);
  $stmt->execute();
  $stmt->bind_result($tb,$tp,$td,$lp);
  $stmt->fetch();
  $stmt->close();

  $sum['total_billed'] = (float)$tb;
  $sum['total_paid']   = (float)$tp;
  $sum['total_due']    = (float)$td;
  $sum['last_purchase']= $lp ?: null;
}

/* =========================
   Invoices in range
========================= */
$invoices = [];
if($HAS_INVOICES){
  $stmt = $conn->prepare("
    SELECT id, invoice_no, invoice_date, grand_total, paid_total, due_total, status, note
    FROM invoices
    WHERE store_id=? AND customer_id=? AND status!='cancelled'
      AND DATE(invoice_date) BETWEEN ? AND ?
    ORDER BY invoice_date DESC, id DESC
  ");
  $stmt->bind_param("iiss", $store_id, $id, $from, $to);
  $stmt->execute();
  $rs = $stmt->get_result();
  while($rs && ($r=$rs->fetch_assoc())) $invoices[]=$r;
  $stmt->close();
}

/* =========================
   Payments in range
========================= */
$payments = [];
if($HAS_PAYMENTS){
  $stmt = $conn->prepare("
    SELECT id, payment_date, amount, mode, note
    FROM customer_payments
    WHERE store_id=? AND customer_id=?
      AND DATE(payment_date) BETWEEN ? AND ?
    ORDER BY payment_date DESC, id DESC
  ");
  $stmt->bind_param("iiss", $store_id, $id, $from, $to);
  $stmt->execute();
  $rs = $stmt->get_result();
  while($rs && ($r=$rs->fetch_assoc())) $payments[]=$r;
  $stmt->close();
}

/* =========================
   Export statement CSV
========================= */
if($export === 1){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="customer_statement_'.$id.'.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['Customer', $cust['name'].' ('.$cust['mobile'].')']);
  fputcsv($out, ['From', $from, 'To', $to]);
  fputcsv($out, []);
  fputcsv($out, ['--- Invoices ---']);
  fputcsv($out, ['Invoice No','Date','Total','Paid','Due','Status','Note']);
  foreach($invoices as $r){
    fputcsv($out, [
      $r['invoice_no'],
      $r['invoice_date'],
      number_format((float)$r['grand_total'],2,'.',''),
      number_format((float)$r['paid_total'],2,'.',''),
      number_format((float)$r['due_total'],2,'.',''),
      $r['status'],
      $r['note']
    ]);
  }
  fputcsv($out, []);
  fputcsv($out, ['--- Payments ---']);
  fputcsv($out, ['Date','Amount','Mode','Note']);
  foreach($payments as $p){
    fputcsv($out, [
      $p['payment_date'],
      number_format((float)$p['amount'],2,'.',''),
      $p['mode'],
      $p['note']
    ]);
  }
  fclose($out);
  exit;
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Customer View</title>
<style>
:root{
  --bg:#f6faf7; --card:#ffffff; --text:#0f172a; --muted:#64748b; --line:#e5e7eb;
  --brand:#16a34a; --brand2:#22c55e; --warn:#f59e0b; --bad:#ef4444; --info:#3b82f6;
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

.grid{margin-top:16px;display:grid;grid-template-columns:1fr;gap:14px}
.card{background:var(--card);border:1px solid rgba(2,6,23,.08);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
.card-h{padding:14px 14px 10px;border-bottom:1px solid rgba(2,6,23,.06);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.card-h h2{margin:0;font-size:14px}
.p{padding:14px}

.pills{display:flex;gap:10px;flex-wrap:wrap}
.pill{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;border:1px solid rgba(2,6,23,.10);background:rgba(255,255,255,.7);font-size:12px}
.dot{width:9px;height:9px;border-radius:99px;background:linear-gradient(135deg,var(--brand),var(--brand2))}
.dot.warn{background:linear-gradient(135deg,#f59e0b,#fbbf24)}
.dot.info{background:linear-gradient(135deg,#3b82f6,#60a5fa)}

.row2{display:grid;grid-template-columns:1.1fr .9fr;gap:14px}
@media(max-width:980px){.row2{grid-template-columns:1fr}}

.btn{border:none;cursor:pointer;border-radius:14px;padding:10px 12px;font-weight:900;font-size:13px}
.btn-primary{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;box-shadow:0 12px 24px rgba(22,163,74,.22)}
.btn-soft{background:rgba(22,163,74,.10);color:#0f172a;border:1px solid rgba(22,163,74,.20);text-decoration:none;display:inline-flex;align-items:center}
.btn-mini{padding:8px 10px;border-radius:12px;font-size:12px}
.msg{margin:10px 0 0;padding:10px 12px;border-radius:14px;font-size:13px;border:1px solid rgba(2,6,23,.08)}
.msg.ok{background:rgba(22,163,74,.09);border-color:rgba(22,163,74,.25)}
.msg.err{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.25)}

.table-wrap{overflow:auto;border-radius:16px}
table{width:100%;border-collapse:separate;border-spacing:0}
th,td{padding:11px 12px;border-bottom:1px solid rgba(2,6,23,.06);font-size:13px;white-space:nowrap;text-align:left}
th{background:linear-gradient(180deg,#ffffff,#fbfffc);font-size:12px;color:var(--muted);position:sticky;top:0;z-index:2}
.badge{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900;border:1px solid rgba(2,6,23,.10);background:rgba(2,6,23,.03)}
.badge.due{background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.25)}
.badge.paid{background:rgba(22,163,74,.10);border-color:rgba(22,163,74,.22)}
.badge.partial{background:rgba(59,130,246,.10);border-color:rgba(59,130,246,.22)}
.small{font-size:12px;color:var(--muted)}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
input,select,textarea{width:100%;border:1px solid rgba(2,6,23,.12);border-radius:14px;padding:10px 11px;font-size:13px;outline:none;background:#fff}
textarea{min-height:70px;resize:vertical}
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div class="brand">
      <div class="logo"></div>
      <div>
        <p class="h1"><?=h($cust['name'])?></p>
        <div class="sub"><?=h($cust['mobile'])?> • <?=h($cust['customer_type'])?> • <?=h($cust['status'])?></div>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
      <a class="btn btn-soft btn-mini" href="customers.php">← Back</a>
      <a class="btn btn-soft btn-mini" href="orders.php">+ New Order</a>
    </div>
  </div>

  <?php if($ok): ?><div class="msg ok"><?=h($ok)?></div><?php endif; ?>
  <?php if($err): ?><div class="msg err"><?=h($err)?></div><?php endif; ?>

  <div class="grid">

    <!-- SUMMARY -->
    <div class="card">
      <div class="card-h">
        <h2>Summary</h2>
        <div class="pills">
          <div class="pill"><span class="dot info"></span> <span class="small">Billed:</span> <b>₹<?=money($sum['total_billed'])?></b></div>
          <div class="pill"><span class="dot"></span> <span class="small">Paid:</span> <b>₹<?=money($sum['total_paid'])?></b></div>
          <div class="pill"><span class="dot warn"></span> <span class="small">Due:</span> <b>₹<?=money($sum['total_due'])?></b></div>
          <div class="pill"><span class="dot"></span> <span class="small">Last Purchase:</span>
            <b><?= $sum['last_purchase'] ? date('d M Y, h:i A', strtotime($sum['last_purchase'])) : '-' ?></b>
          </div>
        </div>
      </div>

      <div class="p row2">
        <div>
          <div class="small"><b>Address:</b> <?=h($cust['address'] ?? '-')?></div>
          <div class="small" style="margin-top:6px"><b>Email:</b> <?=h($cust['email'] ?? '-')?></div>
          <div class="small" style="margin-top:6px"><b>Notes:</b> <?=h($cust['notes'] ?? '-')?></div>
        </div>

        <div>
          <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;justify-content:flex-end">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <div class="field" style="min-width:160px">
              <label>From</label>
              <input name="from" value="<?=h($from)?>" type="date">
            </div>
            <div class="field" style="min-width:160px">
              <label>To</label>
              <input name="to" value="<?=h($to)?>" type="date">
            </div>
            <button class="btn btn-soft btn-mini" type="submit">Filter</button>
            <a class="btn btn-soft btn-mini" href="customer-view.php?id=<?= (int)$id ?>&from=<?=h($from)?>&to=<?=h($to)?>&export=1">Export Excel</a>
          </form>

          <?php if($paymentsEnabled && $sum['total_due'] > 0.009): ?>
          <div style="margin-top:10px">
            <button class="btn btn-primary btn-mini" type="button" onclick="openPay()">Receive Payment</button>
            <div class="small" style="margin-top:6px">FIFO: adjusts oldest due invoices first.</div>
          </div>
          <?php elseif(!$paymentsEnabled): ?>
            <div class="small" style="margin-top:10px">Payments tables missing (optional). Receive payment disabled.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- INVOICES + PAYMENTS -->
    <div class="row2">
      <div class="card">
        <div class="card-h"><h2>Invoices (<?=h($from)?> → <?=h($to)?>)</h2></div>
        <div class="p" style="padding-top:10px">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Invoice</th>
                  <th>Date</th>
                  <th>Total</th>
                  <th>Paid</th>
                  <th>Due</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
              <?php if(!empty($invoices)): ?>
                <?php foreach($invoices as $r): ?>
                  <?php
                    $st = strtolower((string)$r['status']);
                    $cls = ($st==='paid')?'paid':(($st==='partial')?'partial':'due');
                  ?>
                  <tr>
                    <td style="font-weight:900">
  <a href="invoice-view.php?id=<?= (int)$r['id'] ?>" style="color:#16a34a;text-decoration:none;font-weight:900">
    <?=h($r['invoice_no'])?>
  </a>
</td>
                    <td class="small"><?= $r['invoice_date'] ? date('d M Y, h:i A', strtotime($r['invoice_date'])) : '-' ?></td>
                    <td>₹<?=money($r['grand_total'])?></td>
                    <td>₹<?=money($r['paid_total'])?></td>
                    <td>₹<?=money($r['due_total'])?></td>
                    <td><span class="badge <?=h($cls)?>"><?=h($r['status'])?></span></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="6" class="small">No invoices in this range.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-h"><h2>Payments (<?=h($from)?> → <?=h($to)?>)</h2></div>
        <div class="p" style="padding-top:10px">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Amount</th>
                  <th>Mode</th>
                  <th>Note</th>
                </tr>
              </thead>
              <tbody>
              <?php if(!empty($payments)): ?>
                <?php foreach($payments as $p): ?>
                  <tr>
                    <td class="small"><?= $p['payment_date'] ? date('d M Y, h:i A', strtotime($p['payment_date'])) : '-' ?></td>
                    <td style="font-weight:900">₹<?=money($p['amount'])?></td>
                    <td><?=h($p['mode'])?></td>
                    <td class="small"><?=h($p['note'] ?? '-')?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="4" class="small">No payments in this range.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Payment Modal -->
<div id="payBack" style="position:fixed;inset:0;background:rgba(2,6,23,.55);display:none;align-items:center;justify-content:center;z-index:999;padding:16px" onclick="closePay()">
  <div style="width:min(520px,100%);background:#fff;border-radius:18px;border:1px solid rgba(2,6,23,.10);box-shadow:0 30px 80px rgba(2,6,23,.25);overflow:hidden" onclick="event.stopPropagation()">
    <div style="padding:14px 14px 10px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-bottom:1px solid rgba(2,6,23,.06)">
      <div>
        <b>Receive Payment</b><br>
        <span class="small">Due: ₹<?=money($sum['total_due'])?></span>
      </div>
      <button type="button" onclick="closePay()" style="width:34px;height:34px;border-radius:12px;border:1px solid rgba(2,6,23,.10);background:rgba(2,6,23,.02);cursor:pointer;font-weight:900">×</button>
    </div>
    <div class="p">
      <form method="post" autocomplete="off">
        <input type="hidden" name="receive_payment" value="1">

        <div class="field">
          <label>Payment Date (optional)</label>
          <input name="payment_date" placeholder="YYYY-MM-DD HH:MM:SS (blank = now)">
        </div>

        <div class="field">
          <label>Amount *</label>
          <input name="amount" type="number" step="0.01" required>
        </div>

        <div class="field">
          <label>Mode</label>
          <select name="mode">
            <option value="cash">Cash</option>
            <option value="upi">UPI</option>
            <option value="card">Card</option>
            <option value="bank">Bank</option>
            <option value="other">Other</option>
          </select>
        </div>

        <div class="field">
          <label>Note (optional)</label>
          <textarea name="note" placeholder="e.g., received by UPI"></textarea>
        </div>

        <button class="btn btn-primary" type="submit">Save Payment</button>
        <button class="btn btn-soft" type="button" onclick="closePay()" style="margin-left:8px">Cancel</button>
      </form>
    </div>
  </div>
</div>

<script>
function openPay(){ document.getElementById('payBack').style.display='flex'; }
function closePay(){ document.getElementById('payBack').style.display='none'; }
</script>
</body>
</html>