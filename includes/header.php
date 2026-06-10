<?php
// includes/header.php
// Requires session_start() and db.php to be called before this include
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_name = $_SESSION['user_name'];
$user_full = $_SESSION['user_full'];

// Cart count from session
$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += $item['qty'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $page_title ?? 'Overdose Cafe' ?></title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400&family=DM+Sans:wght@300;400;500;600&display=swap"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg: #0D0A06;
      --surface: #131008;
      --panel: #1A1208;
      --card: #1F1610;
      --border: rgba(212,175,90,0.15);
      --border-hover: rgba(212,175,90,0.35);
      --gold: #D4AF5A;
      --gold-light: #F0D080;
      --cream: #F5EDD8;
      --muted: rgba(245,237,216,0.45);
      --muted2: rgba(245,237,216,0.25);
      --error: #E05555;
      --success: #5BAD7E;
      --nav-h: 68px;
    }

    body {
      background: var(--bg);
      color: var(--cream);
      font-family: 'DM Sans', sans-serif;
      min-height: 100vh;
    }

    /* ── NAV ── */
    .oc-nav {
      position: sticky;
      top: 0;
      z-index: 100;
      height: var(--nav-h);
      background: rgba(13,10,6,0.92);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      padding: 0 40px;
      gap: 32px;
    }

    .nav-logo {
      font-family: 'Playfair Display', serif;
      font-size: 1.15rem;
      font-weight: 700;
      letter-spacing: 3px;
      text-transform: uppercase;
      color: var(--gold);
      text-decoration: none;
      margin-right: auto;
    }

    .nav-links {
      display: flex;
      list-style: none;
      gap: 4px;
    }

    .nav-links a {
      display: block;
      padding: 8px 16px;
      font-size: 0.8rem;
      font-weight: 500;
      letter-spacing: 0.5px;
      color: var(--muted);
      text-decoration: none;
      border-radius: 2px;
      transition: color 0.2s, background 0.2s;
    }

    .nav-links a:hover,
    .nav-links a.active {
      color: var(--cream);
      background: rgba(212,175,90,0.08);
    }

    .nav-cart {
      position: relative;
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 18px;
      border: 1px solid var(--border);
      border-radius: 2px;
      font-size: 0.8rem;
      font-weight: 600;
      color: var(--gold);
      text-decoration: none;
      transition: border-color 0.2s, background 0.2s;
    }

    .nav-cart:hover {
      border-color: var(--gold);
      background: rgba(212,175,90,0.06);
    }

    .cart-badge {
      background: var(--gold);
      color: #0D0A06;
      font-size: 0.65rem;
      font-weight: 700;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .nav-user {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 6px 14px;
      border-radius: 2px;
      border: 1px solid var(--border);
    }

    .nav-avatar {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: var(--gold);
      color: #0D0A06;
      font-size: 0.75rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .nav-username {
      font-size: 0.8rem;
      font-weight: 500;
      color: var(--cream);
    }

    .nav-logout {
      padding: 8px 16px;
      font-size: 0.78rem;
      font-weight: 600;
      color: rgba(224,85,85,0.7);
      text-decoration: none;
      border-radius: 2px;
      transition: color 0.2s, background 0.2s;
      letter-spacing: 0.5px;
    }

    .nav-logout:hover {
      color: var(--error);
      background: rgba(224,85,85,0.08);
    }

    /* ── MAIN LAYOUT ── */
    .page-layout {
      display: grid;
      grid-template-columns: 220px 1fr;
      min-height: calc(100vh - var(--nav-h));
    }

    /* ── SIDEBAR ── */
    .page-sidebar {
      position: sticky;
      top: var(--nav-h);
      height: calc(100vh - var(--nav-h));
      overflow-y: auto;
      padding: 36px 24px;
      border-right: 1px solid var(--border);
      scrollbar-width: none;
      background: var(--surface);
    }

    .page-sidebar::-webkit-scrollbar { display: none; }

    .sidebar-section-label {
      font-size: 0.65rem;
      font-weight: 700;
      letter-spacing: 2px;
      text-transform: uppercase;
      color: var(--gold);
      opacity: 0.6;
      margin-bottom: 12px;
      padding: 0 10px;
    }

    .sidebar-menu {
      list-style: none;
      margin-bottom: 28px;
    }

    .sidebar-menu a {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 9px 10px;
      border-radius: 3px;
      font-size: 0.83rem;
      font-weight: 500;
      color: var(--muted);
      text-decoration: none;
      transition: all 0.2s;
      border-left: 2px solid transparent;
    }

    .sidebar-menu a:hover {
      color: var(--cream);
      background: rgba(212,175,90,0.05);
    }

    .sidebar-menu a.active {
      color: var(--cream);
      border-left-color: var(--gold);
      background: rgba(212,175,90,0.07);
      font-weight: 600;
    }

    .sidebar-menu .menu-icon {
      width: 16px;
      text-align: center;
      font-size: 0.9rem;
      opacity: 0.7;
    }

    .sidebar-divider {
      height: 1px;
      background: var(--border);
      margin: 20px 0;
    }

    /* ── CONTENT AREA ── */
    .page-content {
      padding: 40px 48px;
      overflow-y: auto;
    }

    /* ── COMMON COMPONENTS ── */
    .page-title {
      font-family: 'Playfair Display', serif;
      font-size: 1.9rem;
      font-weight: 700;
      color: var(--cream);
      margin-bottom: 6px;
    }

    .page-subtitle {
      font-size: 0.85rem;
      color: var(--muted);
      margin-bottom: 36px;
    }

    .section-divider {
      height: 1px;
      background: var(--border);
      margin: 32px 0;
    }

    .gold-badge {
      display: inline-block;
      background: rgba(212,175,90,0.12);
      border: 1px solid rgba(212,175,90,0.25);
      border-radius: 2px;
      padding: 3px 10px;
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      color: var(--gold);
    }

    .btn-gold {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: var(--gold);
      color: #0D0A06;
      border: none;
      border-radius: 2px;
      padding: 11px 22px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.82rem;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      cursor: pointer;
      text-decoration: none;
      transition: background 0.2s, transform 0.1s;
    }

    .btn-gold:hover {
      background: var(--gold-light);
      transform: translateY(-1px);
    }

    .btn-outline {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: transparent;
      color: var(--gold);
      border: 1px solid var(--border-hover);
      border-radius: 2px;
      padding: 10px 20px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.82rem;
      font-weight: 600;
      letter-spacing: 1px;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.2s;
    }

    .btn-outline:hover {
      border-color: var(--gold);
      background: rgba(212,175,90,0.06);
    }

    .alert {
      border-radius: 2px;
      padding: 12px 16px;
      font-size: 0.83rem;
      margin-bottom: 20px;
    }

    .alert-error { background: rgba(224,85,85,0.1); border: 1px solid rgba(224,85,85,0.3); color: var(--error); }
    .alert-success { background: rgba(91,173,126,0.1); border: 1px solid rgba(91,173,126,0.3); color: var(--success); }
    .alert-info { background: rgba(212,175,90,0.08); border: 1px solid rgba(212,175,90,0.2); color: var(--gold); }

    /* ── FOOTER ── */
    .oc-footer {
      border-top: 1px solid var(--border);
      padding: 20px 40px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 0.75rem;
      color: var(--muted2);
      background: var(--surface);
    }

    .oc-footer span { color: rgba(212,175,90,0.35); }
  </style>
</head>
<body>

<nav class="oc-nav">
  <a href="products.php" class="nav-logo">Overdose Cafe</a>
  <ul class="nav-links">
    <li><a href="products.php" <?= (basename($_SERVER['PHP_SELF']) === 'products.php') ? 'class="active"' : '' ?>>Menu</a></li>
    <li><a href="orders.php" <?= (basename($_SERVER['PHP_SELF']) === 'orders.php') ? 'class="active"' : '' ?>>My Orders</a></li>
    <li><a href="settings.php" <?= (basename($_SERVER['PHP_SELF']) === 'settings.php') ? 'class="active"' : '' ?>>Settings</a></li>
  </ul>

  <a href="cart.php" class="nav-cart">
    ☕ Cart
    <span class="cart-badge"><?= $cart_count ?></span>
  </a>

  <div class="nav-user">
    <div class="nav-avatar"><?= strtoupper(substr($user_name, 0, 1)) ?></div>
    <span class="nav-username"><?= htmlspecialchars($user_name) ?></span>
  </div>

  <a href="logout.php" class="nav-logout">Sign Out</a>
</nav>
