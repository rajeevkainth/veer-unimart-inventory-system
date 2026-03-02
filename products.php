<?php
session_start();
require __DIR__ . "/config/db.php";

$active    = 'products';
$pageTitle = 'Products';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function num($v, $d=2){
  if($v === null || $v === '') return number_format(0, $d, '.', '');
  return number_format((float)$v, $d, '.', '');
}

function ensure_dir($path){
  if(!is_dir($path)){
    @mkdir($path, 0755, true);
  }
}

function upload_product_image($productId, $file){
  if(!isset($file) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE){
    return null; // no file uploaded
  }

  if(($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK){
    throw new Exception("Image upload failed. Please try again.");
  }

  // Size limit: 2MB
  if(($file['size'] ?? 0) > 2 * 1024 * 1024){
    throw new Exception("Image too large. Max 2MB allowed.");
  }

  $tmp = $file['tmp_name'] ?? '';
  if($tmp === '' || !is_uploaded_file($tmp)){
    throw new Exception("Invalid upload.");
  }

  // Validate image
  $info = @getimagesize($tmp);
  if($info === false){
    throw new Exception("Please upload a valid image (JPG/PNG/WebP).");
  }

  $mime = $info['mime'] ?? '';
  $allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
  ];
  if(!isset($allowed[$mime])){
    throw new Exception("Only JPG, PNG, WebP images are allowed.");
  }
  $ext = $allowed[$mime];

  // Build destination
  $baseRel = "uploads/products/".$productId;
  $baseAbs = __DIR__ . "/".$baseRel;
  ensure_dir($baseAbs);

  // Always save as main.<ext> (overwrite)
  $destRel = $baseRel."/main.".$ext;
  $destAbs = __DIR__ . "/".$destRel;

  // Remove older main.* files
  foreach(['jpg','png','webp'] as $e){
    $p = $baseAbs."/main.".$e;
    if(file_exists($p)) @unlink($p);
  }

  if(!@move_uploaded_file($tmp, $destAbs)){
    throw new Exception("Unable to save image. Check folder permissions.");
  }

  return $destRel;
}

/* =========================
   PRG Success Message (prevents duplicate on refresh)
========================= */
$success = '';
$ok = $_GET['ok'] ?? '';
if($ok === 'added')   $success = "Product added successfully ✅";
if($ok === 'updated') $success = "Product updated successfully ✅";

$errors = [];

/* =========================
   Handle Add / Update
========================= */
if($_SERVER['REQUEST_METHOD'] === 'POST'){
  $action = $_POST['action'] ?? '';

  $id        = (int)($_POST['id'] ?? 0);
  $name      = trim((string)($_POST['name'] ?? ''));
  $barcode   = trim((string)($_POST['barcode'] ?? ''));
  $unit      = trim((string)($_POST['unit'] ?? 'pcs'));
  $min_stock = (float)($_POST['min_stock'] ?? 0);
  $track_expiry = isset($_POST['track_expiry']) ? 1 : 0;

  $mrp       = (float)($_POST['mrp'] ?? 0);
  $retail    = (float)($_POST['retail_price'] ?? 0);
  $wholesale = (float)($_POST['wholesale_price'] ?? 0);
  $gst       = (float)($_POST['gst_percent'] ?? 0);

  if($name === '') $errors[] = "Product name is required.";
  if($gst < 0 || $gst > 100) $errors[] = "GST must be between 0 and 100.";

  if($barcode === '') $barcode = null;

  if(empty($errors)){
    try{
      if($action === 'add'){
        // Insert product first
        $stmt = $conn->prepare("INSERT INTO products (name, barcode, unit, min_stock, track_expiry, is_active) VALUES (?,?,?,?,?,1)");
        $stmt->bind_param("sssdi", $name, $barcode, $unit, $min_stock, $track_expiry);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();

        // Upload image (optional) and store path
        if(isset($_FILES['product_image'])){
          $imgPath = upload_product_image($newId, $_FILES['product_image']);
          if($imgPath){
            $stmt = $conn->prepare("UPDATE products SET image_path=? WHERE id=? LIMIT 1");
            $stmt->bind_param("si", $imgPath, $newId);
            $stmt->execute();
            $stmt->close();
          }
        }

        // Upsert prices
        $stmt = $conn->prepare("
          INSERT INTO product_prices (product_id, mrp, retail_price, wholesale_price, gst_percent)
          VALUES (?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
            mrp=VALUES(mrp),
            retail_price=VALUES(retail_price),
            wholesale_price=VALUES(wholesale_price),
            gst_percent=VALUES(gst_percent)
        ");
        $stmt->bind_param("idddd", $newId, $mrp, $retail, $wholesale, $gst);
        $stmt->execute();
        $stmt->close();

        header("Location: products.php?ok=added");
        exit;

      } elseif($action === 'update' && $id > 0){
        // Update product base
        $stmt = $conn->prepare("UPDATE products SET name=?, barcode=?, unit=?, min_stock=?, track_expiry=? WHERE id=? LIMIT 1");
        $stmt->bind_param("sssdii", $name, $barcode, $unit, $min_stock, $track_expiry, $id);
        $stmt->execute();
        $stmt->close();

        // Upload image (optional) and store path
        if(isset($_FILES['product_image'])){
          $imgPath = upload_product_image($id, $_FILES['product_image']);
          if($imgPath){
            $stmt = $conn->prepare("UPDATE products SET image_path=? WHERE id=? LIMIT 1");
            $stmt->bind_param("si", $imgPath, $id);
            $stmt->execute();
            $stmt->close();
          }
        }

        // Upsert prices
        $stmt = $conn->prepare("
          INSERT INTO product_prices (product_id, mrp, retail_price, wholesale_price, gst_percent)
          VALUES (?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
            mrp=VALUES(mrp),
            retail_price=VALUES(retail_price),
            wholesale_price=VALUES(wholesale_price),
            gst_percent=VALUES(gst_percent)
        ");
        $stmt->bind_param("idddd", $id, $mrp, $retail, $wholesale, $gst);
        $stmt->execute();
        $stmt->close();

        header("Location: products.php?ok=updated&edit=".$id);
        exit;
      }
    } catch(Throwable $e){
      if(strpos($e->getMessage(), 'uniq_barcode') !== false){
        $errors[] = "This barcode already exists. Please use a different barcode.";
      } else {
        $errors[] = $e->getMessage();
      }
    }
  }
}

/* =========================
   Fetch Edit Record
========================= */
$edit = null;
if(isset($_GET['edit'])){
  $eid = (int)$_GET['edit'];
  if($eid > 0){
    $stmt = $conn->prepare("
      SELECT p.*, pp.mrp, pp.retail_price, pp.wholesale_price, pp.gst_percent
      FROM products p
      LEFT JOIN product_prices pp ON pp.product_id = p.id
      WHERE p.id=? LIMIT 1
    ");
    $stmt->bind_param("i", $eid);
    $stmt->execute();
    $res = $stmt->get_result();
    $edit = $res ? $res->fetch_assoc() : null;
    $stmt->close();
  }
}

/* =========================
   Search + List
========================= */
$q = trim((string)($_GET['q'] ?? ''));
$like = '%'.$q.'%';

$stmt = $conn->prepare("
  SELECT p.id, p.name, p.barcode, p.image_path, p.unit, p.min_stock, p.track_expiry, p.is_active,
         COALESCE(pp.mrp,0) mrp,
         COALESCE(pp.retail_price,0) retail_price,
         COALESCE(pp.wholesale_price,0) wholesale_price,
         COALESCE(pp.gst_percent,0) gst_percent,
         COALESCE(SUM(sm.qty_in - sm.qty_out),0) AS current_stock
  FROM products p
  LEFT JOIN product_prices pp ON pp.product_id = p.id
  LEFT JOIN stock_movements sm ON sm.product_id = p.id
  WHERE p.is_active=1
    AND (p.name LIKE ? OR COALESCE(p.barcode,'') LIKE ?)
  GROUP BY p.id
  ORDER BY p.id DESC
  LIMIT 200
");
$stmt->bind_param("ss", $like, $like);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Defaults for form */
$form = [
  'id' => 0,
  'name' => '',
  'barcode' => '',
  'image_path' => '',
  'unit' => 'pcs',
  'min_stock' => '0',
  'track_expiry' => 0,
  'mrp' => '0',
  'retail_price' => '0',
  'wholesale_price' => '0',
  'gst_percent' => '0'
];

if($edit){
  $form = [
    'id' => (int)$edit['id'],
    'name' => (string)$edit['name'],
    'barcode' => (string)($edit['barcode'] ?? ''),
    'image_path' => (string)($edit['image_path'] ?? ''),
    'unit' => (string)$edit['unit'],
    'min_stock' => (string)$edit['min_stock'],
    'track_expiry' => (int)$edit['track_expiry'],
    'mrp' => (string)($edit['mrp'] ?? 0),
    'retail_price' => (string)($edit['retail_price'] ?? 0),
    'wholesale_price' => (string)($edit['wholesale_price'] ?? 0),
    'gst_percent' => (string)($edit['gst_percent'] ?? 0)
  ];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Grocery Store • Products</title>
<link rel="stylesheet" href="assets/css/app.css">
<style>
.two{
  display:grid;
  grid-template-columns: 420px minmax(0, 1fr);
  gap:14px;
  align-items:start;
}
@media (max-width: 980px){
  .two{ grid-template-columns: 1fr; }
}

/* ✅ Force both cards to stay in their grid columns */
.two > .card:first-child{ grid-column:1; }
.two > .card:last-child{  grid-column:2; min-width:0; }

/* ✅ Stop any element from painting outside its card */
.two > .card{
  position:relative;
  isolation:isolate;      /* creates a new paint layer */
  overflow:hidden;        /* prevents “behind/overlap” painting */
}

/* table scroll stays inside card only */
.table-wrap{
  overflow:auto;
  max-width:100%;
}

/* IMPORTANT: remove any global table-layout fixed effect */
.plist{ table-layout:auto !important; }
  @media (max-width: 980px){ .two{grid-template-columns:1fr} }

  .field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
  .field label{font-size:12px;color:var(--muted);font-weight:800}
  .input{
    border:1px solid var(--line);
    background:#fbfdfb;
    border-radius:12px;
    padding:10px 12px;
    outline:none;
    font-size:14px;
  }
  .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
  @media(max-width:560px){ .row, .row3{grid-template-columns:1fr} }

  .msg{padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:#fff;margin-bottom:10px}
  .msg.ok{border-color:#bfe6c9;background:#f1fbf4}
  .msg.err{border-color:#f2c5c5;background:#fff5f5}

  .tbl-actions a{font-weight:900;color:var(--g2);text-decoration:none}
  .tbl-actions a:hover{text-decoration:underline}

  .thumb{
    width:42px;height:42px;border-radius:12px;
    border:1px solid var(--line); background:#fff;
    object-fit:cover;
  }
  .imgbox{display:flex;align-items:center;gap:10px}
  .hint{font-size:12px;color:var(--muted);margin-top:6px}

  /* ✅ ONLY product list table styling (no global table rules) */
  .table-wrap{
    border:1px solid var(--line);
    border-radius:14px;
    overflow-x:auto;
    overflow-y:hidden;
    background:#fff;
  }
  .plist{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
  }
  .table-wrap::-webkit-scrollbar{ height:8px; }
  .table-wrap::-webkit-scrollbar-thumb{
    background: rgba(31,122,63,.25);
    border-radius:10px;
  }
  /* ===== FIX: stop right card overflow (grid min-width issue) ===== */
.two{ align-items:start; }          /* keep cards aligned */
.two > .card{ min-width:0; }        /* ✅ MOST IMPORTANT */

/* ===== FIX: table scroll stays inside card only ===== */
.table-wrap{
  width:100%;
  max-width:100%;
  overflow-x:auto;
  overflow-y:hidden;
  display:block;
}

/* table should not force page width */
.plist{
  width:100%;
  min-width:980px;                 /* keeps columns usable */
  table-layout:auto;               /* do NOT force fixed */
}

/* optional: prevent any page horizontal scroll */
html, body{ overflow-x:hidden; }

/* ✅ Stop page-level horizontal scrollbar caused by grid overflow */
.two > .card{ min-width:0; }   /* IMPORTANT for CSS grid children */

/* ✅ Make table wrapper never overflow outside card */
.table-wrap{
  width:100%;
  max-width:100%;
  display:block;
  overflow-x:auto;
  overflow-y:hidden;
}

/* ✅ Keep table inside wrapper */
.plist{
  width:max-content;   /* table can be wider, wrapper will scroll */
  min-width:100%;      /* but not smaller than wrapper */
  border-collapse:separate;
  border-spacing:0;
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

        <div class="two">

          <!-- Form -->
          <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
              <div>
                <div style="font-weight:900;color:var(--g2);font-size:16px">
                  <?php echo $form['id'] ? "Edit Product" : "Add Product"; ?>
                </div>
                <div class="hint">Upload image for POS fast selection.</div>
              </div>
              <?php if($form['id']): ?>
                <a class="pill" href="products.php" style="text-decoration:none">+ New</a>
              <?php endif; ?>
            </div>

            <?php if($success): ?><div class="msg ok"><?php echo h($success); ?></div><?php endif; ?>
            <?php if(!empty($errors)): ?>
              <div class="msg err">
                <b>Fix these:</b>
                <ul style="margin:8px 0 0 18px">
                  <?php foreach($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <?php if($form['image_path']): ?>
              <div class="imgbox" style="margin:10px 0 6px">
                <img class="thumb" src="<?php echo h($form['image_path']); ?>" alt="">
                <div class="muted" style="font-size:12px">Current image</div>
              </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" autocomplete="off">
              <input type="hidden" name="action" value="<?php echo $form['id'] ? 'update' : 'add'; ?>">
              <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">

              <div class="field">
                <label>Product Name *</label>
                <input class="input" name="name" value="<?php echo h($form['name']); ?>" placeholder="e.g., Aashirvaad Atta 10kg">
              </div>

              <div class="row">
                <div class="field">
                  <label>Barcode (optional)</label>
                  <input class="input" name="barcode" value="<?php echo h($form['barcode']); ?>" placeholder="Scan / type barcode">
                </div>
                <div class="field">
                  <label>Unit</label>
                  <select class="input" name="unit">
                    <?php
                      $units = ['pcs','kg','g','ltr','ml','packet','box','bag'];
                      foreach($units as $u){
                        $sel = ($form['unit']===$u) ? 'selected' : '';
                        echo "<option $sel value='".h($u)."'>".h($u)."</option>";
                      }
                    ?>
                  </select>
                </div>
              </div>

              <div class="field">
                <label>Product Image (JPG/PNG/WebP, max 2MB)</label>
                <input class="input" type="file" name="product_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
              </div>

              <div class="row">
                <div class="field">
                  <label>Min Stock (alert)</label>
                  <input class="input" name="min_stock" value="<?php echo h(num($form['min_stock'],3)); ?>" placeholder="0">
                </div>
                <div class="field" style="justify-content:flex-end">
                  <label style="display:flex;align-items:center;gap:10px;margin-top:22px">
                    <input type="checkbox" name="track_expiry" <?php echo ((int)$form['track_expiry']===1?'checked':''); ?>>
                    Track expiry / batch for this product
                  </label>
                </div>
              </div>

              <div class="row3">
                <div class="field">
                  <label>MRP (₹)</label>
                  <input class="input" name="mrp" value="<?php echo h(num($form['mrp'])); ?>" placeholder="0.00">
                </div>
                <div class="field">
                  <label>Retail Price (₹)</label>
                  <input class="input" name="retail_price" value="<?php echo h(num($form['retail_price'])); ?>" placeholder="0.00">
                </div>
                <div class="field">
                  <label>Wholesale Price (₹)</label>
                  <input class="input" name="wholesale_price" value="<?php echo h(num($form['wholesale_price'])); ?>" placeholder="0.00">
                </div>
              </div>

              <div class="row">
                <div class="field">
                  <label>GST %</label>
                  <input class="input" name="gst_percent" value="<?php echo h(num($form['gst_percent'])); ?>" placeholder="0">
                </div>
                <div class="field" style="justify-content:flex-end">
                  <button class="btn btn-primary" type="submit" style="width:100%">
                    <?php echo $form['id'] ? "Update Product" : "Add Product"; ?>
                  </button>
                </div>
              </div>

            </form>
          </div>

          <!-- List -->
          <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px">
              <div>
                <div style="font-weight:900;color:var(--g2);font-size:16px">Product List</div>
                <div class="muted" style="font-size:12px;margin-top:4px">Search by name or barcode.</div>
              </div>

              <form method="get" style="display:flex;gap:8px;align-items:center">
                <input class="input" name="q" value="<?php echo h($q); ?>" placeholder="Search..." style="width:240px">
                <button class="btn" type="submit">Search</button>
              </form>
            </div>

            <div class="table-wrap">
              <table class="plist">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Image</th>
                    <th>Product</th>
                    <th>Prices (₹)</th>
                    <th>GST</th>
                    <th>Stock</th>
                    <th>Expiry Tracking</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                <?php if(empty($rows)): ?>
                  <tr><td colspan="8" class="muted">No products found.</td></tr>
                <?php else: ?>
                  <?php foreach($rows as $r): ?>
                    <tr>
                      <td><?php echo (int)$r['id']; ?></td>
                      <td>
                        <?php if(!empty($r['image_path'])): ?>
                          <img class="thumb" src="<?php echo h($r['image_path']); ?>" alt="">
                        <?php else: ?>
                          <div class="thumb" style="display:grid;place-items:center;color:var(--muted)">🖼️</div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div style="font-weight:900"><?php echo h($r['name']); ?></div>
                        <div class="muted" style="font-size:12px">
                          <?php echo h($r['barcode'] ?: 'No barcode'); ?> • <?php echo h($r['unit']); ?>
                        </div>
                      </td>
                      <td>
                        <div><b>R:</b> <?php echo num($r['retail_price']); ?> <span class="muted">|</span> <b>W:</b> <?php echo num($r['wholesale_price']); ?></div>
                        <div class="muted" style="font-size:12px"><b>MRP:</b> <?php echo num($r['mrp']); ?></div>
                      </td>
                      <td><?php echo num($r['gst_percent']); ?>%</td>
                      <td><b><?php echo num($r['current_stock'],3); ?></b> <span class="muted"><?php echo h($r['unit']); ?></span></td>
                      <td><?php echo ((int)$r['track_expiry']===1) ? 'Tracked' : 'Not tracked'; ?></td>
                      <td class="tbl-actions"><a href="products.php?edit=<?php echo (int)$r['id']; ?>">Edit</a></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="muted" style="margin-top:10px;font-size:12px">
              Stock updates automatically after we build Purchases + POS.
            </div>
          </div>

        </div><!-- two -->
      </div>
    </div>
  </div>
</div>
</body>
</html>