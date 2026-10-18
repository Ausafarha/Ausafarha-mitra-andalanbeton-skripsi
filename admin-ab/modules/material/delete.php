<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/app.php';
initSession();
requireLogin();

$id    = getInt('id');
$token = get('token');

if (!hash_equals(csrfToken(), $token)) {
    setFlash('error', 'Token keamanan tidak valid.');
    redirect(APP_URL . '/admin-ab/modules/material/index.php');
}

$mat = Database::fetchOne("SELECT * FROM materials WHERE id = ?", [$id]);
if (!$mat) {
    setFlash('error', 'Material tidak ditemukan.');
    redirect(APP_URL . '/admin-ab/modules/material/index.php');
}

// Check if material has stock transactions
$hasTransactions = Database::fetchColumn(
    "SELECT COUNT(*) FROM stock_in WHERE material_id = ?", [$id]
);

if ($hasTransactions > 0) {
    setFlash('error', 'Material tidak dapat dihapus karena memiliki riwayat transaksi. Nonaktifkan material sebagai gantinya.');
    redirect(APP_URL . '/admin-ab/modules/material/index.php');
}
// Hapus dari Cloudinary jika image adalah URL Cloudinary
if ($mat['image'] && str_contains($mat['image'], 'cloudinary.com')) {
    // Extract public_id dari URL
    $parts = explode('/upload/', $mat['image']);
    $publicId = 'andalan-beton/products/' . pathinfo($parts[1], PATHINFO_FILENAME);
    deleteFromCloudinary($publicId);
}
// Delete image file
if ($mat['image']) deleteFile($mat['image']);

Database::delete('materials', 'id = ?', [$id]);
logActivity('delete', 'materials', "Menghapus material: {$mat['name']}");
setFlash('success', "Material \"{$mat['name']}\" berhasil dihapus.");
redirect(APP_URL . '/admin-ab/modules/material/index.php');
