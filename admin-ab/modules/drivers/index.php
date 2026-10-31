<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/app.php';
initSession();
requireLogin();

// Kunci cache browser
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$pageTitle    = 'Kelola Supir';
$pageSubtitle = 'Daftar supir armada pengiriman';

$search = get('search');
$page   = max(1, getInt('page', 1));

$params = [];
$where  = ['1=1'];

if ($search) {
    $where[]  = "(name ILIKE ? OR phone ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$whereStr = implode(' AND ', $where);

$sql = "
    SELECT id, name, phone, is_active, created_at
    FROM drivers
    WHERE $whereStr
    ORDER BY name ASC
";

$paginated = paginate($sql, $params, $page);
$drivers   = $paginated['items'];

include __DIR__ . '/../../partials/head.php';
?>

<div class="admin-wrapper">
<?php include __DIR__ . '/../../partials/sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__ . '/../../partials/topbar.php'; ?>
<div class="page-body">

<div class="flex-between mb-20">
  <div class="section-header" style="margin-bottom:0;">
    <h2>Daftar Supir</h2>
    <p>Total <?= $paginated['total'] ?> supir terdaftar</p>
  </div>
  <a href="<?= APP_URL ?>/admin-ab/modules/drivers/create.php" class="btn btn-primary">
    <i class="fas fa-plus"></i> Tambah Supir
  </a>
</div>

<div class="card mb-20">
  <div class="card-body" style="padding: 16px 20px;">
    <form method="GET" class="filter-bar">
      <div class="search-box" style="flex:1;">
        <input type="text" name="search" class="form-control" placeholder="Cari nama atau nomor HP supir..." value="<?= htmlspecialchars($search) ?>">
        <i class="fas fa-search search-icon"></i>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Cari</button>
      <?php if ($search): ?>
        <a href="<?= APP_URL ?>/admin-ab/modules/drivers/index.php" class="btn btn-secondary"><i class="fas fa-times"></i> Reset</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:0;">
    <?php if (empty($drivers)): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-id-card"></i></div>
        <div class="empty-state-title">Tidak ada supir ditemukan</div>
        <div class="empty-state-desc">Silakan tambahkan data supir baru untuk mulai menjadwalkan pengiriman.</div>
        <a href="<?= APP_URL ?>/admin-ab/modules/drivers/create.php" class="btn btn-primary" style="margin-top:16px;"><i class="fas fa-plus"></i> Tambah Supir</a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead>
              <tr>
                  <th>No</th>
                  <th>Nama Supir</th>
                  <th>Nomor Telepon / WhatsApp</th>
                  <th>Status</th>
                  <th>Aksi</th>
              </tr>
          </thead>
          <tbody>
              <?php $no = ($page - 1) * 10 + 1; foreach ($drivers as $d): ?>
              <tr>
                  <td><?= $no++ ?></td>
                  <td style="font-weight:600;"><?= htmlspecialchars($d['name']) ?></td>
                  <td><?= htmlspecialchars($d['phone'] ?? '-') ?></td>
                  <td>
                      <?php if ($d['is_active']): ?>
                          <span class="badge badge-info">Aktif</span>
                      <?php else: ?>
                          <span class="badge badge-secondary">Nonaktif</span>
                      <?php endif; ?>
                  </td>
                  <td>
                      <div class="actions">
                          <a href="<?= APP_URL ?>/admin-ab/modules/drivers/edit.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-secondary" data-tooltip="Edit"><i class="fas fa-edit"></i></a>
                          <button onclick="confirmDelete('<?= APP_URL ?>/admin-ab/modules/drivers/delete.php?id=<?= $d['id'] ?>&token=<?= csrfToken() ?>','<?= htmlspecialchars(addslashes($d['name'])) ?>')" class="btn btn-sm btn-danger" data-tooltip="Hapus"><i class="fas fa-trash"></i></button>
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