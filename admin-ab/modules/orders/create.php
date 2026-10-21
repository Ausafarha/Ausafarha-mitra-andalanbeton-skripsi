<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/app.php';
initSession(); requireLogin();

$pageTitle = 'Tambah Pesanan Baru ';
$errors = [];
$user = currentUser();

// Ambil daftar material aktif bertipe product
$materials = Database::fetchAll("SELECT id, name, unit, price, current_stock FROM materials WHERE type = 'product' AND is_active = true ORDER BY name");

$data = [
    'customer_name'    => '',
    'customer_phone'   => '',
    'customer_email'   => '',
    'delivery_address' => '',
    'driver_name'      => '',
    'vehicle_number'   => '',
    'notes'            => '',
    'status'           => 'completed', // Default langsung completed agar otomatis memotong stok & masuk barang keluar
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
        if (empty($materialIds))            $errors[] = 'Pilih minimal satu item material.';
        if (in_array($data['status'], ['completed', 'processing'])) {
         if (empty($data['driver_name']))    $errors[] = 'Nama driver wajib diisi saat pesanan diproses/selesai.';
         if (empty($data['vehicle_number'])) $errors[] = 'Nomor kendaraan wajib diisi saat pesanan diproses/selesai.';
     }
        // Validasi Active Trip Warning (Peringatan Bentrok Armada)
        if (in_array($data['status'], ['completed', 'processing']) && !empty($data['driver_name'])) {
            $todayDate = date('Y-m-d');
            
            // Cek apakah driver sudah bertugas hari ini
            $activeDriver = Database::fetchOne("
                SELECT id FROM stock_out 
                WHERE driver_name = ? AND out_date = ? 
                LIMIT 1
            ", [$data['driver_name'], $todayDate]);

            if ($activeDriver) {
                $errors[] = "⚠️ Peringatan Bentrok: Driver '{$data['driver_name']}' sudah tercatat aktif melakukan pengiriman tugas lain pada hari ini.";
            }
        }

        if (in_array($data['status'], ['completed', 'processing']) && !empty($data['vehicle_number'])) {
            $todayDate = date('Y-m-d');
            
            // Cek apakah kendaraan sudah bertugas hari ini
            $activeVehicle = Database::fetchOne("
                SELECT id FROM stock_out 
                WHERE vehicle_number = ? AND out_date = ? 
                LIMIT 1
            ", [$data['vehicle_number'], $todayDate]);

            if ($activeVehicle) {
                $errors[] = "⚠️ Peringatan Bentrok: Kendaraan bernomor '{$data['vehicle_number']}' sudah ditugaskan untuk pengiriman lain pada hari ini.";
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
                // Cek stok material
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

        if (empty($errors)) {
            // Generate Order Number unik (Contoh: ORD-20260815-XXXX)
            $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

            // 1. Simpan ke tabel orders
            $orderId = Database::insert('orders', [
                'order_number'     => $orderNumber,
                'customer_name'    => $data['customer_name'],
                'customer_phone'   => $data['customer_phone'],
                'customer_email'   => $data['customer_email'],
                'delivery_address' => $data['delivery_address'],
                'notes'            => $data['notes'],
                'status'           => $data['status'],
                'total_amount'     => $totalAmount,
                'processed_by'     => $user['id'],
                'processed_at'     => date('Y-m-d H:i:s')
            ]);

            // 2. Simpan item ke order_items & otomatisasi stok/barang keluar jika status completed/processing
            foreach ($orderItems as $item) {
                Database::insert('order_items', [
                    'order_id'       => $orderId,
                    'material_id'    => $item['material_id'],
                    'quantity'       => $item['quantity'],
                    'price_per_unit' => $item['price_per_unit'],
                    'subtotal'       => $item['subtotal']
                ]);

                // Jika status completed atau processing, langsung potong stok & catat barang keluar
                if (in_array($data['status'], ['completed', 'processing'])) {
                    // Update Stok
                    Database::query("UPDATE materials SET current_stock = current_stock - ? WHERE id = ?", [$item['quantity'], $item['material_id']]);

                    // Insert Logistik Barang Keluar
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
                     'out_date'      => date('Y-m-d')
                    ]);
                }
            }

            logActivity('create', 'orders', "Membuat pesanan manual: {$orderNumber}");
            
            // Pesan flash dinamis berdasarkan status awal
            if (in_array($data['status'], ['completed', 'processing'])) {
                setFlash('success', 'Pesanan baru berhasil dibuat dan stok barang otomatis terpotong.');
            } else {
                setFlash('success', 'Pesanan baru berhasil dibuat dengan status Menunggu Pembayaran. Stok belum terpotong.');
            }
            
            redirect(APP_URL . '/admin-ab/modules/orders/index.php');
        }
    }
}

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
        <div class="card-header"><div class="card-title">Informasi Pelanggan</div></div>
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
          <div class="form-group">
            <label class="form-label">Email (Opsional)</label>
            <input type="email" name="customer_email" class="form-control" value="<?= htmlspecialchars($data['customer_email'] ?? '') ?>" placeholder="pelanggan@email.com">
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
                <!-- Row item akan ditambahkan via JavaScript -->
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
        <div class="card-header"><div class="card-title">Pengaturan Pesanan & Pengiriman</div></div>
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
            <div style="font-size:12.5px;font-weight:700;margin-bottom:8px;color:var(--brand-600);"><i class="fas fa-truck"></i> Informasi Pengemudi</div>
            <div class="form-group mb-10">
              <label class="form-label" style="font-size:12px;">Nama Driver <span>*</span></label>
              <input type="text" name="driver_name" class="form-control" value="<?= htmlspecialchars($data['driver_name']??'') ?>" placeholder="Nama pengemudi">
            </div>
            <div class="form-group mb-0">
              <label class="form-label" style="font-size:12px;">Nomor Kendaraan (PLAT) <span>*</span></label>
              <input type="text" name="vehicle_number" class="form-control text-mono" value="<?= htmlspecialchars($data['vehicle_number']??'') ?>" placeholder="B 1234 CD">
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

document.getElementById('add-item-btn').addEventListener('click', createRow);

// Tambah row pertama saat halaman dimuat
createRow();
document.addEventListener('DOMContentLoaded', function() {
    const mapAdmin = L.map('admin-map').setView([-7.24583, 108.98123], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(mapAdmin);
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
});
</script>