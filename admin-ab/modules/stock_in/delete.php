<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/app.php';
initSession(); requireLogin();

$id = getInt('id'); $token = get('token');
if (!hash_equals(csrfToken(),$token)) { 
    setFlash('error','Token tidak valid.'); 
    redirect(APP_URL.'/admin-ab/modules/stock_in/index.php'); 
}

// 1. Ambil data barang masuk dulu sebelum dihapus (untuk tahu material_id dan quantity-nya)
$row = Database::fetchOne("SELECT * FROM stock_in WHERE id=?",[$id]);
if (!$row) { 
    setFlash('error','Data tidak ditemukan.'); 
    redirect(APP_URL.'/admin-ab/modules/stock_in/index.php'); 
}

$materialId = $row['material_id'];
$qtyToDelete = $row['quantity'];

// 2. Kurangi stok di tabel materials karena barang masuk ini dibatalkan/dihapus
Database::query("
    UPDATE materials 
    SET current_stock = current_stock - ? 
    WHERE id = ?
", [$qtyToDelete, $materialId]);

// 3. Hapus data dari tabel stock_in
Database::delete('stock_in','id=?',[$id]);

logActivity('delete','stock_in',"Menghapus data barang masuk ID:{$id} (Stok dikurangi: {$qtyToDelete})");
setFlash('success','Data barang masuk berhasil dihapus dan stok telah disesuaikan.');
redirect(APP_URL.'/admin-ab/modules/stock_in/index.php');