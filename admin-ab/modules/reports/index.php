<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/app.php';
initSession(); requireLogin();
$pageTitle='Laporan'; $pageSubtitle='Rekap data operasional';

$type          = get('type','stock_in');
$dateFrom      = get('date_from', date('Y-m-01'));
$dateTo        = get('date_to',   date('Y-m-d'));
$matId         = getInt('material_id');
$matType       = get('material_type'); 
$sourceType    = get('source_type');
$driverName    = get('driver_name', '');
$vehicleNumber = get('vehicle_number', '');

$materials = Database::fetchAll("SELECT id,name FROM materials WHERE is_active=true ORDER BY name");
$drivers   = Database::fetchAll("SELECT name FROM drivers WHERE is_active = true ORDER BY name ASC");
$vehicles  = Database::fetchAll("SELECT plate_number FROM vehicles WHERE is_active = true ORDER BY plate_number ASC");

// Build report data
$reportData  = [];
$reportTitle = '';
$summary     = []; 

switch ($type) {
case 'stock_in':
        $reportTitle = 'Rekap Barang Masuk';
        $params = [$dateFrom, $dateTo];
        $extraWhere = '';
        if ($matId) { $extraWhere .= ' AND si.material_id=?'; $params[] = $matId; }
        if ($matType) { $extraWhere .= ' AND m.type=?'; $params[] = $matType; }
        
        $reportData = Database::fetchAll("
            SELECT 
                si.received_date AS tanggal,
                m.name AS material,
                si.quantity AS jumlah,
                m.unit AS satuan,
                COALESCE(si.supplier_name, '-') AS supplier,
                COALESCE(si.invoice_number, '-') AS nomor_invoice,
                COALESCE(u.name, '-') AS diterima_oleh,
                COALESCE(si.notes, '-') AS catatan,
                CASE WHEN m.type = 'raw' THEN '📦 Bahan Baku' ELSE '🏭 Produk Jadi' END AS tipe_material
            FROM stock_in si
            JOIN materials m ON si.material_id = m.id
            LEFT JOIN users u ON si.received_by = u.id
            WHERE si.received_date BETWEEN ? AND ? $extraWhere
            ORDER BY si.received_date DESC, si.id DESC
        ", $params);

        // Ringkasan untuk Kartu Statistik Laporan Barang Masuk
        $summary['total_items_in'] = count($reportData);
        $summary['total_qty_in']   = array_sum(array_column($reportData, 'jumlah'));
        break;

        case 'stock_out':
        $reportTitle = 'Rekap Barang Keluar (Detail Pesanan vs Non-Pesanan)';
        $params = [$dateFrom, $dateTo];
        $extraWhere = '';
        
        if ($matId) { $extraWhere .= ' AND so.material_id=?'; $params[] = $matId; }
        if ($matType) { $extraWhere .= ' AND m.type=?'; $params[] = $matType; }
        if ($sourceType === 'order') {
            $extraWhere .= ' AND so.order_id IS NOT NULL';
        } elseif ($sourceType === 'manual') {
            $extraWhere .= ' AND so.order_id IS NULL';
        }
        if ($driverName) { $extraWhere .= ' AND so.driver_name=?'; $params[] = $driverName; }
        if ($vehicleNumber) { $extraWhere .= ' AND so.vehicle_number=?'; $params[] = $vehicleNumber; }

        $reportData = Database::fetchAll("
            SELECT 
                CONCAT(so.out_date::text, ' ', COALESCE(so.out_time, o.delivery_time, '00:00')) AS tgl_kirim,
TO_CHAR(COALESCE(o.estimated_finish_time, so.estimated_finish_time), 'HH24:MI') AS eta_jam,
                COALESCE(o.created_at, so.created_at) AS waktu_pesan,
                CASE 
                    WHEN so.order_id IS NOT NULL THEN CONCAT('SJ-', REPLACE(o.order_number, 'ORD-', ''))
                    ELSE CONCAT('SJ-MAN-', TO_CHAR(so.out_date, 'YYYYMMDD'), '-', so.id)
                END AS nomor_surat_jalan,
                CASE 
                    WHEN so.order_id IS NOT NULL THEN CONCAT('🛒 Pesanan (#', o.order_number, ')')
                    ELSE CONCAT('📋 Manual / Non-Pesanan (#', so.id, ')') 
                END AS sumber_transaksi,
                m.name AS material, 
                so.quantity AS jumlah,
                m.unit AS satuan,
                COALESCE(NULLIF(so.customer_name,''), NULLIF(o.customer_name,''), 'Internal / Non-Pesanan') AS penerima_proyek,
                COALESCE(NULLIF(so.customer_phone,''), NULLIF(o.customer_phone,''), '-') AS hp_penerima,
                COALESCE(NULLIF(o.customer_email,''), '-') AS email_penerima,
                COALESCE(so.destination, '-') AS tujuan_pengiriman,
                COALESCE(so.driver_name, '-') AS nama_driver,
                COALESCE(so.vehicle_number, '-') AS no_kendaraan,
                COALESCE(
    NULLIF(
        CONCAT_WS(' | ',
            CASE WHEN TRIM(o.notes) <> '' AND o.processed_by IS NULL 
                 THEN '[Pelanggan] ' || TRIM(o.notes) END,
            CASE WHEN TRIM(o.notes) <> '' AND o.processed_by IS NOT NULL 
                 THEN '[Admin Order] ' || TRIM(o.notes) END,
            CASE WHEN TRIM(so.notes) <> '' AND so.notes NOT ILIKE 'Otomatis dari%' 
                 THEN '[Barang Keluar] ' || TRIM(so.notes) END,
            CASE WHEN TRIM(o.admin_notes) <> '' 
                 THEN '[Status] ' || TRIM(o.admin_notes) END
        ),
    ''),
    '-'
) AS catatan_transaksi,
                COALESCE(u.name, '-') AS petugas_input,
                
                CASE WHEN m.type = 'raw' THEN '📦 Bahan Baku' ELSE '🏭 Produk Jadi' END AS tipe_material,
                so.id AS stock_out_id,
so.order_id AS order_id_ref
            FROM stock_out so 
            JOIN materials m ON so.material_id = m.id
            LEFT JOIN orders o ON so.order_id = o.id
            LEFT JOIN users u ON so.processed_by = u.id
            WHERE so.out_date BETWEEN ? AND ? $extraWhere
            ORDER BY so.out_date DESC, so.out_time DESC, so.id DESC
        ", $params);

        // === Ringkasan utama ===
        $summary['total_items_out'] = count($reportData);
        $summary['total_qty_out']   = array_sum(array_column($reportData, 'jumlah'));

        // === Params breakdown (filter yang sama, tanpa JOIN materials dulu) ===
        $bParams = [$dateFrom, $dateTo];
        $bWhere  = '';
        if ($matId) { $bWhere .= ' AND so.material_id=?'; $bParams[] = $matId; }
        if ($sourceType === 'order') { $bWhere .= ' AND so.order_id IS NOT NULL'; }
        if ($sourceType === 'manual') { $bWhere .= ' AND so.order_id IS NULL'; }
        if ($driverName) { $bWhere .= ' AND so.driver_name=?'; $bParams[] = $driverName; }
        if ($vehicleNumber) { $bWhere .= ' AND so.vehicle_number=?'; $bParams[] = $vehicleNumber; }

        // per-material butuh JOIN materials + filter material_type
        $bmParams = $bParams;
        $bmWhere  = $bWhere;
        if ($matType) { $bmWhere .= ' AND m.type=?'; $bmParams[] = $matType; }

        $summary['per_material'] = Database::fetchAll("
            SELECT m.name AS material, m.unit,
                   SUM(so.quantity) AS total_qty, COUNT(*) AS rit
            FROM stock_out so
            JOIN materials m ON so.material_id = m.id
            WHERE so.out_date BETWEEN ? AND ? $bmWhere
            GROUP BY m.id, m.name, m.unit
            ORDER BY total_qty DESC
        ", $bmParams);

        $summary['per_sumber'] = Database::fetchAll("
            SELECT CASE WHEN so.order_id IS NULL THEN '📋 Manual / Non-Pesanan' ELSE '🛒 Pesanan' END AS sumber,
       COUNT(*) AS trx, SUM(so.quantity) AS qty
FROM stock_out so
WHERE so.out_date BETWEEN ? AND ? $bWhere
GROUP BY 1
ORDER BY 1
        ", $bParams);

        $summary['per_driver'] = Database::fetchAll("
            SELECT COALESCE(NULLIF(so.driver_name,''),'-') AS driver,
                   COUNT(*) AS rit, SUM(so.quantity) AS qty
            FROM stock_out so
            WHERE so.out_date BETWEEN ? AND ? $bWhere
            GROUP BY so.driver_name
            ORDER BY rit DESC
        ", $bParams);

        $summary['per_proyek'] = Database::fetchAll("
            SELECT COALESCE(NULLIF(so.destination,''),'-') AS proyek,
                   COUNT(*) AS rit, SUM(so.quantity) AS qty
            FROM stock_out so
            WHERE so.out_date BETWEEN ? AND ? $bWhere
            GROUP BY so.destination
            ORDER BY qty DESC
            LIMIT 10
        ", $bParams);
        break;

       case 'orders':
        $reportTitle = 'Rekap Pesanan';
        $reportData = Database::fetchAll("
            SELECT 
                o.order_number AS nomor,
                o.customer_name AS pelanggan,
                o.customer_phone AS hp,
                COALESCE((
                    SELECT STRING_AGG(CONCAT(m.name, ' (', oi.quantity, ' ', m.unit, ')'), ', ')
                    FROM order_items oi
                    JOIN materials m ON oi.material_id = m.id
                    WHERE oi.order_id = o.id
                ), '-') AS produk_dibeli,
                o.created_at AS tgl_pesan,
                CONCAT(o.delivery_date::text, ' ', COALESCE(o.delivery_time::text, '00:00')) AS tgl_kirim,
                o.total_amount AS total,
                o.status
            FROM orders o
            WHERE DATE(o.created_at) BETWEEN ? AND ?
            ORDER BY o.created_at DESC
        ", [$dateFrom, $dateTo]);
        
        $summary['total_revenue'] = (float)Database::fetchColumn("
            SELECT COALESCE(SUM(total_amount),0) FROM orders 
            WHERE status = 'completed' AND DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo]);
        
        $summary['total_orders'] = (int)Database::fetchColumn("
            SELECT COUNT(*) FROM orders 
            WHERE DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo]);
        
        $summary['completed_orders'] = (int)Database::fetchColumn("
            SELECT COUNT(*) FROM orders 
            WHERE status = 'completed' AND DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo]);
        
        $summary['pending_orders'] = (int)Database::fetchColumn("
            SELECT COUNT(*) FROM orders 
            WHERE status = 'pending' AND DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo]);
        
        $summary['avg_order'] = $summary['completed_orders'] > 0 ? 
            round($summary['total_revenue'] / $summary['completed_orders']) : 0;
        break;

    case 'product_sales':
        $reportTitle = 'Laporan Penjualan per Produk';
        $reportData = Database::fetchAll("
            SELECT 
                m.name AS produk,
                mc.name AS kategori,
                m.unit,
                COALESCE(SUM(oi.quantity),0) AS qty_terjual,
                COALESCE(SUM(oi.subtotal),0) AS total_pendapatan
            FROM order_items oi
            JOIN materials m ON oi.material_id = m.id
            LEFT JOIN material_categories mc ON m.category_id = mc.id
            JOIN orders o ON oi.order_id = o.id
            WHERE o.status = 'completed' 
                AND DATE(o.created_at) BETWEEN ? AND ?
            GROUP BY m.id, m.name, m.unit, mc.name
            ORDER BY total_pendapatan DESC
        ", [$dateFrom, $dateTo]);
        
        $summary['total_revenue'] = array_sum(array_column($reportData, 'total_pendapatan'));
        $summary['total_qty']     = array_sum(array_column($reportData, 'qty_terjual'));
        $summary['total_products']= count($reportData);
        break;

    case 'stock_summary':
        $reportTitle = 'Ringkasan Stok Material';
        $extraWhere = '';
        $params = [];
        if ($matType) { $extraWhere = ' AND m.type = ?'; $params[] = $matType; }
        $reportData = Database::fetchAll("
            SELECT ms.code AS kode, ms.name AS material, ms.unit, ms.category_name AS kategori,
                  ms.total_in AS total_masuk, ms.total_out AS total_keluar,
                  ms.current_stock AS stok_sekarang, ms.min_stock AS stok_minimum,
                  ms.stock_status AS status_stok, ms.price AS harga,
                  CASE WHEN m.type = 'raw' THEN '📦 Bahan Baku' ELSE '🏭 Produk Jadi' END AS tipe
            FROM material_stock ms
            JOIN materials m ON ms.id = m.id
            WHERE ms.is_active=true $extraWhere
            ORDER BY ms.name
        ", $params);
        break;
}

// Export PDF
if (isset($_GET['export']) && $_GET['export']==='pdf') {
    header('Content-Type: text/html; charset=utf-8');
    $cp = getCompanyProfile();
    ?>
    <!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">
    <title><?=$reportTitle?></title>
    <style>
    body{font-family:Arial,sans-serif;font-size:11px;color:#222;margin:15px;}
    h2{font-size:16px;margin:0;}h3{font-size:13px;margin:0;color:#555;}
    .header{border-bottom:2px solid #1d4ed8;padding-bottom:10px;margin-bottom:14px;display:flex;align-items:center;gap:12px;}
    .header-logo img{max-height:50px;width:auto;object-fit:contain;}
    .header-text{flex:1;}
    .company{font-size:10px;color:#777;margin-top:3px;}
    .summary-box{background:#f0fdf4;border:1px solid #bbf7d0;padding:10px;margin-bottom:12px;border-radius:6px;}
    .summary-box p{margin:3px 0;font-size:11px;}
    table{width:100%;border-collapse:collapse;margin-top:8px;}
    th{background:#1d4ed8;color:white;padding:6px 8px;text-align:left;font-size:10px;}
    td{padding:5px 8px;border-bottom:1px solid #eee;font-size:10.5px;}
    tr:nth-child(even){background:#f9fafb;}
    .footer{margin-top:15px;font-size:9.5px;color:#888;border-top:1px solid #ddd;padding-top:6px;text-align:right;}
    @media print{button{display:none;} @page { size: landscape; }}
    </style>
    </head><body>
    <div class="header">
      <?php if (!empty($cp['logo'])): ?>
        <div class="header-logo">
          <img src="<?= uploadUrl($cp['logo']) ?>" alt="Logo PT">
        </div>
      <?php endif; ?>
      <div class="header-text">
        <h2><?=$reportTitle?></h2>
        <h3><?=htmlspecialchars($cp['company_name']??APP_NAME)?></h3>
        <div class="company">Periode: <?=formatDate($dateFrom)?> s/d <?=formatDate($dateTo)?> | Dicetak: <?=formatDateTime(date('Y-m-d H:i:s'))?></div>
      </div>
    </div>
    <?php if (!empty($summary)): ?>
    <div class="summary-box">
        <?php if (isset($summary['total_revenue'])): ?>
            <p><strong>Total Pendapatan:</strong> <?=formatRupiah($summary['total_revenue'])?></p>
        <?php endif; ?>
        <?php if (isset($summary['total_orders'])): ?>
            <p><strong>Total Pesanan:</strong> <?=$summary['total_orders']?> (Selesai: <?=$summary['completed_orders']?>, Menunggu: <?=$summary['pending_orders']?>)</p>
        <?php endif; ?>
        <?php if (isset($summary['total_items_out'])): ?>
            <p><strong>Total Transaksi Keluar:</strong> <?=$summary['total_items_out']?> Data | <strong>Total Volume/Qty Keluar:</strong> <?=formatNumber($summary['total_qty_out'])?></p>
        <?php endif; ?>
                <?php if (!empty($summary['per_material'])): ?>
        <div style="display:flex;gap:10px;margin-top:8px;">
            <div style="flex:1;border:1px solid #d1d5db;padding:6px;border-radius:4px;background:#fff;">
                <div style="font-weight:bold;font-size:10px;margin-bottom:3px;border-bottom:1px solid #eee;padding-bottom:2px;">📦 Rekap per Material</div>
                <table style="width:100%;font-size:9.5px;">
                    <thead><tr style="background:#f3f4f6;"><th style="padding:2px 4px;">Material</th><th style="text-align:right;padding:2px 4px;">Qty</th><th style="text-align:right;padding:2px 4px;">Rit</th></tr></thead>
                    <tbody>
                    <?php foreach($summary['per_material'] as $pm): ?>
                        <tr><td style="padding:2px 4px;"><?=htmlspecialchars($pm['material'])?></td><td style="text-align:right;padding:2px 4px;"><?=formatNumber($pm['total_qty'])?> <?=$pm['unit']?></td><td style="text-align:right;padding:2px 4px;"><?=$pm['rit']?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="flex:1;border:1px solid #d1d5db;padding:6px;border-radius:4px;background:#fff;">
                <div style="font-weight:bold;font-size:10px;margin-bottom:3px;border-bottom:1px solid #eee;padding-bottom:2px;">🚚 Rekap per Driver</div>
                <table style="width:100%;font-size:9.5px;">
                    <thead><tr style="background:#f3f4f6;"><th style="padding:2px 4px;">Driver</th><th style="text-align:right;padding:2px 4px;">Rit</th><th style="text-align:right;padding:2px 4px;">Qty</th></tr></thead>
                    <tbody>
                    <?php foreach($summary['per_driver'] as $pd): ?>
                        <tr><td style="padding:2px 4px;"><?=htmlspecialchars($pd['driver'])?></td><td style="text-align:right;padding:2px 4px;"><?=$pd['rit']?></td><td style="text-align:right;padding:2px 4px;"><?=formatNumber($pd['qty'])?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="flex:1;border:1px solid #d1d5db;padding:6px;border-radius:4px;background:#fff;">
                <div style="font-weight:bold;font-size:10px;margin-bottom:3px;border-bottom:1px solid #eee;padding-bottom:2px;">🎯 Rekap per Sumber</div>
                <table style="width:100%;font-size:9.5px;">
                    <thead><tr style="background:#f3f4f6;"><th style="padding:2px 4px;">Sumber</th><th style="text-align:right;padding:2px 4px;">Trx</th><th style="text-align:right;padding:2px 4px;">Qty</th></tr></thead>
                    <tbody>
                    <?php foreach($summary['per_sumber'] as $ps): ?>
                        <tr><td style="padding:2px 4px;"><?=htmlspecialchars($ps['sumber'])?></td><td style="text-align:right;padding:2px 4px;"><?=$ps['trx']?></td><td style="text-align:right;padding:2px 4px;"><?=formatNumber($ps['qty'])?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <table>
    <thead>
        <tr>
       <?php if($reportData): foreach(array_keys($reportData[0]) as $col): ?>
    <?php if (in_array($col, ['stock_out_id','order_id_ref','eta_jam'])) continue; ?>
    <th><?=ucwords(str_replace('_',' ',$col))?></th>
<?php endforeach; endif; ?>
        </tr></thead>
    <tbody>
    <?php foreach($reportData as $row): ?>
        <tr>
        <?php foreach($row as $k=>$v): ?>
    <?php if (in_array($k, ['stock_out_id','order_id_ref','eta_jam'])) continue; ?>
    <td><?php
        if (in_array($k,['total','harga_satuan','harga','total_pendapatan'])) {
            echo $v ? formatRupiah($v) : '-';
        } elseif ($k === 'tanggal') {
            echo formatDate($v);
        } elseif ($k === 'tgl_pesan') {
            echo formatDateTime($v);
        } elseif ($k === 'tgl_kirim') {
            $etaVal = $row['eta_jam'] ?? null;
            echo formatDateTime($v) . ($etaVal ? ' (ETA: ' . htmlspecialchars($etaVal) . ')' : '');    // pesanan & stock_out: tanggal + jam
                    } elseif ($k==='waktu_pesan') {
                        echo formatDateTime($v); 
                    } elseif ($k==='status') {
                        echo ucfirst($v);
                    } elseif ($k==='status_stok') {
                        echo ucfirst(str_replace('_',' ',$v));
                    } else {
                        echo htmlspecialchars($v??'-');
                    }
            ?></td>
        <?php endforeach; ?>
        </tr><?php endforeach; ?>
    </tbody>
    </table>
    <div class="footer">Total <?=count($reportData)?> data | <?=htmlspecialchars($cp['company_name']??APP_NAME)?></div>
    <script>window.onload=()=>window.print();</script>
    </body></html>
    <?php exit;
}

include __DIR__.'/../../partials/head.php';
?>
<div class="admin-wrapper">
<?php include __DIR__.'/../../partials/sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__.'/../../partials/topbar.php'; ?>
<div class="page-body">

<div class="section-header mb-20"><h2>Laporan Operasional</h2><p>Filter dan ekspor data laporan rekapitulasi detail</p></div>

<div class="card mb-20">
  <div class="card-header"><div class="card-title">Filter Laporan</div></div>
  <div class="card-body">
    <form method="GET">
        <div class="grid grid-2" style="gap:16px;margin-bottom:16px;">
            <div class="form-group" style="margin:0;">
                <label class="form-label">Jenis Laporan</label>
                <select name="type" class="form-control" id="report-type">
                    <option value="stock_in"      <?=$type==='stock_in'     ?'selected':''?>>📥 Barang Masuk</option>
                    <option value="stock_out"     <?=$type==='stock_out'    ?'selected':''?>>📤 Barang Keluar (Detail Transaksi)</option>
                    <option value="orders"        <?=$type==='orders'       ?'selected':''?>>📋 Pesanan</option>
                    <option value="product_sales" <?=$type==='product_sales'?'selected':''?>>💰 Penjualan per Produk</option>
                    <option value="stock_summary" <?=$type==='stock_summary'?'selected':''?>>📊 Ringkasan Stok</option>
                </select>
            </div>
            <div class="form-group" style="margin:0;" id="material-filter">
                <label class="form-label">Material (opsional)</label>
                <select name="material_id" class="form-control">
                    <option value="">Semua Material</option>
                    <?php foreach($materials as $m):?>
                    <option value="<?=$m['id']?>" <?=$matId==$m['id']?'selected':''?>><?=htmlspecialchars($m['name'])?></option>
                    <?php endforeach;?>
                </select>
            </div>
        </div>

        <div class="grid grid-2" style="gap:16px;margin-bottom:16px;" id="extra-filters">
            <div class="form-group" style="margin:0;">
                <label class="form-label">Tipe Material</label>
                <select name="material_type" class="form-control" id="material-type">
                    <option value="">Semua Tipe</option>
                    <option value="product" <?= ($matType === 'product') ? 'selected' : '' ?>>🏭 Produk Jadi</option>
                    <option value="raw" <?= ($matType === 'raw') ? 'selected' : '' ?>>📦 Bahan Baku</option>
                </select>
            </div>
            
            <div class="form-group" style="margin:0;" id="source-filter">
                <label class="form-label">Sumber Barang Keluar</label>
                <select name="source_type" class="form-control">
                    <option value="">Semua Sumber (Pesanan & Non-Pesanan)</option>
                    <option value="order" <?= ($sourceType === 'order') ? 'selected' : '' ?>>🛒 Khusus Pesanan</option>
                    <option value="manual" <?= ($sourceType === 'manual') ? 'selected' : '' ?>>📋 Khusus Non-Pesanan (Manual)</option>
                </select>
            </div>
        </div>  
                <div class="grid grid-2" style="gap:16px;margin-bottom:16px;" id="armada-filters">
            <div class="form-group" style="margin:0;">
                <label class="form-label">Driver (opsional)</label>
                <select name="driver_name" class="form-control">
                    <option value="">Semua Driver</option>
                    <?php foreach($drivers as $d):?>
                    <option value="<?=htmlspecialchars($d['name'])?>" <?=$driverName===$d['name']?'selected':''?>><?=htmlspecialchars($d['name'])?></option>
                    <?php endforeach;?>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Kendaraan (opsional)</label>
                <select name="vehicle_number" class="form-control">
                    <option value="">Semua Kendaraan</option>
                    <?php foreach($vehicles as $v):?>
                    <option value="<?=htmlspecialchars($v['plate_number'])?>" <?=$vehicleNumber===$v['plate_number']?'selected':''?>><?=htmlspecialchars($v['plate_number'])?></option>
                    <?php endforeach;?>
                </select>
            </div>
        </div>

      <div class="grid grid-2" style="gap:16px;" id="date-range">
        <div class="form-group" style="margin:0;"><label class="form-label">Dari Tanggal</label><input type="date" name="date_from" class="form-control" value="<?=$dateFrom?>"></div>
        <div class="form-group" style="margin:0;"><label class="form-label">Sampai Tanggal</label><input type="date" name="date_to" class="form-control" value="<?=$dateTo?>"></div>
      </div>

      <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Tampilkan</button>
        <a href="?type=<?=urlencode($type)?>&date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>&material_id=<?=$matId?>&material_type=<?=urlencode($matType)?>&source_type=<?=urlencode($sourceType)?>&driver_name=<?=urlencode($driverName)?>&vehicle_number=<?=urlencode($vehicleNumber)?>&export=pdf" target="_blank" class="btn btn-danger"><i class="fas fa-file-pdf"></i> Export PDF (Cetak)</a>
      </div>
    </form>
  </div>
</div>

<!-- Ringkasan Card -->
<?php if (!empty($summary) && $type !== 'stock_summary'): ?>
<div class="grid grid-4 mb-20" style="margin-top: -10px;">
    <?php if (isset($summary['total_revenue'])): ?>
    <div class="stat-card" style="--stat-color: #8b5cf6;">
        <div class="stat-icon" style="background: rgba(139,92,246,0.1); color: #8b5cf6;"><i class="fas fa-chart-line"></i></div>
        <div class="stat-content">
            <div class="stat-label">Total Pendapatan</div>
            <div class="stat-value" style="font-size:18px;"><?= $summary['total_revenue'] ? formatRupiah($summary['total_revenue']) : '-' ?></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($summary['total_items_in'])): ?>
    <div class="stat-card" style="--stat-color: #10b981;">
        <div class="stat-icon" style="background: rgba(16,185,129,0.1); color: #10b981;"><i class="fas fa-arrow-down"></i></div>
        <div class="stat-content">
            <div class="stat-label">Total Transaksi Masuk</div>
            <div class="stat-value"><?= $summary['total_items_in'] ?> Data</div>
            <div class="stat-change up">Total Volume: <?= formatNumber($summary['total_qty_in']) ?> unit</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($summary['total_items_out'])): ?>
    <div class="stat-card" style="--stat-color: #ef4444;">
        <div class="stat-icon" style="background: rgba(239,68,68,0.1); color: #ef4444;"><i class="fas fa-truck-loading"></i></div>
        <div class="stat-content">
            <div class="stat-label">Total Transaksi Keluar</div>
            <div class="stat-value"><?= $summary['total_items_out'] ?> Data</div>
            <div class="stat-change down">Total Volume: <?= formatNumber($summary['total_qty_out']) ?></div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (isset($summary['total_orders'])): ?>
    <div class="stat-card" style="--stat-color: #3b82f6;">
        <div class="stat-icon" style="background: rgba(59,130,246,0.1); color: #3b82f6;"><i class="fas fa-clipboard-list"></i></div>
        <div class="stat-content">
            <div class="stat-label">Total Pesanan</div>
            <div class="stat-value"><?= $summary['total_orders'] ?></div>
            <div class="stat-change up">Selesai: <?= $summary['completed_orders'] ?> | Menunggu: <?= $summary['pending_orders'] ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php if ($type === 'stock_out' && !empty($summary['per_material'])): ?>
<div class="grid grid-3 mb-20" style="gap:16px;">
    <div class="card">
        <div class="card-header"><div class="card-title" style="font-size:14px;">📦 Rekap per Material</div></div>
        <div class="card-body" style="padding:0;">
            <table class="table" style="margin:0;">
                <thead><tr><th style="font-size:11px;">Material</th><th style="text-align:right;font-size:11px;">Qty</th><th style="text-align:right;font-size:11px;">Rit</th></tr></thead>
                <tbody>
                <?php foreach($summary['per_material'] as $pm): ?>
                    <tr>
                        <td style="font-size:12px;"><?=htmlspecialchars($pm['material'])?></td>
                        <td style="text-align:right;font-size:12px;font-weight:600;"><?=formatNumber($pm['total_qty'])?> <span style="font-size:10px;color:var(--text-muted);"><?=$pm['unit']?></span></td>
                        <td style="text-align:right;font-size:12px;"><?=$pm['rit']?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div class="card-title" style="font-size:14px;">🚚 Rekap per Driver</div></div>
        <div class="card-body" style="padding:0;">
            <table class="table" style="margin:0;">
                <thead><tr><th style="font-size:11px;">Driver</th><th style="text-align:right;font-size:11px;">Rit</th><th style="text-align:right;font-size:11px;">Qty</th></tr></thead>
                <tbody>
                <?php foreach($summary['per_driver'] as $pd): ?>
                    <tr>
                        <td style="font-size:12px;"><?=htmlspecialchars($pd['driver'])?></td>
                        <td style="text-align:right;font-size:12px;"><?=$pd['rit']?></td>
                        <td style="text-align:right;font-size:12px;font-weight:600;"><?=formatNumber($pd['qty'])?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div class="card-title" style="font-size:14px;">🎯 Top Tujuan / Proyek</div></div>
        <div class="card-body" style="padding:0;">
            <table class="table" style="margin:0;">
                <thead><tr><th style="font-size:11px;">Proyek</th><th style="text-align:right;font-size:11px;">Rit</th><th style="text-align:right;font-size:11px;">Qty</th></tr></thead>
                <tbody>
                <?php foreach($summary['per_proyek'] as $pp): 
                    // 1. Ambil URL Maps jika ada
                    preg_match('/https?:\/\/maps\.google\.com\/\?q=[-?\d\.\,]+/i', $pp['proyek'], $matches);
                    $mapUrl = $matches[0] ?? '';
                    
                    // 2. Bersihkan teks dari tulisan panjang "Titik Lokasi Maps"
                    $cleanText = preg_replace('/📍 Titik Lokasi Maps:\s*https?:\/\/maps\.google\.com\/\?q=[-?\d\.\,]+/i', '', $pp['proyek']);
                    $cleanText = trim($cleanText);
                    if (empty($cleanText)) $cleanText = 'Titik Maps';
                ?>
                    <tr>
                        <td style="font-size:12px;max-width:180px;" class="truncate" title="<?=htmlspecialchars($cleanText)?>">
                            <?=htmlspecialchars($cleanText)?>
                            <?php if ($mapUrl): ?>
                                <br>
                                <a href="<?= htmlspecialchars($mapUrl) ?>" target="_blank" style="color:#2563eb; font-weight:600; font-size:11px; text-decoration:none;">
                                    <i class="fas fa-map-marker-alt"></i> Buka Maps
                                </a>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;font-size:12px;"><?=$pp['rit']?></td>
                        <td style="text-align:right;font-size:12px;font-weight:600;"><?=formatNumber($pp['qty'])?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Report Results -->
<div class="card">
  <div class="card-header">
    <div><div class="card-title"><?=$reportTitle?></div><div class="card-subtitle">Periode: <?=formatDate($dateFrom)?> s/d <?=formatDate($dateTo)?> — <?=count($reportData)?> data</div></div>
  </div>
  <div class="card-body" style="padding:0;">
    <?php if(empty($reportData)):?>
      <div class="empty-state"><div class="empty-state-icon"><i class="fas fa-file-alt"></i></div><div class="empty-state-title">Tidak ada data pada periode ini</div></div>
    <?php else:?>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
            <?php foreach(array_keys($reportData[0] ?? []) as $col): ?>
    <?php if (in_array($col, ['stock_out_id','order_id_ref','eta_jam'])) continue; ?>
    <th><?= ucwords(str_replace('_', ' ', $col)) ?></th>
<?php endforeach; ?>
            </tr></thead>
          
            <tbody>
            <?php foreach($reportData as $row):?>
            <tr>
              <?php foreach($row as $k=>$v):?>
                <?php if (in_array($k, ['stock_out_id','order_id_ref','eta_jam'])) continue; ?>
                    <td style="font-size:13px;"><?php
                    if (in_array($k,['total','harga_satuan','harga','total_pendapatan'])) {
                        echo $v ? formatRupiah($v) : '-';
                    } elseif ($k==='qty_terjual' || $k==='jumlah') {
                        echo $v ? formatNumber($v) : '0';
                    } elseif ($k === 'tanggal') {
        echo formatDate($v);
    } elseif ($k === 'tgl_pesan') {
        echo formatDateTime($v);
            } elseif ($k === 'tgl_kirim') {
        $tglFormatted = formatDateTime($v);
        $etaVal = $row['eta_jam'] ?? null;
        if ($etaVal) {
            echo $tglFormatted . ' <span style="background:#ede9fe;color:#6d28d9;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;margin-left:4px;white-space:nowrap;">ETA ' . htmlspecialchars($etaVal) . '</span>';
        } else {
            echo $tglFormatted;
        }
    } elseif ($k === 'waktu_pesan') {
        echo formatDateTime($v);
    } elseif ($k === 'nomor_surat_jalan') {
    // Nomor SJ → clickable ke print_sj.php
    $orderId = $row['order_id_ref'] ?? null;
    $soId    = $row['stock_out_id'] ?? null;
    $sjUrl   = $orderId
        ? APP_URL.'/admin-ab/modules/stock_out/print_sj.php?type=order&id='.$orderId
        : APP_URL.'/admin-ab/modules/stock_out/print_sj.php?type=manual&id='.$soId;

    echo '<a href="'.$sjUrl.'" target="_blank" '
         . 'style="background:#dbeafe;color:#1e40af;padding:4px 8px;border-radius:4px;font-weight:700;'
         . 'text-decoration:none;display:inline-flex;align-items:center;gap:5px;'
         . 'font-family:monospace;font-size:11px;border:1px solid #bfdbfe;white-space:nowrap;" '
         . 'title="Klik untuk cetak Surat Jalan">'
         . htmlspecialchars($v)
         . ' <i class="fas fa-print" style="font-size:10px;"></i></a>';
                   } elseif ($k==='sumber_transaksi') {
    if (str_contains($v, '🛒 Pesanan (#')) {
        // Jika sumber dari Pesanan Online
        preg_match('/#([A-Z0-9\-]+)/', $v, $match);
        $ordNum     = $match[1] ?? '';
        $targetId   = $ordNum
            ? Database::fetchColumn("SELECT id FROM orders WHERE order_number = ?", [$ordNum])
            : '';
        $linkTarget = $targetId ? APP_URL.'/admin-ab/modules/orders/view.php?id='.$targetId : '#';

        echo '<a href="'.$linkTarget.'" target="_blank" class="badge badge-primary" style="background:#e0e7ff;color:#3730a3;padding:4px 8px;border-radius:4px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px;" title="Klik untuk lihat detail pesanan">'
            .htmlspecialchars($v)
            .' <i class="fas fa-external-link-alt" style="font-size:10px;"></i></a>';
    } else {
        // Jika sumber Manual / Non-Pesanan -> Arahkan langsung ke edit stock_out menggunakan stock_out_id
        $manualId   = $row['stock_out_id'] ?? '';
        $linkTarget = $manualId ? APP_URL.'/admin-ab/modules/stock_out/edit.php?id='.$manualId : '#';

        echo '<a href="'.$linkTarget.'" target="_blank" class="badge badge-secondary" style="background:#f3f4f6;color:#374151;padding:4px 8px;border-radius:4px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px;" title="Klik untuk edit data barang keluar manual">'
            .htmlspecialchars($v)
            .' <i class="fas fa-edit" style="font-size:10px;"></i></a>';
                        }
                    } elseif ($k==='status') {
                        echo orderStatusLabel($v);
                    } elseif ($k==='status_stok') {
                        echo stockStatusLabel($v);
                    } else {
                        // Format teks biasa
                        $safeText = htmlspecialchars($v ?? '-');
                        
                        // Deteksi link Maps dan ubah jadi tombol/link
                        $safeText = preg_replace(
                            '/(https?:\/\/maps\.google\.com\/\?q=[-?\d\.\,]+)/i', 
                            '<a href="$1" target="_blank" style="color:#2563eb; font-weight:600; text-decoration:underline;"><i class="fas fa-map-marker-alt"></i> Buka Lokasi (Maps)</a>', 
                            $safeText
                        );
                        
                        echo $safeText;
                    }
                ?></td>
              <?php endforeach;?>
            </tr><?php endforeach;?>
          </tbody>
        </table>
      </div>
    <?php endif;?>
  </div>
</div>
</div>
<?php include __DIR__.'/../../partials/footer.php'; ?>
<script>
document.getElementById('report-type').addEventListener('change', function() {
  const df = document.getElementById('material-filter');
  const dr = document.getElementById('date-range');
  const sourceFilter = document.getElementById('source-filter');
  const tf = document.getElementById('material-type')?.parentElement;
  const armadaFilters = document.getElementById('armada-filters');

  const showStockOut = (this.value === 'stock_out');

  if (showStockOut) {
    df.style.display = ''; dr.style.display = '';
    if (tf) tf.style.display = '';
    if (sourceFilter) sourceFilter.style.display = '';
    if (armadaFilters) armadaFilters.style.display = '';
  } else if (this.value === 'stock_summary') {
    df.style.display = 'none'; dr.style.display = 'none';
    if (tf) tf.style.display = 'none';
    if (sourceFilter) sourceFilter.style.display = 'none';
    if (armadaFilters) armadaFilters.style.display = 'none';
  } else if (this.value === 'product_sales' || this.value === 'orders') {
    df.style.display = 'none'; dr.style.display = '';
    if (tf) tf.style.display = 'none';
    if (sourceFilter) sourceFilter.style.display = 'none';
    if (armadaFilters) armadaFilters.style.display = 'none';
  } else {
    df.style.display = ''; dr.style.display = '';
    if (tf) tf.style.display = '';
    if (sourceFilter) sourceFilter.style.display = 'none';
    if (armadaFilters) armadaFilters.style.display = 'none';
  }
});
document.getElementById('report-type').dispatchEvent(new Event('change'));
</script>