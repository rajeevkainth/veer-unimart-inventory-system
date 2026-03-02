<?php
// grocery/partials/topbar.php
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<div class="topbar">
  <div style="display:flex;align-items:center;gap:12px;min-width:260px">
    <div style="font-weight:900;color:var(--g2);font-size:16px"><?php echo htmlspecialchars($pageTitle); ?></div>
    <span class="pill"><?php echo date('d M Y'); ?></span>
  </div>

  <div class="search">
    🔎
    <input type="text" placeholder="Search product / invoice / customer (coming soon)" disabled>
  </div>

  <div class="btn-group">
    <button class="btn btn-primary" onclick="location.href='pos.php'">+ Retail Sale</button>
    <button class="btn" onclick="location.href='wholesale.php'">+ Wholesale</button>
    <button class="btn" onclick="location.href='purchases.php'">+ Purchase</button>
  </div>
</div>