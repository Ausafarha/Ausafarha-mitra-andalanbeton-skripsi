<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
initSession();
requireLogin();

// Cegah caching halaman admin (biar gak bisa back)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Ringkasan Operasional Logistik';

// ── Stats ──────────────────────────────────────────────────
$totalMaterials = (int)Database::fetchColumn("SELECT COUNT(*) FROM materials WHERE is_active = true");
$totalStock     = (int)Database::fetchColumn("SELECT COALESCE(SUM(current_stock),0) FROM material_stock WHERE is_active = true");
$totalStockIn   = (int)Database::fetchColumn("SELECT COALESCE(SUM(quantity),0) FROM stock_in");
$totalStockOut  = (int)Database::fetchColumn("SELECT COALESCE(SUM(quantity),0) FROM stock_out");
$lowStockCount  = (int)Database::fetchColumn("SELECT COUNT(*) FROM material_stock WHERE stock_status IN ('low_stock','out_of_stock') AND is_active = true");

// ── GRAFIK BULANAN (12 Bulan Terakhir) ─────────────────────
$chartMonths = [];
$chartIn     = [];
$chartOut    = [];
$chartData   = [];

for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-{$i} months"));
    $lbl = date('M Y', strtotime("-{$i} months"));
    $chartMonths[] = $lbl;
    $chartData[$ym] = ['in' => 0, 'out' => 0];
}

$startDate = date('Y-m-01', strtotime("-11 months"));

// Tarik data Stock In
$rawIn = Database::fetchAll("SELECT TO_CHAR(received_date,'YYYY-MM') as ym, SUM(quantity) as total FROM stock_in WHERE received_date >= ? GROUP BY ym", [$startDate]);
foreach($rawIn as $r) { if(isset($chartData[$r['ym']])) $chartData[$r['ym']]['in'] = (int)$r['total']; }

// Tarik data Stock Out
$rawOut = Database::fetchAll("SELECT TO_CHAR(out_date,'YYYY-MM') as ym, SUM(quantity) as total FROM stock_out WHERE out_date >= ? GROUP BY ym", [$startDate]);
foreach($rawOut as $r) { if(isset($chartData[$r['ym']])) $chartData[$r['ym']]['out'] = (int)$r['total']; }

foreach($chartData as $ym => $v) {
    $chartIn[]  = $v['in'];
    $chartOut[] = $v['out'];
}

// ── GRAFIK MINGGUAN (7 Hari Terakhir) ──────────────────────
$weekDays   = [];
$weekIn     = [];
$weekOut    = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $lbl  = date('D d/m', strtotime("-{$i} days"));
    $weekDays[] = $lbl;
    $weekIn[]   = (int)Database::fetchColumn("SELECT COALESCE(SUM(quantity),0) FROM stock_in WHERE received_date=?", [$date]);
    $weekOut[]  = (int)Database::fetchColumn("SELECT COALESCE(SUM(quantity),0) FROM stock_out WHERE out_date=?", [$date]);
}

// ── Top materials (Barang Keluar Terbanyak) ────────────────
$topMaterials = Database::fetchAll("
    SELECT m.name, m.unit, COALESCE(SUM(so.quantity),0) as total_out, ms.current_stock
    FROM materials m
    LEFT JOIN stock_out so ON so.material_id = m.id
    LEFT JOIN material_stock ms ON ms.id = m.id
    WHERE m.is_active = true
    GROUP BY m.id, m.name, m.unit, ms.current_stock
    ORDER BY total_out DESC
    LIMIT 5
");

// ── Recent activities ──────────────────────────────────────
$recentActivities = Database::fetchAll("
    SELECT al.*, u.name as user_name
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    ORDER BY al.created_at DESC
    LIMIT 6
");

// ── Low stock materials ────────────────────────────────────
$lowStockMaterials = Database::fetchAll("
    SELECT * FROM material_stock
    WHERE stock_status IN ('low_stock','out_of_stock') AND is_active = true
    ORDER BY current_stock ASC
    LIMIT 5
");

include __DIR__ . '/partials/head.php';
?>

<div class="admin-wrapper">
<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="main-content">
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="page-body">

<!-- ── Stats Grid ────────────────────────────────────────── -->
<div class="grid grid-4 mb-20">
  <div class="stat-card" style="--stat-color: var(--brand-500); cursor: pointer;" onclick="window.location.href='<?= APP_URL ?>/admin-ab/modules/material/index.php'">
    <div class="stat-icon" style="background: var(--info-bg); color: var(--brand-600);"><i class="fas fa-boxes"></i></div>
    <div class="stat-content">
      <div class="stat-label">Total Material</div>
      <div class="stat-value" data-counter><?= $totalMaterials ?></div>
      <div class="stat-change up"><i class="fas fa-arrow-up"></i> Jenis material aktif</div>
    </div>
  </div>

  <div class="stat-card" style="--stat-color: var(--success); cursor: pointer;" onclick="window.location.href='<?= APP_URL ?>/admin-ab/modules/material/index.php'">
    <div class="stat-icon" style="background: var(--success-bg); color: var(--success);"><i class="fas fa-warehouse"></i></div>
    <div class="stat-content">
      <div class="stat-label">Total Stok Gudang</div>
      <div class="stat-value" data-counter><?= formatNumber($totalStock) ?></div>
      <div class="stat-change <?= $lowStockCount > 0 ? 'down' : 'up' ?>">
        <?= $lowStockCount > 0 ? "<i class='fas fa-exclamation-triangle'></i> $lowStockCount butuh restock" : "<i class='fas fa-check'></i> Stok aman" ?>
      </div>
    </div>
  </div>

  <div class="stat-card" style="--stat-color: #06b6d4; cursor: pointer;" onclick="window.location.href='<?= APP_URL ?>/admin-ab/modules/stock_in/index.php'">
    <div class="stat-icon" style="background: rgba(6,182,212,0.1); color: #06b6d4;"><i class="fas fa-arrow-down"></i></div>
    <div class="stat-content">
      <div class="stat-label">Total Barang Masuk</div>
      <div class="stat-value" data-counter><?= formatNumber($totalStockIn) ?></div>
      <div class="stat-change up">Kumulatif seluruh material</div>
    </div>
  </div>

  <div class="stat-card" style="--stat-color: #f97316; cursor: pointer;" onclick="window.location.href='<?= APP_URL ?>/admin-ab/modules/stock_out/index.php'">
    <div class="stat-icon" style="background: rgba(249,115,22,0.1); color: #f97316;"><i class="fas fa-arrow-up"></i></div>
    <div class="stat-content">
      <div class="stat-label">Total Barang Keluar</div>
      <div class="stat-value" data-counter><?= formatNumber($totalStockOut) ?></div>
      <div class="stat-change down">Kumulatif seluruh material</div>
    </div>
  </div>
</div>

<!-- ── Charts Row ─────────────────────────────────────────── -->
<div class="grid" style="grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">
  <!-- Monthly Chart -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Grafik Pergerakan Stok</div>
        <div class="card-subtitle">Barang masuk & keluar</div>
      </div>
      <select id="chart-type" class="form-control" style="width:130px; font-size:12px; padding:6px 10px;">
        <option value="monthly">12 Bulan</option>
        <option value="weekly">7 Hari Terakhir</option>
      </select>
    </div>
    <div class="card-body">
      <div class="chart-container" style="height: 260px;">
        <canvas id="mainChart"></canvas>
      </div>
    </div>
  </div>

  <!-- Low Stock Alerts -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title" style="color: var(--warning);"><i class="fas fa-exclamation-triangle"></i> Notifikasi Stok</div>
        <div class="card-subtitle">Material yang perlu diperhatikan</div>
      </div>
    </div>
    <div class="card-body" style="padding: 0;">
      <?php if (empty($lowStockMaterials)): ?>
        <div class="empty-state" style="padding:40px;">
          <div class="empty-state-icon" style="color: var(--success);"><i class="fas fa-check-circle"></i></div>
          <div class="empty-state-title">Semua stok normal</div>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table">
            <thead><tr><th>Material</th><th>Sisa</th><th>Min</th></tr></thead>
            <tbody>
              <?php foreach ($lowStockMaterials as $mat): ?>
              <tr>
                <td style="font-weight:600;"><?= htmlspecialchars($mat['name']) ?></td>
                <td style="color:<?= $mat['stock_status']=='out_of_stock'?'var(--danger)':'var(--warning)' ?>; font-weight:bold;"><?= formatNumber($mat['current_stock']) ?> <?= $mat['unit'] ?></td>
                <td><?= $mat['min_stock'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Bottom Row ─────────────────────────────────────────── -->
<div class="grid" style="grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
  <!-- Top Materials -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Material Paling Sering Keluar</div>
        <div class="card-subtitle">Berdasarkan volume distribusi</div>
      </div>
    </div>
    <div class="card-body">
      <?php if (empty($topMaterials)): ?>
        <div class="empty-state" style="padding:20px;"><div class="empty-state-title">Belum ada data</div></div>
      <?php else: ?>
        <?php
        $maxOut = max(array_column($topMaterials, 'total_out'));
        if ($maxOut == 0) $maxOut = 1;
        foreach ($topMaterials as $i => $mat):
          $pct = ($mat['total_out'] / $maxOut) * 100;
        ?>
        <div style="margin-bottom: 16px;">
          <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:5px;">
            <span style="font-size:13px; font-weight:600;"><?= htmlspecialchars($mat['name']) ?></span>
            <span style="font-size:12px; color:var(--text-muted);"><?= formatNumber($mat['total_out']) ?> <?= $mat['unit'] ?></span>
          </div>
          <div class="stock-bar">
            <div class="stock-bar-fill" style="width:<?= $pct ?>%; background: <?= ['#3b82f6','#22c55e','#f59e0b','#ef4444','#8b5cf6'][$i % 5] ?>;"></div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent Activity -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Log Aktivitas Terakhir</div>
        <div class="card-subtitle">Jejak audit admin</div>
      </div>
    </div>
    <div class="card-body" style="padding: 0;">
      <?php if (empty($recentActivities)): ?>
        <div class="empty-state" style="padding:40px;"><div class="empty-state-title">Belum ada aktivitas</div></div>
      <?php else: ?>
        <div style="padding: 16px 20px; display:flex; flex-direction:column; gap:14px;">
          <?php foreach($recentActivities as $act): ?>
          <div style="display:flex; gap:12px; align-items:flex-start;">
            <div style="width:32px; height:32px; border-radius:50%; background:var(--bg-muted); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
              <i class="fas fa-history" style="color:var(--text-muted); font-size:12px;"></i>
            </div>
            <div>
              <div style="font-size:13px; font-weight:600;"><?= htmlspecialchars($act['user_name'] ?? 'Sistem') ?></div>
              <div style="font-size:12px; color:var(--text-secondary); margin-top:2px;"><?= htmlspecialchars($act['description']) ?></div>
              <div style="font-size:11px; color:var(--text-muted); margin-top:4px;"><?= timeAgo($act['created_at']) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

</div><!-- /.page-body -->
</div><!-- /.main-content -->
</div><!-- /.admin-wrapper -->

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="<?= ASSETS_URL ?>js/main.js?v=<?= time() ?>"></script>

<script>
const monthlyData = {
  labels: <?= json_encode($chartMonths) ?>,
  in: <?= json_encode($chartIn) ?>,
  out: <?= json_encode($chartOut) ?>
};

const weeklyData = {
  labels: <?= json_encode($weekDays) ?>,
  in: <?= json_encode($weekIn) ?>,
  out: <?= json_encode($weekOut) ?>
};

function getChartColors() {
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  return {
    grid: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)',
    text: isDark ? '#6b7280' : '#9ca3af',
  };
}

let mainChart;
function buildMainChart(type = 'monthly') {
  const ctx = document.getElementById('mainChart').getContext('2d');
  const data = type === 'monthly' ? monthlyData : weeklyData;
  const cc = getChartColors();

  if (mainChart) mainChart.destroy();

  mainChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: data.labels,
      datasets: [
        {
          label: 'Barang Masuk',
          data: data.in,
          backgroundColor: 'rgba(59,130,246,0.8)',
          borderRadius: 4,
        },
        {
          label: 'Barang Keluar',
          data: data.out,
          backgroundColor: 'rgba(249,115,22,0.8)',
          borderRadius: 4,
        }
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { color: cc.text, font: { size: 12 } } },
        tooltip: { mode: 'index', intersect: false },
      },
      scales: {
        x: { ticks: { color: cc.text }, grid: { color: cc.grid } },
        y: { ticks: { color: cc.text }, grid: { color: cc.grid }, beginAtZero: true }
      },
    },
  });
}

document.getElementById('chart-type').addEventListener('change', function() { buildMainChart(this.value); });
buildMainChart('monthly');
document.getElementById('theme-toggle')?.addEventListener('click', () => { setTimeout(() => buildMainChart(document.getElementById('chart-type').value), 100); });
</script>
</body>
</html>