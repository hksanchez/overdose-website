<?php
session_start();
require_once 'includes/db.php';

$page_title = 'Cart — Overdose Cafe';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$msg = '';
$error = '';

// Remove item
if (isset($_GET['remove'])) {
    $rid = (int)$_GET['remove'];
    unset($_SESSION['cart'][$rid]);
    header("Location: cart.php");
    exit();
}

// Update quantities
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cart'])) {
    foreach ($_POST['quantities'] as $pid => $qty) {
        $qty = (int)$qty;
        if ($qty <= 0) {
            unset($_SESSION['cart'][$pid]);
        } else {
            $_SESSION['cart'][$pid]['qty'] = $qty;
        }
    }
    header("Location: cart.php");
    exit();
}

// Apply voucher
$discount    = 0;
$voucher_msg = '';
$voucher_code = $_SESSION['voucher_code'] ?? '';
$discount_val = $_SESSION['voucher_discount'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_voucher'])) {
    $code = strtoupper(trim($_POST['voucher_code']));
    if ($code) {
        $stmt = $conn->prepare("SELECT * FROM vouchers WHERE code = ? AND is_active = 1");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $v = $stmt->get_result()->fetch_assoc();

        // Calculate subtotal
        $sub = 0;
        if (isset($_SESSION['cart'])) {
            foreach ($_SESSION['cart'] as $item) {
                $sub += $item['price'] * $item['qty'];
            }
        }

        if (!$v) {
            $error = 'Invalid or expired voucher code.';
            $_SESSION['voucher_code'] = '';
            $_SESSION['voucher_discount'] = 0;
        } elseif ($sub < $v['min_order']) {
            $error = 'Minimum order of ₱' . number_format($v['min_order'], 2) . ' required for this voucher.';
            $_SESSION['voucher_code'] = '';
            $_SESSION['voucher_discount'] = 0;
        } else {
            $_SESSION['voucher_code'] = $code;
            if ($v['discount_type'] === 'percent') {
                $_SESSION['voucher_discount'] = round($sub * ($v['discount_value'] / 100), 2);
            } else {
                $_SESSION['voucher_discount'] = min($v['discount_value'], $sub);
            }
            $voucher_msg = 'Voucher applied! You saved ₱' . number_format($_SESSION['voucher_discount'], 2) . '.';
        }
        $voucher_code = $_SESSION['voucher_code'] ?? '';
        $discount_val = $_SESSION['voucher_discount'] ?? 0;
    }
}

// Place order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    if (empty($_SESSION['cart'])) {
        $error = 'Your cart is empty.';
    } else {
        $sub = 0;
        foreach ($_SESSION['cart'] as $item) {
            $sub += $item['price'] * $item['qty'];
        }
        $disc = $_SESSION['voucher_discount'] ?? 0;
        $total = $sub - $disc;
        $vcode = $_SESSION['voucher_code'] ?? null;
        $uid = $_SESSION['user_id'];

        $stmt = $conn->prepare("INSERT INTO orders (user_id, total_amount, discount, voucher_code, status) VALUES (?, ?, ?, ?, 'Pending')");
        $stmt->bind_param("idds", $uid, $total, $disc, $vcode);
        $stmt->execute();
        $order_id = $conn->insert_id;

        $istmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        foreach ($_SESSION['cart'] as $item) {
            $istmt->bind_param("iiid", $order_id, $item['id'], $item['qty'], $item['price']);
            $istmt->execute();
        }

        // Clear cart and voucher
        unset($_SESSION['cart'], $_SESSION['voucher_code'], $_SESSION['voucher_discount']);
        header("Location: orders.php?placed=" . $order_id);
        exit();
    }
}

// Calculate totals
$subtotal = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $subtotal += $item['price'] * $item['qty'];
    }
}
$disc_applied = $_SESSION['voucher_discount'] ?? 0;
$total = $subtotal - $disc_applied;

require_once 'includes/header.php';
?>

<div class="page-layout">
  <aside class="page-sidebar">
    <div class="sidebar-section-label">Menu</div>
    <ul class="sidebar-menu">
      <li><a href="products.php"><span class="menu-icon">☕</span> Products</a></li>
      <li><a href="cart.php" class="active"><span class="menu-icon">🛒</span> Cart</a></li>
      <li><a href="orders.php"><span class="menu-icon">📋</span> My Orders</a></li>
      <li><a href="settings.php"><span class="menu-icon">⚙️</span> Settings</a></li>
    </ul>
    <div class="sidebar-divider"></div>
    <div style="padding:0 10px;">
      <p style="font-size:0.72rem;color:var(--muted2);line-height:1.6;">Available vouchers:<br/>
        <span style="color:var(--gold);font-weight:600;">OVERDOSE10</span> — 10% off ₱200+<br/>
        <span style="color:var(--gold);font-weight:600;">FIRSTCUP</span> — ₱50 off ₱150+<br/>
        <span style="color:var(--gold);font-weight:600;">CAFFEINE20</span> — 20% off ₱500+
      </p>
    </div>
  </aside>

  <main class="page-content">
    <h1 class="page-title">Your Cart</h1>
    <p class="page-subtitle">Review your items before placing your order.</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($voucher_msg): ?>
      <div class="alert alert-success"><?= htmlspecialchars($voucher_msg) ?></div>
    <?php endif; ?>

    <?php if (empty($_SESSION['cart'])): ?>
      <div class="empty-cart">
        <div class="empty-icon">🛒</div>
        <p>Your cart is empty.</p>
        <a href="products.php" class="btn-gold" style="margin-top:16px;">Browse Menu</a>
      </div>
    <?php else: ?>

      <div class="cart-layout">
        <!-- Cart items -->
        <div class="cart-items-col">
          <div class="cart-table">
            <div class="cart-table-head">
              <span>Item</span>
              <span>Price</span>
              <span>Qty</span>
              <span>Subtotal</span>
              <span></span>
            </div>
            <?php foreach ($_SESSION['cart'] as $pid => $item): ?>
              <div class="cart-table-row">
                <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                <span>₱<?= number_format($item['price'], 2) ?></span>
                <span class="qty-controls">
                  <?php if ($item['qty'] > 1): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="quantities[<?= $pid ?>]" value="<?= $item['qty'] - 1 ?>"/>
                      <button type="submit" name="update_cart" class="qty-btn">−</button>
                    </form>
                  <?php else: ?>
                    <a href="cart.php?remove=<?= $pid ?>" class="qty-btn qty-btn-remove">−</a>
                  <?php endif; ?>
                  <span class="qty-num"><?= $item['qty'] ?></span>
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="quantities[<?= $pid ?>]" value="<?= $item['qty'] + 1 ?>"/>
                    <button type="submit" name="update_cart" class="qty-btn" <?= $item['qty'] >= 20 ? 'disabled' : '' ?>>+</button>
                  </form>
                </span>
                <span class="item-sub">₱<?= number_format($item['price'] * $item['qty'], 2) ?></span>
                <span><a href="cart.php?remove=<?= $pid ?>" class="remove-btn">✕</a></span>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Voucher -->
          <div class="voucher-box">
            <h4>Apply Voucher</h4>
            <form method="POST" style="display:flex;gap:10px;margin-top:12px;">
              <input type="text" name="voucher_code" placeholder="Enter code e.g. OVERDOSE10" value="<?= htmlspecialchars($voucher_code) ?>" class="voucher-input"/>
              <button type="submit" name="apply_voucher" class="btn-gold" style="white-space:nowrap;">Apply</button>
            </form>
          </div>
        </div>

        <!-- Order summary -->
        <div class="order-summary">
          <h3>Order Summary</h3>
          <div class="summary-row">
            <span>Subtotal</span>
            <span>₱<?= number_format($subtotal, 2) ?></span>
          </div>
          <?php if ($disc_applied > 0): ?>
            <div class="summary-row discount">
              <span>Voucher (<?= htmlspecialchars($_SESSION['voucher_code']) ?>)</span>
              <span>−₱<?= number_format($disc_applied, 2) ?></span>
            </div>
          <?php endif; ?>
          <div class="summary-divider"></div>
          <div class="summary-row total">
            <span>Total</span>
            <span>₱<?= number_format($total, 2) ?></span>
          </div>
          <form method="POST" style="margin-top:20px;">
            <button type="submit" name="place_order" class="btn-gold" style="width:100%;justify-content:center;padding:14px;">
              Place Order
            </button>
          </form>
          <a href="products.php" class="btn-outline" style="width:100%;justify-content:center;padding:13px;margin-top:10px;">
            Continue Shopping
          </a>
        </div>
      </div>

    <?php endif; ?>
  </main>
</div>

<footer class="oc-footer">
  <div>© <?= date('Y') ?> Overdose Cafe · Manila, PH</div>
  <span>Crafted with ☕ and too much caffeine</span>
</footer>

<style>
  .cart-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 28px;
    align-items: start;
  }

  .cart-table { width: 100%; }

  .cart-table-head,
  .cart-table-row {
    display: grid;
    grid-template-columns: 2fr 1fr 110px 1fr 36px;
    gap: 12px;
    align-items: center;
    padding: 12px 0;
    font-size: 0.82rem;
  }

  .cart-table-head {
    border-bottom: 1px solid var(--border);
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--gold);
    opacity: 0.7;
  }

  .cart-table-row {
    border-bottom: 1px solid var(--border);
    color: var(--cream);
  }

  .item-name { font-weight: 500; }
  .item-sub { font-weight: 700; color: var(--gold); }

  .qty-controls {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .qty-btn {
    width: 26px;
    height: 26px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 2px;
    color: var(--cream);
    font-size: 1rem;
    line-height: 1;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: border-color 0.2s, background 0.2s;
    padding: 0;
  }

  .qty-btn:hover:not(:disabled) {
    border-color: var(--gold);
    background: rgba(212,175,90,0.1);
    color: var(--gold);
  }

  .qty-btn:disabled {
    opacity: 0.35;
    cursor: not-allowed;
  }

  .qty-btn-remove { color: rgba(224,85,85,0.6); }
  .qty-btn-remove:hover { border-color: var(--error); color: var(--error); background: rgba(224,85,85,0.08); }

  .qty-num {
    min-width: 20px;
    text-align: center;
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--cream);
  }

  .remove-btn {
    color: rgba(224,85,85,0.5);
    text-decoration: none;
    font-size: 0.85rem;
    transition: color 0.2s;
  }

  .remove-btn:hover { color: var(--error); }

  .voucher-box {
    margin-top: 24px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 20px;
  }

  .voucher-box h4 {
    font-family: 'Playfair Display', serif;
    font-size: 1rem;
    font-weight: 700;
    color: var(--cream);
  }

  .voucher-input {
    flex: 1;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 2px;
    padding: 10px 14px;
    color: var(--cream);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.85rem;
    outline: none;
    text-transform: uppercase;
  }

  .voucher-input:focus { border-color: var(--gold); }

  .order-summary {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 24px;
  }

  .order-summary h3 {
    font-family: 'Playfair Display', serif;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--cream);
    margin-bottom: 20px;
  }

  .summary-row {
    display: flex;
    justify-content: space-between;
    font-size: 0.85rem;
    color: var(--muted);
    margin-bottom: 10px;
  }

  .summary-row.discount { color: var(--success); }

  .summary-row.total {
    font-size: 1rem;
    font-weight: 700;
    color: var(--cream);
    margin-bottom: 0;
  }

  .summary-divider {
    height: 1px;
    background: var(--border);
    margin: 14px 0;
  }

  .empty-cart {
    text-align: center;
    padding: 80px 0;
    color: var(--muted);
  }

  .empty-icon { font-size: 3rem; margin-bottom: 16px; opacity: 0.4; }

  @media (max-width: 900px) { .cart-layout { grid-template-columns: 1fr; } }
</style>

</body>
</html>