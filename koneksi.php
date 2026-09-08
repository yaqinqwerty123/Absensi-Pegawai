<?php
// ==========================================================
// KONEKSI DATABASE (native mysql_*)
// Kredensial disamakan dengan config CodeIgniter (database.php)
// hostname : 192.168.0.233
// database : rsmn_sdm
// ==========================================================

$host   = "192.168.0.233";
$user   = "umum";
$pass   = "RSmndb2020";
$dbname = "rsmn_sdm";

$conn = mysql_connect($host, $user, $pass);

if (!$conn) {
    die("Koneksi ke database gagal: " . mysql_error());
}

$pilih_db = mysql_select_db($dbname, $conn);

if (!$pilih_db) {
    die("Database '$dbname' tidak ditemukan: " . mysql_error());
}

// samain charset dengan config CI (char_set: utf8)
mysql_query("SET NAMES 'utf8'", $conn);
?>
