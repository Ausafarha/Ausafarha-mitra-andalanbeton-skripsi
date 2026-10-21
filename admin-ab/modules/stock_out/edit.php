<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/app.php';
initSession(); requireLogin();

$id = getInt('id'); 
$row = Database::fetchOne("SELECT * FROM stock_out WHERE id=?", [$id]);

if (!$row) {
    setFlash('error', 'Data tidak ditemukan.');
    redirect(APP_URL.'/admin-ab/modules/stock_out/index.php');
}

// 🛡️ PROTEKSI: Jika barang keluar ini berasal dari Pesanan, larang edit manual dari sini!
if (!empty($row['order_id'])) {
    setFlash('error', 'Gagal! Data barang keluar ini terikat secara otomatis dengan Pesanan. Silakan kelola melalui menu Pesanan.');
    redirect(APP_URL.'/admin-ab/modules/stock_out/index.php');
}

$pageTitle = 'Edit Barang Keluar Manual'; 
$errors = [];
$materials = Database::fetchAll("SELECT id, name, unit FROM materials WHERE is_active=true ORDER BY name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Token tidak valid.';
    } else {
        $data = [
            'material_id'    => postInt('material_id'),
            'quantity'       => postInt('quantity'),
            'destination'    => post('destination') ?: null,
            'customer_name'  => post('customer_name') ?: null,
            'driver_name'    => post('driver_name') ?: null,
            'vehicle_number' => post('vehicle_number') ?: null,
            'notes'          => post('notes') ?: null,
            'out_date'       => post('out_date') ?: date('Y-m-d')
        ];

        if (!$data['material_id']) $errors[] = 'Pilih material.';
        if ($data['quantity'] <= 0) $errors[] = 'Jumlah harus > 0.';
        if (empty($data['driver_name'])) $errors[] = 'Nama driver wajib diisi.';
        if (empty($data['vehicle_number'])) $errors[] = 'Nomor kendaraan wajib diisi.';

        if (empty($errors)) {
            // Hitung selisih stok jika quantity berubah (opsional/penyesuaian stok sederhana)
            // Di sini kita update langsung data barang keluar manualnya
            Database::update('stock_out', $data, 'id=?', [$id]);
            logActivity('update', 'stock_out', "Mengubah data barang keluar manual ID:{$id}");
            setFlash('success', 'Data barang keluar manual berhasil diperbarui.');
            redirect(APP_URL.'/admin-ab/modules/stock_out/index.php');
        }
        $row = array_merge($row, $data);
    }
}
include __DIR__.'/../../partials/head.php';
?>
<div class="admin-wrapper">
<?php include __DIR__.'/../../partials/sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__.'/../../partials/topbar.php'; ?>
<div class="page-body">
<div class="mb-20">
    <a href="<?=APP_URL?>/admin-ab/modules/stock_out/index.php" class="btn btn-ghost btn-sm" style="margin-bottom:8px;"><i class="fas fa-arrow-left"></i> Kembali</a>
    <h2 style="font-size:20px;font-weight:800;">Edit Barang Keluar Manual</h2>
</div>

<?php foreach($errors as $e):?><div class="alert alert-danger"><?=htmlspecialchars($e)?></div><?php endforeach;?>

<div class="card" style="max-width:700px;">
  <div class="card-body">
    <form method="POST">
      <?=csrfField()?>
      <div class="form-group">
        <label class="form-label">Material <span>*</span></label>
        <select name="material_id" class="form-control" required>
          <option value="">-- Pilih --</option>
          <?php foreach($materials as $m):?>
          <option value="<?=$m['id']?>" <?=$row['material_id']==$m['id']?'selected':''?>><?=htmlspecialchars($m['name'])?> (<?=$m['unit']?>)</option>
          <?php endforeach;?>
        </select>
      </div>
      <div class="grid grid-2">
        <div class="form-group"><label class="form-label">Jumlah <span>*</span></label><input type="number" name="quantity" class="form-control" value="<?=$row['quantity']?>" min="1" required></div>
        <div class="form-group"><label class="form-label">Tanggal <span>*</span></label><input type="date" name="out_date" class="form-control" value="<?=$row['out_date']?>" required></div>
      </div>
      <div class="form-group"><label class="form-label">Tujuan/Proyek</label><input type="text" name="destination" class="form-control" value="<?=htmlspecialchars($row['destination']??'')?>"></div>
      <div class="form-group"><label class="form-label">Nama Pelanggan (Opsional)</label><input type="text" name="customer_name" class="form-control" value="<?=htmlspecialchars($row['customer_name']??'')?>"></div>
      <div class="grid grid-2">
        <div class="form-group"><label class="form-label">Driver <span>*</span></label><input type="text" name="driver_name" class="form-control" value="<?=htmlspecialchars($row['driver_name']??'')?>" required></div>
        <div class="form-group"><label class="form-label">Nomor Kendaraan (PLAT) <span>*</span></label><input type="text" name="vehicle_number" class="form-control text-mono" value="<?=htmlspecialchars($row['vehicle_number']??'')?>" required></div>
      </div>
      <div class="form-group"><label class="form-label">Catatan</label><textarea name="notes" class="form-control" rows="3"><?=htmlspecialchars($row['notes']??'')?></textarea></div>
      <div style="display:flex;gap:10px;"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Perbarui Data</button><a href="<?=APP_URL?>/admin-ab/modules/stock_out/index.php" class="btn btn-secondary">Batal</a></div>
    </form>
  </div>
</div>
</div>
<?php include __DIR__.'/../../partials/footer.php'; ?>