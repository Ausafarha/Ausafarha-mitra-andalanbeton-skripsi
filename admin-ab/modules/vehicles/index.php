<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/app.php';
initSession();
requireLogin();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$pageTitle    = 'Kelola Kendaraan';
$pageSubtitle = 'Daftar armada truk dan kendaraan operasional';

$search = get('search');
$page   = max(1, getInt('page', 1));

$params = [];
$where  = ['1=1'];

if ($search) {
    $where[]  = "(plate_number ILIKE ? OR vehicle_type ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$whereStr = implode(' AND ', $where);

$sql = "
    SELECT id, plate_number, vehicle_type, is_active, created_at
    FROM vehicles
    WHERE $whereStr
    ORDER BY plate_number ASC
";

$paginated = paginate($sql, $params, $page);
$vehicles  = $paginated['items'];

include __DIR__ . '/../../partials/head.php';
?>

<div class="admin-wrapper">
<?php include __DIR__ . '/../../partials/sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__ . '/../../partials/topbar.php'; ?>
<div class="page-body">

<div class="flex-between mb-20">
  <div class="section-header" style="margin-bottom:0;">
    <h2>Daftar Kendaraan</h2>
    <p>Total <?= $paginated['total'] ?> kendaraan terdaftar</p>
  </div>
  <a href="<?= APP_URL ?>/admin-ab/modules/vehicles/create.php" class="btn btn-primary">
    <i class="fas fa-plus"></i> Tambah Kendaraan
  </a>
</div>

<div class="card mb-20">
  <div class="card-body" style="padding: 16px 20px;">
    <form method="GET" class="filter-bar">
      <div class="search-box" style="flex:1;">
        <input type="text" name="search" class="form-control" placeholder="Cari plat nomor atau jenis kendaraan..." value="<?= htmlspecialchars($search) ?>">
        <i class="fas fa-search search-icon"></i>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Cari</button>
      <?php if ($search): ?>
        <a href="<?= APP_URL ?>/admin-ab/modules/vehicles/index.php" class="btn btn-secondary"><i class="fas fa-times"></i> Reset</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:0;">
    <?php if (empty($vehicles)): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-truck"></i></div>
        <div class="empty-state-title">Tidak ada kendaraan ditemukan</div>
        <div class="empty-state-desc">Silakan tambahkan data kendaraan baru untuk operasional pengiriman.</div>
        <a href="<?= APP_URL ?>/admin-ab/modules/vehicles/create.php" class="btn btn-primary" style="margin-top:16px;"><i class="fas fa-plus"></i> Tambah Kendaraan</a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead>
              <tr>
                  <th>No</th>
                  <th>Plat Nomor</th>
                  <th>Jenis Kendaraan</th>
                  <th>Status</th>
                  <th>Aksi</th>
              </tr>
          </thead>
          <tbody>
              <?php $no = ($page - 1) * 10 + 1; foreach ($vehicles as $v): ?>
              <tr>
                  <td><?= $no++ ?></td>
                  <td><span class="text-mono" style="font-weight:700; background:var(--bg-muted); padding:3px 8px; border-radius:4px;"><?= htmlspecialchars($v['plate_number']) ?></span></td>
                  <td><?= htmlspecialchars($v['vehicle_type'] ?? '-') ?></td>
                  <td>
                      <?php if ($v['is_active']): ?>
                          <span class="badge badge-info">Aktif</span>
                      <?php else: ?>
                          <span class="badge badge-secondary">Nonaktif</span>
                      <?php endif; ?>
                  </td>
                  <td>
                      <div class="actions">
                          <a href="<?= APP_URL ?>/admin-ab/modules/vehicles/edit.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-secondary" data-tooltip="Edit"><i class="fas fa-edit"></i></a>
                          <button onclick="confirmDelete('<?= APP_URL ?>/admin-ab/modules/vehicles/delete.php?id=<?= $v['id'] ?>&token=<?= csrfToken() ?>','<?= htmlspecialchars(addslashes($v['plate_number'])) ?>')" class="btn btn-sm btn-danger" data-tooltip="Hapus"><i class="fas fa-trash"></i></button>
                      </div>
                  </td>
              </tr>
              <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($paginated['pages'] > 1): ?>
      <div class="card-footer" style="justify-content:space-between; flex-wrap: wrap; gap: 10px;">
        <span style="font-size:13px;color:var(--text-muted);">Halaman <?= $page ?> dari <?= $paginated['pages'] ?> (<?= $paginated['total'] ?> data)</span>
        <div class="pagination">
          <?php for ($p = 1; $p <= $paginated['pages']; $p++): ?>
            <a href="?page=<?= $p ?>&search=<?= urlencode($search) ?>" class="page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

</div> </div> </div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>