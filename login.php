<?php
session_start();
require_once 'includes/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];

    if (empty($phone) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE phone = ?");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['first_name'];
            $_SESSION['user_full'] = $user['first_name'] . ' ' . $user['last_name'];
            header("Location: products.php");
            exit();
        } else {
            $error = 'Invalid phone number or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login — Overdose Cafe</title>
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
      position: relative;
      overflow: hidden;
    }

    /* Background texture */
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background:
        radial-gradient(ellipse 60% 50% at 20% 50%, rgba(100,60,10,0.25) 0%, transparent 70%),
        radial-gradient(ellipse 50% 60% at 80% 30%, rgba(50,30,5,0.3) 0%, transparent 70%);
      pointer-events: none;
    }

    body::after {
      content: '';
      position: fixed;
      inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23D4AF5A' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
      pointer-events: none;
      opacity: 0.5;
    }

    .auth-wrap {
      position: relative;
      z-index: 1;
      display: grid;
      grid-template-columns: 420px 380px;
      min-height: 560px;
      border: 1px solid var(--border);
      border-radius: 4px;
      overflow: hidden;
      box-shadow: 0 40px 120px rgba(0,0,0,0.6), 0 0 0 1px rgba(212,175,90,0.05);
    }

    /* Left branding panel */
    .auth-brand {
      background: linear-gradient(160deg, #1C1409 0%, #0D0A06 100%);
      border-right: 1px solid var(--border);
      padding: 60px 48px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .brand-logo {
      font-family: 'Playfair Display', serif;
      font-size: 1.05rem;
      font-weight: 700;
      letter-spacing: 4px;
      text-transform: uppercase;
      color: var(--gold);
    }

    .brand-tagline {
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 40px 0;
    }

    .brand-tagline h1 {
      font-family: 'Playfair Display', serif;
      font-size: 2.8rem;
      font-weight: 900;
      line-height: 1.15;
      color: var(--cream);
      margin-bottom: 20px;
    }

    .brand-tagline h1 em {
      color: var(--gold);
      font-style: italic;
    }

    .brand-tagline p {
      font-size: 0.88rem;
      line-height: 1.75;
      color: var(--muted);
      max-width: 280px;
    }

    .brand-divider {
      width: 40px;
      height: 1px;
      background: var(--gold);
      opacity: 0.5;
      margin: 24px 0;
    }

    .brand-footer {
      font-size: 0.72rem;
      letter-spacing: 1.5px;
      color: rgba(212,175,90,0.35);
      text-transform: uppercase;
    }

    /* Right form panel */
    .auth-form {
      background: var(--surface);
      padding: 60px 44px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .form-header {
      margin-bottom: 36px;
    }

    .form-header h2 {
      font-family: 'Playfair Display', serif;
      font-size: 1.6rem;
      font-weight: 700;
      color: var(--cream);
      margin-bottom: 6px;
    }

    .form-header p {
      font-size: 0.82rem;
      color: var(--muted);
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      display: block;
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      color: var(--gold);
      margin-bottom: 8px;
    }

    .form-group input {
      width: 100%;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 2px;
      padding: 12px 16px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.9rem;
      color: var(--cream);
      outline: none;
      transition: border-color 0.2s;
    }

    .form-group input:focus {
      border-color: var(--gold);
    }

    .form-group input::placeholder {
      color: rgba(245,237,216,0.2);
    }

    .error-msg {
      background: rgba(224,85,85,0.1);
      border: 1px solid rgba(224,85,85,0.3);
      border-radius: 2px;
      padding: 10px 14px;
      font-size: 0.82rem;
      color: var(--error);
      margin-bottom: 20px;
    }

    .btn-submit {
      width: 100%;
      background: var(--gold);
      color: #0D0A06;
      border: none;
      border-radius: 2px;
      padding: 14px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.85rem;
      font-weight: 700;
      letter-spacing: 2px;
      text-transform: uppercase;
      cursor: pointer;
      margin-top: 8px;
      transition: background 0.2s, transform 0.1s;
    }

    .btn-submit:hover {
      background: var(--gold-light);
      transform: translateY(-1px);
    }

    .auth-switch {
      text-align: center;
      margin-top: 24px;
      font-size: 0.82rem;
      color: var(--muted);
    }

    .auth-switch a {
      color: var(--gold);
      text-decoration: none;
      font-weight: 600;
    }

    .auth-switch a:hover { text-decoration: underline; }

    @media (max-width: 820px) {
      .auth-wrap { grid-template-columns: 1fr; }
      .auth-brand { display: none; }
      .auth-form { padding: 48px 32px; }
    }
  </style>
</head>
<body>

<div class="auth-wrap">
  <div class="auth-brand">
    <div class="brand-logo">Overdose Cafe</div>
    <div class="brand-tagline">
      <h1>One Cup<br/>Too Many<br/>is <em>Perfect.</em></h1>
      <div class="brand-divider"></div>
      <p>Specialty coffee and handcrafted pastries for those who refuse to settle for ordinary.</p>
    </div>
    <div class="brand-footer">Est. 2024 · Manila, PH</div>
  </div>

  <div class="auth-form">
    <div class="form-header">
      <h2>Welcome back</h2>
      <p>Sign in to your account to order.</p>
    </div>

    <?php if ($error): ?>
      <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php">
      <div class="form-group">
        <label>Phone Number</label>
        <input type="text" name="phone" placeholder="09XXXXXXXXX" maxlength="15" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required/>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="••••••••" required/>
      </div>
      <button type="submit" class="btn-submit">Sign In</button>
    </form>

    <div class="auth-switch">
      Don't have an account? <a href="register.php">Create one</a>
    </div>
  </div>
</div>

</body>
</html>
