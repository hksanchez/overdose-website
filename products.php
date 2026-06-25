<?php
session_start();
require_once 'includes/db.php';

$page_title = 'Menu — Overdose Cafe';

// Active category from GET param (for sidebar highlight)
$active_cat = isset($_GET['cat']) && $_GET['cat'] === 'pastries' ? 'pastries' : 'coffee';


// Check if any coffee supplies (cups, lids, straws) are out of stock
$supplies_q = $conn->query("SELECT MIN(quantity) AS min_supply FROM inventory WHERE category = 'supplies' AND item_name IN ('Cups', 'Lids', 'Straws')");
$supplies_row = $supplies_q ? $supplies_q->fetch_assoc() : null;
$coffee_supplies_out = ($supplies_row === null || $supplies_row['min_supply'] === null || (int)$supplies_row['min_supply'] <= 0);

// Fetch products grouped by category, joined with inventory stock
$coffee   = $conn->query("SELECT p.*, COALESCE(i.quantity, 1) AS stock FROM products p LEFT JOIN inventory i ON i.linked_product_id = p.id WHERE p.category = 'coffee' ORDER BY p.id");
$pastries = $conn->query("SELECT p.*, COALESCE(i.quantity, 1) AS stock FROM products p LEFT JOIN inventory i ON i.linked_product_id = p.id WHERE p.category = 'pastries' ORDER BY p.id");

// Fetch store settings
$st_q = $conn->query("SELECT * FROM site_settings");
$store_settings = [];
if ($st_q) while($r = $st_q->fetch_assoc()) $store_settings[$r['setting_key']] = $r['setting_value'];
$is_online = ($store_settings['store_status'] ?? 'online') === 'online';
$store_hours = $store_settings['store_hours'] ?? '';

// Fetch promo/sale items for popup
$promo_items = [];
$promo_q = $conn->query("SELECT * FROM products WHERE is_promo = 1 AND promo_price IS NOT NULL ORDER BY id LIMIT 6");
if ($promo_q) while ($pr = $promo_q->fetch_assoc()) $promo_items[] = $pr;

// Show promo popup once per login session
$show_promo_popup = false;
if (!empty($promo_items) && isset($_SESSION['user_id']) && empty($_SESSION['promo_popup_shown'])) {
    $show_promo_popup = true;
    $_SESSION['promo_popup_shown'] = true;
}

// Fetch active vouchers
$vouchers_sql = "SELECT * FROM vouchers WHERE is_active = 1";
if (isset($_SESSION['user_id'])) {
    $uid = intval($_SESSION['user_id']);
    $vouchers_sql .= " AND code NOT IN (SELECT voucher_code FROM orders WHERE user_id = $uid AND voucher_code IS NOT NULL AND voucher_code != '')";
}
$vouchers_sql .= " ORDER BY min_order ASC LIMIT 3";
$vouchers_q = $conn->query($vouchers_sql);
$active_vouchers = [];
if ($vouchers_q) while ($v = $vouchers_q->fetch_assoc()) $active_vouchers[] = $v;

require_once 'includes/header.php';
?>

<?php
$slides = glob('assets/products/slide_*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE);
if (empty($slides)) {
    // Fallback to the default background if no slide_*.jpg files exist
    $slides = ['assets/products/bg.jpg'];
}
?>

<!-- TOP PROMO BANNER -->
<div id="top-promo-banner" class="top-promo-banner">
  <div class="banner-bg">
    <?php foreach($slides as $index => $slide): ?>
      <div class="slide-layer <?= $index === 0 ? 'active' : '' ?>" style="background-image: url('<?= htmlspecialchars($slide) ?>');"></div>
    <?php endforeach; ?>
    <div class="banner-overlay"></div>
  </div>
  <div class="banner-content">
    <div class="banner-tagline">
      One Cup Too Many is <em>Perfect.</em><?php if (!empty($active_vouchers)): ?> Grab yours with these exclusive deals.<?php endif; ?>
    </div>
    <?php if (!empty($active_vouchers)): ?>
    <div class="banner-vouchers-container">
      <span class="banner-title">Available Vouchers:</span>
      <?php foreach ($active_vouchers as $v): ?>
        <span class="banner-voucher">
          <strong><?= htmlspecialchars($v['code']) ?></strong> - 
          <?php if ($v['discount_type'] === 'percent'): ?>
            <?= floatval($v['discount_value']) ?>% OFF
          <?php else: ?>
            ₱<?= floatval($v['discount_value']) ?> OFF
          <?php endif; ?>
        </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <button id="close-promo-banner" class="banner-close">&times;</button>
</div>

<style>
.top-promo-banner {
  position: relative;
  width: 100%;
  padding: 32px 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #F5EDD8;
  font-family: 'DM Sans', sans-serif;
  overflow: hidden;
  transition: opacity 0.4s ease, height 0.4s ease, padding 0.4s ease;
  z-index: 101;
}

.top-promo-banner.hidden {
  opacity: 0;
  pointer-events: none;
  height: 0 !important;
  padding-top: 0;
  padding-bottom: 0;
}

.banner-bg {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  z-index: 0;
}

.slide-layer {
  position: absolute;
  inset: 0;
  background-size: cover;
  background-position: center;
  opacity: 0;
  transition: opacity 1.5s ease-in-out;
}

.slide-layer.active {
  opacity: 1;
}

.banner-overlay {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  z-index: 1;
}

.banner-content {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  width: 100%;
}

.banner-tagline {
  font-family: 'Playfair Display', serif;
  font-size: 1.9rem;
  font-weight: 700;
  color: var(--cream);
  text-align: center;
  margin-bottom: 2px;
  white-space: nowrap;
}

.banner-tagline em {
  color: var(--gold);
  font-style: italic;
}

.banner-vouchers-container {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 16px;
  font-size: 1.0rem;
}

.banner-title {
  font-weight: 700;
  color: var(--gold);
  text-transform: uppercase;
  letter-spacing: 2px;
  font-size: 1.0rem;
}

.banner-voucher {
  background: rgba(212, 175, 90, 0.15);
  border: 1px solid rgba(212, 175, 90, 0.3);
  padding: 6px 14px;
  border-radius: 6px;
  font-weight: 500;
}

.banner-voucher strong {
  color: var(--gold-light);
  letter-spacing: 1px;
}

.banner-close {
  position: absolute;
  right: 30px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  color: rgba(245, 237, 216, 0.6);
  font-size: 2.2rem;
  cursor: pointer;
  z-index: 1;
  transition: color 0.2s;
}

.banner-close:hover {
  color: var(--cream);
}
</style>

<script>
document.addEventListener("DOMContentLoaded", function() {
  const banner = document.getElementById('top-promo-banner');
  if (banner) {
    document.body.insertBefore(banner, document.body.firstChild);
    
    banner.style.height = banner.scrollHeight + "px";
    
    const closeBtn = document.getElementById('close-promo-banner');
    if(closeBtn) {
      closeBtn.addEventListener('click', function() {
        banner.style.height = banner.scrollHeight + "px"; 
        requestAnimationFrame(() => {
          banner.classList.add('hidden');
        });
      });
    }

    const pastriesSection = document.getElementById('pastries');
    window.addEventListener('scroll', function() {
      if (pastriesSection && !banner.classList.contains('hidden')) {
        const rect = pastriesSection.getBoundingClientRect();
        // Hide when pastries section scrolls near the top of the viewport (e.g. 150px)
        if (rect.top <= 150) {
          banner.style.height = banner.scrollHeight + "px"; 
          requestAnimationFrame(() => {
            banner.classList.add('hidden');
          });
        }
      }
    });
    // ── Slideshow Logic ────────────────────────────────────────────────────────
    const slideLayers = document.querySelectorAll('.slide-layer');
    if (slideLayers.length > 1) {
      let currentSlide = 0;
      setInterval(() => {
        slideLayers[currentSlide].classList.remove('active');
        currentSlide = (currentSlide + 1) % slideLayers.length;
        slideLayers[currentSlide].classList.add('active');
      }, 3000);
    }
  }
});
</script>

<?php
// Consume the cart flash for the toast
$cart_flash_name = '';
if (!empty($_SESSION['cart_flash']) && isset($_GET['added'])) {
    $cart_flash_name = $_SESSION['cart_flash'];
    unset($_SESSION['cart_flash']);
}
?>

<?php if ($show_promo_popup): ?>
<!-- ══ PROMO POPUP ════════════════════════════════════════════════════════ -->
<div id="promo-popup-overlay" style="
  position: fixed; inset: 0; z-index: 999;
  background: rgba(0,0,0,0.75);
  backdrop-filter: blur(6px);
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
  animation: promoFadeIn 0.4s ease-out;
">
  <div id="promo-popup-box" style="
    background: #181309;
    border: 1px solid rgba(212,175,90,0.3);
    border-radius: 8px;
    width: 100%;
    max-width: 640px;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 32px 80px rgba(0,0,0,0.6), 0 0 0 1px rgba(212,175,90,0.08);
    animation: promoSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  ">

    <!-- Header -->
    <div style="
      padding: 22px 28px 18px;
      border-bottom: 1px solid rgba(212,175,90,0.12);
      display: flex; align-items: flex-start; justify-content: space-between;
      background: linear-gradient(135deg, rgba(212,175,90,0.06) 0%, transparent 60%);
    ">
      <div>
        <div style="font-size:0.6rem;font-weight:700;letter-spacing:3px;text-transform:uppercase;color:var(--gold);opacity:0.8;margin-bottom:6px;">Limited Time</div>
        <div style="font-family:'Playfair Display',serif;font-size:1.4rem;font-weight:700;color:#F5EDD8;line-height:1.2;">Today's Special Offers</div>
        <div style="font-size:0.8rem;color:rgba(245,237,216,0.5);margin-top:5px;">Handpicked deals just for you — grab them while they last.</div>
      </div>
      <button onclick="document.getElementById('promo-popup-overlay').style.display='none'" style="
        background: rgba(245,237,216,0.05);
        border: 1px solid rgba(245,237,216,0.1);
        border-radius: 50%;
        width: 32px; height: 32px;
        color: rgba(245,237,216,0.5);
        font-size: 1rem;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: all 0.2s;
        flex-shrink: 0;
        margin-left: 16px;
        margin-top: 2px;
      " onmouseover="this.style.background='rgba(245,237,216,0.1)';this.style.color='#F5EDD8'" onmouseout="this.style.background='rgba(245,237,216,0.05)';this.style.color='rgba(245,237,216,0.5)'">✕</button>
    </div>

    <!-- Promo Items Grid -->
    <div style="padding: 24px 28px; overflow-y: auto; flex: 1;">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px;">
        <?php foreach ($promo_items as $pi):
          $pct = abs(round((($pi['price'] - $pi['promo_price']) / max($pi['price'], $pi['promo_price'])) * 100));
        ?>
        <div style="
          background: #1E1710;
          border: 1px solid rgba(212,175,90,0.12);
          border-radius: 6px;
          overflow: hidden;
          transition: border-color 0.2s, transform 0.2s;
        " onmouseover="this.style.borderColor='rgba(212,175,90,0.35)';this.style.transform='translateY(-2px)'" onmouseout="this.style.borderColor='rgba(212,175,90,0.12)';this.style.transform='translateY(0)'">
          <!-- Product Image -->
          <div style="position:relative;aspect-ratio:4/3;overflow:hidden;background:#131008;">
            <img src="<?= htmlspecialchars($pi['image']) ?>" alt="<?= htmlspecialchars($pi['name']) ?>"
              style="width:100%;height:100%;object-fit:cover;"
              onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div style="display:none;width:100%;height:100%;align-items:center;justify-content:center;font-size:2rem;">☕</div>
            <!-- Discount badge -->
            <div style="
              position:absolute;top:8px;right:8px;
              background:#D4AF5A;color:#0D0A06;
              font-size:0.58rem;font-weight:800;letter-spacing:1px;
              padding:3px 7px;border-radius:10px;
            "><?= '-' . $pct . '%' ?></div>
          </div>
          <!-- Product Info -->
          <div style="padding:12px;">
            <div style="font-size:0.8rem;font-weight:600;color:#F5EDD8;margin-bottom:6px;line-height:1.3;"><?= htmlspecialchars($pi['name']) ?></div>
            <div style="display:flex;align-items:center;gap:7px;margin-bottom:10px;">
              <span style="font-size:0.95rem;font-weight:700;color:#D4AF5A;">₱<?= number_format($pi['promo_price'], 2) ?></span>
              <span style="font-size:0.72rem;color:rgba(245,237,216,0.3);text-decoration:line-through;">₱<?= number_format($pi['price'], 2) ?></span>
            </div>
            <form method="POST" action="products.php">
              <input type="hidden" name="add_to_cart" value="1"/>
              <input type="hidden" name="product_id" value="<?= $pi['id'] ?>"/>
              <input type="hidden" name="qty" value="1"/>
              <button type="submit" style="
                width:100%;display:block;text-align:center;
                background:rgba(212,175,90,0.12);border:1px solid rgba(212,175,90,0.25);
                color:#D4AF5A;font-size:0.7rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;
                padding:7px;border-radius:3px;cursor:pointer;font-family:'DM Sans',sans-serif;
                transition:background 0.2s,border-color 0.2s;
              " onmouseover="this.style.background='rgba(212,175,90,0.22)';this.style.borderColor='rgba(212,175,90,0.5)'" onmouseout="this.style.background='rgba(212,175,90,0.12)';this.style.borderColor='rgba(212,175,90,0.25)'">Add to Cart</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Footer -->
    <div style="
      padding: 16px 28px;
      border-top: 1px solid rgba(212,175,90,0.1);
      display: flex; align-items: center; justify-content: space-between;
      background: rgba(212,175,90,0.02);
    ">
      <span style="font-size:0.75rem;color:rgba(245,237,216,0.35);">Prices valid while stocks last.</span>
      <button onclick="document.getElementById('promo-popup-overlay').style.display='none'" style="
        background:transparent;border:1px solid rgba(212,175,90,0.25);
        color:rgba(212,175,90,0.7);font-family:'DM Sans',sans-serif;
        font-size:0.75rem;font-weight:600;letter-spacing:1px;text-transform:uppercase;
        padding:8px 18px;border-radius:2px;cursor:pointer;
        transition:all 0.2s;
      " onmouseover="this.style.background='rgba(212,175,90,0.08)';this.style.color='#D4AF5A'" onmouseout="this.style.background='transparent';this.style.color='rgba(212,175,90,0.7)'">Maybe Later</button>
    </div>
  </div>
</div>

<style>
  @keyframes promoFadeIn  { from { opacity: 0; } to { opacity: 1; } }
  @keyframes promoSlideUp { from { opacity: 0; transform: translateY(30px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
</style>
<?php endif; ?>

<!-- HERO -->
<section class="catalog-hero">
  <div class="hero-label">Full Menu</div>
  <h1>The Overdose<br/><em>Catalog</em></h1>
  <p>Specialty coffee and handcrafted pastries for those who refuse to settle for ordinary. Freshly brewed and baked daily in Manila.</p>
</section>

<!-- CATALOG BODY -->
<?php if (!$is_online): ?>
  <div style="background:rgba(224,85,85,0.08); border-bottom:1px solid rgba(224,85,85,0.2); padding:16px 48px; text-align:center;">
    <div style="color:var(--error); font-weight:700; font-size:0.9rem; margin-bottom:4px;">We are currently closed.</div>
    <div style="color:var(--muted); font-size:0.8rem;"><?= htmlspecialchars($store_hours) ?></div>
  </div>
<?php endif; ?>

<div class="catalog-page">

  <!-- SIDEBAR -->
  <aside class="catalog-sidebar">
    <div class="sidebar-label">Categories</div>
    <ul class="sidebar-nav">
      <li><a href="products.php?cat=coffee#coffee" <?= $active_cat === 'coffee' ? 'class="active"' : '' ?>><span class="nav-dot"></span> Coffee <span class="sidebar-count"><?= $coffee->num_rows ?></span></a></li>
      <li><a href="products.php?cat=pastries#pastries" <?= $active_cat === 'pastries' ? 'class="active"' : '' ?>><span class="nav-dot"></span> Pastries <span class="sidebar-count"><?= $pastries->num_rows ?></span></a></li>
    </ul>

    <div class="sidebar-divider"></div>

    <div class="sidebar-label">My Account</div>
    <ul class="sidebar-nav">
      <li><a href="cart.php"><span class="nav-dot"></span> 🛒 View Cart</a></li>
      <li><a href="orders.php"><span class="nav-dot"></span> 📋 My Orders</a></li>
      <li><a href="settings.php"><span class="nav-dot"></span> ⚙️ Settings</a></li>
    </ul>
  </aside>

  <!-- PRODUCT SECTIONS -->
  <main class="catalog-main">

    <!-- COFFEE -->
    <section class="catalog-section-block" id="coffee">
      <div class="catalog-section-header">
        <h2>Coffee</h2>
        <span class="item-count"><?= $coffee->num_rows ?> items</span>
      </div>
      <p class="catalog-section-desc">Specialty espresso-based drinks, crafted with precision from single-origin and blended beans sourced across the Philippines and beyond.</p>

      <div class="product-grid">
        <?php while ($p = $coffee->fetch_assoc()):
          $is_promo    = $p['is_promo'] && $p['promo_price'];
          $display_price = $is_promo ? $p['promo_price'] : $p['price'];
          $out_of_stock  = $coffee_supplies_out || (int)$p['stock'] <= 0;
        ?>
          <div class="product-card<?= $out_of_stock ? ' out-of-stock' : '' ?>">
            <div class="product-card-img-wrap">
              <!--
                ╔══════════════════════════════════════════════════════╗
                  CHANGE IMAGE HERE
                  Replace the src with your image path.
                  Images are stored in: assets/products/
                  Current file for this product: <?= htmlspecialchars($p['image']) ?>
                  To change: update the 'image' column in the products
                  table in your database, or edit db.php seed data.
                ╚══════════════════════════════════════════════════════╝
              -->
              <img src="<?= htmlspecialchars(trim($p['image'])) ?>?v=<?= @filemtime(trim($p['image'])) ?>"
                   alt="<?= htmlspecialchars($p['name']) ?>"
                   class="product-img-fluid"
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
              <div class="img-fallback">☕</div>
              <?php if ($is_promo): ?>
                <div class="badge-sale">SALE</div>
              <?php endif; ?>
              <?php if ($out_of_stock): ?>
                <div class="badge-unavailable">NOT AVAILABLE</div>
              <?php endif; ?>
            </div>
            <div class="product-card-body">
              <h3><?= htmlspecialchars($p['name']) ?></h3>
              <p class="product-desc"><?= htmlspecialchars($p['description']) ?></p>
              <p class="price">
                ₱<?= number_format($display_price, 2) ?>
                <?php if ($is_promo): ?>
                  <span class="price-old">₱<?= number_format($p['price'], 2) ?></span>
                <?php endif; ?>
              </p>
              <form method="POST" class="card-add-form">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>"/>
                <input type="hidden" name="page_cat" value="coffee"/>
                <input type="number" name="qty" value="1" min="1" max="20" class="card-qty"<?= $out_of_stock ? ' disabled' : '' ?>/>
                <button type="submit" name="add_to_cart" class="btn-card-cart"<?= $out_of_stock ? ' disabled' : '' ?>>
                  + Add to Cart
                </button>
              </form>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    </section>

    <!-- PASTRIES -->
    <section class="catalog-section-block" id="pastries">
      <div class="catalog-section-header">
        <h2>Pastries</h2>
        <span class="item-count"><?= $pastries->num_rows ?> items</span>
      </div>
      <p class="catalog-section-desc">Handcrafted daily using quality butter and seasonal ingredients — from flaky croissants and éclairs to warm cinnamon rolls and delicate egg tarts.</p>

      <div class="product-grid">
        <?php while ($p = $pastries->fetch_assoc()):
          $is_promo    = $p['is_promo'] && $p['promo_price'];
          $display_price = $is_promo ? $p['promo_price'] : $p['price'];
          $out_of_stock  = (int)$p['stock'] <= 0;
        ?>
          <div class="product-card<?= $out_of_stock ? ' out-of-stock' : '' ?>">
            <div class="product-card-img-wrap">
              <!--
                ╔══════════════════════════════════════════════════════╗
                  CHANGE IMAGE HERE
                  Replace the src with your image path.
                  Images are stored in: assets/products/
                  Current file for this product: <?= htmlspecialchars($p['image']) ?>
                  To change: update the 'image' column in the products
                  table in your database, or edit db.php seed data.
                ╚══════════════════════════════════════════════════════╝
              -->
              <img src="<?= htmlspecialchars(trim($p['image'])) ?>?v=<?= @filemtime(trim($p['image'])) ?>"
                   alt="<?= htmlspecialchars($p['name']) ?>"
                   class="product-img-fluid"
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
              <div class="img-fallback">🥐</div>
              <?php if ($is_promo): ?>
                <div class="badge-sale">SALE</div>
              <?php endif; ?>
              <?php if ($out_of_stock): ?>
                <div class="badge-unavailable">NOT AVAILABLE</div>
              <?php endif; ?>
            </div>
            <div class="product-card-body">
              <h3><?= htmlspecialchars($p['name']) ?></h3>
              <p class="product-desc"><?= htmlspecialchars($p['description']) ?></p>
              <p class="price">
                ₱<?= number_format($display_price, 2) ?>
                <?php if ($is_promo): ?>
                  <span class="price-old">₱<?= number_format($p['price'], 2) ?></span>
                <?php endif; ?>
              </p>
              <form method="POST" class="card-add-form">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>"/>
                <input type="hidden" name="page_cat" value="pastries"/>
                <input type="number" name="qty" value="1" min="1" max="20" class="card-qty"<?= $out_of_stock ? ' disabled' : '' ?>/>
                <button type="submit" name="add_to_cart" class="btn-card-cart"<?= $out_of_stock ? ' disabled' : '' ?>>
                  + Add to Cart
                </button>
              </form>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    </section>

  </main>
</div>

<footer class="oc-footer">
  <div>© <?= date('Y') ?> Overdose Cafe · Manila, PH</div>
  <span>Intentional spaces. Exceptional coffee.</span>
</footer>

<style>
  /* ── CART TOAST ── */
  #cart-toast {
    position: fixed;
    bottom: 28px;
    right: 28px;
    z-index: 9000;
    display: none;
    cursor: pointer;
    text-decoration: none;
  }
  #cart-toast.show {
    display: flex;
    animation: toastSlideIn 0.35s cubic-bezier(0.16,1,0.3,1) forwards;
  }
  #cart-toast.hide {
    animation: toastSlideOut 0.3s ease-in forwards;
  }
  .toast-inner {
    display: flex;
    align-items: center;
    gap: 14px;
    background: #1E1710;
    border: 1px solid rgba(212,175,90,0.3);
    border-radius: 6px;
    padding: 14px 18px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.5), 0 0 0 1px rgba(212,175,90,0.06);
    min-width: 260px;
    max-width: 320px;
    position: relative;
    overflow: hidden;
  }
  .toast-icon {
    font-size: 1.3rem;
    flex-shrink: 0;
  }
  .toast-text {
    flex: 1;
  }
  .toast-title {
    font-size: 0.78rem;
    font-weight: 700;
    color: #F5EDD8;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 200px;
  }
  .toast-sub {
    font-size: 0.68rem;
    color: rgba(212,175,90,0.8);
    font-weight: 600;
    letter-spacing: 0.5px;
  }
  .toast-close {
    font-size: 0.85rem;
    color: rgba(245,237,216,0.3);
    margin-left: 4px;
    flex-shrink: 0;
    transition: color 0.2s;
  }
  #cart-toast:hover .toast-close { color: rgba(245,237,216,0.7); }
  .toast-progress {
    position: absolute;
    bottom: 0; left: 0;
    height: 2px;
    background: rgba(212,175,90,0.6);
    border-radius: 0 0 0 6px;
    animation: toastProgress 4s linear forwards;
  }
  @keyframes toastSlideIn {
    from { opacity: 0; transform: translateX(20px) translateY(8px); }
    to   { opacity: 1; transform: translateX(0) translateY(0); }
  }
  @keyframes toastSlideOut {
    from { opacity: 1; transform: translateX(0); }
    to   { opacity: 0; transform: translateX(24px); }
  }
  @keyframes toastProgress {
    from { width: 100%; }
    to   { width: 0%; }
  }

  /* ── HERO ── */
  .catalog-hero {
    background: linear-gradient(180deg, var(--surface) 0%, var(--bg) 100%);
    border-bottom: 1px solid var(--border);
    padding: 72px 80px 52px;
    position: relative;
    overflow: hidden;
  }

  .catalog-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse 50% 80% at 90% 50%, rgba(100,60,10,0.18) 0%, transparent 70%);
    pointer-events: none;
  }

  .hero-label {
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--gold);
    opacity: 0.7;
    margin-bottom: 16px;
  }

  .catalog-hero h1 {
    font-family: 'Playfair Display', serif;
    font-size: 3.2rem;
    font-weight: 900;
    color: var(--cream);
    line-height: 1.1;
    margin-bottom: 18px;
    max-width: 560px;
  }

  .catalog-hero h1 em { color: var(--gold); font-style: italic; }

  .catalog-hero p {
    font-size: 0.9rem;
    color: var(--muted);
    max-width: 500px;
    line-height: 1.75;
  }

  /* ── CATALOG LAYOUT ── */
  .catalog-page {
    display: grid;
    grid-template-columns: 220px 1fr;
    min-height: calc(100vh - var(--nav-h));
  }

  .catalog-sidebar {
    position: sticky;
    top: var(--nav-h);
    height: calc(100vh - var(--nav-h));
    overflow-y: auto;
    padding: 40px 20px 40px 28px;
    border-right: 1px solid var(--border);
    scrollbar-width: none;
    background: var(--surface);
  }

  .catalog-sidebar::-webkit-scrollbar { display: none; }

  .sidebar-label {
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    color: var(--gold);
    opacity: 0.6;
    margin-bottom: 12px;
    padding: 0 10px;
  }

  .sidebar-nav {
    list-style: none;
    margin-bottom: 24px;
  }

  .sidebar-nav a {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 10px;
    border-radius: 3px;
    font-size: 0.83rem;
    font-weight: 500;
    color: var(--muted);
    text-decoration: none;
    transition: all 0.2s;
    border-left: 2px solid transparent;
  }

  .sidebar-nav a:hover {
    color: var(--cream);
    background: rgba(212,175,90,0.05);
  }

  .sidebar-nav a.active {
    color: var(--cream);
    border-left-color: var(--gold);
    background: rgba(212,175,90,0.07);
    font-weight: 600;
  }

  .sidebar-count {
    margin-left: auto;
    font-size: 0.68rem;
    color: var(--gold);
    opacity: 0.6;
    background: rgba(212,175,90,0.1);
    border-radius: 10px;
    padding: 1px 7px;
  }

  .nav-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--border);
    flex-shrink: 0;
  }

  .sidebar-divider {
    height: 1px;
    background: var(--border);
    margin: 20px 0;
  }

  /* ── CATALOG MAIN ── */
  .catalog-main { padding: 48px 48px 64px; }

  .catalog-section-block { margin-bottom: 64px; }

  .catalog-section-header {
    display: flex;
    align-items: baseline;
    gap: 14px;
    margin-bottom: 10px;
  }

  .catalog-section-header h2 {
    font-family: 'Playfair Display', serif;
    font-size: 1.6rem;
    font-weight: 700;
    color: var(--cream);
  }

  .item-count {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 1px;
    color: var(--gold);
    opacity: 0.6;
    background: rgba(212,175,90,0.1);
    border: 1px solid rgba(212,175,90,0.2);
    border-radius: 2px;
    padding: 2px 9px;
  }

  .catalog-section-desc {
    font-size: 0.82rem;
    color: var(--muted);
    line-height: 1.7;
    margin-bottom: 28px;
    max-width: 560px;
  }

  /* ── PRODUCT GRID ── */
  .product-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
  }

  .product-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 4px;
    overflow: hidden;
    transition: border-color 0.2s, transform 0.2s;
  }

  .product-card:hover {
    border-color: rgba(212,175,90,0.35);
    transform: translateY(-2px);
  }

  .product-card-img-wrap {
    position: relative;
    aspect-ratio: 1/1;
    background: var(--panel);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    overflow: hidden;
  }

  /* ─────────────────────────────────────────────────────
     PRODUCT IMAGES
     Images are pulled from the `image` column in the
     products table (set in db.php seed or your DB).
     Path format: assets/products/filename.jpg
     To swap an image: update that column in the DB.
  ───────────────────────────────────────────────────── */
  .product-img-fluid {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .img-fallback {
    display: none;
    width: 100%;
    height: 100%;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    opacity: 0.35;
  }

  .promo-tag {
    position: absolute;
    top: 10px;
    left: 10px;
    background: var(--gold);
    color: #0A0804;
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 1.5px;
    padding: 3px 8px;
    border-radius: 2px;
    z-index: 1;
  }

  /* ── SALE BADGE (upper-right of image) ── */
  .badge-sale {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #e63946;
    color: #fff;
    font-size: 0.62rem;
    font-weight: 800;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    padding: 4px 9px;
    border-radius: 3px;
    z-index: 2;
    box-shadow: 0 2px 8px rgba(230,57,70,0.45);
    display: flex;
    align-items: center;
    gap: 4px;
  }

  /* ── NOT AVAILABLE BADGE (upper-left of image) ── */
  .badge-unavailable {
    position: absolute;
    top: 10px;
    left: 10px;
    background: #555;
    color: #fff;
    font-size: 0.62rem;
    font-weight: 800;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    padding: 4px 9px;
    border-radius: 3px;
    z-index: 2;
    box-shadow: 0 2px 8px rgba(0,0,0,0.35);
  }

  /* Dim out-of-stock cards slightly */
  .product-card.out-of-stock {
    opacity: 0.75;
  }

  .product-card.out-of-stock .btn-card-cart {
    background: rgba(120,120,120,0.12);
    border-color: rgba(120,120,120,0.2);
    color: var(--muted);
    cursor: not-allowed;
  }

  .product-card.out-of-stock .card-qty {
    opacity: 0.4;
    cursor: not-allowed;
  }

  .product-card-body { padding: 14px 16px 16px; }

  .product-card-body h3 {
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--cream);
    margin-bottom: 4px;
    line-height: 1.35;
  }

  .product-desc {
    font-size: 0.74rem;
    color: var(--muted);
    line-height: 1.5;
    margin-bottom: 10px;
    min-height: 32px;
  }

  .price {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--gold);
    margin-bottom: 2px;
  }

  .price-old {
    font-size: 0.72rem;
    color: var(--muted2);
    text-decoration: line-through;
    margin-left: 6px;
    font-weight: 400;
  }

  .card-add-form {
    display: flex;
    gap: 6px;
    margin-top: 12px;
  }

  .card-qty {
    width: 48px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 2px;
    padding: 6px 6px;
    color: var(--cream);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.82rem;
    text-align: center;
    outline: none;
  }

  .card-qty:focus { border-color: var(--gold); }

  .btn-card-cart {
    flex: 1;
    background: rgba(212,175,90,0.1);
    border: 1px solid rgba(212,175,90,0.22);
    border-radius: 2px;
    color: var(--gold);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.72rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    padding: 6px 8px;
  }

  .btn-card-cart:hover {
    background: var(--gold);
    color: #0A0804;
    border-color: var(--gold);
  }

  @media (max-width: 1200px) { .product-grid { grid-template-columns: repeat(3, 1fr); } }
  @media (max-width: 900px)  { .catalog-page { grid-template-columns: 1fr; } .catalog-sidebar { display: none; } }
  @media (max-width: 700px)  { .product-grid { grid-template-columns: repeat(2, 1fr); } .catalog-main { padding: 32px 20px; } }
</style>

<!-- CART TOAST (always in DOM, shown via JS) -->
<a href="cart.php" id="cart-toast">
  <div class="toast-inner">
    <div class="toast-icon">✅</div>
    <div class="toast-text">
      <div class="toast-title" id="toast-name"></div>
      <div class="toast-sub">Added to cart · Tap to view →</div>
    </div>
    <div class="toast-close" id="toast-close">✕</div>
    <div class="toast-progress" id="toast-progress"></div>
  </div>
</a>

<script>
(function () {
  var toast      = document.getElementById('cart-toast');
  var toastName  = document.getElementById('toast-name');
  var toastClose = document.getElementById('toast-close');
  var toastProg  = document.getElementById('toast-progress');
  var toastTimer = null;

  function showToast(name) {
    toastName.textContent = name;
    // Reset animation
    toastProg.style.animation = 'none';
    void toastProg.offsetWidth; // reflow
    toastProg.style.animation = 'toastProgress 4s linear forwards';

    toast.classList.remove('hide');
    toast.classList.add('show');

    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      dismissToast();
    }, 4000);
  }

  function dismissToast() {
    toast.classList.add('hide');
    setTimeout(function () {
      toast.classList.remove('show', 'hide');
    }, 300);
  }

  toastClose.addEventListener('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    clearTimeout(toastTimer);
    dismissToast();
  });

  // Intercept ALL add-to-cart forms
  document.querySelectorAll('.card-add-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault(); // stop page reload/scroll

      var data = new FormData(form);
      data.append('add_to_cart', '1');

      fetch('add_to_cart.php', {
        method: 'POST',
        body: data,
        credentials: 'same-origin'
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.error === 'not_logged_in') {
          window.location.href = 'login.php';
          return;
        }
        if (res.success) {
          // Update cart badge in nav
          var badge = document.querySelector('.cart-badge');
          if (badge) badge.textContent = res.cart_count;
          showToast(res.name);
        }
      })
      .catch(function () {
        // Fallback: let form submit normally
        form.submit();
      });
    });
  });

  // Also intercept the promo popup forms
  document.querySelectorAll('#promo-popup-overlay form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var data = new FormData(form);
      fetch('add_to_cart.php', {
        method: 'POST',
        body: data,
        credentials: 'same-origin'
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) {
          var badge = document.querySelector('.cart-badge');
          if (badge) badge.textContent = res.cart_count;
          document.getElementById('promo-popup-overlay').style.display = 'none';
          showToast(res.name);
        }
      });
    });
  });
})();
</script>
<!-- BACK TO TOP BUTTON -->
<button id="back-to-top" class="back-to-top" title="Back to Top">
  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
    <path fill-rule="evenodd" d="M8 15a.5.5 0 0 0 .5-.5V2.707l3.146 3.147a.5.5 0 0 0 .708-.708l-4-4a.5.5 0 0 0-.708 0l-4 4a.5.5 0 1 0 .708.708L7.5 2.707V14.5a.5.5 0 0 0 .5.5z"/>
  </svg>
</button>

<style>
.back-to-top {
  position: fixed;
  bottom: 30px;
  right: 30px;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: var(--gold);
  color: var(--bg);
  border: none;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
  z-index: 1000;
  transition: transform 0.2s, background 0.2s, opacity 0.3s;
  opacity: 0;
  pointer-events: none;
}
.back-to-top.visible {
  opacity: 1;
  pointer-events: auto;
}
.back-to-top:hover {
  background: var(--gold-light);
  transform: translateY(-3px);
}
</style>

<script>
document.addEventListener("DOMContentLoaded", function() {
  const backToTop = document.getElementById('back-to-top');
  const sections = document.querySelectorAll('.catalog-section-block');
  const navLinks = document.querySelectorAll('.sidebar-nav a[href*="#"]');
  
  // Back to top click
  let clickedToTop = false;
  if (backToTop) {
    backToTop.addEventListener('click', function() {
      window.scrollTo({ top: 0, behavior: 'smooth' });
      backToTop.classList.remove('visible');
      clickedToTop = true;
      // Allow it to show again after user manually scrolls later
      setTimeout(() => { clickedToTop = false; }, 1000); 
    });
  }

  // Scroll spy logic
  window.addEventListener('scroll', function() {
    if (backToTop && !clickedToTop) {
      if (window.scrollY > 300) {
        backToTop.classList.add('visible');
      } else {
        backToTop.classList.remove('visible');
      }
    }

    let current = '';
    
    sections.forEach(section => {
      const sectionTop = section.offsetTop;
      // Adjust offset to trigger slightly before reaching the top
      if (window.scrollY >= sectionTop - 140) {
        current = section.getAttribute('id');
      }
    });

    if (current) {
      navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('href').includes('#' + current)) {
          link.classList.add('active');
        }
      });
    }
  });
});
</script>

</body>
</html>
