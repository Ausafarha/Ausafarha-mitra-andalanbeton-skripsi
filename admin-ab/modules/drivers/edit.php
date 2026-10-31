<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/app.php';
initSession();
requireLogin();

$id = getInt('id');
// Gunakan fetchOne sesuai database.php[cite: 4]
$driver = Database::fetchOne("SELECT * FROM drivers WHERE id = ?", [$id]);
if (!$driver) {
    setFlash('error', 'Data supir tidak ditemukan.');
    redirect(APP_URL . '/admin-ab/modules/drivers/index.php');
}

$pageTitle = 'Edit Supir';
$errors    = [];
$data      = $driver;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { 
        $errors[] = 'Token keamanan tidak valid.'; 
    } else {
        $data = [
            'name'      => trim(post('name')),
            'phone'     => trim(post('phone')),
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];

        if (empty($data['name'])) {
            $errors[] = 'Nama supir wajib diisi.';
        }

        if (empty($errors)) {
            Database::update('drivers', $data, 'id = ?', [$id]);
            logActivity('update', 'drivers', "Mengubah data supir: {$data['name']}");
            setFlash('success', "Data supir \"{$data['name']}\" berhasil diperbarui.");
            redirect(APP_URL . '/admin-ab/modules/drivers/index.php');
        }
    }
}


include __DIR__ . '/../../partials/head.php';
?>
<div class="admin-wrapper">
<?php include __DIR__ . '/../../partials/sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__ . '/../../partials/topbar.php'; ?>
<div class="page-body">

<div class="flex-between mb-20">
  <div>
    <a href="<?= APP_URL ?>/admin-ab/modules/drivers/index.php" class="btn btn-ghost btn-sm" style="margin-bottom:8px;">
      <i class="fas fa-arrow-left"></i> Kembali
    </a>
    <h2 style="font-size:20px;font-weight:800;">Edit Supir: <?= htmlspecialchars($driver['name']) ?></h2>
  </div>
</div>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<form method="POST">
  <?= csrfField() ?>
  <div class="card mb-20" style="max-width: 600px;">
    <div class="card-header"><div class="card-title">Informasi Supir</div></div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label">Nama Lengkap Supir <span>*</span></label>
        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($data['name']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Nomor Telepon / WhatsApp</label>
        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($data['phone']) ?>">
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-top:15px;">
          <input type="checkbox" name="is_active" value="1" <?= $data['is_active'] ? 'checked' : '' ?> style="width:18px;height:18px;">
          <div>
            <div style="font-size:13.5px;font-weight:600;">Status Aktif</div>
            <div style="font-size:12px;color:var(--text-muted);">Supir dapat dipilih dalam penjadwalan tugas</div>
          </div>
        </label>
      </div>
    </div>
  </div>

  <div style="display:flex;gap:10px;margin-top:8px;">
    <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Perbarui Supir</button>
    <a href="<?= APP_URL ?>/admin-ab/modules/drivers/index.php" class="btn btn-secondary btn-lg">Batal</a>
  </div>
</form>

</div> </div> </div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>