<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/app.php';
initSession(); requireLogin();

$pageTitle = 'Pengaturan'; 
$pageSubtitle = 'Konfigurasi sistem';
$errors = [];
$user = currentUser();
$profile = Database::fetchOne("SELECT * FROM company_profile LIMIT 1") ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Token tidak valid.';
    } else {
        $tab = post('tab', 'logo');

        // === TAB LOGO ===
        if ($tab === 'logo') {
            if (!empty($_FILES['logo']['name'])) {
                $up = uploadImage($_FILES['logo'], 'company');
                if ($up['success']) {
                    // Hapus logo lama jika ada
                    if (!empty($profile['logo'])) {
                        deleteFile($profile['logo']);
                    }
                    
                    // Simpan ke database
                    if ($profile) {
                        Database::update('company_profile', ['logo' => $up['filename']], 'id=?', [$profile['id']]);
                    } else {
                        // Jika belum ada data profil sama sekali, buat baru
                        Database::insert('company_profile', [
                            'company_name' => 'Andalan Beton',
                            'logo' => $up['filename']
                        ]);
                    }
                    logActivity('update', 'settings', 'Memperbarui logo perusahaan');
                    setFlash('success', 'Logo perusahaan berhasil diperbarui.');
                    redirect(APP_URL.'/admin-ab/modules/settings/index.php?tab=logo');
                } else {
                    $errors[] = $up['message'];
                }
            } else {
                $errors[] = 'Pilih file gambar terlebih dahulu.';
            }
        }

        // === TAB AKUN & KEAMANAN ===
        if ($tab === 'account') {
            $newUsername = post('new_username');
            $oldPass = $_POST['old_password'] ?? '';
            $newPass = $_POST['new_password'] ?? '';
            $conPass = $_POST['confirm_password'] ?? '';
            
            $dbUser = Database::fetchOne("SELECT * FROM users WHERE id=?",[$user['id']]);
            
            if (!password_verify($oldPass, $dbUser['password'])) {
                $errors[] = 'Password lama tidak sesuai.';
            }
            
            // Cek username unik
            if (!empty($newUsername)) {
                $exists = Database::fetchColumn("SELECT id FROM users WHERE username = ? AND id != ?", [$newUsername, $user['id']]);
                if ($exists) $errors[] = 'Username sudah digunakan.';
            }
            
            if (!empty($newPass) && $newPass !== $conPass) {
                $errors[] = 'Konfirmasi password tidak cocok.';
            }
            if (!empty($newPass) && strlen($newPass) < 6) {
                $errors[] = 'Password baru minimal 6 karakter.';
            }
            
            if (empty($errors)) {
                $updateData = ['username' => $newUsername];
                if (!empty($newPass) && strlen($newPass) >= 6) {
                    $updateData['password'] = password_hash($newPass, PASSWORD_DEFAULT);
                }
                Database::update('users', $updateData, 'id=?', [$user['id']]);
                logActivity('update', 'settings', 'Mengubah username/password');
                setFlash('success', 'Akun berhasil diperbarui.');
                redirect(APP_URL.'/admin-ab/modules/settings/index.php?tab=account');
            }
        }
    }
}

$profile = Database::fetchOne("SELECT * FROM company_profile LIMIT 1") ?: [];
$activeTab = get('tab','logo');
include __DIR__.'/../../partials/head.php';
?>
<div class="admin-wrapper">
<?php include __DIR__.'/../../partials/sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__.'/../../partials/topbar.php'; ?>
<div class="page-body">

<div class="section-header mb-20"><h2>Pengaturan Sistem</h2><p>Kelola logo dan keamanan akun Anda</p></div>

<?php foreach($errors as $e):?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?=htmlspecialchars($e)?></div><?php endforeach;?>

<!-- Tabs -->
<div style="display:flex;gap:6px;margin-bottom:20px;border-bottom:1.5px solid var(--border);padding-bottom:0;">
  <?php foreach([
    'logo'=>'🖼️ Logo Perusahaan',
    'account'=>'👤 Akun & Keamanan'
  ] as $tab=>$lbl):?>
  <a href="?tab=<?=$tab?>" style="padding:10px 18px;border-radius:var(--radius-md) var(--radius-md) 0 0;font-size:13.5px;font-weight:600;text-decoration:none;border:1.5px solid <?=$activeTab===$tab?'var(--border) var(--border) var(--bg-surface)':'transparent'?>;background:<?=$activeTab===$tab?'var(--bg-surface)':'transparent'?>;color:<?=$activeTab===$tab?'var(--brand-600)':'var(--text-secondary)'?>;margin-bottom:-1.5px;"><?=$lbl?></a>
  <?php endforeach;?>
</div>

<?php if($activeTab==='logo'):?>
<div class="card" style="max-width:500px;">
  <div class="card-header"><div class="card-title">Logo Perusahaan</div></div>
  <div class="card-body">
    <form method="POST" enctype="multipart/form-data">
      <?=csrfField()?>
      <input type="hidden" name="tab" value="logo">
      
      <div style="text-align:center; margin-bottom: 20px;">
        <?php if(!empty($profile['logo'])):?>
          <img id="logo-preview" src="<?=uploadUrl($profile['logo'])?>" style="max-width:180px; max-height:180px; object-fit:contain; border-radius:8px; border: 1px solid var(--border); padding: 10px; background: var(--bg-surface);">
        <?php else:?>
          <div style="width:120px; height:120px; margin:0 auto; border-radius:50%; background:var(--bg-muted); display:flex; align-items:center; justify-content:center; font-size:36px; font-weight:800; color:var(--brand-500);">
            AB
          </div>
        <?php endif;?>
      </div>

      <div class="upload-area" onclick="document.getElementById('logo-input').click()" style="margin-bottom:16px;">
        <i class="fas fa-cloud-upload-alt" style="font-size:24px;color:var(--brand-500);"></i>
        <div style="font-size:13px; margin-top:6px; font-weight:600;">Klik untuk pilih gambar</div>
        <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Format: JPG, PNG, WebP (Maks 5MB)</div>
      </div>
      
      <input type="file" id="logo-input" name="logo" accept="image/*" style="display:none;" required onchange="previewImage(this)">
      
      <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Simpan Logo</button>
    </form>
  </div>
</div>
<?php endif;?>

<?php if($activeTab==='account'):?>
<div class="card" style="max-width:480px;">
    <div class="card-header"><div class="card-title">Ganti Username & Password</div></div>
    <div class="card-body">
        <form method="POST">
            <?=csrfField()?>
            <input type="hidden" name="tab" value="account">
            <div class="form-group">
                <label class="form-label">Username Baru <span>*</span></label>
                <input type="text" name="new_username" class="form-control" value="<?=htmlspecialchars($user['username'] ?? '')?>" required>
                <div class="form-text">Username yang akan digunakan untuk login.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Password Lama <span>*</span></label>
                <input type="password" name="old_password" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Password Baru (opsional)</label>
                <input type="password" name="new_password" class="form-control" minlength="6">
                <div class="form-text">Kosongkan jika tidak ingin mengganti password.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Konfirmasi Password Baru</label>
                <input type="password" name="confirm_password" class="form-control">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Perubahan</button>
        </form>
    </div>
</div>
<?php endif;?>

</div>
<?php include __DIR__.'/../../partials/footer.php'; ?>

<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            let preview = document.getElementById('logo-preview');
            if (!preview) {
                // Jika sebelumnya kotak AB, ganti dengan tag img
                const container = input.parentElement.querySelector('div[style*="text-align:center"]');
                container.innerHTML = '<img id="logo-preview" style="max-width:180px; max-height:180px; object-fit:contain; border-radius:8px; border: 1px solid var(--border); padding: 10px; background: var(--bg-surface);">';
                preview = document.getElementById('logo-preview');
            }
            preview.src = e.target.result;
            preview.style.display = 'block';
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>