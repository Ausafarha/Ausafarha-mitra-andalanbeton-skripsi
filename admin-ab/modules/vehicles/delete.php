<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/app.php';
initSession();
requireLogin();

$id = getInt('id');
$token = get('token');

if (empty($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    setFlash('error', 'Token keamanan tidak valid.');
    redirect(APP_URL . '/admin-ab/modules/vehicles/index.php');
}

$vehicle = Database::fetchOne("SELECT plate_number FROM vehicles WHERE id = ?", [$id]);
if ($vehicle) {
    Database::delete('vehicles', 'id = ?', [$id]);
    logActivity('delete', 'vehicles', "Menghapus kendaraan: {$vehicle['plate_number']}");
    setFlash('success', "Kendaraan \"{$vehicle['plate_number']}\" berhasil dihapus.");
} else {
    setFlash('error', 'Data kendaraan tidak ditemukan.');
}

redirect(APP_URL . '/admin-ab/modules/vehicles/index.php');