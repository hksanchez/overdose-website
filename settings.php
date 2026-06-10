<?php
session_start();
require_once 'includes/db.php';

$page_title = 'Settings — Overdose Cafe';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$uid = $_SESSION['user_id'];

// Fetch user
$uq = $conn->prepare("SELECT * FROM users WHERE id = ?");
$uq->bind_param("i", $uid);
$uq->execute();
$user = $uq->get_result()->fetch_assoc();

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone   = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $new_pw  = $_POST['new_password'];
    $cur_pw  = $_POST['current_password'];

    if (empty($phone) || empty($address)) {
        $error = 'Phone and address are required.';
    } elseif (!preg_match('/^(09|\+639)\d{9}$/', $phone)) {
        $error = 'Phone must be a valid PH format (e.g. 09XXXXXXXXX).';
    } else {
        // If changing password
        if (!empty($new_pw)) {
            if (!password_verify($cur_pw, $user['password'])) {
                $error = 'Current password is incorrect.';
            } elseif (strlen($new_pw) < 6) {
                $error = 'New password must be at least 6 characters.';
            } else {
                $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET phone = ?, address = ?, password = ? WHERE id = ?");
                $stmt->bind_param("sssi", $phone, $address, $hashed, $uid);
                $stmt->execute();
                $msg = 'Profile and password updated successfully.';
            }
        } else {
            $stmt = $conn->prepare("UPDATE users SET phone = ?, address = ? WHERE id = ?");
            $stmt->bind_param("ssi", $phone, $address, $uid);
            $stmt->execute();
            $msg = 'Profile updated successfully.';
        }

        if (!$error) {
            // Refresh user data
            $uq->execute();
            $user = $uq->get_result()->fetch_assoc();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="page-layout">
  <aside class="page-sidebar">
    <div class="sidebar-section-label">Menu</div>
    <ul class="sidebar-menu">
      <li><a href="products.php"><span class="menu-icon">☕</span> Products</a></li>
      <li><a href="cart.php"><span class="menu-icon">🛒</span> Cart</a></li>
      <li><a href="orders.php"><span class="menu-icon">📋</span> My Orders</a></li>
      <li><a href="settings.php" class="active"><span class="menu-icon">⚙️</span> Settings</a></li>
    </ul>
  </aside>

  <main class="page-content" style="max-width:680px;">
    <h1 class="page-title">Account Settings</h1>
    <p class="page-subtitle">Manage your delivery details and password.</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($msg): ?>
      <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- Profile card (read-only) -->
    <div class="settings-card" style="margin-bottom:28px;">
      <div class="settings-card-header">Account Info</div>
      <div class="info-grid">
        <div class="info-item">
          <span class="info-label">Full Name</span>
          <span class="info-value"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></span>
        </div>
        <div class="info-item">
          <span class="info-label">Member Since</span>
          <span class="info-value"><?= date('F Y', strtotime($user['created_at'])) ?></span>
        </div>
      </div>
    </div>

    <!-- Editable settings form -->
    <div class="settings-card">
      <div class="settings-card-header">Edit Details</div>
      <form method="POST" action="settings.php">
        <div class="form-group">
          <label>Phone Number</label>
          <input type="text" name="phone" value="<?= htmlspecialchars($user['phone']) ?>" placeholder="09XXXXXXXXX" maxlength="13" required/>
          <span class="field-hint">Used for order updates and login.</span>
        </div>

        <div class="form-group">
          <label>Delivery Address</label>
          <textarea name="address" rows="3" required><?= htmlspecialchars($user['address']) ?></textarea>
          <span class="field-hint">Your default delivery location.</span>
        </div>

        <div class="settings-section-label">Change Password <span class="optional-tag">optional</span></div>

        <div class="form-group">
          <label>Current Password</label>
          <input type="password" name="current_password" placeholder="Enter current password"/>
        </div>

        <div class="form-group">
          <label>New Password</label>
          <input type="password" name="new_password" placeholder="Min. 6 characters"/>
          <span class="field-hint">Leave blank to keep your current password.</span>
        </div>

        <div style="margin-top:24px;">
          <button type="submit" class="btn-gold">Save Changes</button>
        </div>
      </form>
    </div>

    <!-- Danger zone: logout -->
    <div class="settings-card danger-card" style="margin-top:28px;">
      <div class="settings-card-header" style="color:var(--error);">Session</div>
      <div style="padding:24px;">
        <p style="font-size:0.83rem;color:var(--muted);margin-bottom:16px;">Sign out of your current session on this device.</p>
        <a href="logout.php" class="btn-danger">Sign Out</a>
      </div>
    </div>

  </main>
</div>

<footer class="oc-footer">
  <div>© <?= date('Y') ?> Overdose Cafe · Manila, PH</div>
  <span>Intentional spaces. Exceptional coffee.</span>
</footer>

<style>
  .settings-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 4px;
    overflow: hidden;
  }

  .settings-card-header {
    padding: 14px 24px;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--gold);
    border-bottom: 1px solid var(--border);
    background: rgba(212,175,90,0.04);
  }

  .settings-card form,
  .settings-card .info-grid {
    padding: 24px;
  }

  .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
  }

  .info-item { display: flex; flex-direction: column; gap: 4px; }
  .info-label { font-size: 0.7rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; color: var(--gold); opacity: 0.6; }
  .info-value { font-size: 0.9rem; color: var(--cream); font-weight: 500; }

  .form-group { margin-bottom: 20px; }

  .form-group label {
    display: block;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--gold);
    opacity: 0.8;
    margin-bottom: 8px;
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
  .form-group textarea:focus { border-color: var(--gold); }

  .form-group input::placeholder,
  .form-group textarea::placeholder { color: rgba(245,237,216,0.2); }

  .field-hint {
    display: block;
    font-size: 0.72rem;
    color: var(--muted2);
    margin-top: 5px;
  }

  .settings-section-label {
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--muted);
    margin: 24px 0 16px;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .optional-tag {
    font-size: 0.65rem;
    background: rgba(245,237,216,0.08);
    border-radius: 2px;
    padding: 2px 7px;
    color: var(--muted2);
    letter-spacing: 0.5px;
    font-weight: 500;
  }

  .danger-card { border-color: rgba(224,85,85,0.2); }
  .danger-card .settings-card-header { background: rgba(224,85,85,0.04); }

  .btn-danger {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: transparent;
    color: var(--error);
    border: 1px solid rgba(224,85,85,0.4);
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

  .btn-danger:hover {
    background: rgba(224,85,85,0.1);
    border-color: var(--error);
  }
</style>

</body>
</html>
