<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/app.php';
initSession(); requireLogin();
$pageTitle='Manajemen Pesanan'; $pageSubtitle='Kelola pesanan masuk dari website';
$search=get('search'); $status=get('status'); $page=max(1,getInt('page',1));
$params=[]; $where=['1=1'];
if ($search){$where[]="(o.customer_name ILIKE ? OR o.order_number ILIKE ? OR o.customer_phone ILIKE ?)";$params[]="%$search%";$params[]="%$search%";$params[]="%$search%";}
if ($status){$where[]="o.status=?";$params[]=$status;}
$whereStr=implode(' AND ',$where);
$sql="SELECT o.*, u.name AS processed_by_name FROM orders o LEFT JOIN users u ON o.processed_by=u.id WHERE $whereStr ORDER BY CASE o.status WHEN 'pending' THEN 1 WHEN 'processing' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END, o.created_at DESC";
$paginated=paginate($sql,$params,$page);
$counts=['all'=>Database::fetchColumn("SELECT COUNT(*) FROM orders"),'pending'=>Database::fetchColumn("SELECT COUNT(*) FROM orders WHERE status='pending'"),'processing'=>Database::fetchColumn("SELECT COUNT(*) FROM orders WHERE status='processing'"),'completed'=>Database::fetchColumn("SELECT COUNT(*) FROM orders WHERE status='completed'"),'rejected'=>Database::fetchColumn("SELECT COUNT(*) FROM orders WHERE status='rejected'")];
include __DIR__.'/../../partials/head.php';
?>
<div class="admin-wrapper">
<?php include __DIR__.'/../../partials/sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__.'/../../partials/topbar.php'; ?>
<div class="page-body">

<div class="flex-between mb-20">
  <div class="section-header" style="margin-bottom:0;"><h2>Daftar Pesanan</h2><p>Kelola semua pesanan dari pelanggan</p></div>
  <a href="<?=APP_URL?>/admin-ab/modules/orders/create.php" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah Pesanan Offline</a>
</div>

<!-- Status tabs -->
<div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap;">
  <?php foreach([['','Semua',$counts['all'],''],['pending','Menunggu',$counts['pending'],'var(--warning)'],['processing','Diproses',$counts['processing'],'var(--info)'],['completed','Selesai',$counts['completed'],'var(--success)'],['rejected','Ditolak',$counts['rejected'],'var(--danger)']] as [$val,$lbl,$cnt,$clr]):?>
  <a href="?status=<?=$val?>&search=<?=urlencode($search)?>" style="display:flex;align-items:center;gap:7px;padding:8px 16px;border-radius:var(--radius-md);font-size:13.5px;font-weight:600;text-decoration:none;border:1.5px solid var(--border);background:<?=$status===$val?'var(--brand-600)':'var(--bg-surface)'?>;color:<?=$status===$val?'white':'var(--text-secondary)'?>;transition:all .18s;">
    <?=$lbl?> <span style="background:<?=$status===$val?'rgba(255,255,255,0.2)':'var(--bg-muted)'?>;padding:1px 8px;border-radius:20px;font-size:12px;"><?=$cnt?></span>
  </a>
  <?php endforeach;?>
</div>

<div class="card mb-20">
  <div class="card-body" style="padding:14px 20px;">
    <form method="GET" class="filter-bar">
      <input type="hidden" name="status" value="<?=htmlspecialchars($status)?>">
      <div class="search-box"><i class="fas fa-search search-icon"></i><input type="text" name="search" class="form-control" placeholder="Cari nama, nomor pesanan, HP..." value="<?=htmlspecialchars($search)?>"></div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Cari</button>
      <?php if($search):?><a href="?status=<?=htmlspecialchars($status)?>" class="btn btn-secondary"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:0;">
    <?php if(empty($paginated['items'])):?>
      <div class="empty-state"><div class="empty-state-icon"><i class="fas fa-clipboard-list"></i></div><div class="empty-state-title">Tidak ada pesanan ditemukan</div></div>
    <?php else:?>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>No. Pesanan</th>
              <th>Pelanggan</th>
              <th>HP</th>
              <th>Total</th>
              <th>Status</th>
              <th>Tgl. Kirim</th> <!-- Kolom baru tanggal pengiriman -->
              <th>Tgl. Pesan</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($paginated['items'] as $ord):?>
            <tr>
              <td><span class="text-mono" style="font-size:12px;font-weight:600;"><?=htmlspecialchars($ord['order_number'])?></span></td>
              <td style="font-weight:600;font-size:13.5px;max-width:160px;" class="truncate"><?=htmlspecialchars($ord['customer_name'])?></td>
              <td style="font-size:13px;"><?=htmlspecialchars($ord['customer_phone'])?></td>
              <td style="font-weight:700;font-size:13.5px;"><?=formatRupiah($ord['total_amount'])?></td>
              <td><?=orderStatusLabel($ord['status'])?></td>
              <!-- Menampilkan Tanggal Pengiriman -->
              <td style="font-size:12.5px;font-weight:600;color:var(--brand-600);">
                <?= !empty($ord['delivery_date']) ? date('d M Y', strtotime($ord['delivery_date'])) : '-' ?>
              </td>
              <td style="font-size:12px;color:var(--text-muted);"><?=formatDate($ord['created_at'],'d M Y H:i')?></td>
              <td>
                <div class="actions">
                  <a href="<?=APP_URL?>/admin-ab/modules/orders/view.php?id=<?=$ord['id']?>" class="btn btn-sm btn-primary" data-tooltip="Detail"><i class="fas fa-eye"></i></a>
                  <button onclick="confirmDelete('<?=APP_URL?>/admin-ab/modules/orders/delete.php?id=<?=$ord['id']?>&token=<?=csrfToken()?>','pesanan <?=htmlspecialchars(addslashes($ord['order_number']))?>')" class="btn btn-sm btn-danger" data-tooltip="Hapus"><i class="fas fa-trash"></i></button>
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
        <div class="pagination"><?php for($p=1;$p<=$paginated['pages'];$p++):?><a href="?page=<?=$p?>&status=<?=urlencode($status)?>&search=<?=urlencode($search)?>" class="page-btn <?=$p==$page?'active':''?>"><?=$p?></a><?php endfor;?></div>
      </div>
      <?php endif;?>
    <?php endif;?>
  </div>
</div>
</div>
<?php include __DIR__.'/../../partials/footer.php'; ?>