<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/app.php';
initSession(); requireLogin();
$pageTitle = 'Barang Keluar';
$pageSubtitle = 'Riwayat distribusi material';
$search=$get_s=get('search'); $matId=getInt('material_id'); $dateFrom=get('date_from'); $dateTo=get('date_to'); $page=max(1,getInt('page',1));
$params=[]; $where=['1=1'];
if ($search){$where[]="(m.name ILIKE ? OR so.destination ILIKE ? OR so.driver_name ILIKE ?)";$params[]="%$search%";$params[]="%$search%";$params[]="%$search%";}
if ($matId){$where[]="so.material_id=?";$params[]=$matId;}
if ($dateFrom){$where[]="so.out_date>=?";$params[]=$dateFrom;}
if ($dateTo){$where[]="so.out_date<=?";$params[]=$dateTo;}
$whereStr=implode(' AND ',$where);
$sql="SELECT so.*, m.name AS material_name, m.unit, u.name AS processed_by_name FROM stock_out so JOIN materials m ON so.material_id=m.id LEFT JOIN users u ON so.processed_by=u.id WHERE $whereStr ORDER BY so.out_date DESC, so.id DESC";
$paginated=paginate($sql,$params,$page);
$materials=Database::fetchAll("SELECT id,name,unit FROM materials WHERE is_active=true ORDER BY name");
$totalOut=Database::fetchColumn("SELECT COALESCE(SUM(so.quantity),0) FROM stock_out so JOIN materials m ON so.material_id=m.id WHERE $whereStr",$params);
include __DIR__.'/../../partials/head.php';
?>
<div class="admin-wrapper">
<?php include __DIR__.'/../../partials/sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__.'/../../partials/topbar.php'; ?>
<div class="page-body">
<div class="flex-between mb-20">
  <div class="section-header" style="margin-bottom:0;"><h2>Barang Keluar</h2><p>Total <?= formatNumber($totalOut) ?> unit didistribusikan</p></div>
  <a href="<?= APP_URL ?>/admin-ab/modules/stock_out/create.php" class="btn btn-primary"><i class="fas fa-plus"></i> Catat Barang Keluar</a>
</div>
<div class="card mb-20">
  <div class="card-body" style="padding:16px 20px;">
    <form method="GET" class="filter-bar">
      <div class="search-box"><i class="fas fa-search search-icon"></i><input type="text" name="search" class="form-control" placeholder="Cari material, tujuan, driver..." value="<?= htmlspecialchars($search) ?>"></div>
      <select name="material_id" class="form-control" style="width:180px;"><option value="">Semua Material</option><?php foreach($materials as $m):?><option value="<?=$m['id']?>" <?=$matId==$m['id']?'selected':''?>><?=htmlspecialchars($m['name'])?></option><?php endforeach;?></select>
      <input type="date" name="date_from" class="form-control" value="<?=$dateFrom?>" style="width:150px;">
      <input type="date" name="date_to"   class="form-control" value="<?=$dateTo?>"   style="width:150px;">
      <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i></button>
      <?php if($search||$matId||$dateFrom||$dateTo):?><a href="?" class="btn btn-secondary"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-body" style="padding:0;">
    <?php if(empty($paginated['items'])):?>
      <div class="empty-state"><div class="empty-state-icon"><i class="fas fa-arrow-up"></i></div><div class="empty-state-title">Belum ada data barang keluar</div><a href="<?=APP_URL?>/admin-ab/modules/stock_out/create.php" class="btn btn-primary" style="margin-top:16px;"><i class="fas fa-plus"></i> Catat Sekarang</a></div>
    <?php else:?>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>No</th><th>Tanggal</th><th>Material</th><th>Jumlah</th><th>Pelanggan</th><th>Tujuan</th><th>Driver</th><th>Kendaraan</th><th>Oleh</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php foreach($paginated['items'] as $i=>$row):?>
            <tr>
              <td style="color:var(--text-muted);font-size:12px;"><?=($page-1)*PER_PAGE+$i+1?></td>
              <td style="font-size:13px;"><?=formatDate($row['out_date'])?></td>
              <td style="font-weight:600;font-size:13.5px;"><?=htmlspecialchars($row['material_name'])?></td>
              <td style="font-weight:700;color:var(--danger);"><?=formatNumber($row['quantity'])?> <span style="font-size:12px;font-weight:400;color:var(--text-muted);"><?=$row['unit']?></span></td>
              <td style="font-size:13px;"><?=htmlspecialchars($row['customer_name']??'-')?></td>
<td style="font-size:13px;max-width:160px;" class="truncate"><?=htmlspecialchars($row['destination']??'-')?></td>
              <td style="font-size:13px;"><?=htmlspecialchars($row['driver_name']??'-')?></td>
              <td style="font-size:12px;" class="text-mono"><?=htmlspecialchars($row['vehicle_number']??'-')?></td>
              <td style="font-size:13px;"><?=htmlspecialchars($row['processed_by_name']??'-')?></td>
<td>
  <div class="actions">
    <!-- Tombol Cetak Surat Jalan -->
    <?php if ($row['order_id']): ?>
      <a href="<?=APP_URL?>/admin-ab/modules/stock_out/print_sj.php?type=order&id=<?=$row['order_id']?>" target="_blank" class="btn btn-sm btn-primary" data-tooltip="Cetak Surat Jalan"><i class="fas fa-print"></i></a>
      <a href="<?=APP_URL?>/admin-ab/modules/orders/view.php?id=<?=$row['order_id']?>" class="btn btn-sm btn-info" data-tooltip="Lihat Pesanan"><i class="fas fa-eye"></i></a>
    <?php else: ?>
      <a href="<?=APP_URL?>/admin-ab/modules/stock_out/print_sj.php?type=manual&id=<?=$row['id']?>" target="_blank" class="btn btn-sm btn-primary" data-tooltip="Cetak Surat Jalan"><i class="fas fa-print"></i></a>
      <a href="<?=APP_URL?>/admin-ab/modules/stock_out/edit.php?id=<?=$row['id']?>" class="btn btn-sm btn-secondary" data-tooltip="Edit Manual"><i class="fas fa-edit"></i></a>
      <button onclick="confirmDelete('<?=APP_URL?>/admin-ab/modules/stock_out/delete.php?id=<?=$row['id']?>&token=<?=csrfToken()?>','distribusi ini')" class="btn btn-sm btn-danger" data-tooltip="Hapus"><i class="fas fa-trash"></i></button>
    <?php endif; ?>
  </div>
</td>
            </tr>
            <?php endforeach;?>
          </tbody>
        </table>
      </div>
      <?php if($paginated['pages']>1):?>
      <div class="card-footer" style="justify-content:space-between;">
        <span style="font-size:13px;color:var(--text-muted);">Halaman <?=$page?> dari <?=$paginated['pages']?></span>
        <div class="pagination"><?php for($p=1;$p<=$paginated['pages'];$p++):?><a href="?page=<?=$p?>&search=<?=urlencode($search)?>&material_id=<?=$matId?>&date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>" class="page-btn <?=$p==$page?'active':''?>"><?=$p?></a><?php endfor;?></div>
      </div>
      <?php endif;?>
    <?php endif;?>
  </div>
</div>
</div>
<?php include __DIR__.'/../../partials/footer.php'; ?>
