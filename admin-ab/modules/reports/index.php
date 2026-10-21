<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/app.php';
initSession(); requireLogin();
$pageTitle='Laporan'; $pageSubtitle='Rekap data operasional';

$type        = get('type','stock_in');
$dateFrom    = get('date_from', date('Y-m-01'));
$dateTo      = get('date_to',   date('Y-m-d'));
$matId       = getInt('material_id');
$matType     = get('material_type'); 
$sourceType  = get('source_type'); // Filter khusus Barang Keluar (all, order, manual)

$materials = Database::fetchAll("SELECT id,name FROM materials WHERE is_active=true ORDER BY name");

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
                si.supplier_name AS supplier,
                si.invoice_number AS invoice,
                COALESCE(si.notes, '-') AS catatan,
                CASE WHEN m.type = 'raw' THEN '📦 Bahan Baku' ELSE '🏭 Produk Jadi' END AS tipe_material
            FROM stock_in si 
            JOIN materials m ON si.material_id=m.id
            WHERE si.received_date BETWEEN ? AND ? $extraWhere
            ORDER BY si.received_date DESC
        ", $params);
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

        $reportData = Database::fetchAll("
            SELECT 
                so.out_date AS tanggal,
                CASE 
                    WHEN so.order_id IS NOT NULL THEN CONCAT('🛒 Pesanan (#', o.order_number, ')')
                    ELSE '📋 Manual / Non-Pesanan' 
                END AS sumber_transaksi,
                m.name AS material, 
                so.quantity AS jumlah,
                m.unit AS satuan,
                COALESCE(so.customer_name, o.customer_name, '-') AS penerima_proyek,
                so.destination AS tujuan_pengiriman,
                COALESCE(so.driver_name, '-') AS nama_driver,
                COALESCE(so.vehicle_number, '-') AS no_kendaraan,
                COALESCE(o.notes, so.notes, '-') AS catatan_pelanggan,
                COALESCE(o.admin_notes, '-') AS catatan_admin,
                CASE WHEN m.type = 'raw' THEN '📦 Bahan Baku' ELSE '🏭 Produk Jadi' END AS tipe_material
            FROM stock_out so 
            JOIN materials m ON so.material_id = m.id
            LEFT JOIN orders o ON so.order_id = o.id
            WHERE so.out_date BETWEEN ? AND ? $extraWhere
            ORDER BY so.out_date DESC, so.id DESC
        ", $params);

        // Ringkasan untuk Barang Keluar
        $summary['total_items_out'] = count($reportData);
        $summary['total_qty_out']   = array_sum(array_column($reportData, 'jumlah'));
        break;

    case 'orders':
        $reportTitle = 'Rekap Pesanan';
        $reportData = Database::fetchAll("
            SELECT order_number AS nomor, customer_name AS pelanggan, customer_phone AS hp,
                   total_amount AS total, status, created_at AS tanggal
            FROM orders
            WHERE DATE(created_at) BETWEEN ? AND ?
            ORDER BY created_at DESC
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
    </div>
    <?php endif; ?>
    <table>
    <thead>
        <tr>
        <?php if($reportData): foreach(array_keys($reportData[0]) as $col): ?>
            <th><?=ucwords(str_replace('_',' ',$col))?></th>
        <?php endforeach; endif; ?>
        </tr></thead>
    <tbody>
    <?php foreach($reportData as $row): ?>
        <tr>
        <?php foreach($row as $k=>$v): ?>
            <td><?php
                    if (in_array($k,['total','harga_satuan','harga','total_pendapatan'])) {
                        echo $v ? formatRupiah($v) : '-';
                    } elseif ($k==='tanggal') {
                        echo formatDate($v);
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

      <div class="grid grid-2" style="gap:16px;" id="date-range">
        <div class="form-group" style="margin:0;"><label class="form-label">Dari Tanggal</label><input type="date" name="date_from" class="form-control" value="<?=$dateFrom?>"></div>
        <div class="form-group" style="margin:0;"><label class="form-label">Sampai Tanggal</label><input type="date" name="date_to" class="form-control" value="<?=$dateTo?>"></div>
      </div>

      <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Tampilkan</button>
        <a href="?type=<?=urlencode($type)?>&date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>&material_id=<?=$matId?>&material_type=<?=urlencode($matType)?>&source_type=<?=urlencode($sourceType)?>&export=pdf" target="_blank" class="btn btn-danger"><i class="fas fa-file-pdf"></i> Export PDF (Cetak)</a>
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
            <?php foreach(array_keys($reportData[0]) as $col):?><th><?=ucwords(str_replace('_',' ',$col))?></th><?php endforeach;?>
            </tr></thead>
          <tbody>
            <?php foreach($reportData as $row):?>
            <tr>
              <?php foreach($row as $k=>$v):?>
                <td style="font-size:13px;"><?php
                    if (in_array($k,['total','harga_satuan','harga','total_pendapatan'])) {
                        echo $v ? formatRupiah($v) : '-';
                    } elseif ($k==='qty_terjual' || $k==='jumlah') {
                        echo $v ? formatNumber($v) : '0';
                    } elseif ($k==='tanggal') {
                        echo formatDate($v);
                    } elseif ($k==='sumber_transaksi') {
                        if (str_contains($v, 'Pesanan')) {
                            echo '<span class="badge badge-primary" style="background:#e0e7ff;color:#3730a3;padding:4px 8px;border-radius:4px;font-weight:600;">'.htmlspecialchars($v).'</span>';
                        } else {
                            echo '<span class="badge badge-secondary" style="background:#f3f4f6;color:#374151;padding:4px 8px;border-radius:4px;font-weight:600;">'.htmlspecialchars($v).'</span>';
                        }
                    } elseif ($k==='status') {
                        echo orderStatusLabel($v);
                    } elseif ($k==='status_stok') {
                        echo stockStatusLabel($v);
                    } else {
                        echo htmlspecialchars($v??'-');
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

  if (this.value === 'stock_out') {
    df.style.display = ''; dr.style.display = '';
    if (tf) tf.style.display = '';
    if (sourceFilter) sourceFilter.style.display = '';
  } else if (this.value === 'stock_summary') {
    df.style.display = 'none'; dr.style.display = 'none';
    if (tf) tf.style.display = 'none';
    if (sourceFilter) sourceFilter.style.display = 'none';
  } else if (this.value === 'product_sales' || this.value === 'orders') {
    df.style.display = 'none'; dr.style.display = '';
    if (tf) tf.style.display = 'none';
    if (sourceFilter) sourceFilter.style.display = 'none';
  } else {
    df.style.display = ''; dr.style.display = '';
    if (tf) tf.style.display = '';
    if (sourceFilter) sourceFilter.style.display = 'none';
  }
});
document.getElementById('report-type').dispatchEvent(new Event('change'));
</script>