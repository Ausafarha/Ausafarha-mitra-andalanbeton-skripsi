<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/app.php';
initSession();
requireLogin();

$id = getInt('id');
$vehicle = Database::fetchOne("SELECT * FROM vehicles WHERE id = ?", [$id]);
if (!$vehicle) {
    setFlash('error', 'Data kendaraan tidak ditemukan.');
    redirect(APP_URL . '/admin-ab/modules/vehicles/index.php');
}

$pageTitle = 'Edit Kendaraan';
$errors    = [];
$data      = $vehicle;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { 
        $errors[] = 'Token keamanan tidak valid.'; 
    } else {
        $data = [
            'plate_number' => strtoupper(trim(post('plate_number'))),
            'vehicle_type' => trim(post('vehicle_type')),
            'is_active'    => isset($_POST['is_active']) ? 1 : 0
        ];

        if (empty($data['plate_number'])) {
            $errors[] = 'Plat nomor kendaraan wajib diisi.';
        } else {
            $exists = Database::fetchColumn("SELECT id FROM vehicles WHERE plate_number = ? AND id != ?", [$data['plate_number'], $id]);
            if ($exists) {
                $errors[] = 'Plat nomor kendaraan tersebut sudah digunakan oleh kendaraan lain.';
            }
        }

        if (empty($errors)) {
            Database::update('vehicles', $data, 'id = ?', [$id]);
            logActivity('update', 'vehicles', "Mengubah data kendaraan: {$data['plate_number']}");
            setFlash('success', "Data kendaraan \"{$data['plate_number']}\" berhasil diperbarui.");
            redirect(APP_URL . '/admin-ab/modules/vehicles/index.php');
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
    <a href="<?= APP_URL ?>/admin-ab/modules/vehicles/index.php" class="btn btn-ghost btn-sm" style="margin-bottom:8px;">
      <i class="fas fa-arrow-left"></i> Kembali
    </a>
    <h2 style="font-size:20px;font-weight:800;">Edit Kendaraan: <?= htmlspecialchars($vehicle['plate_number']) ?></h2>
  </div>
</div>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<form method="POST">
  <?= csrfField() ?>
  <div class="card mb-20" style="max-width: 600px;">
    <div class="card-header"><div class="card-title">Informasi Kendaraan</div></div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label">Plat Nomor <span>*</span></label>
        <input type="text" name="plate_number" class="form-control" value="<?= htmlspecialchars($data['plate_number']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Jenis / Tipe Kendaraan</label>
        <select name="vehicle_type" class="form-control">
          <option value="">-- Pilih Jenis Kendaraan --</option>
          <?php foreach (['Truk Molen (Mixer Truck)', 'Truk Dump / Tronton', 'Pick-up', 'Truk Box', 'Kendaraan Operasional'] as $vt): ?>
            <option value="<?= $vt ?>" <?= $data['vehicle_type']===$vt?'selected':'' ?>><?= $vt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-top:15px;">
          <input type="checkbox" name="is_active" value="1" <?= $data['is_active'] ? 'checked' : '' ?> style="width:18px;height:18px;">
          <div>
            <div style="font-size:13.5px;font-weight:600;">Status Aktif</div>
            <div style="font-size:12px;color:var(--text-muted);">Kendaraan dapat dipilih dalam penjadwalan pengiriman</div>
          </div>
        </label>
      </div>
    </div>
  </div>

  <div style="display:flex;gap:10px;margin-top:8px;">
    <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Perbarui Kendaraan</button>
    <a href="<?= APP_URL ?>/admin-ab/modules/vehicles/index.php" class="btn btn-secondary btn-lg">Batal</a>
  </div>
</form>

</div> </div> </div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>