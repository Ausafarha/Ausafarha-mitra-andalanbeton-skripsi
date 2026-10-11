<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
initSession();

// Jika udah login, langsung redirect ke dashboard
if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/admin-ab/dashboard.php');
    exit;
}

$error = '';
$username = '';

// Konfigurasi Login Throttle
$max_attempts = 5;
$lockout_time = 15 * 60; // 15 menit dalam detik

// Inisialisasi session tracking percobaan login jika belum ada
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}
if (!isset($_SESSION['lockout_time'])) {
    $_SESSION['lockout_time'] = 0;
}

// Cek apakah sedang dalam masa pemblokiran (lockout)
if (time() < $_SESSION['lockout_time']) {
    $remaining_time = ceil(($_SESSION['lockout_time'] - time()) / 60);
    $error = "⛔ Terlalu banyak percobaan login yang gagal. Akun/perangkat Anda diblokir sementara. Silakan coba lagi dalam $remaining_time menit.";
}

// Cek apakah session expired (dari redirect dashboard)
if (isset($_GET['msg']) && $_GET['msg'] === 'session_expired' && empty($error)) {
    $error = '⏳ Sesi Anda telah berakhir. Silakan login kembali.';
}

// Regenerate CSRF token setiap load login
if (empty($_SESSION['csrf_token']) || isset($_GET['refresh'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && time() >= $_SESSION['lockout_time']) {
    if (!verifyCsrf()) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $username = post('username');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Username dan password wajib diisi.';
        } else {
            try {
                $user = Database::fetchOne(
                    "SELECT * FROM users WHERE username = ? AND is_active = true LIMIT 1",
                    [$username]
                );

                if ($user && password_verify($password, $user['password'])) {
                    // Login Berhasil -> Reset counter login attempts
                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['lockout_time'] = 0;

                    session_regenerate_id(true);
                    $_SESSION['admin_id']       = $user['id'];
                    $_SESSION['admin_name']     = $user['name'];
                    $_SESSION['admin_username'] = $user['username'];
                    $_SESSION['admin_role']     = $user['role'];
                    $_SESSION['admin_login_at'] = time();

                    // Update last login
                    Database::query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);

                    // Log activity
                    Database::query(
                        "INSERT INTO activity_logs (user_id, action, module, description, ip_address) VALUES (?,?,?,?,?)",
                        [$user['id'], 'login', 'auth', 'User ' . $user['username'] . ' berhasil login', $_SERVER['REMOTE_ADDR'] ?? '']
                    );

                    $redirect = $_SESSION['login_redirect'] ?? APP_URL . '/admin-ab/dashboard.php';
                    unset($_SESSION['login_redirect']);
                    redirect($redirect);
                } else {
                    // Login Gagal -> Tambah counter
                    $_SESSION['login_attempts']++;
                    $attempts_left = $max_attempts - $_SESSION['login_attempts'];

                    if ($_SESSION['login_attempts'] >= $max_attempts) {
                        $_SESSION['lockout_time'] = time() + $lockout_time;
                        $error = '⛔ Batas percobaan login terlampaui. Akun Anda dikunci selama 15 menit ke depan.';
                    } else {
                        $error = "Username atau password salah. Sisa percobaan: $attempts_left kali.";
                    }
                    sleep(1);
                }
            } catch (PDOException $e) {
                $error = '⚠️ Gagal terhubung ke database. Silakan coba beberapa saat lagi. (' . $e->getMessage() . ')';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login Admin — <?= APP_NAME ?></title>
  <link rel="shortcut icon" href="/assets/img/icon-192.png" type="image/png">
  <link rel="icon" href="/assets/img/icon-192.png" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= ASSETS_URL ?>css/main.css">
  <style>
    body { background: var(--bg-base); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }

    .login-wrapper {
      display: grid; grid-template-columns: 1fr 1fr;
      max-width: 900px; width: 100%;
      border-radius: var(--radius-xl);
      overflow: hidden;
      box-shadow: var(--shadow-xl);
      border: 1px solid var(--border);
    }

    .login-left {
      background: linear-gradient(145deg, #0f172a 0%, #2c5cec 60%, #66ef8f 100%);
      padding: 48px 40px;
      display: flex; flex-direction: column; justify-content: space-between;
      position: relative; overflow: hidden;
    }

    .login-left::before {
      content: '';
      position: absolute; top: -80px; right: -80px;
      width: 300px; height: 300px;
      background: rgba(59,130,246,0.15);
      border-radius: 50%;
    }

    .login-left::after {
      content: '';
      position: absolute; bottom: -60px; left: -60px;
      width: 200px; height: 200px;
      background: rgba(59,130,246,0.1);
      border-radius: 50%;
    }

    .login-brand { position: relative; z-index: 1; }
    .login-brand-icon {
      width: 52px; height: 52px;
      background: rgba(255,255,255,0.15);
      border-radius: var(--radius-lg);
      display: flex; align-items: center; justify-content: center;
      font-size: 24px; font-weight: 800; color: white;
      margin-bottom: 16px; border: 1px solid rgba(255,255,255,0.2);
    }
    .login-brand h2 { font-size: 22px; font-weight: 800; color: white; }
    .login-brand p  { font-size: 13px; color: rgba(255,255,255,0.5); margin-top: 4px; }

    .login-features { position: relative; z-index: 1; }
    .login-feature { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
    .login-feature-icon { width: 36px; height: 36px; background: rgba(255,255,255,0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--brand-300); font-size: 15px; }
    .login-feature-text { font-size: 13px; color: rgba(255,255,255,0.7); }
    .login-feature-text strong { color: white; display: block; font-size: 13.5px; }

    .login-right {
      background: var(--bg-surface);
      padding: 48px 40px;
      display: flex; flex-direction: column; justify-content: center;
    }

    .login-right h1 { font-size: 26px; font-weight: 800; margin-bottom: 6px; }
    .login-right p  { font-size: 14px; color: var(--text-muted); margin-bottom: 32px; }

    .login-hint {
      padding: 12px 16px;
      background: var(--info-bg);
      border: 1px solid var(--brand-200);
      border-radius: var(--radius-md);
      font-size: 12.5px; color: var(--brand-700);
      margin-bottom: 24px;
    }

    @media (max-width: 640px) {
      .login-wrapper { grid-template-columns: 1fr; }
      .login-left { display: none; }
      .login-right { padding: 32px 24px; }
    }
  </style>
  <script>
    (function() {
      const t = localStorage.getItem('andalan_theme') || 'light';
      document.documentElement.setAttribute('data-theme', t);
    })();
  </script>
</head>
<body>
<div class="toast-container"></div>

<div class="login-wrapper">
  <!-- Left Panel -->
  <div class="login-left">
    <div class="login-brand">
      <div class="login-brand-icon">AB</div>
      <h2>PT MITRA ANDALAN BETON PANTURA</h2>
      <p>Industry Readymix dan Precast</p>
    </div>

    <div class="login-features">
      <div class="login-feature">
        <div class="login-feature-icon"><i class="fas fa-chart-pie"></i></div>
        <div class="login-feature-text">
          <strong>Dashboard Analitik</strong>
          Monitor kinerja bisnis real-time
        </div>
      </div>
      <div class="login-feature">
        <div class="login-feature-icon"><i class="fas fa-boxes"></i></div>
        <div class="login-feature-text">
          <strong>Monitoring Persediaan</strong>
          Kelola stok material secara efisien
        </div>
      </div>
      <div class="login-feature">
        <div class="login-feature-icon"><i class="fas fa-truck"></i></div>
        <div class="login-feature-text">
          <strong>Distribusi & Pesanan</strong>
          Pantau distribusi dan pemesanan online
        </div>
      </div>
    </div>
  </div>

  <!-- Right Panel -->
  <div class="login-right">
    <h1>Admin Andalan Beton</h1>
    <p>Silakan masuk dengan akun admin Anda</p>

    

    <?php if ($error): ?>
    <div class="alert alert-danger" data-auto-dismiss="5000">
      <i class="fas fa-exclamation-circle"></i>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="">
      <?= csrfField() ?>

      <div class="form-group">
        <label class="form-label" for="username">Username</label>
        <div class="input-group">
          <i class="fas fa-user input-group-icon"></i>
          <input type="text" id="username" name="username" class="form-control"
            placeholder="Masukkan username" value="<?= htmlspecialchars($username) ?>"
            required autocomplete="username" autofocus>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <div class="input-group">
          <i class="fas fa-lock input-group-icon"></i>
          <input type="password" id="password" name="password" class="form-control"
            placeholder="Masukkan password" required autocomplete="current-password"
            style="padding-right: 44px;">
          <button type="button" onclick="togglePassword()" style="position:absolute;right:12px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:14px;" id="pass-toggle">
            <i class="fas fa-eye"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 btn-lg" style="margin-top: 8px;">
        <i class="fas fa-sign-in-alt"></i> Masuk ke Dashboard
      </button>
    </form>

    <p style="margin-top: 24px; font-size: 12.5px; text-align: center; color: var(--text-muted);">
      <a href="<?= APP_URL ?>/" style="color: var(--text-brand);">← Kembali ke Website</a>
    </p>
  </div>
</div>

<script src="<?= ASSETS_URL ?>js/main.js?v=<?= time() ?>"></script>
<script>
function togglePassword() {
  const inp = document.getElementById('password');
  const btn = document.getElementById('pass-toggle').querySelector('i');
  if (inp.type === 'password') {
    inp.type = 'text';
    btn.className = 'fas fa-eye-slash';
  } else {
    inp.type = 'password';
    btn.className = 'fas fa-eye';
  }
}
</script>
</body>
</html>
