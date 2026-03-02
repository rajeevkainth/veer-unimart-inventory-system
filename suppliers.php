<?php
// public_html/grocery/suppliers.php
session_start();
require __DIR__ . "/config/db.php";

error_reporting(E_ALL & ~E_NOTICE);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* -----------------------
   Page Config
------------------------ */
$active = 'suppliers';
$pageTitle = "Suppliers";

/* -----------------------
   Helpers
------------------------ */
if(!function_exists('h')){
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function money_eu($n){
  $x = is_numeric($n) ? (float)$n : 0.0;
  return "€" . number_format($x, 2);
}
function post($k, $d=''){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function get($k, $d=''){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }

/* -----------------------
   CSRF (simple)
------------------------ */
if(empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* -----------------------
   Flash messages
------------------------ */
$okMsg = $_SESSION['okMsg'] ?? '';
$errMsg = $_SESSION['errMsg'] ?? '';
unset($_SESSION['okMsg'], $_SESSION['errMsg']);

function flash_ok($msg){
  $_SESSION['okMsg'] = $msg;
  header("Location: suppliers.php");
  exit;
}
function flash_err($msg){
  $_SESSION['errMsg'] = $msg;
  header("Location: suppliers.php");
  exit;
}

/* -----------------------
   Handle Actions (POST)
------------------------ */
if($_SERVER['REQUEST_METHOD'] === 'POST'){
  $token = post('csrf');
  if(!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)){
    flash_err("Security check failed. Please refresh and try again.");
  }

  $action = post('action');

  try{
    if($action === 'save_supplier'){
      $id            = (int)post('id', 0);
      $supplier_name = post('supplier_name');
      $mobile        = post('mobile');
      $email         = post('email');
      $address       = post('address');
      $notes         = post('notes');
      $opening       = post('opening_balance', '0');

      if($supplier_name === ''){
        flash_err("Supplier name is required.");
      }

      $opening = preg_replace('/[^0-9.\-]/', '', $opening);
      if($opening === '' || !is_numeric($opening)) $opening = 0;
      $opening = (float)$opening;

      if($id > 0){
        $stmt = $conn->prepare("
          UPDATE suppliers
          SET supplier_name=?, mobile=?, email=?, address=?, notes=?, opening_balance=?
          WHERE id=?
          LIMIT 1
        ");
        $stmt->bind_param("sssssdi", $supplier_name, $mobile, $email, $address, $notes, $opening, $id);
        $stmt->execute();
        $stmt->close();
        flash_ok("Supplier updated successfully.");
      } else {
        $stmt = $conn->prepare("
          INSERT INTO suppliers (supplier_name, mobile, email, address, notes, opening_balance, is_active, created_at)
          VALUES (?,?,?,?,?,?,1,NOW())
        ");
        $stmt->bind_param("sssssd", $supplier_name, $mobile, $email, $address, $notes, $opening);
        $stmt->execute();
        $stmt->close();
        flash_ok("Supplier added successfully.");
      }
    }

    if($action === 'toggle_supplier'){
      $id = (int)post('id', 0);
      $to = (int)post('to', 0);
      if($id <= 0) flash_err("Invalid supplier.");

      $stmt = $conn->prepare("UPDATE suppliers SET is_active=? WHERE id=? LIMIT 1");
      $stmt->bind_param("ii", $to, $id);
      $stmt->execute();
      $stmt->close();

      flash_ok($to ? "Supplier activated." : "Supplier disabled.");
    }

  } catch(Throwable $e){
    flash_err("Error: " . $e->getMessage());
  }

  flash_err("Unknown action.");
}

/* -----------------------
   Edit Mode (GET)
------------------------ */
$editId = (int)get('edit', 0);
$edit = [
  'id'=>0,'supplier_name'=>'','mobile'=>'','email'=>'','address'=>'','notes'=>'','opening_balance'=>'0.00','is_active'=>1
];

if($editId > 0){
  $stmt = $conn->prepare("
    SELECT id, supplier_name, mobile, email, address, notes, opening_balance, COALESCE(is_active,1) AS is_active
    FROM suppliers
    WHERE id=? LIMIT 1
  ");
  $stmt->bind_param("i", $editId);
  $stmt->execute();
  $res = $stmt->get_result();
  if($row = $res->fetch_assoc()){
    $edit = $row;
  } else {
    $errMsg = "Supplier not found.";
  }
  $stmt->close();
}

/* -----------------------
   Counts (for instant verification)
------------------------ */
$counts = ['total'=>0,'active'=>0,'inactive'=>0];
$resC = $conn->query("
  SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN COALESCE(is_active,1)=1 THEN 1 ELSE 0 END) AS active,
    SUM(CASE WHEN COALESCE(is_active,1)=0 THEN 1 ELSE 0 END) AS inactive
  FROM suppliers
");
if($resC){
  $counts = $resC->fetch_assoc() ?: $counts;
  $resC->free();
}

/* -----------------------
   List / Filters (GET)
------------------------ */
$q      = get('q', '');
$status = get('status', 'active'); // active | inactive | all
$where = [];
$params = [];
$types = "";

if($q !== ''){
  $where[] = "(supplier_name LIKE ? OR mobile LIKE ? OR email LIKE ?)";
  $like = "%".$q."%";
  $params[] = $like; $params[] = $like; $params[] = $like;
  $types .= "sss";
}

/* ✅ IMPORTANT FIX:
   Use COALESCE so NULL behaves like Active (1)
*/
if($status === 'active'){
  $where[] = "COALESCE(is_active,1)=1";
} elseif($status === 'inactive'){
  $where[] = "COALESCE(is_active,1)=0";
}

$sqlWhere = $where ? ("WHERE ".implode(" AND ", $where)) : "";

$sql = "
  SELECT id, supplier_name, mobile, email, opening_balance,
         COALESCE(is_active,1) AS is_active, created_at
  FROM suppliers
  $sqlWhere
  ORDER BY COALESCE(is_active,1) DESC, supplier_name ASC
  LIMIT 500
";

$stmt = $conn->prepare($sql);
if($params){
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$list = $stmt->get_result();
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
  :root{
    --g:#1F7A3F; --g2:#14532D;
    --bg:#f7faf7; --card:#fff; --line:#e5e7eb;
    --text:#0f172a; --muted:#64748b;
    --shadow:0 10px 30px rgba(2,6,23,.08);
    --shadow2:0 6px 18px rgba(2,6,23,.10);
    --radius:16px;
  }
  *{box-sizing:border-box}
  body{
    margin:0;
    font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial;
    color:var(--text);
    background:linear-gradient(180deg, #f2fbf2, var(--bg));
  }
  a{color:inherit; text-decoration:none}
  .layout{display:flex; min-height:100vh}
  .main{flex:1; min-width:0}
  .wrap{max-width:1200px; margin:0 auto; padding:18px 16px 26px}
  .topbar{
    position:sticky; top:0; z-index:999;
    background:rgba(247,250,247,.9);
    backdrop-filter: blur(8px);
    border-bottom:1px solid var(--line);
  }
  .topbar .wrap{padding:14px 16px}
  .head{display:flex; align-items:center; justify-content:space-between; gap:12px;}
  .title{display:flex; flex-direction:column; gap:2px;}
  .title h1{margin:0; font-size:20px; letter-spacing:.2px;}
  .title .sub{color:var(--muted); font-size:13px;}
  .pill{
    display:inline-flex; align-items:center; gap:8px;
    padding:8px 10px; border:1px solid var(--line); background:#fff;
    border-radius:999px; box-shadow:0 6px 16px rgba(2,6,23,.06);
    font-size:13px; color:var(--muted);
  }
  .dot{width:10px;height:10px;border-radius:999px;background:var(--g)}
  .grid{
    margin-top:16px;
    display:grid;
    grid-template-columns: 420px minmax(0, 1fr);
    gap:14px;
    align-items:start;
  }
  @media (max-width: 980px){ .grid{grid-template-columns:1fr} }
  .card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    overflow:hidden;
    isolation:isolate;
  }
  .card-h{
    padding:14px 14px 12px;
    border-bottom:1px solid var(--line);
    display:flex; align-items:center; justify-content:space-between; gap:10px;
  }
  .card-h .h{font-weight:800; display:flex; align-items:center; gap:10px;}
  .badge{
    display:inline-flex; align-items:center;
    padding:6px 10px; border-radius:999px; font-size:12px;
    border:1px solid var(--line); background:#f8fff8; color:var(--g2); font-weight:700;
  }
  .card-b{padding:14px}
  .row{display:grid; grid-template-columns:1fr 1fr; gap:10px}
  @media (max-width: 520px){ .row{grid-template-columns:1fr} }
  label{display:block; font-size:12px; color:var(--muted); margin:0 0 6px}
  input, textarea, select{
    width:100%;
    border:1px solid var(--line);
    border-radius:12px;
    padding:10px 12px;
    outline:none;
    background:#fff;
    font-size:14px;
  }
  textarea{min-height:92px; resize:vertical}
  input:focus, textarea:focus, select:focus{
    border-color: rgba(31,122,63,.45);
    box-shadow: 0 0 0 4px rgba(31,122,63,.10);
  }
  .actions{display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;}
  .btn{
    border:0;
    border-radius:12px;
    padding:10px 14px;
    font-weight:800;
    cursor:pointer;
    transition:.15s;
    display:inline-flex; align-items:center; gap:8px;
  }
  .btn-primary{
    background:linear-gradient(135deg, var(--g), var(--g2));
    color:#fff;
    box-shadow: 0 10px 18px rgba(31,122,63,.22);
  }
  .btn-primary:hover{transform: translateY(-1px)}
  .btn-ghost{background:#fff; border:1px solid var(--line); color:var(--text);}
  .btn-warn{background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; font-weight:900;}
  .btn-good{background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; font-weight:900;}
  .alert{
    margin-top:14px;
    padding:12px 14px;
    border-radius:14px;
    border:1px solid var(--line);
    background:#fff;
    box-shadow:0 6px 14px rgba(2,6,23,.06);
    font-size:14px;
  }
  .alert.ok{border-color:#bbf7d0; background:#f0fdf4}
  .alert.err{border-color:#fecaca; background:#fff1f2}
  .filters{display:flex; gap:10px; flex-wrap:wrap; align-items:center;}
  .filters .grow{flex:1; min-width:220px}
  .table-wrap{overflow:auto; max-height: 70vh;}
  table{width:100%; border-collapse:separate; border-spacing:0}
  th, td{
    padding:12px 12px;
    border-bottom:1px solid var(--line);
    white-space:nowrap;
    vertical-align:middle;
    font-size:14px;
  }
  th{
    position:sticky; top:0;
    background:#f6fff6;
    z-index:1;
    font-size:12px;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.08em;
  }
  .muted{color:var(--muted); font-size:12px}
  .status{
    display:inline-flex; align-items:center; gap:8px;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    border:1px solid var(--line);
  }
  .status.on{background:#ecfdf5; color:#065f46; border-color:#a7f3d0}
  .status.off{background:#fff7ed; color:#9a3412; border-color:#fed7aa}
  .link{font-weight:900; color:var(--g2);}
  .mini{font-size:12px; padding:8px 10px; border-radius:10px;}
</style>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . "/partials/sidebar.php"; ?>

  <div class="main">
    <div class="topbar">
      <div class="wrap">
        <div class="head">
          <div class="title">
            <h1>Suppliers</h1>
            <div class="sub">
              Vendors & purchase partners • Total: <b><?php echo (int)$counts['total']; ?></b>
              • Active: <b><?php echo (int)$counts['active']; ?></b>
              • Inactive: <b><?php echo (int)$counts['inactive']; ?></b>
            </div>
          </div>
          <div class="pill"><span class="dot"></span> UniMart Inventory</div>
        </div>

        <?php if($okMsg): ?><div class="alert ok"><?php echo h($okMsg); ?></div><?php endif; ?>
        <?php if($errMsg): ?><div class="alert err"><?php echo h($errMsg); ?></div><?php endif; ?>
      </div>
    </div>

    <div class="wrap">
      <div class="grid">

        <!-- LEFT: Add/Edit Form -->
        <div class="card">
          <div class="card-h">
            <div class="h">
              <?php if((int)$edit['id'] > 0): ?> ✏️ Edit Supplier <?php else: ?> ➕ Add Supplier <?php endif; ?>
            </div>
            <span class="badge"><?php echo ((int)$edit['id']>0) ? ("ID #".(int)$edit['id']) : "New"; ?></span>
          </div>

          <div class="card-b">
            <form method="post" action="suppliers.php">
              <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="action" value="save_supplier">
              <input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>">

              <div style="margin-bottom:10px">
                <label>Supplier Name *</label>
                <input name="supplier_name" value="<?php echo h($edit['supplier_name']); ?>" placeholder="e.g. Fresh Veg Traders" required>
              </div>

              <div class="row">
                <div>
                  <label>Mobile</label>
                  <input name="mobile" value="<?php echo h($edit['mobile']); ?>" placeholder="e.g. 9876543210">
                </div>
                <div>
                  <label>Email</label>
                  <input name="email" value="<?php echo h($edit['email']); ?>" placeholder="e.g. vendor@email.com">
                </div>
              </div>

              <div style="margin-top:10px">
                <label>Address</label>
                <input name="address" value="<?php echo h($edit['address']); ?>" placeholder="City, street, etc.">
              </div>

              <div class="row" style="margin-top:10px">
                <div>
                  <label>Opening Balance (Due)</label>
                  <input name="opening_balance" value="<?php echo h($edit['opening_balance']); ?>" placeholder="0.00">
                  <div class="muted" style="margin-top:6px">If you already owe this supplier, enter due amount here.</div>
                </div>
                <div>
                  <label>Status</label>
                  <input value="<?php echo ((int)$edit['is_active']===1) ? 'Active' : 'Inactive'; ?>" disabled>
                </div>
              </div>

              <div style="margin-top:10px">
                <label>Notes</label>
                <textarea name="notes" placeholder="Optional notes (payment terms, GST, etc.)"><?php echo h($edit['notes']); ?></textarea>
              </div>

              <div class="actions">
                <button class="btn btn-primary" type="submit">
                  <?php echo ((int)$edit['id']>0) ? "Update Supplier" : "Save Supplier"; ?>
                </button>
                <?php if((int)$edit['id']>0): ?>
                  <a class="btn btn-ghost" href="suppliers.php">Cancel Edit</a>
                <?php else: ?>
                  <button class="btn btn-ghost" type="reset">Clear</button>
                <?php endif; ?>
              </div>
            </form>

            <?php if((int)$edit['id']>0): ?>
              <div class="actions" style="margin-top:10px">
                <form method="post" action="suppliers.php" onsubmit="return confirm('Are you sure?');">
                  <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                  <input type="hidden" name="action" value="toggle_supplier">
                  <input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>">
                  <?php if((int)$edit['is_active']===1): ?>
                    <input type="hidden" name="to" value="0">
                    <button class="btn btn-warn" type="submit">Disable Supplier</button>
                  <?php else: ?>
                    <input type="hidden" name="to" value="1">
                    <button class="btn btn-good" type="submit">Activate Supplier</button>
                  <?php endif; ?>
                </form>
              </div>
            <?php endif; ?>

          </div>
        </div>

        <!-- RIGHT: List -->
        <div class="card">
          <div class="card-h">
            <div class="h">📋 Supplier List</div>
            <span class="badge">Max 500</span>
          </div>

          <div class="card-b" style="padding-bottom:10px">
            <form class="filters" method="get" action="suppliers.php">
              <div class="grow">
                <input name="q" value="<?php echo h($q); ?>" placeholder="Search by name / mobile / email...">
              </div>
              <select name="status" style="width:auto">
                <option value="active"   <?php echo $status==='active'?'selected':''; ?>>Active</option>
                <option value="inactive" <?php echo $status==='inactive'?'selected':''; ?>>Inactive</option>
                <option value="all"      <?php echo $status==='all'?'selected':''; ?>>All</option>
              </select>
              <button class="btn btn-primary mini" type="submit">Search</button>
              <a class="btn btn-ghost mini" href="suppliers.php">Reset</a>
            </form>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Supplier</th>
                  <th>Contact</th>
                  <th>Opening Due</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th style="text-align:right">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if($list->num_rows === 0): ?>
                  <tr><td colspan="6" style="padding:18px;color:var(--muted)">No suppliers found.</td></tr>
                <?php else: ?>
                  <?php while($r = $list->fetch_assoc()): ?>
                    <tr>
                      <td>
                        <div style="font-weight:900"><?php echo h($r['supplier_name']); ?></div>
                        <div class="muted">ID #<?php echo (int)$r['id']; ?></div>
                      </td>
                      <td>
                        <div><?php echo h($r['mobile'] ?: '-'); ?></div>
                        <div class="muted"><?php echo h($r['email'] ?: ''); ?></div>
                      </td>
                      <td style="font-weight:900"><?php echo money_eu($r['opening_balance']); ?></td>
                      <td>
                        <?php if((int)$r['is_active'] === 1): ?>
                          <span class="status on">● Active</span>
                        <?php else: ?>
                          <span class="status off">● Inactive</span>
                        <?php endif; ?>
                      </td>
                      <td class="muted">
                        <?php $t = strtotime($r['created_at'] ?? ''); echo $t ? date('d M Y', $t) : '-'; ?>
                      </td>
                      <td style="text-align:right">
                        <a class="link" href="suppliers.php?edit=<?php echo (int)$r['id']; ?>">Edit</a>
                        <form method="post" action="suppliers.php" style="display:inline-block; margin-left:10px" onsubmit="return confirm('Are you sure?');">
                          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                          <input type="hidden" name="action" value="toggle_supplier">
                          <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                          <?php if((int)$r['is_active'] === 1): ?>
                            <input type="hidden" name="to" value="0">
                            <button class="btn btn-warn mini" type="submit">Disable</button>
                          <?php else: ?>
                            <input type="hidden" name="to" value="1">
                            <button class="btn btn-good mini" type="submit">Activate</button>
                          <?php endif; ?>
                        </form>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div style="padding:12px 14px; border-top:1px solid var(--line); color:var(--muted); font-size:12px">
            Tip: Disable suppliers instead of deleting to keep purchase history safe.
          </div>
        </div>

      </div>
    </div>
  </div>
</div>
</body>
</html>