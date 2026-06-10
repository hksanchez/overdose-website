<?php
session_start();
require_once 'includes/db.php';

$page_title = 'Menu — Overdose Cafe';

// Active category from GET param (for sidebar highlight)
$active_cat = isset($_GET['cat']) && $_GET['cat'] === 'pastries' ? 'pastries' : 'coffee';

// Handle add to cart
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }
    $pid = (int)$_POST['product_id'];
    $qty = max(1, (int)($_POST['qty'] ?? 1));
    if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
    if (isset($_SESSION['cart'][$pid])) {
        $_SESSION['cart'][$pid]['qty'] += $qty;
    } else {
        $pq = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $pq->bind_param("i", $pid);
        $pq->execute();
        $pr = $pq->get_result()->fetch_assoc();
        if ($pr) {
            $_SESSION['cart'][$pid] = [
                'id'    => $pr['id'],
                'name'  => $pr['name'],
                'price' => $pr['is_promo'] && $pr['promo_price'] ? $pr['promo_price'] : $pr['price'],
                'qty'   => $qty
            ];
        }
    }
    $msg = 'Added to cart!';
}

// Fetch products grouped by category
$coffee   = $conn->query("SELECT * FROM products WHERE category = 'coffee' ORDER BY id");
$pastries = $conn->query("SELECT * FROM products WHERE category = 'pastries' ORDER BY id");

require_once 'includes/header.php';
?>

<!-- ALERT -->
<?php if ($msg): ?>
  <div class="alert-bar">✓ <?= htmlspecialchars($msg) ?> — <a href="cart.php" style="color:inherit;font-weight:700;">View Cart →</a></div>
<?php endif; ?>

<!-- HERO -->
<section class="catalog-hero">
  <div class="hero-label">Overdose Cafe · Full Menu</div>
  <h1>The Overdose<br/><em>Catalog</em></h1>
  <p>Specialty coffee and handcrafted pastries for those who refuse to settle for ordinary. Freshly brewed and baked daily in Manila.</p>
</section>

<!-- CATALOG BODY -->
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
          $display_price = ($p['is_promo'] && $p['promo_price']) ? $p['promo_price'] : $p['price'];
        ?>
          <div class="product-card">
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
              <img src="<?= htmlspecialchars(trim($p['image'])) ?>"
                   alt="<?= htmlspecialchars($p['name']) ?>"
                   class="product-img-fluid"
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
              <div class="img-fallback">☕</div>
              <?php if ($p['is_promo']): ?>
                <span class="promo-tag">PROMO</span>
              <?php endif; ?>
            </div>
            <div class="product-card-body">
              <h3><?= htmlspecialchars($p['name']) ?></h3>
              <p class="product-desc"><?= htmlspecialchars($p['description']) ?></p>
              <p class="price">
                ₱<?= number_format($display_price, 2) ?>
                <?php if ($p['is_promo'] && $p['promo_price']): ?>
                  <span class="price-old">₱<?= number_format($p['price'], 2) ?></span>
                <?php endif; ?>
              </p>
              <form method="POST" class="card-add-form">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>"/>
                <input type="number" name="qty" value="1" min="1" max="20" class="card-qty"/>
                <button type="submit" name="add_to_cart" class="btn-card-cart">+ Add to Cart</button>
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
          $display_price = ($p['is_promo'] && $p['promo_price']) ? $p['promo_price'] : $p['price'];
        ?>
          <div class="product-card">
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
              <img src="<?= htmlspecialchars(trim($p['image'])) ?>"
                   alt="<?= htmlspecialchars($p['name']) ?>"
                   class="product-img-fluid"
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
              <div class="img-fallback">🥐</div>
              <?php if ($p['is_promo']): ?>
                <span class="promo-tag">PROMO</span>
              <?php endif; ?>
            </div>
            <div class="product-card-body">
              <h3><?= htmlspecialchars($p['name']) ?></h3>
              <p class="product-desc"><?= htmlspecialchars($p['description']) ?></p>
              <p class="price">
                ₱<?= number_format($display_price, 2) ?>
                <?php if ($p['is_promo'] && $p['promo_price']): ?>
                  <span class="price-old">₱<?= number_format($p['price'], 2) ?></span>
                <?php endif; ?>
              </p>
              <form method="POST" class="card-add-form">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>"/>
                <input type="number" name="qty" value="1" min="1" max="20" class="card-qty"/>
                <button type="submit" name="add_to_cart" class="btn-card-cart">+ Add to Cart</button>
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
  <span>Crafted with ☕ and too much caffeine</span>
</footer>

<style>
  /* ── ALERT BAR ── */
  .alert-bar {
    background: rgba(91,173,126,0.1);
    border-bottom: 1px solid rgba(91,173,126,0.25);
    padding: 12px 48px;
    font-size: 0.83rem;
    color: #5BAD7E;
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

</body>
</html>