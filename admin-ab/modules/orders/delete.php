<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/app.php';
initSession(); requireLogin();

$id=getInt('id'); $token=get('token');
if (!hash_equals(csrfToken(),$token)){
    setFlash('error','Token tidak valid.');
    redirect(APP_URL.'/admin-ab/modules/orders/index.php');
}

$ord=Database::fetchOne("SELECT * FROM orders WHERE id=?",[$id]);
if (!$ord){
    setFlash('error','Pesanan tidak ditemukan.');
    redirect(APP_URL.'/admin-ab/modules/orders/index.php');
}

// 1. Cek apakah pesanan ini sebelumnya sudah memotong stok (status processing / completed)
if (in_array($ord['status'], ['processing', 'completed'])) {
    $items = Database::fetchAll("SELECT * FROM order_items WHERE order_id = ?", [$id]);
    
    foreach ($items as $item) {
        // Kembalikan stok ke tabel materials
        Database::query("UPDATE materials SET current_stock = current_stock + ? WHERE id = ?", [$item['quantity'], $item['material_id']]);
    }
}

// 2. Hapus data otomatis di tabel stock_out yang terikat dengan order_id ini
Database::delete('stock_out','order_id=?',[$id]);

// 3. Hapus item pesanan dan data pesanan utamanya
Database::delete('order_items','order_id=?',[$id]);
Database::delete('orders','id=?',[$id]);

logActivity('delete','orders',"Menghapus pesanan {$ord['order_number']} beserta penyesuaian stok terkait.");
setFlash('success','Pesanan berhasil dihapus dan stok telah disesuaikan kembali.');
redirect(APP_URL.'/admin-ab/modules/orders/index.php');