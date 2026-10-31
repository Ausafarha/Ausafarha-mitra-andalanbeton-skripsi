<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/app.php';
initSession(); requireLogin();

$pageTitle = 'Tambah Pesanan Baru ';
$errors = [];
$user = currentUser();

// Ambil daftar material aktif bertipe product
$materials = Database::fetchAll("SELECT id, name, unit, price, current_stock FROM materials WHERE type = 'product' AND is_active = true ORDER BY name");

// Ambil daftar supir & kendaraan yang aktif untuk dropdown anti-nabrak
$drivers = Database::fetchAll("SELECT name FROM drivers WHERE is_active = true ORDER BY name ASC");
$vehicles = Database::fetchAll("SELECT plate_number, vehicle_type FROM vehicles WHERE is_active = true ORDER BY plate_number ASC");

$data = [
    'customer_name'    => '',
    'customer_phone'   => '',
    'customer_email'   => '',
    'delivery_address' => '',
    'delivery_date'    => date('Y-m-d'), // Tambahan tanggal pengiriman
    'driver_name'      => '',
    'vehicle_number'   => '',
    'notes'            => '',
    'status'           => 'completed', 
    'items'            => []
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Token tidak valid.';
    } else {
        $data['customer_name']    = post('customer_name');
        $data['customer_phone']   = post('customer_phone');
        $data['customer_email']   = post('customer_email') ?: null;
        $data['delivery_address'] = post('delivery_address');
        $data['delivery_date']    = post('delivery_date') ?: date('Y-m-d');
        $data['notes']            = post('notes') ?: null;
        $data['status']           = post('status') ?: 'completed';
        $data['driver_name']      = post('driver_name') ?: null;
        $data['vehicle_number']   = post('vehicle_number') ?: null;
        
        $materialIds = $_POST['material_ids'] ?? [];
        $quantities  = $_POST['quantities'] ?? [];
        $prices      = $_POST['prices'] ?? [];

        if (empty($data['customer_name']))  $errors[] = 'Nama pelanggan wajib diisi.';
        if (empty($data['customer_phone'])) $errors[] = 'Nomor HP pelanggan wajib diisi.';
        if (empty($data['delivery_address'])) $errors[] = 'Alamat pengiriman wajib diisi.';
        if (empty($data['delivery_date']))  $errors[] = 'Tanggal pengiriman wajib diisi.';
        if (empty($materialIds))            $errors[] = 'Pilih minimal satu item material.';
        
        if (in_array($data['status'], ['completed', 'processing'])) {
             if (empty($data['driver_name']))    $errors[] = 'Pilih driver pengiriman saat pesanan diproses/selesai.';
             if (empty($data['vehicle_number'])) $errors[] = 'Pilih kendaraan pengiriman saat pesanan diproses/selesai.';
        }

$data['delivery_time'] = post('delivery_time') ?: date('H:i');

        // Peringatan Pintar (Soft Warning) untuk Driver & Kendaraan di Tanggal yang Sama
        $warningMessages = [];
        if (in_array($data['status'], ['completed', 'processing']) && !empty($data['driver_name'])) {
            $activeDriver = Database::fetchOne("
                SELECT so.id FROM stock_out so
                LEFT JOIN orders o ON so.order_id = o.id
                WHERE so.driver_name = ? AND (COALESCE(o.delivery_date, so.out_date)) = ?
                LIMIT 1
            ", [$data['driver_name'], $data['delivery_date']]);

            if ($activeDriver) {
                $warningMessages[] = "Driver '{$data['driver_name']}' sudah memiliki jadwal pengiriman lain pada tanggal {$data['delivery_date']}. (Ritase bolak-balik / pastikan waktu aman).";
            }
        }

        if (in_array($data['status'], ['completed', 'processing']) && !empty($data['vehicle_number'])) {
            $activeVehicle = Database::fetchOne("
                SELECT so.id FROM stock_out so
                LEFT JOIN orders o ON so.order_id = o.id
                WHERE so.vehicle_number = ? AND (COALESCE(o.delivery_date, so.out_date)) = ?
                LIMIT 1
            ", [$data['vehicle_number'], $data['delivery_date']]);

            if ($activeVehicle) {
                $warningMessages[] = "Kendaraan '{$data['vehicle_number']}' sudah tercatat ditugaskan pada tanggal {$data['delivery_date']}.";
            }
        }


        // Validasi item & stok
        $orderItems = [];
        $totalAmount = 0;

        foreach ($materialIds as $idx => $mId) {
            $mId   = (int)$mId;
            $qty   = (int)($quantities[$idx] ?? 0);
            $price = (float)($prices[$idx] ?? 0);

            if ($mId > 0 && $qty > 0) {
                $mat = Database::fetchOne("SELECT name, current_stock FROM materials WHERE id = ?", [$mId]);
                if ($mat && $qty > $mat['current_stock']) {
                    $errors[] = "Stok {$mat['name']} tidak mencukupi. Tersedia: {$mat['current_stock']}";
                }

                $subtotal = $qty * $price;
                $totalAmount += $subtotal;
                $orderItems[] = [
                    'material_id'    => $mId,
                    'quantity'       => $qty,
                    'price_per_unit' => $price,
                    'subtotal'       => $subtotal
                ];
            }
        }

        if (empty($orderItems) && empty($errors)) {
            $errors[] = 'Jumlah barang harus lebih dari 0.';
        }
        if (!empty($warningMessages)) {
            setFlash('warning', '⚠️ Perhatian: ' . implode(' | ', $warningMessages));
        }
        if (empty($errors)) {
            $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

            // Simpan ke tabel orders
            $orderId = Database::insert('orders', [
                'order_number'     => $orderNumber,
                'customer_name'    => $data['customer_name'],
                'customer_phone'   => $data['customer_phone'],
                'customer_email'   => $data['customer_email'],
                'delivery_address' => $data['delivery_address'],
                'delivery_date'    => $data['delivery_date'],
                'delivery_time'    => $data['delivery_time'],
                'notes'            => $data['notes'],
                'status'           => $data['status'],
                'total_amount'     => $totalAmount,
                'processed_by'     => $user['id'],
                'processed_at'     => date('Y-m-d H:i:s')
            ]);

            foreach ($orderItems as $item) {
                Database::insert('order_items', [
                    'order_id'       => $orderId,
                    'material_id'    => $item['material_id'],
                    'quantity'       => $item['quantity'],
                    'price_per_unit' => $item['price_per_unit'],
                    'subtotal'       => $item['subtotal']
                ]);

                if (in_array($data['status'], ['completed', 'processing'])) {
                    Database::query("UPDATE materials SET current_stock = current_stock - ? WHERE id = ?", [$item['quantity'], $item['material_id']]);

                   Database::insert('stock_out', [
                     'material_id'   => $item['material_id'],
                     'order_id'      => $orderId,
                     'quantity'      => $item['quantity'],
                     'destination'   => $data['delivery_address'],
                     'customer_name' => $data['customer_name'],
                     'driver_name'   => $data['driver_name'],
                     'vehicle_number'=> $data['vehicle_number'],
                     'notes'         => "Otomatis dari Pesanan Manual #{$orderNumber}",
                     'processed_by'  => $user['id'],
                     'out_date'      => $data['delivery_date'],
                     'out_time'      => $data['delivery_time']
                    ]);
                }
            }

            logActivity('create', 'orders', "Membuat pesanan manual: {$orderNumber}");
            setFlash('success', 'Pesanan baru berhasil dibuat. Silakan isi ETA lalu kirim ke customer via WA.');
            redirect(APP_URL . '/admin-ab/modules/orders/view.php?id=' . $orderId);
        }
    }
}
// Ambil data jadwal aktif untuk validasi bentrok di Frontend JS
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

include __DIR__ . '/../../partials/head.php';
?>
<!-- Leaflet Maps CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="admin-wrapper">
<?php include __DIR__ . '/../../partials/sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__ . '/../../partials/topbar.php'; ?>
<div class="page-body">

<div class="mb-20">
  <a href="<?= APP_URL ?>/admin-ab/modules/orders/index.php" class="btn btn-ghost btn-sm" style="margin-bottom:8px;"><i class="fas fa-arrow-left"></i> Kembali</a>
  <h2 style="font-size:20px;font-weight:800;">Buat Pesanan Baru</h2>
</div>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<form method="POST" id="form-order">
  <?= csrfField() ?>
  <div class="grid" style="grid-template-columns:2fr 1fr;gap:20px;">
    
    <div>
      <div class="card mb-20">
        <div class="card-header"><div class="card-title">Informasi Pelanggan & Pengiriman</div></div>
        <div class="card-body">
          <div class="grid grid-2">
            <div class="form-group">
              <label class="form-label">Nama Pelanggan <span>*</span></label>
              <input type="text" name="customer_name" class="form-control" value="<?= htmlspecialchars($data['customer_name']) ?>" required placeholder="Contoh: Budi Santoso">
            </div>
            <div class="form-group">
              <label class="form-label">Nomor WhatsApp/HP <span>*</span></label>
              <input type="text" name="customer_phone" class="form-control" value="<?= htmlspecialchars($data['customer_phone']) ?>" required placeholder="08123456789">
            </div>
          </div>
          <div class="grid grid-2">
            <div class="form-group">
              <label class="form-label">Email (Opsional)</label>
              <input type="email" name="customer_email" class="form-control" value="<?= htmlspecialchars($data['customer_email'] ?? '') ?>" placeholder="pelanggan@email.com">
            </div>
            <div class="grid grid-2">
              <div class="form-group">
                <label class="form-label">Tanggal Pengiriman <span>*</span></label>
                <input type="date" name="delivery_date" class="form-control" value="<?= htmlspecialchars($data['delivery_date']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label">Jam Pengiriman </label>
                <input type="time" name="delivery_time" class="form-control" value="<?= htmlspecialchars($data['delivery_time'] ?? date('H:i')) ?>" required>
              </div>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Alamat Pengiriman / Proyek <span>*</span></label>
            <textarea name="delivery_address" id="admin_delivery_address" class="form-control" rows="2" required placeholder="Alamat lengkap tujuan pengiriman..."><?= htmlspecialchars($data['delivery_address']) ?></textarea>
          </div>
          <div class="form-group">
            <label class="form-label"><i class="fas fa-map-marker-alt" style="color:#ef4444;"></i> Tandai Titik Pengiriman (Klik Peta)</label>
            <div id="admin-map" style="height:220px; border-radius:8px; border:1px solid var(--border);"></div>
          </div>
          <div class="form-group">
            <label class="form-label">Catatan Pesanan</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Catatan tambahan..."><?= htmlspecialchars($data['notes'] ?? '') ?></textarea>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header flex-between">
          <div class="card-title">Item Material</div>
          <button type="button" class="btn btn-sm btn-secondary" id="add-item-btn"><i class="fas fa-plus"></i> Tambah Item</button>
        </div>
        <div class="card-body" style="padding:0;">
          <div class="table-responsive">
            <table class="table" id="items-table">
              <thead>
                <tr>
                  <th>Material</th>
                  <th style="width:120px;">Jumlah</th>
                  <th style="width:160px;">Harga Satuan</th>
                  <th style="width:160px;">Subtotal</th>
                  <th style="width:50px;"></th>
                </tr>
              </thead>
              <tbody id="items-container">
                <!-- Row item via JS -->
              </tbody>
            </table>
          </div>
          <div style="padding:16px 20px;text-align:right;border-top:1px solid var(--border);">
            <span style="font-size:14px;color:var(--text-muted);">Grand Total: </span>
            <strong style="font-size:20px;color:var(--brand-600);" id="grand-total">Rp 0</strong>
          </div>
        </div>
      </div>
    </div>

    <div>
      <div class="card mb-20">
        <div class="card-header"><div class="card-title">Pengaturan Pesanan & Armada</div></div>
        <div class="card-body">
          <div class="form-group mb-16">
            <label class="form-label">Status Pesanan</label>
            <select name="status" class="form-control">
              <option value="completed" selected>✅ Selesai (Langsung Potong Stok)</option>
              <option value="processing">🔄 Diproses</option>
              <option value="pending">⏳ Menunggu Pembayaran</option>
            </select>
          </div>

          <div style="padding:12px;background:var(--bg-muted);border-radius:8px;margin-bottom:16px;border:1px solid var(--border);">
            <div style="font-size:12.5px;font-weight:700;margin-bottom:8px;color:var(--brand-600);"><i class="fas fa-truck"></i> Penugasan Armada</div>
            
            <div class="form-group mb-10">
              <label class="form-label" style="font-size:12px;">Pilih Driver <span>*</span></label>
              <select name="driver_name" class="form-control">
                <option value="">-- Pilih Supir --</option>
                <?php foreach ($drivers as $d): ?>
                  <option value="<?= htmlspecialchars($d['name']) ?>" <?= ($data['driver_name'] ?? '') === $d['name'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group mb-0">
              <label class="form-label" style="font-size:12px;">Pilih Kendaraan (Plat Nomor) <span>*</span></label>
              <select name="vehicle_number" class="form-control text-mono">
                <option value="">-- Pilih Kendaraan --</option>
                <?php foreach ($vehicles as $v): ?>
                  <option value="<?= htmlspecialchars($v['plate_number']) ?>" <?= ($data['vehicle_number'] ?? '') === $v['plate_number'] ? 'selected' : '' ?>><?= htmlspecialchars($v['plate_number']) ?> (<?= htmlspecialchars($v['vehicle_type']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Simpan Pesanan</button>
        </div>
      </div>
    </div>

  </div>
</form>

</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
const materialsData = <?= json_encode($materials) ?>;
const activeSchedules = <?= json_encode($activeSchedules, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function formatRupiah(number) {
    return 'Rp ' + Number(number).toLocaleString('id-ID');
}

function createRow() {
    const tr = document.createElement('tr');
    let optionsHtml = '<option value="">-- Pilih Material --</option>';
    materialsData.forEach(m => {
        optionsHtml += `<option value="${m.id}" data-price="${m.price}" data-stock="${m.current_stock}" data-unit="${m.unit}">${m.name} (Stok: ${m.current_stock} ${m.unit})</option>`;
    });

    tr.innerHTML = `
        <td>
            <select name="material_ids[]" class="form-control mat-select" required>
                ${optionsHtml}
            </select>
        </td>
        <td>
            <input type="number" name="quantities[]" class="form-control mat-qty" min="1" value="1" required>
        </td>
        <td>
            <input type="number" name="prices[]" class="form-control mat-price" min="0" value="0" required>
        </td>
        <td>
            <span class="mat-subtotal" style="font-weight:700;line-height:36px;">Rp 0</span>
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-danger remove-row"><i class="fas fa-trash"></i></button>
        </td>
    `;

    document.getElementById('items-container').appendChild(tr);

    const select = tr.querySelector('.mat-select');
    const qtyInput = tr.querySelector('.mat-qty');
    const priceInput = tr.querySelector('.mat-price');
    const removeBtn = tr.querySelector('.remove-row');

    select.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.value) {
            priceInput.value = selectedOption.getAttribute('data-price') || 0;
        } else {
            priceInput.value = 0;
        }
        calculateTotals();
    });

    qtyInput.addEventListener('input', calculateTotals);
    priceInput.addEventListener('input', calculateTotals);
    removeBtn.addEventListener('click', function() {
        tr.remove();
        calculateTotals();
    });
}

function calculateTotals() {
    let grandTotal = 0;
    const rows = document.querySelectorAll('#items-container tr');
    rows.forEach(row => {
        const qty = parseFloat(row.querySelector('.mat-qty').value) || 0;
        const price = parseFloat(row.querySelector('.mat-price').value) || 0;
        const subtotal = qty * price;
        row.querySelector('.mat-subtotal').textContent = formatRupiah(subtotal);
        grandTotal += subtotal;
    });
    document.getElementById('grand-total').textContent = formatRupiah(grandTotal);
}

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
// ============================================================
// EVENT LISTENER (MAPS & SUBMIT)
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    
    // 1. Inisialisasi Peta Leaflet (Hanya SATU KALI)
    const mapAdmin = L.map('admin-map').setView([-7.24583, 108.98123], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19
    }).addTo(mapAdmin);
    let adminMarker;

    mapAdmin.on('click', function(e) {
        const lat = e.latlng.lat.toFixed(6);
        const lng = e.latlng.lng.toFixed(6);
        if (adminMarker) adminMarker.setLatLng(e.latlng);
        else adminMarker = L.marker(e.latlng).addTo(mapAdmin);

        const txt = document.getElementById('admin_delivery_address');
        const mapUrl = `https://maps.google.com/?q=${lat},${lng}`;
        let cleanText = txt.value.replace(/\n\n📍 Titik Lokasi Maps: https:\/\/maps\.google\.com\/\?q=[^\s]+/g, '').trim();
        txt.value = cleanText + (cleanText ? '\n\n' : '') + '📍 Titik Lokasi Maps: ' + mapUrl;
    });

    // 2. Tambah Baris Pertama
    document.getElementById('add-item-btn').addEventListener('click', createRow);
    createRow();

    // 3. Validasi Submit Form
    document.getElementById('form-order')?.addEventListener('submit', function(e) {
        const statusEl = document.querySelector('[name="status"]');
        if (statusEl && !['processing', 'completed'].includes(statusEl.value)) return true;

        const driverEl = document.querySelector('[name="driver_name"]');
        const vehicleEl = document.querySelector('[name="vehicle_number"]');
        const dateEl = document.querySelector('[name="delivery_date"]');
        const timeEl = document.querySelector('[name="delivery_time"]');

        const driver = driverEl ? driverEl.value.trim() : '';
        const vehicle = vehicleEl ? vehicleEl.value.trim() : '';
        const deliveryDate = dateEl ? dateEl.value : '';
        const newTime = timeEl ? timeEl.value : '';

        if ((driver || vehicle) && deliveryDate) {
            const warning = analyzeArmadaConflict(driver, vehicle, deliveryDate, newTime);
            if (warning && !confirm(warning)) { e.preventDefault(); return false; }
        }
    });
});
</script>