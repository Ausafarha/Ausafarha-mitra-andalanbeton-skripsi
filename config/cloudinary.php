<?php
// ============================================================
// config/cloudinary.php
// Cloudinary Upload Helper - ANDALAN BETON
// ============================================================

require_once __DIR__ . '/../vendor/autoload.php';

use Cloudinary\Cloudinary;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// ⚠️ BYPASS SSL - HANYA UNTUK LOKAL DEVELOPMENT!
// JANGAN PAKAI INI DI PRODUCTION (RENDER)
$arrContextOptions = [
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
];
stream_context_set_default($arrContextOptions);

$cloudinary = new Cloudinary([
    'cloud' => [
        'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'],
        'api_key'    => $_ENV['CLOUDINARY_API_KEY'],
        'api_secret' => $_ENV['CLOUDINARY_API_SECRET'],
    ],
    'url' => [
        'secure' => true
    ]
]);

function uploadToCloudinary($file, $folder = 'products') {
    global $cloudinary;
    
    try {
        $result = $cloudinary->uploadApi()->upload($file['tmp_name'], [
            'folder' => "mitra-andalanbeton/{$folder}",
            'allowed_formats' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_bytes' => 5 * 1024 * 1024
        ]);
        
        return [
            'success' => true,
            'url' => $result['secure_url'],
            'public_id' => $result['public_id']
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Upload ke Cloudinary gagal: ' . $e->getMessage()
        ];
    }
}

function deleteFromCloudinary($publicId) {
    global $cloudinary;
    
    try {
        $cloudinary->uploadApi()->destroy($publicId);
        return true;
    } catch (Exception $e) {
        error_log('Cloudinary delete error: ' . $e->getMessage());
        return false;
    }
}