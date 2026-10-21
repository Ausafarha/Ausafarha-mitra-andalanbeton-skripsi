<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/app.php';
initSession(); requireLogin();

$type = get('type', 'order'); // 'order' atau 'manual'
$id   = getInt('id');

$sjData = [];
$sjItems = [];

if ($type === 'order') {
    // Ambil data dari pesanan
    $ord = Database::fetchOne("SELECT * FROM orders WHERE id=?", [$id]);
    if (!$ord) { die('Pesanan tidak ditemukan.'); }

    // Ambil log driver dari stock_out yang terikat order_id ini
    $log = Database::fetchOne("SELECT driver_name, vehicle_number, out_date FROM stock_out WHERE order_id=? LIMIT 1", [$id]);

    $sjNumber      = 'SJ-' . str_replace('ORD-', '', $ord['order_number']);
    $sjDate        = $log['out_date'] ?? date('Y-m-d');
    $customerName  = $ord['customer_name'];
    $destination   = $ord['delivery_address'];
    $driverName    = $log['driver_name'] ?? '-';
    $vehicleNumber = $log['vehicle_number'] ?? '-';
    $notes         = $ord['notes'] ?? '-';

    // Ambil item material pesanan
    $sjItems = Database::fetchAll("
        SELECT m.name AS material_name, oi.quantity, m.unit 
        FROM order_items oi 
        JOIN materials m ON oi.material_id = m.id 
        WHERE oi.order_id = ?
    ", [$id]);

} else {
    // Ambil data dari barang keluar manual (stock_out)
    $so = Database::fetchOne("
        SELECT so.*, m.name AS material_name, m.unit 
        FROM stock_out so 
        JOIN materials m ON so.material_id = m.id 
        WHERE so.id=?
    ", [$id]);
    if (!$so) { die('Data barang keluar tidak ditemukan.'); }

    $sjNumber      = 'SJ-MAN-' . date('Ymd', strtotime($so['out_date'])) . '-' . $so['id'];
    $sjDate        = $so['out_date'];
    $customerName  = $so['customer_name'] ?? 'Non-Pesanan / Internal';
    $destination   = $so['destination'];
    $driverName    = $so['driver_name'] ?? '-';
    $vehicleNumber = $so['vehicle_number'] ?? '-';
    $notes         = $so['notes'] ?? '-';

    $sjItems = [[
        'material_name' => $so['material_name'],
        'quantity'      => $so['quantity'],
        'unit'          => $so['unit']
    ]];
}

$cp = getCompanyProfile();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Surat Jalan - <?= $sjNumber ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 20px; background: #fff; }
        .sj-container { max-width: 800px; margin: 0 auto; border: 1.5px solid #222; padding: 20px; }
        .sj-header { display: flex; justify-content: space-between; border-bottom: 2px solid #222; padding-bottom: 12px; margin-bottom: 15px; }
        .company-info h1 { font-size: 18px; margin: 0 0 4px 0; text-transform: uppercase; }
        .company-info p { font-size: 11px; margin: 2px 0; color: #555; }
        .sj-title-box { text-align: right; }
        .sj-title-box h2 { font-size: 20px; margin: 0 0 5px 0; letter-spacing: 1px; }
        .sj-title-box p { font-size: 12px; margin: 2px 0; font-weight: bold; }
        
        .sj-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; font-size: 12px; }
        .info-card { border: 1px solid #ccc; padding: 10px; border-radius: 4px; background: #fdfdfd; }
        .info-card table { width: 100%; border-collapse: collapse; }
        .info-card td { padding: 3px 0; vertical-align: top; }
        .info-card td.label { width: 110px; color: #555; font-weight: bold; }

        table.sj-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.sj-table th, table.sj-table td { border: 1px solid #222; padding: 8px 10px; text-align: left; font-size: 12px; }
        table.sj-table th { background: #f0f0f0; text-transform: uppercase; font-size: 11px; }

        .sj-footer { display: grid; grid-template-columns: repeat(3, 1fr); text-align: center; margin-top: 30px; font-size: 12px; }
        .sign-box { height: 75px; }
        .sign-name { font-weight: bold; text-decoration: underline; margin-top: 5px; }
        
        .print-btn-bar { text-align: center; margin-bottom: 20px; }
        .btn-print { background: #1d4ed8; color: white; border: none; padding: 10px 20px; font-size: 14px; font-weight: bold; border-radius: 6px; cursor: pointer; }
        .btn-print:hover { background: #1e40af; }

        @media print {
            .print-btn-bar { display: none; }
            body { margin: 0; }
            .sj-container { border: none; padding: 0; }
        }
    </style>
</head>
<body>

<div class="print-btn-bar">
    <button onclick="window.print()" class="btn-print"><i class="fas fa-print"></i> Cetak Surat Jalan Sekarang</button>
</div>

<div class="sj-container">
    <!-- Header -->
    <div class="sj-header">
        <div class="company-info">
            <h1><?= htmlspecialchars($cp['company_name'] ?? 'PT Mitra Andalan Beton Pantura') ?></h1>
            <p><?= htmlspecialchars($cp['address'] ?? 'Brebes & Purwokerto, Jawa Tengah') ?></p>
            <p>Telp: <?= htmlspecialchars($cp['phone'] ?? '-') ?> | Email: <?= htmlspecialchars($cp['email'] ?? '-') ?></p>
        </div>
        <div class="sj-title-box">
            <h2>SURAT JALAN</h2>
            <p>No: <?= htmlspecialchars($sjNumber) ?></p>
            <p>Tanggal: <?= formatDate($sjDate) ?></p>
        </div>
    </div>

    <!-- Info Detail -->
    <div class="sj-info-grid">
        <div class="info-card">
            <table>
                <tr><td class="label">Penerima / Proyek</td><td>: <strong><?= htmlspecialchars($customerName) ?></strong></td></tr>
                <tr><td class="label">Tujuan / Lokasi</td><td>: <?= nl2br(htmlspecialchars($destination)) ?></td></tr>
            </table>
        </div>
        <div class="info-card">
            <table>
                <tr><td class="label">Nama Driver</td><td>: <strong><?= htmlspecialchars($driverName) ?></strong></td></tr>
                <tr><td class="label">No. Kendaraan</td><td>: <strong class="text-mono"><?= htmlspecialchars($vehicleNumber) ?></strong></td></tr>
                <tr><td class="label">Keterangan</td><td>: <?= htmlspecialchars($notes) ?></td></tr>
            </table>
        </div>
    </div>

    <!-- Tabel Item Material -->
    <table class="sj-table">
        <thead>
            <tr>
                <th style="width: 40px; text-align: center;">No</th>
                <th>Nama Material / Produk Beton</th>
                <th style="width: 120px; text-align: right;">Jumlah / Volume</th>
                <th style="width: 80px; text-align: center;">Satuan</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sjItems as $i => $item): ?>
            <tr>
                <td style="text-align: center;"><?= $i + 1 ?></td>
                <td><strong><?= htmlspecialchars($item['material_name']) ?></strong></td>
                <td style="text-align: right; font-weight: bold;"><?= formatNumber($item['quantity']) ?></td>
                <td style="text-align: center;"><?= htmlspecialchars($item['unit']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="font-size: 11px; color: #555; margin-bottom: 25px;">
        * Barang diterima dalam keadaan baik dan cukup sesuai dengan rincian di atas.<br>
        * Lembar 1: Pembeli / Arsip Proyek | Lembar 2: Penagihan (Finance) | Lembar 3: Pos Satpam / Gudang.
    </div>

    <!-- Tanda Tangan 3 Pihak (Standar Industri) -->
    <div class="sj-footer">
        <div>
            <p>Hormat Kami,</p>
            <div class="sign-box"></div>
            <p class="sign-name">Bagian Gudang / Admin</p>
        </div>
        <div>
            <p>Pengirim / Driver,</p>
            <div class="sign-box"></div>
            <p class="sign-name"><?= htmlspecialchars($driverName) ?></p>
        </div>
        <div>
            <p>Penerima / Proyek,</p>
            <div class="sign-box"></div>
            <p class="sign-name">( ........................................ )</p>
        </div>
    </div>
</div>

</body>
</html>