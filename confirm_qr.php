<?php
session_start();
include "koneksi.php";
date_default_timezone_set('Asia/Jakarta');

// WAJIB SAMA PERSIS dengan QR_SECRET_KEY di index.php
define('QR_SECRET_KEY', 'RSMN-GANTI-STRING-RAHASIA-INI-2026');

function tandaiTokenTerpakai($t) {
    $t = (int) $t;
    @mysql_query("INSERT INTO qr_token_used (t_value, used_at) VALUES ('".$t."', NOW())");
    return (mysql_affected_rows() > 0);
}

header('Content-Type: application/json');

if (isset($_SESSION['qr_pending_t'])) {
    $t = $_SESSION['qr_pending_t'];

    if (tandaiTokenTerpakai($t)) {
        $_SESSION['qr_ok']      = true;
        $_SESSION['qr_ok_time'] = time();
        unset($_SESSION['qr_pending_t']);
        echo json_encode(array('result' => 'true'));
    } else {
        unset($_SESSION['qr_pending_t']);
        echo json_encode(array('result' => 'false'));
    }
} else {
    echo json_encode(array('result' => 'false'));
}