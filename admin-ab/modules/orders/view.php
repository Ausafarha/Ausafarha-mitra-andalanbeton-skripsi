<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/app.php';
initSession(); requireLogin();

function renderAddressWithMap($address) {
    if (empty($address)) return '-';
    // Hanya ubah tautan jika berformat Google Maps (?q=lat,lng)
    $pattern = '/(https?:\/\/maps\.google\.com\/\?q=[-?\d\.\,]+)/i';
    $replacement = '<br><a href="$1" target="_blank" class="btn btn-sm btn-outline" style="margin-top:6px;display:inline-flex;align-items:center;gap:6px;color:#2563eb;border-color:#2563eb;"><i class="fas fa-hand-pointer"></i> Klik untuk Buka Lokasi di Google Maps</a>';
    return preg_replace($pattern, $replacement, htmlspecialchars($address, ENT_QUOTES, 'UTF-8'));
}

$id  = getInt('id');
$ord = Database::fetchOne("SELECT o.*, u.name AS processed_by_name FROM orders o LEFT JOIN users u ON o.processed_by=u.id WHERE o.id=?",[$id]);
if (!$ord){setFlash('error','Pesanan tidak ditemukan.');redirect(APP_URL.'/admin-ab/modules/orders/index.php');}
$items = Database::fetchAll("SELECT oi.*, m.name AS material_name, m.unit FROM order_items oi JOIN materials m ON oi.material_id=m.id WHERE oi.order_id=?",[$id]);
$pageTitle='Detail Pesanan #'.$ord['order_number'];

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_status'])) {
    if (!verifyCsrf()){setFlash('error','Token tidak valid.');}
    else {
        $newStatus     = post('status');
        $adminNotes    = post('admin_notes');
        $driverName    = post('driver_name');
        $vehicleNumber = post('vehicle_number');
        $allowedStatuses = ['pending','processing','completed','rejected'];

        if (in_array($newStatus,$allowedStatuses)) {
            $user=currentUser();
            $oldStatus = $ord['status'];

            // Peringatan Soft Warning jika Driver/Kendaraan sudah ada tugas lain di tanggal yang sama
            if (in_array($newStatus, ['processing', 'completed'])) {
                $warningMsgs = [];
                $deliveryDate = $ord['delivery_date'] ?: date('Y-m-d');

                $checkDriver = Database::fetchOne("
                    SELECT so.id FROM stock_out so
                    LEFT JOIN orders o ON so.order_id = o.id
                    WHERE so.driver_name = ? AND (COALESCE(o.delivery_date, so.out_date)) = ? AND so.order_id != ? 
                    LIMIT 1
                ", [$driverName, $deliveryDate, $id]);

                if ($checkDriver) {
                    $warningMsgs[] = "Driver '{$driverName}' sudah memiliki jadwal pengiriman lain pada tanggal {$deliveryDate}.";
                }

                $checkVehicle = Database::fetchOne("
                    SELECT so.id FROM stock_out so
                    LEFT JOIN orders o ON so.order_id = o.id
                    WHERE so.vehicle_number = ? AND (COALESCE(o.delivery_date, so.out_date)) = ? AND so.order_id != ? 
                    LIMIT 1
                ", [$vehicleNumber, $deliveryDate, $id]);

                if ($checkVehicle) {
                    $warningMsgs[] = "Kendaraan '{$vehicleNumber}' sudah tercatat ditugaskan pada tanggal {$deliveryDate}.";
                }

                if (!empty($warningMsgs)) {
                    setFlash('warning', '⚠️ Perhatian: ' . implode(' | ', $warningMsgs) . ' (Pastikan estimasi waktu ritase aman).');
                }
            }

            // 1. KONDISI POTONG STOK & CATAT BARANG KELUAR (Support Ganti Supir/Kendaraan)
            if (in_array($newStatus, ['processing', 'completed'])) {
                
                // Validasi input Driver & Plat Nomor jika pesanan mau diproses/dikirim
                if (empty($driverName) || empty($vehicleNumber)) {
                    setFlash('error', 'Nama Driver dan Nomor Kendaraan wajib diisi saat memproses pengiriman pesanan.');
                    redirect(APP_URL.'/admin-ab/modules/orders/view.php?id='.$id);
                    exit;
                }

                $orderItems = Database::fetchAll("SELECT * FROM order_items WHERE order_id = ?", [$id]);
                
                // Jika sebelumnya sudah pernah dipotong (misal mau ganti supir), 
                // kembalikan stok lama terlebih dahulu & hapus log stock_out lama agar bersih dari data supir lama
                $alreadyProcessed = in_array($oldStatus, ['processing', 'completed']);
                if ($alreadyProcessed) {
                    foreach ($orderItems as $item) {
                        Database::query("UPDATE materials SET current_stock = current_stock + ? WHERE id = ?", [$item['quantity'], $item['material_id']]);
                    }
                    Database::delete('stock_out', 'order_id = ?', [$id]);
                }

                // Validasi Stok dulu sebelum dipotong ulang
                $hasError = false;
                foreach ($orderItems as $item) {
                    $stock = (int)Database::fetchColumn("SELECT current_stock FROM materials WHERE id = ?", [$item['material_id']]);
                    if ($stock < $item['quantity']) {
                        $matName = Database::fetchColumn("SELECT name FROM materials WHERE id = ?", [$item['material_id']]);
                        setFlash('error', "Stok {$matName} tidak mencukupi! Tersedia: {$stock}, Dibutuhkan: {$item['quantity']}");
                        $hasError = true;
                        break;
                    }
                }

                if ($hasError) {
                    redirect(APP_URL.'/admin-ab/modules/orders/view.php?id='.$id);
                    exit;
                }

                // Potong stok dan insert baru ke stock_out dengan data supir/kendaraan terbaru yang dipilih admin
                foreach ($orderItems as $item) {
                    Database::query("UPDATE materials SET current_stock = current_stock - ? WHERE id = ?", [$item['quantity'], $item['material_id']]);

                    Database::insert('stock_out', [
                        'material_id'   => $item['material_id'],
                        'order_id'      => $id,
                        'quantity'      => $item['quantity'],
                        'destination'   => $ord['delivery_address'] ?: ("Pengiriman pesanan " . $ord['order_number']),
                        'customer_name' => $ord['customer_name'],
                        'driver_name'   => $driverName,
                        'vehicle_number'=> $vehicleNumber,
                        'notes'         => "Otomatis dari pesanan #{$ord['order_number']}",
                        'processed_by'  => $user['id'],
                        'out_date'      => $ord['delivery_date'] ?: date('Y-m-d'),
                        'out_time'      => $ord['delivery_time'] ?: date('H:i')
                    ]);
                }
            }
            // 2. KONDISI KEMBALIKAN STOK (ROLLBACK): Jika sebelumnya sudah dipotong, lalu diubah ke 'rejected' atau 'pending'
            else if (in_array($oldStatus, ['processing', 'completed']) && in_array($newStatus, ['pending', 'rejected'])) {
                $orderItems = Database::fetchAll("SELECT * FROM order_items WHERE order_id = ?", [$id]);
                
                foreach ($orderItems as $item) {
                    // Kembalikan stok ke master materials
                    Database::query("UPDATE materials SET current_stock = current_stock + ? WHERE id = ?", [$item['quantity'], $item['material_id']]);
                }

                // Hapus catatan otomatis di stock_out berdasarkan order_id ini
                Database::delete('stock_out', 'order_id = ?', [$id]);
            }
            
            // Update status pesanan
            $etaRaw   = post('estimated_finish_time');
            $etaValue = $etaRaw ? str_replace('T', ' ', $etaRaw) : null;

            Database::update('orders',[
                'status'=>$newStatus,
                'admin_notes'=>$adminNotes?:null,
                'estimated_finish_time'=>$etaValue,
                'processed_by'=>$user['id'],
                'processed_at'=>$newStatus!=='pending'?date('Y-m-d H:i:s'):null
            ],'id=?',[$id]);
            
            logActivity('update','orders',"Mengubah status pesanan {$ord['order_number']} menjadi {$newStatus}");
            setFlash('success','Status pesanan berhasil diperbarui dan sinkronisasi stok serta driver berhasil dicatat.');
            redirect(APP_URL.'/admin-ab/modules/orders/view.php?id='.$id);
        }
    }
}
$activeSchedules = Database::fetchAll("
    SELECT so.id, so.order_id, so.driver_name, so.vehicle_number, 
           COALESCE(o.delivery_date, so.out_date) as delivery_date, 
           COALESCE(so.out_time, o.delivery_time, '00:00') as out_time, 
           COALESCE(o.status, 'manual') as order_status,
           COALESCE(NULLIF(so.destination,''), NULLIF(o.delivery_address,''), '-') as destination
    FROM stock_out so 
    LEFT JOIN orders o ON so.order_id = o.id
    WHERE (so.order_id IS NULL OR so.order_id != ?)
      AND (o.status IS NULL OR o.status != 'rejected')
      AND NOT (so.order_id IS NOT NULL AND o.id IS NULL)
", [$id]);
include __DIR__.'/../../partials/head.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<div class="admin-wrapper">
<?php include __DIR__.'/../../partials/sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__.'/../../partials/topbar.php'; ?>
<div class="page-body">

<div class="flex-between mb-20">
  <div>
    <a href="<?=APP_URL?>/admin-ab/modules/orders/index.php" class="btn btn-ghost btn-sm" style="margin-bottom:8px;"><i class="fas fa-arrow-left"></i> Kembali</a>
    <h2 style="font-size:20px;font-weight:800;">Pesanan <?=htmlspecialchars($ord['order_number'])?></h2>
  </div>
  <div style="display:flex;gap:10px;align-items:center;">
    <?php if(in_array($ord['status'], ['processing', 'completed'])): ?>
      <a href="<?=APP_URL?>/admin-ab/modules/stock_out/print_sj.php?type=order&id=<?=$ord['id']?>" target="_blank" class="btn btn-secondary"><i class="fas fa-print"></i> Cetak Surat Jalan</a>
    <?php endif; ?>
    <?=orderStatusLabel($ord['status'])?>
  </div>
</div>

<div class="grid" style="grid-template-columns:2fr 1fr;gap:20px;">
  <!-- Order Details -->
  <div>
    <div class="card mb-20">
      <div class="card-header"><div class="card-title"><i class="fas fa-user" style="margin-right:8px;"></i>Informasi Pemesan</div></div>
      <div class="card-body">
        <div class="grid grid-2" style="gap:16px;">
          <div><div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Nama Pemesan</div><div style="font-weight:700;"><?=htmlspecialchars($ord['customer_name'])?></div></div>
          <div><div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Nomor HP</div><div style="font-weight:600;"><a href="tel:<?=htmlspecialchars($ord['customer_phone'])?>"><?=htmlspecialchars($ord['customer_phone'])?></a></div></div>
          <?php if($ord['customer_email']):?><div><div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Email</div><div style="font-weight:600;"><?=htmlspecialchars($ord['customer_email'])?></div></div><?php endif;?>
          <div><div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Tanggal Pesan</div><div style="font-weight:600;"><?=formatDateTime($ord['created_at'])?></div></div>
          <div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Tanggal & Jam Pengiriman</div>
            <div style="font-weight:700;color:var(--brand-600);">
              <?= !empty($ord['delivery_date']) ? date('d M Y', strtotime($ord['delivery_date'])) : '-' ?>
              <?= !empty($ord['delivery_time']) ? ' Pukul ' . date('H:i', strtotime($ord['delivery_time'])) : ' (Jam belum diatur)' ?>
            </div>
          </div>
        </div>
        <div style="margin-top:16px;">
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Alamat Pengiriman</div>
          <div style="font-weight:600;line-height:1.5;"><?=nl2br(renderAddressWithMap($ord['delivery_address']))?></div>
        </div>

        <?php
        preg_match('/maps\.google\.com\/\?q=(-?\d+\.\d+),(-?\d+\.\d+)/', $ord['delivery_address'], $matches);
        $hasCoords = !empty($matches[1]) && !empty($matches[2]);
        ?>
        
        <?php if ($hasCoords): ?>
        <div style="margin-top:16px;">
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">Visual Lokasi Pengiriman</div>
          <div id="view-map" style="height:220px; border-radius:8px; border:1px solid var(--border);"></div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const lat = <?= $matches[1] ?>;
            const lng = <?= $matches[2] ?>;
            const viewMap = L.map('view-map').setView([lat, lng], 14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(viewMap);
            L.marker([lat, lng]).addTo(viewMap).bindPopup('Lokasi Pengiriman Pesanan').openPopup();
        });
        </script>
        <?php endif; ?>
        <?php if($ord['notes']):?><div style="margin-top:12px;padding:12px;background:var(--bg-muted);border-radius:8px;"><div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Catatan Pelanggan</div><div style="font-size:13.5px;"><?=nl2br(htmlspecialchars($ord['notes']))?></div></div><?php endif;?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-boxes" style="margin-right:8px;"></i>Item Pesanan</div></div>
      <div class="card-body" style="padding:0;">
        <?php if(empty($items)):?>
          <div class="empty-state" style="padding:30px;"><div class="empty-state-title">Tidak ada item</div></div>
        <?php else:?>
          <div class="table-responsive">
            <table class="table">
              <thead><tr><th>Material</th><th>Jumlah</th><th>Harga</th><th>Subtotal</th></tr></thead>
              <tbody>
                <?php foreach($items as $item):?>
                <tr>
                  <td style="font-weight:600;"><?=htmlspecialchars($item['material_name'])?></td>
                  <td><?=formatNumber($item['quantity'])?> <?=$item['unit']?></td>
                  <td><?=formatRupiah($item['price_per_unit'])?></td>
                  <td style="font-weight:700;"><?=formatRupiah($item['subtotal'])?></td>
                </tr>
                <?php endforeach;?>
              </tbody>
            </table>
          </div>
          <div style="padding:16px 20px;text-align:right;border-top:1px solid var(--border);">
            <span style="font-size:14px;color:var(--text-muted);">Total Pesanan: </span>
            <strong style="font-size:20px;color:var(--brand-600);"><?=formatRupiah($ord['total_amount'])?></strong>
          </div>
        <?php endif;?>
      </div>
    </div>
  </div>

  <!-- Status Update & Pengiriman -->
  <div>
    <div class="card mb-20">
      <div class="card-header"><div class="card-title">Perbarui Status & Pengiriman</div></div>
      <div class="card-body">
        <form method="POST">
          <?=csrfField()?>
          <input type="hidden" name="update_status" value="1">
          <div class="form-group">
            <label class="form-label">Status Pesanan</label>
            <select name="status" class="form-control" id="order-status-select">
              <?php foreach(['pending'=>'⏳ Menunggu','processing'=>'🔄 Diproses','completed'=>'✅ Selesai','rejected'=>'❌ Ditolak'] as $val=>$lbl):?>
                <option value="<?=$val?>" <?=$ord['status']===$val?'selected':''?>><?=$lbl?></option>
              <?php endforeach;?>
            </select>
          </div>

        <?php
          // Ambil daftar riwayat driver dan kendaraan unik yang pernah bertugas untuk autocomplete
          $activeDrivers = Database::fetchAll("SELECT name FROM drivers WHERE is_active = true ORDER BY name ASC");
          $activeVehicles = Database::fetchAll("SELECT plate_number FROM vehicles WHERE is_active = true ORDER BY plate_number ASC");
          
          // Ambil data stock_out yang terikat dengan pesanan ini (jika sudah ada)
          $currentLog = Database::fetchOne("SELECT driver_name, vehicle_number FROM stock_out WHERE order_id = ? LIMIT 1", [$id]);
          $savedDriver = $currentLog['driver_name'] ?? '';
          $savedVehicle = $currentLog['vehicle_number'] ?? '';

          // === Generate WA URL untuk kirim ETA ke customer ===
          $waUrl = '';
          if (!empty($ord['estimated_finish_time'])
              && !empty($ord['customer_phone'])
              && in_array($ord['status'], ['processing', 'completed'])) {
              $phone = preg_replace('/[^0-9]/', '', $ord['customer_phone']);
              if (str_starts_with($phone, '0')) {
                  $phone = '62' . substr($phone, 1);
              } elseif (str_starts_with($phone, '8')) {
                  $phone = '62' . $phone;
              }

              $etaDate    = formatDate(date('Y-m-d', strtotime($ord['estimated_finish_time'])));
              $etaTime    = date('H:i', strtotime($ord['estimated_finish_time']));
              $driverWa   = $savedDriver ?: 'Driver';
              $platWa     = $savedVehicle ?: '-';

              $waMsg = "Halo *{$ord['customer_name']}*,%0A%0A"
                     . "Pesanan *{$ord['order_number']}* sedang menuju lokasi Anda.%0A%0A"
                     . "🚚 Driver: *{$driverWa}*%0A"
                     . "🚛 Kendaraan: *{$platWa}*%0A"
                     . "⏰ Estimasi Tiba: *{$etaDate} jam {$etaTime}*%0A"
                     . "📍 Tujuan: {$ord['delivery_address']}%0A%0A"
                     . "Balas pesan ini kalau ada perubahan atau kendala.%0A"
                     . "Terima kasih 🙏";

              $waUrl = "https://wa.me/{$phone}?text={$waMsg}";
          }
          ?>

          <!-- Section Driver & Plat Nomor (Wajib diisi jika diproses/selesai) -->
          <div id="driver-info-section" style="padding:12px;background:var(--bg-muted);border-radius:8px;margin-bottom:16px;">
            <div style="font-size:12.5px;font-weight:700;margin-bottom:8px;color:var(--brand-600);"><i class="fas fa-truck"></i> Informasi Pengemudi</div>
            
            <div class="form-group mb-10">
              <label class="form-label" style="font-size:12px;">Pilih Driver <span>*</span></label>
              <select name="driver_name" class="form-control">
                <option value="">-- Pilih Supir --</option>
                <?php foreach($activeDrivers as $d): ?>
                  <option value="<?= htmlspecialchars($d['name']) ?>" <?= $savedDriver === $d['name'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group mb-0">
              <label class="form-label" style="font-size:12px;">Pilih Kendaraan (Plat Nomor) <span>*</span></label>
              <select name="vehicle_number" class="form-control text-mono">
                <option value="">-- Pilih Kendaraan --</option>
                <?php foreach($activeVehicles as $v): ?>
                  <option value="<?= htmlspecialchars($v['plate_number']) ?>" <?= $savedVehicle === $v['plate_number'] ? 'selected' : '' ?>><?= htmlspecialchars($v['plate_number']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Estimasi Tiba (ETA)</label>
            <input type="datetime-local" name="estimated_finish_time" class="form-control"
                   value="<?= !empty($ord['estimated_finish_time']) ? date('Y-m-d\TH:i', strtotime($ord['estimated_finish_time'])) : '' ?>">
            <small style="color:var(--text-muted);font-size:11.5px;display:block;margin-top:4px;">
              <i class="fas fa-info-circle"></i> Opsional — dipakai untuk info WA ke customer
            </small>
          </div>
          <div class="form-group">
            <label class="form-label">Catatan Admin</label>
            <textarea name="admin_notes" class="form-control" rows="3" placeholder="Catatan internal..."><?=htmlspecialchars($ord['admin_notes']??'')?></textarea>
          </div>
          <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Perbarui Status</button>
        </form>

        <?php if ($waUrl): ?>
        <div style="margin-top:16px;padding-top:16px;border-top:1px dashed var(--border);">
          <div style="font-size:12.5px;font-weight:600;color:var(--text-muted);margin-bottom:8px;">
            <i class="fas fa-paper-plane"></i> Setelah ETA tersimpan, kirim ke customer:
          </div>
          <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank"
             class="btn btn-lg w-100"
             style="background:#25d366;color:white;border-color:#25d366;font-weight:700;">
            <i class="fab fa-whatsapp"></i> Kirim ETA via WA ke Customer
          </a>
          <small style="color:var(--text-muted);font-size:11px;display:block;margin-top:6px;text-align:center;">
            Buka WA Web/App dengan pesan siap kirim
          </small>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">Riwayat</div></div>
      <div class="card-body">
        <div style="font-size:13px;color:var(--text-secondary);">
          <div style="margin-bottom:12px;"><strong>Dibuat:</strong><br><?=formatDateTime($ord['created_at'])?></div>
          <?php if($ord['processed_at']):?>
          <div style="margin-bottom:12px;"><strong>Diproses:</strong><br><?=formatDateTime($ord['processed_at'])?><br>oleh: <?=htmlspecialchars($ord['processed_by_name']??'-')?></div>
          <?php endif;?>
          <?php if($ord['admin_notes']):?>
          <div><strong>Catatan Admin:</strong><br><em><?=nl2br(htmlspecialchars($ord['admin_notes']))?></em></div>
          <?php endif;?>
        </div>
      </div>
    </div>
  </div>
</div>
</div>
<script>
const activeSchedules = <?= json_encode($activeSchedules) ?>;
const deliveryDateCurrent = "<?= $ord['delivery_date'] ?: date('Y-m-d') ?>";

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

document.addEventListener('DOMContentLoaded', function() {
    const updateForm = document.querySelector('form[method="POST"]');
    if (updateForm) {
        updateForm.addEventListener('submit', function(e) {
            const statusSelect = document.getElementById('order-status-select');
            if (statusSelect && ['processing', 'completed'].includes(statusSelect.value)) {
                const driverEl = document.querySelector('[name="driver_name"]');
                const vehicleEl = document.querySelector('[name="vehicle_number"]');
                const driver = driverEl ? driverEl.value.trim() : '';
                const vehicle = vehicleEl ? vehicleEl.value.trim() : '';

                if ((driver || vehicle) && deliveryDateCurrent) {
                    // view.php tidak punya input out_time, passing '' untuk gap calc
                    const warning = analyzeArmadaConflict(driver, vehicle, deliveryDateCurrent, '');
                    if (warning && !confirm(warning)) { e.preventDefault(); return false; }
                }
            }
        });
    }
});
</script>
<?php include __DIR__.'/../../partials/footer.php'; ?>