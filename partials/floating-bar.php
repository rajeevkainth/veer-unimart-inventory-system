<?php
/*  public_html/grocery/partials/floating-bar.php
    UniMart Compact + Draggable Floating Inventory Bar
*/
?>
<style>
:root{
  --um-green:#16a34a;
  --um-green2:#22c55e;
  --um-ink:#0f172a;
  --um-card:#ffffff;
  --um-shadow: 0 16px 40px rgba(2,6,23,.14);
}

/* ✅ Compact draggable bar */
.um-floatbar{
  position:fixed;
  left:16px;               /* default */
  top:160px;               /* default */
  z-index:9999;

  display:flex;
  flex-direction:column;
  gap:8px;

  padding:8px;
  border-radius:18px;

  background: linear-gradient(180deg, rgba(34,197,94,.10), rgba(255,255,255,.92));
  border:1px solid rgba(15,23,42,.08);
  box-shadow: var(--um-shadow);
  backdrop-filter: blur(10px);

  width:64px;              /* ✅ less wide */
  user-select:none;
}

/* drag handle (top grip) */
.um-drag-handle{
  display:flex;
  align-items:center;
  justify-content:center;
  height:18px;
  border-radius:12px;
  background: rgba(15,23,42,.04);
  border:1px solid rgba(15,23,42,.06);
  cursor:grab;
}
.um-dots{
  width:20px;
  height:10px;
  background:
    radial-gradient(circle, rgba(15,23,42,.35) 2px, transparent 3px) 0 0/10px 10px;
  border-radius:8px;
  opacity:.7;
}
.um-floatbar.is-dragging .um-drag-handle{ cursor:grabbing; }

/* Buttons */
.um-floatbar-item,
.um-floatbar-toggle{
  position:relative;
  width:48px;
  height:48px;
  margin:0 auto;
  border-radius:16px;
  border:1px solid rgba(15,23,42,.08);
  background:var(--um-card);

  display:flex;
  align-items:center;
  justify-content:center;

  text-decoration:none;
  cursor:pointer;

  transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease, opacity .16s ease;
}

.um-floatbar-item:hover,
.um-floatbar-toggle:hover{
  transform: translateY(-1px);
  border-color: rgba(34,197,94,.45);
  box-shadow: 0 10px 22px rgba(34,197,94,.18);
}

.um-floatbar-toggle{
  background: linear-gradient(135deg, var(--um-green), var(--um-green2));
  color:#fff;
  font-weight:900;
}

/* pill icon like your screenshot */
.um-ico-pill{
  width:30px; height:30px;
  border-radius:12px;
  display:flex; align-items:center; justify-content:center;
  background:#f1f5f9;
  border:1px solid rgba(15,23,42,.06);
  font-size:15px;
  font-weight:900;
  color:#0f172a;
  line-height:1;
}

/* Tooltip */
.um-tip{
  position:absolute;
  left:56px;
  white-space:nowrap;

  padding:8px 10px;
  border-radius:12px;
  background:#fff;
  border:1px solid rgba(15,23,42,.08);
  box-shadow: 0 12px 30px rgba(2,6,23,.12);

  font-size:13px;
  font-weight:800;
  color:var(--um-ink);

  opacity:0;
  transform: translateX(-6px);
  pointer-events:none;
  transition: opacity .14s ease, transform .14s ease;
}
.um-floatbar-item:hover .um-tip{ opacity:1; transform: translateX(0); }

/* Collapsed state */
.um-floatbar.is-collapsed{ padding:8px; }
.um-floatbar.is-collapsed .um-floatbar-item{
  opacity:0;
  transform: translateX(-8px);
  pointer-events:none;
  height:0;
  margin:0;
  border:0;
  overflow:hidden;
}
.um-floatbar.is-collapsed .um-floatbar-toggle{
  width:50px;
  height:50px;
  border-radius:18px;
}

/* Mobile: hide tooltip */
@media (max-width: 640px){
  .um-tip{ display:none; }
}
</style>

<?php
$cur = basename($_SERVER['PHP_SELF'] ?? '');
function active_if($cur, array $files){ return in_array($cur,$files,true) ? ' style="border-color:rgba(34,197,94,.55);box-shadow:0 10px 22px rgba(34,197,94,.18)"' : ''; }
?>

<div class="um-floatbar" id="umFloatBar" aria-label="Quick menu">
  <!-- Drag grip -->
  <div class="um-drag-handle" id="umDragHandle" title="Drag to move">
    <div class="um-dots"></div>
  </div>

  <!-- Toggle -->
  <button class="um-floatbar-toggle" id="umFloatToggle" type="button" aria-expanded="true" title="Close">
    ✕
  </button>

  <!-- MENU (absolute /grocery/ paths to avoid relative issues) -->
  <a class="um-floatbar-item" href="/grocery/dashboard.php" aria-label="Dashboard"<?= active_if($cur,['dashboard.php']) ?>>
    <span class="um-ico-pill" style="color:#16a34a;">⌂</span><span class="um-tip">Dashboard</span>
  </a>

  <a class="um-floatbar-item" href="/grocery/products.php" aria-label="Products"<?= active_if($cur,['products.php']) ?>>
    <span class="um-ico-pill" style="color:#0f172a;">P</span><span class="um-tip">Products</span>
  </a>

  <a class="um-floatbar-item" href="/grocery/customers.php" aria-label="Customers"<?= active_if($cur,['customers.php','customer-view.php']) ?>>
    <span class="um-ico-pill" style="color:#2563eb;">C</span><span class="um-tip">Customers</span>
  </a>

  <a class="um-floatbar-item" href="/grocery/orders.php" aria-label="Orders / Billing"<?= active_if($cur,['orders.php','invoice-view.php']) ?>>
    <span class="um-ico-pill" style="color:#f59e0b;">€</span><span class="um-tip">Orders / Billing</span>
  </a>

  <a class="um-floatbar-item" href="/grocery/stock-ledger.php" aria-label="Stock Ledger"<?= active_if($cur,['stock-ledger.php']) ?>>
    <span class="um-ico-pill" style="color:#0ea5e9;">L</span><span class="um-tip">Stock Ledger</span>
  </a>

  <a class="um-floatbar-item" href="/grocery/stock-adjustment.php" aria-label="Stock Adjustment"<?= active_if($cur,['stock-adjustment.php']) ?>>
    <span class="um-ico-pill" style="color:#16a34a;">±</span><span class="um-tip">Stock Adjustment</span>
  </a>
</div>

<script>
(function(){
  const bar = document.getElementById('umFloatBar');
  const toggle = document.getElementById('umFloatToggle');
  const handle = document.getElementById('umDragHandle');
  if(!bar || !toggle || !handle) return;

  const KEY_COLLAPSE = 'um_floatbar_collapsed_v2';
  const KEY_POS = 'um_floatbar_pos_v2';

  // Apply saved collapsed state
  const applyCollapsed = (collapsed) => {
    bar.classList.toggle('is-collapsed', collapsed);
    toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    toggle.title = collapsed ? 'Open' : 'Close';
    toggle.textContent = collapsed ? '☰' : '✕';
  };

  // Apply saved position
  const applyPos = () => {
    try{
      const raw = localStorage.getItem(KEY_POS);
      if(!raw) return;
      const pos = JSON.parse(raw);
      if(typeof pos.x === 'number' && typeof pos.y === 'number'){
        bar.style.left = pos.x + 'px';
        bar.style.top  = pos.y + 'px';
      }
    }catch(e){}
  };

  applyPos();
  applyCollapsed(localStorage.getItem(KEY_COLLAPSE) === '1');

  toggle.addEventListener('click', () => {
    const collapsed = !bar.classList.contains('is-collapsed');
    localStorage.setItem(KEY_COLLAPSE, collapsed ? '1' : '0');
    applyCollapsed(collapsed);
  });

  // Drag logic (mouse + touch)
  let dragging=false, startX=0, startY=0, startLeft=0, startTop=0;

  function clamp(v, min, max){ return Math.min(Math.max(v,min),max); }

  function beginDrag(clientX, clientY){
    dragging = true;
    bar.classList.add('is-dragging');
    startX = clientX;
    startY = clientY;
    const rect = bar.getBoundingClientRect();
    startLeft = rect.left;
    startTop  = rect.top;
  }

  function moveDrag(clientX, clientY){
    if(!dragging) return;

    const dx = clientX - startX;
    const dy = clientY - startY;

    const w = bar.offsetWidth;
    const h = bar.offsetHeight;

    const maxX = window.innerWidth  - w - 8;
    const maxY = window.innerHeight - h - 8;

    const x = clamp(startLeft + dx, 8, maxX);
    const y = clamp(startTop  + dy, 8, maxY);

    bar.style.left = x + 'px';
    bar.style.top  = y + 'px';

    localStorage.setItem(KEY_POS, JSON.stringify({x, y}));
  }

  function endDrag(){
    if(!dragging) return;
    dragging = false;
    bar.classList.remove('is-dragging');
  }

  // Mouse
  handle.addEventListener('mousedown', (e)=>{
    e.preventDefault();
    beginDrag(e.clientX, e.clientY);
  });
  window.addEventListener('mousemove', (e)=> moveDrag(e.clientX, e.clientY));
  window.addEventListener('mouseup', endDrag);

  // Touch
  handle.addEventListener('touchstart', (e)=>{
    const t = e.touches[0];
    beginDrag(t.clientX, t.clientY);
  }, {passive:true});
  window.addEventListener('touchmove', (e)=>{
    const t = e.touches[0];
    if(t) moveDrag(t.clientX, t.clientY);
  }, {passive:true});
  window.addEventListener('touchend', endDrag);

})();
</script>