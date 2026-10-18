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
        $newStatus = post('status');
        $adminNotes= post('admin_notes');
        $allowedStatuses = ['pending','processing','completed','rejected'];
        if (in_array($newStatus,$allowedStatuses)) {
            $user=currentUser();
            $oldStatus = $ord['status'];

            // 1. KONDISI POTONG STOK: Dari status belum potong ke status potong (processing/completed)
            if (in_array($newStatus, ['processing', 'completed']) && !in_array($oldStatus, ['processing', 'completed'])) {
                $items = Database::fetchAll("SELECT * FROM order_items WHERE order_id = ?", [$id]);
                $hasError = false;

                // Validasi Stok dulu sebelum potong
                foreach ($items as $item) {
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

                // Potong stok dan insert ke stock_out
                foreach ($items as $item) {
                    Database::query("UPDATE materials SET current_stock = current_stock - ? WHERE id = ?", [$item['quantity'], $item['material_id']]);

                    Database::insert('stock_out', [
                        'material_id'   => $item['material_id'],
                        'order_id'      => $id,
                        'quantity'      => $item['quantity'],
                        'destination'   => $ord['delivery_address'] ?: ("Pengiriman pesanan " . $ord['order_number']),
                        'customer_name' => $ord['customer_name'],
                        'driver_name'   => null,
                        'vehicle_number'=> null,
                        'notes'         => "Otomatis dari pesanan #{$ord['order_number']}",
                        'processed_by'  => $user['id'],
                        'out_date'      => date('Y-m-d')
                    ]);
                }
            }

            // 2. KONDISI KEMBALIKAN STOK (ROLLBACK): Jika sebelumnya sudah dipotong, lalu diubah ke 'rejected' atau 'pending'
            elseif (in_array($oldStatus, ['processing', 'completed']) && in_array($newStatus, ['pending', 'rejected'])) {
                $items = Database::fetchAll("SELECT * FROM order_items WHERE order_id = ?", [$id]);
                
                foreach ($items as $item) {
                    // Kembalikan stok ke master materials
                    Database::query("UPDATE materials SET current_stock = current_stock + ? WHERE id = ?", [$item['quantity'], $item['material_id']]);
                }

                // Hapus catatan otomatis di stock_out berdasarkan order_id ini
                Database::delete('stock_out', 'order_id = ?', [$id]);
            }
            
            // Update status pesanan
            Database::update('orders',[
                'status'=>$newStatus,
                'admin_notes'=>$adminNotes?:null,
                'processed_by'=>$user['id'],
                'processed_at'=>$newStatus!=='pending'?date('Y-m-d H:i:s'):null
            ],'id=?',[$id]);
            
            logActivity('update','orders',"Mengubah status pesanan {$ord['order_number']} menjadi {$newStatus}");
            setFlash('success','Status pesanan berhasil diperbarui dan sinkronisasi stok disesuaikan.');
            redirect(APP_URL.'/admin-ab/modules/orders/view.php?id='.$id);
        }
    }
}
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
  <?=orderStatusLabel($ord['status'])?>
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
        </div>
        <div style="margin-top:16px;">
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Alamat Pengiriman</div>
          <div style="font-weight:600;line-height:1.5;"><?=nl2br(renderAddressWithMap($ord['delivery_address']))?></div>
        </div>

        <?php
        // Ekstrak koordinat lat & lng dari teks alamat jika ada
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

  <!-- Status Update -->
  <div>
    <div class="card mb-20">
      <div class="card-header"><div class="card-title">Perbarui Status</div></div>
      <div class="card-body">
        <form method="POST">
          <?=csrfField()?>
          <input type="hidden" name="update_status" value="1">
          <div class="form-group">
            <label class="form-label">Status Pesanan</label>
            <select name="status" class="form-control">
              <?php foreach(['pending'=>'⏳ Menunggu','processing'=>'🔄 Diproses','completed'=>'✅ Selesai','rejected'=>'❌ Ditolak'] as $val=>$lbl):?>
                <option value="<?=$val?>" <?=$ord['status']===$val?'selected':''?>><?=$lbl?></option>
              <?php endforeach;?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Catatan Admin</label>
            <textarea name="admin_notes" class="form-control" rows="4" placeholder="Catatan internal..."><?=htmlspecialchars($ord['admin_notes']??'')?></textarea>
          </div>
          <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Perbarui Status</button>
        </form>
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
<?php include __DIR__.'/../../partials/footer.php'; ?>
