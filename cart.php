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

// Fetch user's registered address from DB
$uq = $conn->prepare("SELECT address FROM users WHERE id = ?");
$uq->bind_param("i", $_SESSION['user_id']);
$uq->execute();
$user_row = $uq->get_result()->fetch_assoc();
$registered_address = $user_row['address'] ?? '';

// Restore fulfillment from session; default delivery address to registered one
$fulfillment   = $_SESSION['fulfillment_type'] ?? 'pickup';
$delivery_addr = $_SESSION['delivery_address'] ?? $registered_address;

// Save fulfillment choice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_fulfillment'])) {
    $fulfillment = ($_POST['fulfillment_type'] === 'delivery') ? 'delivery' : 'pickup';
    // reset_address button resets to registered address
    if (isset($_POST['reset_address'])) {
        $delivery_addr = $registered_address;
    } else {
        $delivery_addr = trim($_POST['delivery_address'] ?? $registered_address);
    }
    $_SESSION['fulfillment_type']  = $fulfillment;
    $_SESSION['delivery_address']  = $delivery_addr;
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
        $ft = $_SESSION['fulfillment_type'] ?? 'pickup';
        $da = $_SESSION['delivery_address'] ?? '';
        if ($ft === 'delivery' && empty($da)) {
            $error = 'Please enter a delivery address before placing your order.';
        } else {
            $sub = 0;
            foreach ($_SESSION['cart'] as $item) {
                $sub += $item['price'] * $item['qty'];
            }
            $disc = $_SESSION['voucher_discount'] ?? 0;
            $dfee = ($ft === 'delivery') ? 50.00 : 0.00;
            $total = $sub - $disc + $dfee;
            $vcode = $_SESSION['voucher_code'] ?? null;
            $uid = $_SESSION['user_id'];

            // Add fulfillment columns if they don't exist yet
            $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS fulfillment_type VARCHAR(10) DEFAULT 'pickup'");
            $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_address TEXT DEFAULT NULL");

            $stmt = $conn->prepare("INSERT INTO orders (user_id, total_amount, discount, voucher_code, fulfillment_type, delivery_address, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->bind_param("iddsss", $uid, $total, $disc, $vcode, $ft, $da);
            $stmt->execute();
            $order_id = $conn->insert_id;

            $istmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            foreach ($_SESSION['cart'] as $item) {
                $istmt->bind_param("iiid", $order_id, $item['id'], $item['qty'], $item['price']);
                $istmt->execute();
            }

            // Clear cart, voucher, and fulfillment
            unset($_SESSION['cart'], $_SESSION['voucher_code'], $_SESSION['voucher_discount'],
                  $_SESSION['fulfillment_type'], $_SESSION['delivery_address']);
            header("Location: orders.php?placed=" . $order_id);
            exit();
        }
    }
}

// Calculate totals
$subtotal = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $subtotal += $item['price'] * $item['qty'];
    }
}
$disc_applied      = $_SESSION['voucher_discount'] ?? 0;
$fulfillment       = $_SESSION['fulfillment_type'] ?? 'pickup';
$delivery_addr     = $_SESSION['delivery_address'] ?? $registered_address;
$delivery_fee      = ($fulfillment === 'delivery') ? 50.00 : 0.00;
$total             = $subtotal - $disc_applied + $delivery_fee;

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

          <!-- Fulfillment -->
          <div class="fulfillment-box">
            <h4>How would you like to receive your order?</h4>
            <form method="POST" id="fulfillment-form" style="margin-top:16px;">
              <div class="fulfillment-options">
                <label class="fulfillment-option <?= $fulfillment === 'pickup' ? 'selected' : '' ?>">
                  <input type="radio" name="fulfillment_type" value="pickup" <?= $fulfillment === 'pickup' ? 'checked' : '' ?> onchange="this.form.submit()"/>
                  <div class="fulfillment-icon">🏪</div>
                  <div>
                    <div class="fulfillment-label">Pick Up</div>
                    <div class="fulfillment-desc">Collect at the store</div>
                  </div>
                </label>
                <label class="fulfillment-option <?= $fulfillment === 'delivery' ? 'selected' : '' ?>">
                  <input type="radio" name="fulfillment_type" value="delivery" <?= $fulfillment === 'delivery' ? 'checked' : '' ?> onchange="this.form.submit()"/>
                  <div class="fulfillment-icon">🛵</div>
                  <div>
                    <div class="fulfillment-label">Delivery</div>
                    <div class="fulfillment-desc">Delivered to your door</div>
                  </div>
                </label>
              </div>
              <?php if ($fulfillment === 'delivery'): ?>
                <?php $is_custom = ($delivery_addr !== $registered_address); ?>
                <div class="delivery-addr-group" id="delivery-addr-group">
                  <div class="addr-label-row">
                    <label class="addr-label">Delivery Address</label>
                    <?php if ($is_custom && $registered_address): ?>
                      <button type="submit" name="reset_address" class="addr-reset-link" title="Revert to your registered address">↩ Use registered address</button>
                    <?php endif; ?>
                  </div>
                  <?php if (!$is_custom && $registered_address): ?>
                    <!-- Showing registered address — read-only with edit toggle -->
                    <div class="addr-display">
                      <span class="addr-display-text"><?= htmlspecialchars($delivery_addr) ?></span>
                      <button type="button" class="addr-edit-btn" onclick="toggleAddrEdit(this)">✏️ Edit</button>
                    </div>
                    <div class="addr-edit-area" style="display:none;">
                      <textarea name="delivery_address" rows="4" class="addr-input"><?= htmlspecialchars($delivery_addr) ?></textarea>
                      <div style="display:flex;gap:8px;margin-top:8px;">
                        <button type="submit" name="save_fulfillment" class="btn-gold" style="flex:1;justify-content:center;">Save</button>
                        <button type="button" class="btn-outline" style="flex:1;justify-content:center;" onclick="toggleAddrEdit(this.closest('.delivery-addr-group').querySelector('.addr-edit-btn'))">Cancel</button>
                      </div>
                    </div>
                    <input type="hidden" name="delivery_address" value="<?= htmlspecialchars($delivery_addr) ?>"/>
                  <?php else: ?>
                    <!-- Custom address — editable -->
                    <textarea name="delivery_address" rows="2" class="addr-input" placeholder="House No., Street, Barangay, City"><?= htmlspecialchars($delivery_addr) ?></textarea>
                    <button type="submit" name="save_fulfillment" class="btn-gold" style="margin-top:10px;">Save Address</button>
                    <input type="hidden" name="reset_address" value="0"/>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <input type="hidden" name="delivery_address" value=""/>
              <?php endif; ?>
              <input type="hidden" name="save_fulfillment" value="1"/>
            </form>
            <?php if ($fulfillment === 'pickup'): ?>
              <p class="pickup-note">📍 Overdose Cafe · Manila, PH &mdash; Ready in ~15 mins</p>
            <?php endif; ?>
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
          <?php if ($fulfillment === 'delivery'): ?>
            <div class="summary-row" style="color:var(--cream);">
              <span>Delivery Fee</span>
              <span>+₱50.00</span>
            </div>
          <?php endif; ?>
          <div class="summary-divider"></div>
          <div class="summary-row" style="align-items:center;">
            <span>Fulfillment</span>
            <span class="fulfillment-badge <?= $fulfillment === 'delivery' ? 'badge-delivery' : 'badge-pickup' ?>">
              <?= $fulfillment === 'delivery' ? '🛵 Delivery' : '🏪 Pick Up' ?>
            </span>
          </div>
          <?php if ($fulfillment === 'delivery' && $delivery_addr): ?>
            <div class="summary-row" style="font-size:0.75rem;color:var(--muted2);gap:8px;flex-direction:column;align-items:flex-start;">
              <span style="color:var(--muted);font-size:0.72rem;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;">Deliver to</span>
              <span><?= htmlspecialchars($delivery_addr) ?></span>
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

  /* ── FULFILLMENT ── */
  .fulfillment-box {
    margin-top: 24px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 20px;
  }

  .fulfillment-box h4 {
    font-family: 'Playfair Display', serif;
    font-size: 1rem;
    font-weight: 700;
    color: var(--cream);
    margin-bottom: 0;
  }

  .fulfillment-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
  }

  .fulfillment-option {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border: 1px solid var(--border);
    border-radius: 3px;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
    background: var(--panel);
    user-select: none;
  }

  .fulfillment-option input[type="radio"] { display: none; }

  .fulfillment-option:hover {
    border-color: rgba(212,175,90,0.4);
    background: rgba(212,175,90,0.04);
  }

  .fulfillment-option.selected {
    border-color: var(--gold);
    background: rgba(212,175,90,0.08);
  }

  .fulfillment-icon { font-size: 1.4rem; line-height: 1; }

  .fulfillment-label {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--cream);
    margin-bottom: 2px;
  }

  .fulfillment-desc {
    font-size: 0.72rem;
    color: var(--muted);
  }

  .fulfillment-option.selected .fulfillment-label { color: var(--gold); }

  .delivery-addr-group {
    margin-top: 14px;
    display: flex;
    flex-direction: column;
  }

  .addr-label {
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--gold);
    opacity: 0.8;
    margin-bottom: 7px;
  }

  .addr-input {
    width: 100%;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 2px;
    padding: 12px 16px;
    color: var(--cream);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.9rem;
    outline: none;
    resize: vertical;
    min-height: 90px;
    transition: border-color 0.2s;
  }

  .addr-input:focus { border-color: var(--gold); }
  .addr-input::placeholder { color: rgba(245,237,216,0.2); }

  .pickup-note {
    margin-top: 12px;
    font-size: 0.75rem;
    color: var(--muted);
    border-top: 1px solid var(--border);
    padding-top: 12px;
  }

  .fulfillment-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    border: 1px solid;
  }

  .badge-pickup {
    color: var(--gold);
    border-color: rgba(212,175,90,0.3);
    background: rgba(212,175,90,0.08);
  }

  .badge-delivery {
    color: #5B9BD4;
    border-color: rgba(91,155,212,0.3);
    background: rgba(91,155,212,0.08);
  }

  .addr-label-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 7px;
  }

  .addr-reset-link {
    background: none;
    border: none;
    color: var(--gold);
    font-size: 0.72rem;
    font-family: 'DM Sans', sans-serif;
    font-weight: 600;
    cursor: pointer;
    padding: 0;
    text-decoration: underline;
    opacity: 0.75;
    transition: opacity 0.2s;
  }

  .addr-reset-link:hover { opacity: 1; }

  .addr-display {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 2px;
    padding: 10px 14px;
  }

  .addr-display-text {
    font-size: 0.85rem;
    color: var(--cream);
    line-height: 1.5;
    flex: 1;
  }

  .addr-edit-btn {
    background: none;
    border: none;
    color: var(--gold);
    font-size: 0.75rem;
    font-family: 'DM Sans', sans-serif;
    font-weight: 600;
    cursor: pointer;
    padding: 0;
    white-space: nowrap;
    opacity: 0.7;
    transition: opacity 0.2s;
  }

  .addr-edit-btn:hover { opacity: 1; }

  @media (max-width: 900px) { .cart-layout { grid-template-columns: 1fr; } }
</style>

<script>
function toggleAddrEdit(editBtn) {
  var group = editBtn.closest('.delivery-addr-group');
  var display = group.querySelector('.addr-display');
  var editArea = group.querySelector('.addr-edit-area');
  var hiddenInput = group.querySelector('input[type="hidden"][name="delivery_address"]');
  var isEditing = editArea.style.display !== 'none';
  if (isEditing) {
    editArea.style.display = 'none';
    display.style.display = 'flex';
    if (hiddenInput) hiddenInput.disabled = false;
    editBtn.textContent = '✏️ Edit';
  } else {
    editArea.style.display = 'block';
    display.style.display = 'none';
    if (hiddenInput) hiddenInput.disabled = true;
    editBtn.textContent = '✏️ Edit';
  }
}
</script>

</body>
</html>