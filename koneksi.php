<?php
// ==========================================================
// KONEKSI DATABASE (mysqli, kompatibel PHP 8.4)
// Kredensial disamakan dengan config CodeIgniter (database.php)
// hostname : 192.168.0.233
// database : rsmn_sdm
// ==========================================================

$host   = "192.168.0.233";
$user   = "umum";
$pass   = "RSmndb2020";
$dbname = "rsmn_sdm";

// Sejak PHP 8.1 mysqli melempar exception secara default.
// Kita tangkap saat connect supaya pesan error tidak membocorkan detail server.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass, $dbname);
    // utf8mb4 = superset dari utf8 (config CI), aman untuk tabel utf8 lama
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('Koneksi DB gagal: ' . $e->getMessage());
    http_response_code(500);
    die("Koneksi ke database gagal. Hubungi admin.");
}