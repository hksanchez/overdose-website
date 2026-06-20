<?php
session_start();
require_once 'includes/db.php';

// Allowed to log into multiple roles at once. No auto-redirect.

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
      --bg:         #0D0A06;
      --surface:    #131008;
      --panel:      #1A1208;
      --border:     rgba(212,175,90,0.16);
      --gold:       #D4AF5A;
      --gold-light: #F0D080;
      --cream:      #F5EDD8;
      --muted:      rgba(245,237,216,0.42);
      --error:      #E05555;
    }

    body {
      background: var(--bg);
      color: var(--cream);
      font-family: 'DM Sans', sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 32px 20px;
      position: relative;
      overflow: hidden;
    }

    /* Subtle ambient glow */
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background:
        radial-gradient(ellipse 55% 55% at 72% 48%, rgba(100,60,10,0.18) 0%, transparent 70%),
        radial-gradient(ellipse 40% 50% at 20% 45%, rgba(40,25,5,0.28) 0%, transparent 70%);
      pointer-events: none;
    }

    /* ── CARD ── */
    .login-wrap {
      position: relative;
      z-index: 1;
      display: grid;
      grid-template-columns: 320px 400px;
      border: 1px solid var(--border);
      border-radius: 6px;
      overflow: hidden;
      box-shadow: 0 32px 100px rgba(0,0,0,0.65);
    }

    /* ── LEFT PANEL ── */
    .brand-panel {
      background: linear-gradient(160deg, #1C1409 0%, #0E0B06 100%);
      border-right: 1px solid var(--border);
      padding: 52px 40px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .brand-logo {
      font-family: 'Playfair Display', serif;
      font-size: 0.88rem;
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
      padding: 32px 0;
    }

    .brand-eyebrow {
      font-size: 0.6rem;
      font-weight: 700;
      letter-spacing: 3px;
      text-transform: uppercase;
      color: rgba(212,175,90,0.5);
      margin-bottom: 16px;
    }

    .brand-headline {
      font-family: 'Playfair Display', serif;
      font-size: 2rem;
      font-weight: 900;
      line-height: 1.18;
      color: var(--cream);
      margin-bottom: 20px;
    }

    .brand-headline em { color: var(--gold); font-style: italic; }

    .brand-divider {
      width: 28px;
      height: 1px;
      background: rgba(212,175,90,0.4);
      margin-bottom: 18px;
    }

    .brand-desc {
      font-size: 0.8rem;
      line-height: 1.8;
      color: var(--muted);
    }

    /* Role list — compact, no redundant text */
    .role-list {
      margin-top: 28px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .role-item {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.76rem;
      color: var(--muted);
    }

    .role-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      flex-shrink: 0;
    }

    .role-name {
      font-weight: 700;
      font-size: 0.76rem;
      margin-right: 2px;
    }

    .brand-footer {
      font-size: 0.62rem;
      letter-spacing: 2px;
      text-transform: uppercase;
      color: rgba(212,175,90,0.25);
    }

    /* ── RIGHT FORM PANEL ── */
    .form-panel {
      background: var(--surface);
      padding: 52px 44px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .form-header { margin-bottom: 28px; }

    .form-header h2 {
      font-family: 'Playfair Display', serif;
      font-size: 1.45rem;
      font-weight: 700;
      color: var(--cream);
      margin-bottom: 5px;
    }

    .form-header p { font-size: 0.78rem; color: var(--muted); }

    .form-group { margin-bottom: 16px; }

    .form-group label {
      display: block;
      font-size: 0.63rem;
      font-weight: 700;
      letter-spacing: 1.8px;
      text-transform: uppercase;
      color: var(--gold);
      opacity: 0.8;
      margin-bottom: 8px;
    }

    .form-group input {
      width: 100%;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 3px;
      padding: 12px 14px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.87rem;
      color: var(--cream);
      outline: none;
      transition: border-color 0.2s;
    }

    .form-group input:focus { border-color: rgba(212,175,90,0.6); }
    .form-group input::placeholder { color: rgba(245,237,216,0.15); }

    .alert-error {
      background: rgba(224,85,85,0.08);
      border: 1px solid rgba(224,85,85,0.28);
      color: var(--error);
      border-radius: 3px;
      padding: 10px 14px;
      font-size: 0.78rem;
      margin-bottom: 18px;
    }

    .btn-login {
      width: 100%;
      background: var(--gold);
      color: #0D0A06;
      border: none;
      border-radius: 3px;
      padding: 13px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 2.5px;
      text-transform: uppercase;
      cursor: pointer;
      margin-top: 8px;
      transition: background 0.2s, transform 0.1s;
    }

    .btn-login:hover { background: var(--gold-light); transform: translateY(-1px); }
    .btn-login:active { transform: translateY(0); }

    .back-link {
      text-align: center;
      margin-top: 24px;
      font-size: 0.75rem;
      color: var(--muted);
    }

    .back-link a { color: var(--gold); text-decoration: none; }
    .back-link a:hover { text-decoration: underline; }

    @media (max-width: 780px) {
      .login-wrap { grid-template-columns: 1fr; }
      .brand-panel { display: none; }
      .form-panel { padding: 40px 28px; }
    }
  </style>
</head>
<body>

<div class="login-wrap">

  <!-- Left brand panel -->
  <div class="brand-panel">
    <div class="brand-logo">Overdose Cafe</div>

    <div class="brand-center">
      <div class="brand-eyebrow">Internal Portal</div>
      <h2 class="brand-headline">Staff &amp;<br/><em>Management</em><br/>Access</h2>
      <div class="brand-divider"></div>
      <p class="brand-desc">Sign in with your assigned credentials. You'll be redirected to the right portal automatically.</p>

      <div class="role-list">
        <div class="role-item">
          <div class="role-dot" style="background:#D4AF5A;"></div>
          <span class="role-name" style="color:var(--gold);">Admin</span>
          <span>Full dashboard access</span>
        </div>
        <div class="role-item">
          <div class="role-dot" style="background:#5B9BD4;"></div>
          <span class="role-name" style="color:#5B9BD4;">Staff</span>
          <span>Orders &amp; inventory</span>
        </div>
        <div class="role-item">
          <div class="role-dot" style="background:#9B7BD4;"></div>
          <span class="role-name" style="color:#9B7BD4;">Rider</span>
          <span>Delivery queue</span>
        </div>
      </div>
    </div>

    <div class="brand-footer">Internal Use Only</div>
  </div>

  <!-- Right form panel -->
  <div class="form-panel">
    <div class="form-header">
      <h2>Sign In</h2>
      <p>Enter your credentials to continue.</p>
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

    <div class="back-link">
      <a href="products.php">← Back to storefront</a>
    </div>
  </div>

</div>

</body>
</html>