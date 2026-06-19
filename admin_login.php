<?php
session_start();
require_once 'includes/db.php';

// ── ENSURE TABLES EXIST ──────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS staff_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Seed default admin if none
$chkA = $conn->query("SELECT COUNT(*) as c FROM admin_users");
if ($chkA->fetch_assoc()['c'] == 0) {
    $pw = password_hash('admin123', PASSWORD_DEFAULT);
    $s  = $conn->prepare("INSERT INTO admin_users (username, password, full_name) VALUES (?,?,?)");
    $u  = 'admin'; $n = 'Admin';
    $s->bind_param("sss", $u, $pw, $n);
    $s->execute();
}

// Seed default staff if none
$chkS = $conn->query("SELECT COUNT(*) as c FROM staff_users");
if ($chkS->fetch_assoc()['c'] == 0) {
    $pw = password_hash('staff123', PASSWORD_DEFAULT);
    $s  = $conn->prepare("INSERT INTO staff_users (username, password, full_name) VALUES (?,?,?)");
    $u  = 'staff'; $n = 'Staff Member';
    $s->bind_param("sss", $u, $pw, $n);
    $s->execute();
}

// ── ENSURE rider_users TABLE EXISTS ─────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS rider_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Seed default rider if none
$chkR = $conn->query("SELECT COUNT(*) as c FROM rider_users");
if ($chkR->fetch_assoc()['c'] == 0) {
    $pw = password_hash('rider123', PASSWORD_DEFAULT);
    $s  = $conn->prepare("INSERT INTO rider_users (username, password, full_name) VALUES (?,?,?)");
    $u  = 'rider'; $n = 'Rider';
    $s->bind_param("sss", $u, $pw, $n);
    $s->execute();
}

// Already logged in — redirect to the right portal
if (isset($_SESSION['admin_id']))  { header("Location: admin.php");  exit(); }
if (isset($_SESSION['staff_id']))  { header("Location: staff.php");  exit(); }
if (isset($_SESSION['rider_id']))  { header("Location: rider.php");  exit(); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {

        // ── Check admin_users first ──────────────────────────────────────────
        $stmt = $conn->prepare("SELECT id, username, password, full_name FROM admin_users WHERE username = ? AND is_active = 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();

        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_user'] = $admin['username'];
            $_SESSION['admin_name'] = $admin['full_name'];
            header("Location: admin.php");
            exit();
        }

        // ── Then check staff_users ───────────────────────────────────────────
        $stmt2 = $conn->prepare("SELECT id, username, password, full_name FROM staff_users WHERE username = ? AND is_active = 1");
        $stmt2->bind_param("s", $username);
        $stmt2->execute();
        $staff = $stmt2->get_result()->fetch_assoc();

        if ($staff && password_verify($password, $staff['password'])) {
            $_SESSION['staff_id']   = $staff['id'];
            $_SESSION['staff_user'] = $staff['username'];
            $_SESSION['staff_name'] = $staff['full_name'];
            header("Location: staff.php");
            exit();
        }

        // ── Then check rider_users ───────────────────────────────────────────
        $stmt3 = $conn->prepare("SELECT id, username, password, full_name FROM rider_users WHERE username = ? AND is_active = 1");
        $stmt3->bind_param("s", $username);
        $stmt3->execute();
        $rider = $stmt3->get_result()->fetch_assoc();

        if ($rider && password_verify($password, $rider['password'])) {
            $_SESSION['rider_id']   = $rider['id'];
            $_SESSION['rider_user'] = $rider['username'];
            $_SESSION['rider_name'] = $rider['full_name'];
            header("Location: rider.php");
            exit();
        }

        // Neither matched
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Sign In — Overdose Cafe</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400;1,700&family=DM+Sans:wght@300;400;500;600&display=swap"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg: #0D0A06;
      --surface: #151009;
      --panel: #1C1409;
      --border: rgba(212,175,90,0.18);
      --gold: #D4AF5A;
      --gold-light: #F0D080;
      --cream: #F5EDD8;
      --muted: rgba(245,237,216,0.45);
      --error: #E05555;
    }

    body {
      background: var(--bg);
      color: var(--cream);
      font-family: 'DM Sans', sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 40px 20px;
      position: relative;
      overflow: hidden;
    }

    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background:
        radial-gradient(ellipse 70% 60% at 75% 50%, rgba(100,60,10,0.22) 0%, transparent 70%),
        radial-gradient(ellipse 50% 60% at 15% 40%, rgba(40,25,5,0.35) 0%, transparent 70%);
      pointer-events: none;
    }

    body::after {
      content: '';
      position: fixed;
      inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23D4AF5A' fill-opacity='0.025'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
      pointer-events: none;
    }

    .login-wrap {
      position: relative;
      z-index: 1;
      display: grid;
      grid-template-columns: 360px 420px;
      border: 1px solid var(--border);
      border-radius: 4px;
      overflow: hidden;
      box-shadow: 0 40px 120px rgba(0,0,0,0.7);
    }

    /* ── LEFT BRAND PANEL ── */
    .brand-panel {
      background: linear-gradient(155deg, #1C1409 0%, #0D0A06 100%);
      border-right: 1px solid var(--border);
      padding: 56px 44px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .brand-logo {
      font-family: 'Playfair Display', serif;
      font-size: 0.95rem;
      font-weight: 700;
      letter-spacing: 4px;
      text-transform: uppercase;
      color: var(--gold);
    }

    .brand-center {
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 36px 0;
    }

    .brand-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(212,175,90,0.08);
      border: 1px solid rgba(212,175,90,0.2);
      border-radius: 2px;
      padding: 5px 12px;
      font-size: 0.65rem;
      font-weight: 700;
      letter-spacing: 2.5px;
      text-transform: uppercase;
      color: var(--gold);
      margin-bottom: 20px;
      width: fit-content;
    }

    .brand-badge::before {
      content: '';
      width: 6px; height: 6px;
      border-radius: 50%;
      background: var(--gold);
      opacity: 0.7;
    }

    .brand-headline {
      font-family: 'Playfair Display', serif;
      font-size: 2.2rem;
      font-weight: 900;
      line-height: 1.15;
      color: var(--cream);
      margin-bottom: 18px;
    }

    .brand-headline em { color: var(--gold); font-style: italic; }

    .brand-desc {
      font-size: 0.83rem;
      line-height: 1.75;
      color: var(--muted);
    }

    .brand-divider {
      width: 36px;
      height: 1px;
      background: var(--gold);
      opacity: 0.4;
      margin: 20px 0;
    }

    /* Role indicators */
    .role-pills {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-top: 28px;
    }

    .role-pill {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.75rem;
      color: var(--muted);
    }

    .role-pill-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      flex-shrink: 0;
    }

    .brand-footer {
      font-size: 0.68rem;
      letter-spacing: 2px;
      color: rgba(212,175,90,0.3);
      text-transform: uppercase;
    }

    /* ── RIGHT FORM PANEL ── */
    .form-panel {
      background: var(--surface);
      padding: 56px 48px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .form-header { margin-bottom: 32px; }

    .form-header h2 {
      font-family: 'Playfair Display', serif;
      font-size: 1.55rem;
      font-weight: 700;
      color: var(--cream);
      margin-bottom: 6px;
    }

    .form-header p { font-size: 0.8rem; color: var(--muted); }

    .form-group { margin-bottom: 18px; }

    .form-group label {
      display: block;
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 1.8px;
      text-transform: uppercase;
      color: var(--gold);
      opacity: 0.85;
      margin-bottom: 8px;
    }

    .form-group input {
      width: 100%;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 2px;
      padding: 12px 16px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.88rem;
      color: var(--cream);
      outline: none;
      transition: border-color 0.2s;
    }

    .form-group input:focus { border-color: var(--gold); }
    .form-group input::placeholder { color: rgba(245,237,216,0.18); }

    .alert-error {
      background: rgba(224,85,85,0.09);
      border: 1px solid rgba(224,85,85,0.28);
      color: var(--error);
      border-radius: 2px;
      padding: 10px 14px;
      font-size: 0.8rem;
      margin-bottom: 20px;
    }

    .btn-login {
      width: 100%;
      background: var(--gold);
      color: #0D0A06;
      border: none;
      border-radius: 2px;
      padding: 13px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.82rem;
      font-weight: 700;
      letter-spacing: 2.5px;
      text-transform: uppercase;
      cursor: pointer;
      margin-top: 8px;
      transition: background 0.2s, transform 0.1s;
    }

    .btn-login:hover { background: var(--gold-light); transform: translateY(-1px); }

    .divider {
      display: flex;
      align-items: center;
      gap: 12px;
      margin: 24px 0 20px;
    }

    .divider-line { flex: 1; height: 1px; background: var(--border); }
    .divider-text { font-size: 0.65rem; color: var(--muted); letter-spacing: 1.5px; text-transform: uppercase; }

    .access-note {
      background: rgba(212,175,90,0.05);
      border: 1px solid rgba(212,175,90,0.12);
      border-radius: 2px;
      padding: 12px 14px;
      font-size: 0.74rem;
      color: var(--muted);
      line-height: 1.75;
    }

    .access-note span {
      display: inline-block;
      font-weight: 600;
      font-size: 0.68rem;
      letter-spacing: 1px;
      padding: 1px 6px;
      border-radius: 2px;
      margin-right: 4px;
    }

    .tag-admin {
      background: rgba(212,175,90,0.12);
      color: var(--gold);
      border: 1px solid rgba(212,175,90,0.25);
    }

    .tag-staff {
      background: rgba(91,155,212,0.12);
      color: #5B9BD4;
      border: 1px solid rgba(91,155,212,0.25);
    }

    .tag-rider {
      background: rgba(155,123,212,0.12);
      color: #9B7BD4;
      border: 1px solid rgba(155,123,212,0.25);
    }

    .back-link {
      text-align: center;
      margin-top: 20px;
      font-size: 0.78rem;
      color: var(--muted);
    }

    .back-link a { color: var(--gold); text-decoration: none; font-weight: 600; }
    .back-link a:hover { text-decoration: underline; }

    @media (max-width: 820px) {
      .login-wrap { grid-template-columns: 1fr; }
      .brand-panel { display: none; }
      .form-panel { padding: 40px 28px; }
    }
  </style>
</head>
<body>

<div class="login-wrap">

  <!-- Brand panel -->
  <div class="brand-panel">
    <div class="brand-logo">Overdose Cafe</div>
    <div class="brand-center">
      <div class="brand-badge">Internal Portal</div>
      <h2 class="brand-headline">One Login.<br/><em>Three</em><br/>Portals.</h2>
      <div class="brand-divider"></div>
      <p class="brand-desc">A single sign-in for admins and staff. You'll be routed to the right dashboard automatically based on your account.</p>
      <div class="role-pills">
        <div class="role-pill">
          <div class="role-pill-dot" style="background:#D4AF5A;"></div>
          <span><strong style="color:var(--gold);">Admin</strong> — Full access: orders, products, promos, inventory</span>
        </div>
        <div class="role-pill">
          <div class="role-pill-dot" style="background:#5B9BD4;"></div>
          <span><strong style="color:#5B9BD4;">Staff</strong> — Orders &amp; inventory management only</span>
        </div>
        <div class="role-pill">
          <div class="role-pill-dot" style="background:#9B7BD4;"></div>
          <span><strong style="color:#9B7BD4;">Rider</strong> — Delivery queue and delivery history only</span>
        </div>
      </div>
    </div>
    <div class="brand-footer">Overdose Cafe · Internal Use Only</div>
  </div>

  <!-- Form panel -->
  <div class="form-panel">
    <div class="form-header">
      <h2>Sign In</h2>
      <p>Enter your credentials — you'll be routed to your portal automatically.</p>
    </div>

    <?php if ($error): ?>
      <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="admin_login.php">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" placeholder="Your username"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               required autofocus/>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="Your password" required/>
      </div>
      <button type="submit" class="btn-login">Sign In</button>
    </form>

    <div class="divider">
      <div class="divider-line"></div>
      <div class="divider-text">Access Levels</div>
      <div class="divider-line"></div>
    </div>

    <div class="access-note">
      <span class="tag-admin">Admin</span> Full dashboard — orders, products, promos, vouchers, inventory.<br/>
      <span class="tag-staff">Staff</span> Orders &amp; inventory only — no financial or catalogue data.<br/>
      <span class="tag-rider">Rider</span> Delivery queue &amp; history only — no orders or inventory access.
    </div>

    <div class="back-link">
      <a href="products.php">← Back to storefront</a>
    </div>
  </div>

</div>

</body>
</html>