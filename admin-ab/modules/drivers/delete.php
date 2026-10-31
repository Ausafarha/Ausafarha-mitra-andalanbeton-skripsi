<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/app.php';
initSession();
requireLogin();

$id = getInt('id');
$token = get('token');

// Validasi token CSRF dari parameter URL (GET)
if (empty($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    setFlash('error', 'Token keamanan tidak valid.');
    redirect(APP_URL . '/admin-ab/modules/drivers/index.php');
}

// Gunakan fetchOne sesuai database.php
$driver = Database::fetchOne("SELECT name FROM drivers WHERE id = ?", [$id]);
if ($driver) {
    // Sesuaikan parameter delete: nama_tabel, string kondisi, array parameter[cite: 4]
    Database::delete('drivers', 'id = ?', [$id]);
    logActivity('delete', 'drivers', "Menghapus supir: {$driver['name']}");
    setFlash('success', "Supir \"{$driver['name']}\" berhasil dihapus.");
} else {
    setFlash('error', 'Data supir tidak ditemukan.');
}

redirect(APP_URL . '/admin-ab/modules/drivers/index.php');