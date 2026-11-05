<?php
session_start();
include "koneksi.php";
date_default_timezone_set('Asia/Jakarta');

define('QR_SECRET_KEY', 'RSMN-GANTI-STRING-RAHASIA-INI-2026');
$QR_SESSION_WINDOW = 300; // samain dengan yang di index.php

function tandaiTokenTerpakai($t) {
    global $conn;
    $t   = (int) $t;
    $sid = session_id(); // beda tiap device/browser yang lagi absen

    // Kalau t_value sudah ada (primary key/unique), mysqli di PHP 8.1+
    // MELEMPAR exception (bukan return false seperti mysql_query lama),
    // jadi harus ditangkap -> artinya token sudah dipakai.
    try {
        $stmt = $conn->prepare(
            "INSERT INTO qr_token_used (t_value, session_id, used_at) VALUES (?, ?, NOW())"
        );
        $stmt->bind_param('is', $t, $sid);
        $stmt->execute();
        $ok = ($stmt->affected_rows > 0);
        $stmt->close();
        return $ok;
    } catch (mysqli_sql_exception $e) {
        return false;
    }
}

header('Content-Type: application/json');

// Kalau session ini sudah keburu valid duluan (request kembar yang lebih cepat),
// anggap SUKSES juga.
if (!empty($_SESSION['qr_ok'])
    && (time() - (int)($_SESSION['qr_ok_time'] ?? 0) <= $QR_SESSION_WINDOW)) {
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