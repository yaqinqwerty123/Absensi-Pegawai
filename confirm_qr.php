<?php
session_start();
include "koneksi.php";
date_default_timezone_set('Asia/Jakarta');

define('QR_SECRET_KEY', 'RSMN-GANTI-STRING-RAHASIA-INI-2026');
$QR_SESSION_WINDOW = 300; // samain dengan yang di index.php



function tandaiTokenTerpakai($t) {
    $t   = (int) $t;
    $sid = session_id(); // beda tiap device/browser yang lagi absen

    @mysql_query("INSERT INTO qr_token_used (t_value, session_id, used_at) 
                  VALUES ('".$t."', '".mysql_real_escape_string($sid)."', NOW())");

    return (mysql_affected_rows() > 0);
}

header('Content-Type: application/json');

// PENTING: kalau session ini udah keburu valid duluan (misal ada request
// kembar/dobel yang lebih cepat consume token-nya), anggap ini SUKSES juga.
// Tanpa ini, request kedua yang telat dikit bakal salah dianggap gagal
// padahal token untuk session yang sama udah berhasil di-consume.
if (isset($_SESSION['qr_ok']) && $_SESSION['qr_ok']
    && (time() - $_SESSION['qr_ok_time'] <= $QR_SESSION_WINDOW)) {
    echo json_encode(array('result' => 'true'));
    exit;
}

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