<?php
// admin/partials/sidebar.php
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));

// ===== FUNGSI isActive YANG LEBIH AKURAT =====
function isActive($targetDir, $targetPages = []) {
    global $currentDir, $currentPage;
    
    // Jika folder saat ini sama dengan target
    if ($currentDir === $targetDir) {
        // Halaman index atau kosong -> aktif
        if ($currentPage === 'index' || $currentPage === '') {
            return 'active';
        }
        // Halaman ada di daftar targetPages -> aktif
        if (in_array($currentPage, $targetPages)) {
            return 'active';
        }
        // Halaman create/edit/view/delete dari folder yang sama -> aktif
        if (in_array($currentPage, ['create', 'edit', 'view', 'delete'])) {
            return 'active';
        }
    }
    
    return '';
}

$user = currentUser();

// Pending orders count
try {
    $pendingOrders = (int)Database::fetchColumn("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
} catch(Exception $e) { $pendingOrders = 0; }

// Low stock count
try {
    $lowStock = (int)Database::fetchColumn("SELECT COUNT(*) FROM material_stock WHERE stock_status IN ('low_stock','out_of_stock') AND is_active = true");
} catch(Exception $e) { $lowStock = 0; }
?>
<aside class="sidebar" id="sidebar">
  <?php $cp = getCompanyProfile(); ?>
  <div class="sidebar-logo">
    <?php if (!empty($cp['logo'])): ?>
      <img src="<?= uploadUrl($cp['logo']) ?>" alt="Logo" class="sidebar-logo-icon" style="object-fit: contain; background: transparent; padding: 2px; flex-shrink: 0;">
    <?php else: ?>
      <div class="sidebar-logo-icon">AB</div>
    <?php endif; ?>
    
    <div class="sidebar-logo-text">
      <div class="sidebar-logo-name" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Andalan Beton</div>
      <div class="sidebar-logo-sub">Admin Panel</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-title">Utama</div>

    <a href="<?= APP_URL ?>/admin-ab/dashboard.php" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
      <i class="fas fa-chart-line"></i>
      <span>Dashboard</span>
    </a>

    <div class="nav-section-title">Inventori</div>

    <a href="<?= APP_URL ?>/admin-ab/modules/material/index.php" class="nav-item <?= isActive('material', ['create', 'edit']) ?>">
      <i class="fas fa-boxes"></i>
      <span>Material</span>
      <?php if ($lowStock > 0): ?>
        <span class="nav-badge danger" data-tooltip="Stok Menipis"><?= $lowStock ?></span>
      <?php endif; ?>
    </a>

    <a href="<?= APP_URL ?>/admin-ab/modules/stock_in/index.php" class="nav-item <?= isActive('stock_in', ['create', 'edit']) ?>">
      <i class="fas fa-arrow-down"></i>
      <span>Barang Masuk</span>
    </a>

    <a href="<?= APP_URL ?>/admin-ab/modules/stock_out/index.php" class="nav-item <?= isActive('stock_out', ['create', 'edit']) ?>">
      <i class="fas fa-arrow-up"></i>
      <span>Barang Keluar</span>
    </a>

    <div class="nav-section-title">Transaksi</div>

    <a href="<?= APP_URL ?>/admin-ab/modules/orders/index.php" class="nav-item <?= isActive('orders', ['view', 'edit']) ?>">
      <i class="fas fa-clipboard-list"></i>
      <span>Pesanan</span>
      <?php if ($pendingOrders > 0): ?>
        <span class="nav-badge"><?= $pendingOrders ?></span>
      <?php endif; ?>
    </a>

    <div class="nav-section-title">Lainnya</div>

    <a href="<?= APP_URL ?>/admin-ab/modules/reports/index.php" class="nav-item <?= isActive('reports') ?>">
      <i class="fas fa-file-alt"></i>
      <span>Laporan</span>
    </a>

    

    <a href="<?= APP_URL ?>/admin-ab/modules/settings/index.php" class="nav-item <?= isActive('settings') ?>">
      <i class="fas fa-cog"></i>
      <span>Pengaturan</span>
    </a>
    

    <a href="<?= APP_URL ?>/admin-ab/modules/drivers/index.php" class="nav-item <?= isActive('drivers', ['create', 'edit']) ?>">
      <i class="fas fa-id-card"></i>
      <span>Data Supir</span>
    </a>

    <a href="<?= APP_URL ?>/admin-ab/modules/vehicles/index.php" class="nav-item <?= isActive('vehicles', ['create', 'edit']) ?>">
      <i class="fas fa-truck"></i>
      <span>Data Kendaraan</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    
    <a href="<?= APP_URL ?>/admin-ab/logout.php" class="nav-item" style="color:#f87171;">
      <i class="fas fa-sign-out-alt"></i>
      <span>Keluar</span>
    </a>
  </div>
</aside>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const links = document.querySelectorAll(".sidebar-nav .nav-item");
    const mainContent = document.querySelector(".main-content");

    if (!mainContent) return;

    links.forEach(link => {
        const url = link.getAttribute("href");
        
        // --- 1. BYPASS HALAMAN YANG BUTUH INITIALISASI BERAT (DASHBOARD & GALERI) ---
        if (url && (url.includes("logout.php") || url.includes("dashboard.php") || url.includes("modules/gallery/"))) {
            return; // Biarkan browser melakukan reload normal (F5) untuk halaman ini agar tidak bug
        }

        link.addEventListener("click", function(e) {
            if (url && !link.getAttribute("target")) {
                e.preventDefault();

                // Ubah visual menu aktif di sidebar
                links.forEach(l => l.classList.remove("active"));
                this.classList.add("active");

                // Beri efek loading halus pada konten utama
                mainContent.style.opacity = "0.5";
                mainContent.style.transition = "opacity 0.2s ease";

                // Ambil data halaman baru di latar belakang
                fetch(url)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const newContent = doc.querySelector(".main-content");

                        if (newContent) {
                            mainContent.innerHTML = newContent.innerHTML;
                            mainContent.style.opacity = "1";
                            
                            window.history.pushState({path: url}, '', url);
                            
                            // Eksekusi ulang inline script bawaan modul yang dimuat
                            newContent.querySelectorAll("script").forEach(oldScript => {
                                const newScript = document.createElement("script");
                                Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                                newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                                oldScript.parentNode.replaceChild(newScript, oldScript);
                            });
                        } else {
                            window.location.href = url;
                        }
                    })
                    .catch(err => {
                        window.location.href = url;
                    });
            }
        });
    });

    // Menangani tombol Back/Forward browser agar selalu segar
    window.addEventListener('popstate', function() {
        window.location.reload();
    });

    window.addEventListener('pageshow', function(event) {
        if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
            window.location.reload();
        }
    });
});
</script>