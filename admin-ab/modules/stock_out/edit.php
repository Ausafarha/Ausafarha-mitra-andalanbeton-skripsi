<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/app.php';
initSession(); requireLogin();

$id = getInt('id'); 
$row = Database::fetchOne("
    SELECT so.*, m.name AS material_name, m.unit 
    FROM stock_out so 
    JOIN materials m ON so.material_id = m.id 
    WHERE so.id=?", 
[$id]);

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
$drivers = Database::fetchAll("SELECT name FROM drivers WHERE is_active = true ORDER BY name ASC");
$vehicles = Database::fetchAll("SELECT plate_number, vehicle_type FROM vehicles WHERE is_active = true ORDER BY plate_number ASC");
$activeSchedules = Database::fetchAll("
    SELECT so.id, so.order_id, so.driver_name, so.vehicle_number, 
           COALESCE(o.delivery_date, so.out_date) as delivery_date, 
           COALESCE(so.out_time, o.delivery_time, '00:00') as out_time, 
           COALESCE(o.status, 'manual') as order_status,
           COALESCE(NULLIF(so.destination,''), NULLIF(o.delivery_address,''), '-') as destination
    FROM stock_out so 
    LEFT JOIN orders o ON so.order_id = o.id
    WHERE (o.status IS NULL OR o.status != 'rejected') 
      AND NOT (so.order_id IS NOT NULL AND o.id IS NULL)
");

// === Generate WA URL untuk kirim ETA ke penerima ===
$waUrl = '';
if (!empty($row['estimated_finish_time']) && !empty($row['customer_phone'])) {
    $phone = preg_replace('/[^0-9]/', '', $row['customer_phone']);
    if (str_starts_with($phone, '0')) {
        $phone = '62' . substr($phone, 1);
    } elseif (str_starts_with($phone, '8')) {
        $phone = '62' . $phone;
    }

    $etaDate   = formatDate(date('Y-m-d', strtotime($row['estimated_finish_time'])));
    $etaTime   = date('H:i', strtotime($row['estimated_finish_time']));
    $custName  = $row['customer_name'] ?: 'Bapak/Ibu';
    $matName   = $row['material_name'] ?: 'Material';
    $qty       = $row['quantity'] ?? 0;
    $unit      = $row['unit'] ?? '';
    $driver    = $row['driver_name'] ?: '-';
    $plat      = $row['vehicle_number'] ?: '-';
    $tujuan    = $row['destination'] ?: '-';

    $waMsg = "Halo *{$custName}*,%0A%0A"
           . "Material *{$matName}* sebanyak *{$qty} {$unit}* sedang dikirim ke lokasi Anda.%0A%0A"
           . "🚚 Driver: *{$driver}*%0A"
           . "🚛 Kendaraan: *{$plat}*%0A"
           . "⏰ Estimasi Tiba: *{$etaDate} jam {$etaTime}*%0A"
           . "📍 Tujuan: {$tujuan}%0A%0A"
           . "Balas pesan ini kalau ada perubahan atau kendala.%0A"
           . "Terima kasih 🙏";

    $waUrl = "https://wa.me/{$phone}?text={$waMsg}";
}

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
            'customer_phone' => post('customer_phone') ?: null,
            'notes'          => post('notes') ?: null,
            'out_date'       => post('out_date') ?: date('Y-m-d'),
            'out_time'       => post('out_time') ?: date('H:i'),
            'estimated_finish_time' => post('estimated_finish_time') ? str_replace('T', ' ', post('estimated_finish_time')) : null
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
            redirect(APP_URL.'/admin-ab/modules/stock_out/edit.php?id='.$id);
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
        <div class="form-group"><label class="form-label">Tanggal Kirim <span>*</span></label><input type="date" name="out_date" class="form-control" value="<?=$row['out_date']?>" required></div>
      </div>
      <div class="form-group">
        <label class="form-label">Jam Kirim <span>*</span></label>
        <input type="time" name="out_time" class="form-control" value="<?= htmlspecialchars($row['out_time'] ?? date('H:i')) ?>" required>
      </div>
      <div class="form-group"><label class="form-label">Tujuan/Proyek</label><input type="text" name="destination" class="form-control" value="<?=htmlspecialchars($row['destination']??'')?>"></div>
      <div class="form-group"><label class="form-label">Nama Pelanggan (Opsional)</label><input type="text" name="customer_name" class="form-control" value="<?=htmlspecialchars($row['customer_name']??'')?>"></div>
      <div class="form-group">
    <label class="form-label">No. HP Penerima (opsional)</label>
    <input type="tel" name="customer_phone" class="form-control" value="<?=htmlspecialchars($row['customer_phone']??'')?>">
</div>
<div class="form-group">
    <label class="form-label">Estimasi Tiba (ETA)</label>
    <input type="datetime-local" name="estimated_finish_time" class="form-control"
           value="<?= !empty($row['estimated_finish_time']) ? date('Y-m-d\TH:i', strtotime($row['estimated_finish_time'])) : '' ?>">
    <small style="color:var(--text-muted);font-size:11.5px;display:block;margin-top:4px;">
        <i class="fas fa-info-circle"></i> Opsional — dipakai untuk info WA ke penerima
    </small>
</div>
      <div class="grid grid-2">
        <div class="form-group">
          <label class="form-label">Pilih Driver <span>*</span></label>
          <select name="driver_name" class="form-control" required>
            <option value="">-- Pilih Supir --</option>
            <?php foreach($drivers as $d): ?>
              <option value="<?= htmlspecialchars($d['name']) ?>" <?= ($row['driver_name'] ?? '') === $d['name'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Pilih Kendaraan (Plat) <span>*</span></label>
          <select name="vehicle_number" class="form-control text-mono" required>
            <option value="">-- Pilih Kendaraan --</option>
            <?php foreach($vehicles as $v): ?>
              <option value="<?= htmlspecialchars($v['plate_number']) ?>" <?= ($row['vehicle_number'] ?? '') === $v['plate_number'] ? 'selected' : '' ?>><?= htmlspecialchars($v['plate_number']) ?> (<?= htmlspecialchars($v['vehicle_type']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group"><label class="form-label">Catatan</label><textarea name="notes" class="form-control" rows="3"><?=htmlspecialchars($row['notes']??'')?></textarea></div>
      <div style="display:flex;gap:10px;"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Perbarui Data</button><a href="<?=APP_URL?>/admin-ab/modules/stock_out/index.php" class="btn btn-secondary">Batal</a></div>
    </form>

    <?php if ($waUrl): ?>
    <div style="margin-top:16px;padding-top:16px;border-top:1px dashed var(--border);">
        <div style="font-size:12.5px;font-weight:600;color:var(--text-muted);margin-bottom:8px;">
            <i class="fas fa-paper-plane"></i> Setelah ETA tersimpan, kirim ke penerima:
        </div>
        <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank"
           class="btn btn-lg w-100"
           style="background:#25d366;color:white;border-color:#25d366;font-weight:700;">
            <i class="fab fa-whatsapp"></i> Kirim ETA via WA ke Penerima
        </a>
        <small style="color:var(--text-muted);font-size:11px;display:block;margin-top:6px;text-align:center;">
            Buka WA Web/App dengan pesan siap kirim
        </small>
    </div>
    <?php endif; ?>
  </div>
</div>
</div>
<script>
const activeSchedules = <?= json_encode($activeSchedules) ?>;

function analyzeArmadaConflict(driver, vehicle, deliveryDate, newOutTime) {
    // 1. Fungsi Pembersih Teks (Hapus link Maps panjang)
    function cleanDest(dest) {
        if (!dest) return '-';
        let clean = dest.replace(/📍 Titik Lokasi Maps:.*/ig, '').replace(/https?:\/\/maps\.google\.com[^\s]*/ig, '').trim();
        return clean || 'Titik Maps';
    }

    const sameDay = activeSchedules.filter(s => String(s.delivery_date) === String(deliveryDate));

    const driverRows = driver ? sameDay.filter(s => (s.driver_name || '').toLowerCase() === driver.toLowerCase()) : [];
    // KUNCI PERBAIKAN: Hitung berdasarkan ID unik (Nomor Pesanan / ID Stock Out), bukan Jam
    const driverTx = new Set(driverRows.map(s => s.order_id || `so-${s.id}`));
    const driverCount = driverTx.size;

    const vehicleRows = vehicle ? sameDay.filter(s => (s.vehicle_number || '').toLowerCase() === vehicle.toLowerCase()) : [];
    const vehicleTx = new Set(vehicleRows.map(s => s.order_id || `so-${s.id}`));
    const vehicleCount = vehicleTx.size;

    if (driverCount === 0 && vehicleCount === 0) return null;

    const bulanId = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    const dObj = new Date(deliveryDate + 'T00:00:00');
    const tglFormatted = dObj.getDate() + ' ' + bulanId[dObj.getMonth()] + ' ' + dObj.getFullYear();

    let msg = `⚠️ Konfirmasi Jadwal Armada — ${tglFormatted}`;

    // 2. Tampilkan Data Driver
    if (driverCount > 0) {
        msg += `\n\n👤 Driver "${driver}" sudah ${driverCount} jadwal hari ini:`;
        const seen = new Set();
        driverRows.sort((a, b) => String(a.out_time).localeCompare(String(b.out_time))).forEach(r => {
            const key = r.order_id || `so-${r.id}`;
            if (seen.has(key)) return; // Biar gak dobel kalau 1 order ada banyak material
            seen.add(key);
            const jam = r.out_time ? String(r.out_time).substring(0, 5) : '--:--';
            msg += `\n   • ${jam} → ${cleanDest(r.destination)} (${r.vehicle_number || 'no plat'})`;
        });
        msg += `\n   → Jadwal baru ini akan jadi JADWAL KE-${driverCount + 1}`;
    }

    // 3. Tampilkan Data Kendaraan (Sekaligus perbaiki teks Maps yang panjang)
    if (vehicleCount > 0) {
        msg += `\n\n🚛 Kendaraan "${vehicle}" sudah ${vehicleCount} jadwal hari ini:`;
        const seen = new Set();
        vehicleRows.sort((a, b) => String(a.out_time).localeCompare(String(b.out_time))).forEach(r => {
            const key = r.order_id || `so-${r.id}`;
            if (seen.has(key)) return;
            seen.add(key);
            const jam = r.out_time ? String(r.out_time).substring(0, 5) : '--:--';
            msg += `\n   • ${jam} → ${cleanDest(r.destination)} (driver: ${r.driver_name || '-'})`; // <-- INI DIBERSIHKAN
        });
        msg += `\n   → Jadwal baru ini akan jadi JADWAL KE-${vehicleCount + 1} untuk kendaraan ini`;
    }

    // 4. Hitung Jarak Waktu
    if (newOutTime && driverRows.length > 0) {
        const times = driverRows.map(s => String(s.out_time).substring(0, 5)).sort();
        const lastTime = times[times.length - 1];
        
        const [lh, lm] = lastTime.split(':').map(Number);
        const [nh, nm] = newOutTime.split(':').map(Number);
        const gapMin = (nh * 60 + nm) - (lh * 60 + lm);

        if (gapMin > 0) {
            const h = Math.floor(gapMin / 60);
            const m = gapMin % 60;
            const gapText = (h > 0 ? h + ' jam ' : '') + m + ' menit';
            const gapIcon = gapMin < 60 ? '🔴' : (gapMin < 120 ? '🟡' : '🟢');
            msg += `\n\n${gapIcon} Jarak dari jadwal terakhir driver (Jam ${lastTime}): ${gapText}`;
            if (gapMin < 60) msg += `\n   (Perhatian: waktu istirahat driver pendek)`;
        } else if (gapMin < 0) {
            msg += `\n\n⚠️ Jam berangkat LEBIH AWAL dari jadwal terakhir driver (Jam ${lastTime})!`;
        }
    }

    if (driverCount >= 3) {
        msg += `\n\n⚠️ Driver "${driver}" sudah ${driverCount} jadwal — di atas rata-rata operasional. Pastikan istirahat cukup.`;
    }

    msg += `\n\n• [OK] Lanjut simpan\n• [Cancel] Ganti armada`;
    return msg;
}

document.querySelector('form[method="POST"]').addEventListener('submit', function(e) {
    const driverEl = document.querySelector('[name="driver_name"]');
    const vehicleEl = document.querySelector('[name="vehicle_number"]');
    const dateEl = document.querySelector('[name="out_date"]');
    const timeEl = document.querySelector('[name="out_time"]');

    const driver = driverEl ? driverEl.value.trim() : '';
    const vehicle = vehicleEl ? vehicleEl.value.trim() : '';
    const deliveryDate = dateEl ? dateEl.value : '';
    const newTime = timeEl ? timeEl.value : '';

    if ((driver || vehicle) && deliveryDate) {
        const warning = analyzeArmadaConflict(driver, vehicle, deliveryDate, newTime);
        if (warning && !confirm(warning)) {
            e.preventDefault();
            return false;
        }
    }
});
</script>
<?php include __DIR__.'/../../partials/footer.php'; ?>