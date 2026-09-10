<?php
session_start();
include "koneksi.php";

date_default_timezone_set('Asia/Jakarta');

// ===============================
// PROTEKSI QR CODE (anti screenshot / replay)
// ===============================
// PENTING: QR_SECRET_KEY di bawah ini WAJIB SAMA PERSIS dengan
// QR_SECRET_KEY di controller Listevent.php (panel admin).
// Kalau beda dikit aja, token ga akan pernah valid.
define('QR_SECRET_KEY', 'RSMN-GANTI-STRING-RAHASIA-INI-2026');

$QR_TOKEN_WINDOW   = 10;  // toleransi (detik) umur token pas di-scan
$QR_SESSION_WINDOW = 300; // berapa lama (detik) sesi dianggap "sudah scan QR" - 5 menit

function cekTokenQR($t, $k, $window) {
    if (!is_numeric($t) || empty($k)) return false;

    $expected = hash_hmac('sha256', $t, QR_SECRET_KEY);
    $tokenCocok = hash_equals($expected, (string)$k);
    $umur = time() - (int)$t;

    // ==== DEBUG SEMENTARA: hapus/comment lagi kalau udah kelar troubleshooting ====
    @file_put_contents(__DIR__ . '/qr_debug.log',
        date('Y-m-d H:i:s') . " | t=$t | server_time=" . time() .
        " | umur_detik=$umur | window=$window | token_cocok=" . ($tokenCocok ? 'YA' : 'TIDAK') .
        " | lolos_window=" . ((abs($umur) <= $window) ? 'YA' : 'TIDAK') . "\n",
        FILE_APPEND
    );
    // ==== END DEBUG ====

    if (!$tokenCocok) return false;

    return (abs($umur) <= $window);
}

// ===============================
// SATU TOKEN = SATU KALI PAKAI
// ===============================
// Kalau cuma ngandelin window waktu (di atas), token yang sama masih
// bisa dipakai berkali-kali selama masih dalam rentang detiknya.
// Itu celahnya: screenshot -> kirim ke temen -> temen scan dalam
// hitungan detik -> tetap keitung "masih fresh".
//
// Makanya di sini token yang SAMA PERSIS (nilai t yang sama) cuma
// boleh berhasil di-consume SEKALI. Percobaan kedua dst pakai t yang
// sama bakal ditolak, walaupun secara waktu masih "valid".
function tandaiTokenTerpakai($t) {
    $t = (int) $t;

    // insert bakal gagal kalau t_value ini udah pernah ada (primary key)
    // @ dipakai buat nyenyepin warning duplicate-key dari mysql_query()
    @mysql_query("INSERT INTO qr_token_used (t_value, used_at) VALUES ('".$t."', NOW())");

    return (mysql_affected_rows() > 0);
}

// kalau URL bawa token QR: validasi window waktu DULU, baru cek "udah pernah dipakai belum"
if (isset($_GET['t'], $_GET['k'])) {

    if (cekTokenQR($_GET['t'], $_GET['k'], $QR_TOKEN_WINDOW)) {

        if (tandaiTokenTerpakai($_GET['t'])) {
            // token fresh & belum pernah dipakai sebelumnya -> sah
            $_SESSION['qr_ok']      = true;
            $_SESSION['qr_ok_time'] = time();

            // redirect ke URL bersih (tanpa ?t=&k=) supaya:
            // 1) token ga nyangkut di address bar / history
            // 2) kalau halaman ini di-refresh nanti, ga nyoba consume token yang
            //    sama lagi (yang bakal ke-reject karena udah "terpakai")
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        // kalau sampai sini: token secara waktu masih valid, TAPI udah pernah
        // dipakai sebelumnya -> kemungkinan besar ini hasil screenshot/forward.
        // sengaja dibiarkan jatuh ke pengecekan $qrValid di bawah (bakal gagal).
    }
}

$qrValid = isset($_SESSION['qr_ok']) && $_SESSION['qr_ok']
    && (time() - $_SESSION['qr_ok_time'] <= $QR_SESSION_WINDOW);

if (!$qrValid) {
    unset($_SESSION['qr_ok'], $_SESSION['qr_ok_time']);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Scan QR Diperlukan</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <style>
            body{font-family:Arial,sans-serif;background:#0C447C;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px;margin:0;}
            .box{max-width:380px;}
            .box i{font-size:48px;margin-bottom:16px;}
            .box h2{margin:0 0 10px;font-size:20px;}
            .box p{font-size:14px;color:rgba(255,255,255,.85);line-height:1.6;}
        </style>
    </head>
    <body>
        <div class="box">
            <i class="fa-solid fa-qrcode"></i>
            <h2>Scan QR Diperlukan</h2>
            <p>Halaman absen hanya bisa diakses dengan scan QR Code yang aktif di layar panitia. QR berganti tiap 5 detik dan hanya bisa dipakai SEKALI, jadi tautan lama / hasil screenshot / kiriman dari orang lain tidak akan berfungsi.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ===============================
// KONFIGURASI RADIUS ABSENSI
// ===============================
$MAX_RADIUS = 700; // meter

function hitungJarak($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

$nowDatetime = date('Y-m-d H:i:s');
$today       = date('Y-m-d');

$error  = '';
$sukses = '';

if (isset($_SESSION['absen_error'])) {
    $error = $_SESSION['absen_error'];
    unset($_SESSION['absen_error']);
}
if (isset($_SESSION['absen_sukses'])) {
    $sukses = $_SESSION['absen_sukses'];
    unset($_SESSION['absen_sukses']);
}

// ===============================
// DATA EVENT (dari list_event_rs)
// ===============================
$eventOpt = '<option value="">Pilih Event</option>';
$qEvent = mysql_query("
    SELECT
        id_event,
        nama_event,
        nama_lokasi,
        latitude_longitude,
        DATE_FORMAT(date,'%d-%m-%Y') AS tgl_event,
        mulai,
        selesai
    FROM list_event_rs
    WHERE (deletemark IS NULL OR deletemark = 0)
      AND date >= CURDATE()
    ORDER BY date ASC, mulai ASC
    LIMIT 5
");
while ($ev = mysql_fetch_assoc($qEvent)) {
    $jamLabel = '';
    if (!empty($ev['mulai']) || !empty($ev['selesai'])) {
        $jamLabel = ' (' . substr($ev['mulai'],0,5) . '-' . substr($ev['selesai'],0,5) . ')';
    }
    $labelLokasi = !empty($ev['nama_lokasi']) ? ' - ' . $ev['nama_lokasi'] : '';

    $eventOpt .= '<option value="'.$ev['id_event'].'">'
        . htmlspecialchars($ev['nama_event']) . $labelLokasi
        . ' | ' . $ev['tgl_event'] . $jamLabel
        . '</option>';
}

// ===============================
// PROSES LOGIN + ABSEN
// ===============================
if (isset($_POST['absen'])) {

    $hp        = isset($_POST['hp']) ? trim($_POST['hp']) : '';
    $pass_raw  = isset($_POST['password']) ? trim($_POST['password']) : '';
    $id_event  = isset($_POST['id_event']) ? $_POST['id_event'] : '';
    $lat       = isset($_POST['latitude']) ? $_POST['latitude'] : '';
    $lng       = isset($_POST['longitude']) ? $_POST['longitude'] : '';
    $device_id = isset($_POST['device_id']) ? trim($_POST['device_id']) : '';

    if ($hp=='' || $pass_raw=='' || $id_event=='') {
        $error = "Semua field wajib diisi";
    }

    if ($error == '' && $device_id == '') {
        $error = "ID perangkat tidak terbaca. Muat ulang halaman lalu coba lagi";
    }

    if ($error == '' && (!is_numeric($lat) || !is_numeric($lng))) {
        $error = "Lokasi tidak terbaca, aktifkan GPS";
    }

    $id_pegawai = '';
    if ($error == '') {
        $passHash = md5(sha1($pass_raw));

        $qPeg = mysql_query("
            SELECT PEGAWAI_ID FROM pegawai
            WHERE REPLACE(REPLACE(HP, '.', ''), '-', '') = '".mysql_real_escape_string($hp)."'
            AND PASSWORD = '".mysql_real_escape_string($passHash)."'
            LIMIT 1
        ");

        if (mysql_num_rows($qPeg)==0) {
            $error = "No HP atau password salah";
        } else {
            $peg = mysql_fetch_assoc($qPeg);
            $id_pegawai = $peg['PEGAWAI_ID'];
        }
    }

    $eventData = null;
    if ($error == '') {
        $qEv = mysql_query("
            SELECT * FROM list_event_rs
            WHERE id_event='".mysql_real_escape_string($id_event)."'
            AND (deletemark IS NULL OR deletemark = 0)
            LIMIT 1
        ");

        if (mysql_num_rows($qEv)==0) {
            $error = "Event tidak ditemukan";
        } else {
            $eventData = mysql_fetch_assoc($qEv);
        }
    }

    if ($error == '' && $eventData) {
        $eventDate   = $eventData['date'];
        $jamMulai    = $eventData['mulai'];
        $jamSelesai  = $eventData['selesai'];

        if (!empty($jamMulai) && !empty($jamSelesai)) {
            $mulaiTimestamp   = strtotime($eventDate . ' ' . $jamMulai);
            $selesaiTimestamp = strtotime($eventDate . ' ' . $jamSelesai);
            $bukaTimestamp    = $mulaiTimestamp - 3600;
            $nowTimestamp     = strtotime($nowDatetime);

            if ($nowTimestamp < $bukaTimestamp) {
                $error = "Absen baru dibuka pukul " . date('H:i', $bukaTimestamp)
                    . " (1 jam sebelum acara dimulai)";
            } elseif ($nowTimestamp > $selesaiTimestamp) {
                $error = "Absen sudah ditutup, acara telah selesai pukul " . date('H:i', $selesaiTimestamp);
            }
        }
    }

    if ($error == '' && $eventData) {
        $koordinat = explode(',', $eventData['latitude_longitude']);

        if (count($koordinat) == 2) {
            $eventLat = trim($koordinat[0]);
            $eventLng = trim($koordinat[1]);

            $jarak = hitungJarak($eventLat, $eventLng, $lat, $lng);
            if ($jarak > $MAX_RADIUS) {
                $error = "Anda berada di luar lokasi event (±" . round($jarak) . " m)";
            }
        } else {
            $error = "Lokasi event belum diset dengan benar";
        }
    }

    if ($error == '') {
        $cek = mysql_query("
            SELECT id_absensi_pegawai FROM absensi_pegawai
            WHERE pegawai_id='".mysql_real_escape_string($id_pegawai)."'
            AND event_id='".mysql_real_escape_string($id_event)."'
            AND (deletemark IS NULL OR deletemark = 0)
        ");

        if (mysql_num_rows($cek) > 0) {
            $error = "Anda sudah absen pada event ini";
        }
    }

    if ($error == '') {
        $cekDevice = mysql_query("
            SELECT id_absensi_pegawai FROM absensi_pegawai
            WHERE device_id='".mysql_real_escape_string($device_id)."'
            AND event_id='".mysql_real_escape_string($id_event)."'
            AND (deletemark IS NULL OR deletemark = 0)
        ");

        if (mysql_num_rows($cekDevice) > 0) {
            $error = "Perangkat ini sudah digunakan untuk absen pada event ini";
        }
    }

    if ($error == '') {
        mysql_query("
            INSERT INTO absensi_pegawai (pegawai_id, event_id, tanggal_absen, device_id, deletemark)
            VALUES (
                '".mysql_real_escape_string($id_pegawai)."',
                '".mysql_real_escape_string($id_event)."',
                '$nowDatetime',
                '".mysql_real_escape_string($device_id)."',
                0
            )
        ");
        $sukses = "Absen berhasil dicatat";
    }

    $_SESSION['absen_error']  = $error;
    $_SESSION['absen_sukses'] = $sukses;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Presensi Pegawai - RSUD Mohammad Noer</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
:root {
    --navy: #0C447C;
    --navy-dark: #082F57;
    --accent: #3FA34B;
    --bg-soft: #F3F6FA;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
    font-family: 'Poppins', 'Segoe UI', Arial, sans-serif;
    background-image:
        linear-gradient(180deg, rgba(6,33,61,.35) 0%, rgba(6,33,61,.55) 45%, rgba(6,33,61,.75) 100%),
        url('assets/bg-rsmn.jpg');
    background-size: cover;
    background-position: center top;
    background-repeat: no-repeat;
    background-attachment: fixed;
}

.wrapper { width: 100%; max-width: 420px; }
.brand { text-align: center; color: #fff; margin-bottom: 20px; }
.brand .logo-circle {
    width: 64px; height: 64px; margin: 0 auto 12px;
    border-radius: 50%; background: #fff;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 8px 24px rgba(0,0,0,.4);
}
.brand .logo-circle i { font-size: 28px; color: var(--navy); }
.brand h1 {
    font-size: 18px; font-weight: 700; margin: 0 0 2px; letter-spacing: .3px;
    text-shadow: 0 2px 8px rgba(0,0,0,.45);
}
.brand p {
    font-size: 12.5px; margin: 0; color: rgba(255,255,255,.85);
    text-shadow: 0 1px 6px rgba(0,0,0,.4);
}
.card {
    background: #fff; padding: 28px 24px 26px; border-radius: 20px;
    box-shadow: 0 20px 45px rgba(0,0,0,.25); animation: fadeIn .45s ease;
}
@keyframes fadeIn { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: none; } }
.card h2 { text-align: center; font-size: 17px; font-weight: 600; color: #1a1a1a; margin: 0 0 4px; }
.card .subtitle { text-align: center; font-size: 12.5px; color: #8a93a3; margin: 0 0 22px; }
.field { margin-bottom: 16px; }
.field label { display: block; font-size: 12.5px; font-weight: 600; color: #4a5568; margin-bottom: 6px; }
.input-group {
    position: relative; display: flex; align-items: center;
    border: 1.5px solid #E3E8EF; border-radius: 12px; background: var(--bg-soft);
    transition: border-color .15s ease, box-shadow .15s ease;
}
.input-group:focus-within {
    border-color: var(--navy); box-shadow: 0 0 0 3px rgba(12,68,124,.12); background: #fff;
}
.input-group .icon { width: 44px; text-align: center; color: #97A3B6; font-size: 15px; flex-shrink: 0; }
.input-group input, .input-group select {
    flex: 1; border: none; background: transparent; outline: none;
    padding: 13px 12px 13px 0; font-size: 14.5px; font-family: inherit;
    color: #1f2937; width: 100%; appearance: none; -webkit-appearance: none;
}
.input-group select { padding-right: 12px; cursor: pointer; }
.input-group .toggle-pass {
    width: 44px; text-align: center; color: #97A3B6; cursor: pointer;
    font-size: 15px; flex-shrink: 0; background: none; border: none;
}
.select-wrap { position: relative; }
.select-wrap::after {
    content: "\f078"; font-family: "Font Awesome 6 Free"; font-weight: 900;
    position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
    color: #97A3B6; font-size: 11px; pointer-events: none;
}
.btn-absen {
    width: 100%; padding: 15px; border: none; border-radius: 12px;
    font-size: 15.5px; font-weight: 700; letter-spacing: .3px;
    background: linear-gradient(135deg, var(--navy), var(--navy-dark)); color: #fff;
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
    box-shadow: 0 10px 20px rgba(12,68,124,.28);
    transition: transform .1s ease, opacity .15s ease; margin-top: 6px;
}
.btn-absen:active { transform: scale(.98); }
.btn-absen:disabled { opacity: .65; cursor: not-allowed; }
.gps-note {
    display: flex; align-items: center; gap: 6px; font-size: 11.5px;
    color: #97A3B6; justify-content: center; margin-top: 14px;
}
.footer-note {
    text-align: center; color: rgba(255,255,255,.55); font-size: 11.5px; margin-top: 18px;
}
</style>
</head>
<body>

<div class="wrapper">

    <div class="brand">
        <div class="logo-circle">
            <i class="fa-solid fa-hospital"></i>
        </div>
        <h1>RSUD Mohammad Noer</h1>
        <p>Sistem Presensi Pegawai</p>
    </div>

    <div class="card">
        <h2>Absen Kehadiran</h2>
        <p class="subtitle">Isi data di bawah untuk mencatat kehadiran Anda</p>

        <form method="post" id="formAbsen">
            <input type="hidden" name="latitude" id="latitude">
            <input type="hidden" name="longitude" id="longitude">
            <input type="hidden" name="device_id" id="device_id">
            <input type="hidden" name="absen" value="1">

            <div class="field">
                <label>No. HP</label>
                <div class="input-group">
                    <span class="icon"><i class="fa-solid fa-phone"></i></span>
                    <input type="text" name="hp" inputmode="numeric" placeholder="Contoh: 0812xxxxxxx" required>
                </div>
            </div>

            <div class="field">
                <label>Password</label>
                <div class="input-group">
                    <span class="icon"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" name="password" id="passwordInput" placeholder="Masukkan password" required>
                    <button type="button" class="toggle-pass" id="togglePass">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="field">
                <label>Pilih Event</label>
                <div class="input-group select-wrap">
                    <span class="icon"><i class="fa-solid fa-calendar-days"></i></span>
                    <select name="id_event" required>
                        <?php echo $eventOpt; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn-absen" id="btnAbsen">
                <i class="fa-solid fa-location-dot"></i>
                ABSEN SEKARANG
            </button>

            <div class="gps-note">
                <i class="fa-solid fa-satellite-dish"></i>
                Pastikan GPS/Lokasi HP Anda aktif
            </div>
        </form>
    </div>

    <div class="footer-note">&copy; <?php echo date('Y'); ?> RSUD Mohammad Noer</div>
</div>

<script>
function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? decodeURIComponent(match[2]) : null;
}

function setCookie(name, value, days) {
    const d = new Date();
    d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
}

function generateUUID() {
    if (window.crypto && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c){
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}

function getDeviceId() {
    let id = null;
    try { id = localStorage.getItem('rsmn_device_id'); } catch (e) {}
    if (!id) { id = getCookie('rsmn_device_id'); }
    if (!id) { id = generateUUID(); }
    try { localStorage.setItem('rsmn_device_id', id); } catch (e) {}
    setCookie('rsmn_device_id', id, 3650);
    return id;
}

document.getElementById('device_id').value = getDeviceId();

document.getElementById('togglePass').addEventListener('click', function(){
    var input = document.getElementById('passwordInput');
    var icon  = this.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
});

const form = document.getElementById('formAbsen');
const btn  = document.getElementById('btnAbsen');
let isSubmitting = false;

form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (isSubmitting) return;

    document.getElementById('device_id').value = getDeviceId();

    if (!navigator.geolocation) {
        Swal.fire('Error', 'Browser tidak mendukung GPS', 'error');
        return;
    }

    isSubmitting = true;
    btn.disabled = true;

    Swal.fire({
        title: 'Mengambil lokasi...',
        text: 'Pastikan GPS aktif',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    navigator.geolocation.getCurrentPosition(
        function (pos) {
            const accuracy = pos.coords.accuracy;

            if (accuracy > 1000) {
                isSubmitting = false;
                btn.disabled = false;
                Swal.close();
                Swal.fire(
                    'Error',
                    'Akurasi GPS buruk (' + Math.round(accuracy) + 'm). Aktifkan GPS & tunggu sinyal stabil.',
                    'error'
                );
                return;
            }

            document.getElementById('latitude').value  = pos.coords.latitude;
            document.getElementById('longitude').value = pos.coords.longitude;

            Swal.close();
            form.submit();
        },
        function (err) {
            isSubmitting = false;
            btn.disabled = false;
            Swal.close();

            let msg = 'Gagal mengambil lokasi';
            if (err.code === 1) msg = 'Izin lokasi ditolak';
            if (err.code === 2) msg = 'Lokasi tidak tersedia';
            if (err.code === 3) msg = 'GPS timeout, coba lagi';

            Swal.fire('Error', msg, 'error');
        },
        {
            enableHighAccuracy: true,
            timeout: 20000,
            maximumAge: 0
        }
    );
});
</script>

<?php if ($error!='') { ?>
<script>
Swal.fire({ icon:'error', title:'Absen Gagal', text:'<?= addslashes($error) ?>' });
</script>
<?php } ?>

<?php if ($sukses!='') { ?>
<script>
Swal.fire({ icon:'success', text:'<?= addslashes($sukses) ?>' });
</script>
<?php } ?>

</body>
</html>