<?php
// grocery/partials/sidebar.php
$active = $active ?? 'dashboard';

function nav_active($key, $active){
  return $key === $active ? 'active' : '';
}
?>
<div class="sidebar">
  <div class="brand">
    <div class="dot"></div>
    <div>
      <div class="title">Unimart International</div>
      <div class="sub">Inventory Cloud</div>
    </div>
  </div>

  <div class="menu">
    <a class="nav-item <?php echo nav_active('dashboard',$active); ?>" href="dashboard.php">
      <div class="nav-ico">🏠</div>
      <div>
        <div class="nav-text">Dashboard</div>
        <div class="nav-hint">Today snapshot</div>
      </div>
    </a>

    <a class="nav-item <?php echo nav_active('products',$active); ?>" href="products.php">
      <div class="nav-ico">📦</div>
      <div>
        <div class="nav-text">Products</div>
        <div class="nav-hint">Items & pricing</div>
      </div>
    </a>

    <a class="nav-item <?php echo nav_active('purchases',$active); ?>" href="purchases.php">
      <div class="nav-ico">🧾</div>
      <div>
        <div class="nav-text">Purchases</div>
        <div class="nav-hint">Stock in</div>
      </div>
    </a>

    <a class="nav-item <?php echo nav_active('retail-pos',$active); ?>" href="retail-pos.php">
      <div class="nav-ico">🛒</div>
      <div>
        <div class="nav-text">Retail POS</div>
        <div class="nav-hint">Fast billing</div>
      </div>
    </a>

    <a class="nav-item <?php echo nav_active('wholesale',$active); ?>" href="wholesale.php">
      <div class="nav-ico">📄</div>
      <div>
        <div class="nav-text">Wholesale</div>
        <div class="nav-hint">Invoices & credit</div>
      </div>
    </a>
    
<a class="nav-item <?php echo (basename($_SERVER['PHP_SELF'])==='customers.php' || basename($_SERVER['PHP_SELF'])==='customer-view.php') ? 'active' : ''; ?>"
   href="customers.php">
  <div class="nav-ico">👥</div>
  <div>
    <div class="nav-text">Customers</div>
    <div class="nav-hint">Customers & credit</div>
  </div>
</a>

<a class="nav-item <?= (basename($_SERVER['PHP_SELF'])==='stock-adjustment.php')?'active':'' ?>" href="stock-adjustment.php">
  <span class="nav-ico">⚙️</span>
  <span class="nav-text">Stock Adjustment</span>
  
 </a>

    <a class="nav-item <?php echo nav_active('stock',$active); ?>" href="stock.php">
      <div class="nav-ico">📊</div>
      <div>
        <div class="nav-text">Stock</div>
        <div class="nav-hint">Ledger & alerts</div>
      </div>
    </a>
    
    <a class="nav-item <?= nav_active('suppliers',$active); ?>" href="/grocery/suppliers.php">
  <span class="nav-ico">🏢</span>
  <span class="nav-text">Suppliers</span>
  <span class="nav-hint">Manage</span>
</a>

<a class="nav-item <?php echo nav_active('analytics',$active); ?>" href="analytics.php">
  <div class="nav-ico">📈</div>
  <div>
    <div class="nav-text">Analytics</div>
    <div class="nav-hint">Business insights</div>
  </div>
</a>

    <a class="nav-item <?php echo nav_active('reports',$active); ?>" href="reports.php">
      <div class="nav-ico">📈</div>
      <div>
        <div class="nav-text">Reports</div>
        <div class="nav-hint">Sales, profit</div>
      </div>
    </a>

    <a class="nav-item <?php echo nav_active('settings',$active); ?>" href="settings.php">
      <div class="nav-ico">⚙️</div>
      <div>
        <div class="nav-text">Settings</div>
        <div class="nav-hint">Users & GST</div>
      </div>
    </a>
  </div>

  <div style="margin-top:14px;padding:10px;border:1px dashed #dbe7dd;border-radius:14px;background:#fbfdfb">
    <div class="muted" style="font-size:12px">
      Theme locked: <b style="color:var(--g2)">Green + White Premium</b>
    </div>
  </div>
</div>