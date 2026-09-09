<?php
session_start();
include "koneksi.php";

date_default_timezone_set('Asia/Jakarta');

// ===============================
// KONFIGURASI RADIUS ABSENSI
// ===============================
// Radius ini dihitung dari titik lokasi EVENT yang dipilih pegawai
// (kolom latitude_longitude di tabel list_event_rs), bukan titik tetap.
$MAX_RADIUS = 200; // meter

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

// ambil pesan hasil proses absen sebelumnya (dititipkan via session sebelum redirect)
// supaya kalau halaman ini di-refresh, yang keulang cuma GET biasa, bukan POST lagi
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
// NOTE: kalau mau tampilkan event hari ini SAJA, tinggal tambah
// "AND date = CURDATE()" di WHERE clause di bawah.
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

    // VALIDASI INPUT
    if ($hp=='' || $pass_raw=='' || $id_event=='') {
        $error = "Semua field wajib diisi";
    }

    // VALIDASI DEVICE ID
    // Kalau device_id ga kekirim (JS gagal jalan / localStorage diblok), tolak absen
    // daripada bikin lubang buat lewatin pengecekan 1-device-1x ini.
    if ($error == '' && $device_id == '') {
        $error = "ID perangkat tidak terbaca. Muat ulang halaman lalu coba lagi";
    }

    // VALIDASI LOKASI GPS
    if ($error == '' && (!is_numeric($lat) || !is_numeric($lng))) {
        $error = "Lokasi tidak terbaca, aktifkan GPS";
    }

    // CEK PEGAWAI: NO HP + PASSWORD
    // Skema hash & normalisasi HP disamakan persis dengan Login_model::log_model()
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

    // AMBIL DATA EVENT TERPILIH (buat cek titik lokasi)
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

    // VALIDASI JENDELA WAKTU ABSEN
    // Absen dibuka mulai 1 jam SEBELUM jam mulai event,
    // dan ditutup begitu lewat jam selesai event.
    if ($error == '' && $eventData) {
        $eventDate   = $eventData['date'];     // format Y-m-d
        $jamMulai    = $eventData['mulai'];    // format H:i:s
        $jamSelesai  = $eventData['selesai'];  // format H:i:s

        if (!empty($jamMulai) && !empty($jamSelesai)) {
            $mulaiTimestamp   = strtotime($eventDate . ' ' . $jamMulai);
            $selesaiTimestamp = strtotime($eventDate . ' ' . $jamSelesai);
            $bukaTimestamp    = $mulaiTimestamp - 3600; // H-1 jam
            $nowTimestamp     = strtotime($nowDatetime);

            if ($nowTimestamp < $bukaTimestamp) {
                $error = "Absen baru dibuka pukul " . date('H:i', $bukaTimestamp)
                    . " (1 jam sebelum acara dimulai)";
            } elseif ($nowTimestamp > $selesaiTimestamp) {
                $error = "Absen sudah ditutup, acara telah selesai pukul " . date('H:i', $selesaiTimestamp);
            }
        }
    }

    // VALIDASI JARAK KE LOKASI EVENT
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

    // CEK APAKAH SUDAH PERNAH ABSEN DI EVENT INI (by akun)
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

    // CEK APAKAH PERANGKAT INI SUDAH PERNAH DIPAKAI ABSEN DI EVENT INI
    // Ini yang nahan orang absenin temennya pakai HP yang sama.
    // Dicek terpisah dari cek akun di atas, supaya ke-block walau akunnya beda.
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

    // SIMPAN ABSENSI
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

    // titipkan hasil ke session, lalu redirect (Post-Redirect-Get)
    // biar kalau halaman ini di-refresh, POST-nya ga keulang & alert ga muncul lagi sendiri
    $_SESSION['absen_error']  = $error;
    $_SESSION['absen_sukses'] = $sukses;
    header('Location: ' . $_SERVER['PHP_SELF']);
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

.wrapper {
    width: 100%;
    max-width: 420px;
}

.brand {
    text-align: center;
    color: #fff;
    margin-bottom: 20px;
}

.brand .logo-circle {
    width: 64px;
    height: 64px;
    margin: 0 auto 12px;
    border-radius: 50%;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 24px rgba(0,0,0,.4);
}

.brand .logo-circle i {
    font-size: 28px;
    color: var(--navy);
}

.brand h1 {
    font-size: 18px;
    font-weight: 700;
    margin: 0 0 2px;
    letter-spacing: .3px;
    text-shadow: 0 2px 8px rgba(0,0,0,.45);
}

.brand p {
    font-size: 12.5px;
    margin: 0;
    color: rgba(255,255,255,.85);
    text-shadow: 0 1px 6px rgba(0,0,0,.4);
}

.card {
    background: #fff;
    padding: 28px 24px 26px;
    border-radius: 20px;
    box-shadow: 0 20px 45px rgba(0,0,0,.25);
    animation: fadeIn .45s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: none; }
}

.card h2 {
    text-align: center;
    font-size: 17px;
    font-weight: 600;
    color: #1a1a1a;
    margin: 0 0 4px;
}

.card .subtitle {
    text-align: center;
    font-size: 12.5px;
    color: #8a93a3;
    margin: 0 0 22px;
}

.field {
    margin-bottom: 16px;
}

.field label {
    display: block;
    font-size: 12.5px;
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 6px;
}

.input-group {
    position: relative;
    display: flex;
    align-items: center;
    border: 1.5px solid #E3E8EF;
    border-radius: 12px;
    background: var(--bg-soft);
    transition: border-color .15s ease, box-shadow .15s ease;
}

.input-group:focus-within {
    border-color: var(--navy);
    box-shadow: 0 0 0 3px rgba(12,68,124,.12);
    background: #fff;
}

.input-group .icon {
    width: 44px;
    text-align: center;
    color: #97A3B6;
    font-size: 15px;
    flex-shrink: 0;
}

.input-group input,
.input-group select {
    flex: 1;
    border: none;
    background: transparent;
    outline: none;
    padding: 13px 12px 13px 0;
    font-size: 14.5px;
    font-family: inherit;
    color: #1f2937;
    width: 100%;
    appearance: none;
    -webkit-appearance: none;
}

.input-group select {
    padding-right: 12px;
    cursor: pointer;
}

.input-group .toggle-pass {
    width: 44px;
    text-align: center;
    color: #97A3B6;
    cursor: pointer;
    font-size: 15px;
    flex-shrink: 0;
    background: none;
    border: none;
}

.select-wrap {
    position: relative;
}

.select-wrap::after {
    content: "\f078";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #97A3B6;
    font-size: 11px;
    pointer-events: none;
}

.btn-absen {
    width: 100%;
    padding: 15px;
    border: none;
    border-radius: 12px;
    font-size: 15.5px;
    font-weight: 700;
    letter-spacing: .3px;
    background: linear-gradient(135deg, var(--navy), var(--navy-dark));
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 10px 20px rgba(12,68,124,.28);
    transition: transform .1s ease, opacity .15s ease;
    margin-top: 6px;
}

.btn-absen:active {
    transform: scale(.98);
}

.btn-absen:disabled {
    opacity: .65;
    cursor: not-allowed;
}

.gps-note {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    color: #97A3B6;
    justify-content: center;
    margin-top: 14px;
}

.footer-note {
    text-align: center;
    color: rgba(255,255,255,.55);
    font-size: 11.5px;
    margin-top: 18px;
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
// ===============================
// DEVICE ID (1 device = 1x absen per event)
// ===============================
// Disimpan di localStorage (utama) + cookie umur panjang (cadangan).
// ID ini dibuat SEKALI per perangkat/browser dan dipakai terus tiap absen,
// jadi server bisa nolak kalau device yang sama dipakai buat absenin
// akun lain di event yang sama.
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
    // fallback buat browser lama yang ga support crypto.randomUUID
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c){
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}

function getDeviceId() {
    let id = null;

    try {
        id = localStorage.getItem('rsmn_device_id');
    } catch (e) {
        // localStorage bisa diblok di beberapa mode browser tertentu
    }

    if (!id) {
        id = getCookie('rsmn_device_id');
    }

    if (!id) {
        id = generateUUID();
    }

    try {
        localStorage.setItem('rsmn_device_id', id);
    } catch (e) {}

    setCookie('rsmn_device_id', id, 3650); // simpan 10 tahun

    return id;
}

document.getElementById('device_id').value = getDeviceId();

// Toggle show/hide password
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

    // pastikan device_id selalu ke-set ulang tiap submit (jaga-jaga)
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