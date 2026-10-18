<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/app.php';
initSession(); requireLogin();

$id = getInt('id'); 
$token = get('token');

if (!hash_equals(csrfToken(), $token)) {
    setFlash('error', 'Token tidak valid.'); 
    redirect(APP_URL.'/admin-ab/modules/stock_out/index.php'); 
}

$row = Database::fetchOne("SELECT * FROM stock_out WHERE id=?", [$id]);
if (!$row) {
    setFlash('error', 'Data tidak ditemukan.'); 
    redirect(APP_URL.'/admin-ab/modules/stock_out/index.php'); 
}

// 🛡️ PROTEKSI: Cek apakah barang keluar ini berasal dari Pesanan (order_id tidak kosong)
if (!empty($row['order_id'])) {
    setFlash('error', 'Gagal menghapus! Data barang keluar ini terikat secara otomatis dengan Pesanan. Silakan kelola atau batalkan melalui menu Pesanan.');
    redirect(APP_URL.'/admin-ab/modules/stock_out/index.php');
}

// Jika murni barang keluar manual (non-pesanan), izinkan hapus dan kembalikan stok fisiknya ke materials
Database::query("
    UPDATE materials 
    SET current_stock = current_stock + ? 
    WHERE id = ?
", [$row['quantity'], $row['material_id']]);

// Hapus data barang keluar manual
Database::delete('stock_out', 'id=?', [$id]);

logActivity('delete', 'stock_out', "Menghapus data barang keluar manual ID:{$id}");
setFlash('success', 'Data barang keluar manual berhasil dihapus dan stok dikembalikan.');
redirect(APP_URL.'/admin-ab/modules/stock_out/index.php');