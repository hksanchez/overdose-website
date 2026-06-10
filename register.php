<?php
session_start();
require_once 'includes/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $phone      = trim($_POST['phone']);
    $address    = trim($_POST['address']);
    $password   = $_POST['password'];
    $confirm    = $_POST['confirm_password'];

    // Validation
    if (empty($first_name) || empty($last_name) || empty($phone) || empty($address) || empty($password)) {
        $error = 'All fields are required.';
    } elseif (!preg_match('/^(09|\+639)\d{9}$/', $phone)) {
        $error = 'Phone number must be a valid PH format (e.g. 09XXXXXXXXX).';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Check if phone already exists
        $check = $conn->prepare("SELECT id FROM users WHERE phone = ?");
        $check->bind_param("s", $phone);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = 'This phone number is already registered.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, phone, address, password) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $first_name, $last_name, $phone, $address, $hashed);

            if ($stmt->execute()) {
                $success = 'Account created! You can now sign in.';
            } else {
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Register — Overdose Cafe</title>
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
      --success: #5BAD7E;
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
      overflow-x: hidden;
    }

    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background:
        radial-gradient(ellipse 60% 50% at 80% 50%, rgba(100,60,10,0.25) 0%, transparent 70%),
        radial-gradient(ellipse 50% 60% at 20% 30%, rgba(50,30,5,0.3) 0%, transparent 70%);
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

    .register-wrap {
      position: relative;
      z-index: 1;
      display: grid;
      grid-template-columns: 380px 460px;
      border: 1px solid var(--border);
      border-radius: 4px;
      overflow: hidden;
      box-shadow: 0 40px 120px rgba(0,0,0,0.6);
    }

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
      font-size: 2.5rem;
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

    .auth-form {
      background: var(--surface);
      padding: 52px 44px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .form-header {
      margin-bottom: 30px;
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

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .form-group {
      margin-bottom: 16px;
    }

    .form-group label {
      display: block;
      font-size: 0.7rem;
      font-weight: 600;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      color: var(--gold);
      margin-bottom: 7px;
    }

    .form-group input,
    .form-group textarea {
      width: 100%;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 2px;
      padding: 11px 15px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.88rem;
      color: var(--cream);
      outline: none;
      transition: border-color 0.2s;
      resize: none;
    }

    .form-group input:focus,
    .form-group textarea:focus {
      border-color: var(--gold);
    }

    .form-group input::placeholder,
    .form-group textarea::placeholder {
      color: rgba(245,237,216,0.2);
    }

    .alert {
      border-radius: 2px;
      padding: 10px 14px;
      font-size: 0.82rem;
      margin-bottom: 18px;
    }

    .alert-error {
      background: rgba(224,85,85,0.1);
      border: 1px solid rgba(224,85,85,0.3);
      color: var(--error);
    }

    .alert-success {
      background: rgba(91,173,126,0.1);
      border: 1px solid rgba(91,173,126,0.3);
      color: var(--success);
    }

    .btn-submit {
      width: 100%;
      background: var(--gold);
      color: #0D0A06;
      border: none;
      border-radius: 2px;
      padding: 13px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.85rem;
      font-weight: 700;
      letter-spacing: 2px;
      text-transform: uppercase;
      cursor: pointer;
      margin-top: 6px;
      transition: background 0.2s, transform 0.1s;
    }

    .btn-submit:hover {
      background: var(--gold-light);
      transform: translateY(-1px);
    }

    .auth-switch {
      text-align: center;
      margin-top: 20px;
      font-size: 0.82rem;
      color: var(--muted);
    }

    .auth-switch a {
      color: var(--gold);
      text-decoration: none;
      font-weight: 600;
    }

    .auth-switch a:hover { text-decoration: underline; }

    @media (max-width: 860px) {
      .register-wrap { grid-template-columns: 1fr; }
      .auth-brand { display: none; }
      .auth-form { padding: 40px 28px; }
    }
  </style>
</head>
<body>

<div class="register-wrap">
  <div class="auth-brand">
    <div class="brand-logo">Overdose Cafe</div>
    <div class="brand-tagline">
      <h1>Join the<br/><em>Caffeine</em><br/>Club.</h1>
      <div class="brand-divider"></div>
      <p>Create an account to order your favorites, track your transactions, and unlock exclusive promos.</p>
    </div>
    <div class="brand-footer">Est. 2024 · Manila, PH</div>
  </div>

  <div class="auth-form">
    <div class="form-header">
      <h2>Create Account</h2>
      <p>Fill in the details below to get started.</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?> <a href="login.php" style="color:inherit;font-weight:600;">Sign in →</a></div>
    <?php endif; ?>

    <form method="POST" action="register.php">
      <div class="form-row">
        <div class="form-group">
          <label>First Name</label>
          <input type="text" name="first_name" placeholder="Juan" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required/>
        </div>
        <div class="form-group">
          <label>Last Name</label>
          <input type="text" name="last_name" placeholder="dela Cruz" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required/>
        </div>
      </div>

      <div class="form-group">
        <label>Phone Number</label>
        <input type="text" name="phone" placeholder="09XXXXXXXXX" maxlength="13" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required/>
      </div>

      <div class="form-group">
        <label>Delivery Address</label>
        <textarea name="address" rows="2" placeholder="House No., Street, Barangay, City"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="Min. 6 characters" required/>
      </div>

      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" name="confirm_password" placeholder="Repeat your password" required/>
      </div>

      <button type="submit" class="btn-submit">Create Account</button>
    </form>

    <div class="auth-switch">
      Already have an account? <a href="login.php">Sign in</a>
    </div>
  </div>
</div>

</body>
</html>
