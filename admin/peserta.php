<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireRole('admin');

/* Status seleksi peserta (keputusan admin, ditentukan manual dari menu ini).
   Halaman Hasil & Nilai hanya menampilkan status ini. */
const STATUS_SELEKSI = [
    'diterima'    => 'Diterima',
    'disimpan'    => 'Disimpan',
    'tidak_lolos' => 'Tidak Lolos',
];

/**
 * Ubah nilai input tanggal-jam ("2026-09-25T09:00") menjadi format DATETIME MySQL
 * ("2026-09-25 09:00:00"). Return null kalau kosong / format tidak valid (= akun langsung aktif).
 */
function parseDatetimeLocal(?string $input): ?string {
    $input = trim((string)$input);
    if ($input === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $input) ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $input);
    return $dt ? $dt->format('Y-m-d H:i:00') : null;
}

/**
 * Validasi rentang masa aktif akun. Return pesan error, atau null kalau valid.
 * $cekLewat = true hanya untuk akun BARU (batas akhir tidak boleh sudah lewat).
 */
function validasiJadwalAkun(?string $mulai, ?string $sampai, bool $cekLewat): ?string {
    if ($sampai === null) return null;
    $tsSampai = strtotime($sampai);
    if ($mulai !== null && $tsSampai <= strtotime($mulai)) {
        return 'Batas aktif akun ("Aktif Sampai") harus setelah waktu "Aktif Mulai".';
    }
    if ($cekLewat && $tsSampai <= time()) {
        return 'Batas aktif akun ("Aktif Sampai") sudah lewat. Pilih tanggal & jam yang akan datang.';
    }
    return null;
}

/** Usia (tahun) dari tanggal lahir, null kalau tidak diisi / tidak valid. */
function hitungUsiaTahun(?string $tanggalLahir): ?int {
    if (empty($tanggalLahir)) return null;
    try {
        return (new DateTime($tanggalLahir))->diff(new DateTime('today'))->y;
    } catch (Throwable $e) {
        return null;
    }
}

/** Kirim email dengan aman: exception SMTP tidak boleh menghentikan halaman. */
function kirimEmailAman(...$args): bool {
    try {
        return (bool)kirimEmailAkunPeserta(...$args);
    } catch (Throwable $e) {
        error_log('Gagal kirim email peserta: ' . $e->getMessage());
        return false;
    }
}

/** Dropdown status seleksi per peserta (disimpan lewat AJAX). */
function selectStatusSeleksi(int $userId, ?string $nilai): string {
    $html = '<select class="form-select form-select-sm sel-seleksi" data-user="' . $userId . '" aria-label="Status seleksi">';
    $html .= '<option value=""' . ($nilai === null || $nilai === '' ? ' selected' : '') . '>Belum ditentukan</option>';
    foreach (STATUS_SELEKSI as $val => $label) {
        $html .= '<option value="' . $val . '"' . ($nilai === $val ? ' selected' : '') . '>' . $label . '</option>';
    }
    return $html . '</select>';
}

// Handle Aksi Form (Tambah / Edit / Hapus Peserta / Ubah Status Seleksi)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['action'] ?? '';

    // ---------- UBAH STATUS SELEKSI (AJAX) ----------
    if ($aksi === 'ubah_status_seleksi') {
        header('Content-Type: application/json; charset=UTF-8');
        $uid = (int)($_POST['peserta_id'] ?? 0);
        $st = (string)($_POST['status'] ?? '');

        if ($uid <= 0 || ($st !== '' && !isset(STATUS_SELEKSI[$st]))) {
            http_response_code(422);
            echo json_encode(['ok' => false]);
            exit;
        }

        getDB()->prepare("UPDATE users SET status_seleksi = ? WHERE id = ? AND role = 'peserta'")
               ->execute([$st === '' ? null : $st, $uid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---------- TAMBAH ----------
    if ($aksi === 'tambah_peserta') {
        $nama = trim($_POST['nama'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $jabatan = trim($_POST['jabatan_dilamar'] ?? '');
        $tanggalLahir = trim($_POST['tanggal_lahir'] ?? '');
        $tanggalLahir = $tanggalLahir !== '' ? $tanggalLahir : null;
        $aktifMulai = parseDatetimeLocal($_POST['aktif_mulai'] ?? '');
        $aktifSampai = parseDatetimeLocal($_POST['aktif_sampai'] ?? '');

        $errJadwal = validasiJadwalAkun($aktifMulai, $aktifSampai, true);
        if ($errJadwal) {
            flash('peserta_error', $errJadwal);
            header('Location: peserta.php');
            exit;
        }

        // Password boleh dikosongkan -> sistem generate otomatis (lalu dikirim via email)
        $passwordDikirim = $password !== '' ? $password : substr(bin2hex(random_bytes(5)), 0, 8);

        if ($nama && $email) {
            try {
                $stmt = getDB()->prepare("
                    INSERT INTO users (nama, email, password, role, jabatan_dilamar, tanggal_lahir, aktif_mulai, aktif_sampai, created_at)
                    VALUES (?, ?, ?, 'peserta', ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $nama, $email, password_hash($passwordDikirim, PASSWORD_BCRYPT),
                    $jabatan ?: null, $tanggalLahir, $aktifMulai, $aktifSampai
                ]);
            } catch (PDOException $e) {
                flash('peserta_error', $e->getCode() == 23000
                    ? 'Email "' . $email . '" sudah terdaftar.'
                    : 'Gagal menyimpan ke database: ' . $e->getMessage());
                header('Location: peserta.php');
                exit;
            }

            $terkirim = kirimEmailAman($nama, $email, $passwordDikirim, $jabatan ?: null, $aktifMulai, $aktifSampai);

            header('Location: peserta.php?success=added&mail=' . ($terkirim ? '1' : '0'));
            exit;
        }
    }

    // ---------- EDIT ----------
    // Password opsional (kosong = tidak diubah, kecuali kirim email dicentang)
    if ($aksi === 'edit_peserta') {
        $id = (int)($_POST['peserta_id'] ?? 0);
        $nama = trim($_POST['nama'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $jabatan = trim($_POST['jabatan_dilamar'] ?? '');
        $tanggalLahir = trim($_POST['tanggal_lahir'] ?? '');
        $tanggalLahir = $tanggalLahir !== '' ? $tanggalLahir : null;
        $aktifMulai = parseDatetimeLocal($_POST['aktif_mulai'] ?? '');
        $aktifSampai = parseDatetimeLocal($_POST['aktif_sampai'] ?? '');
        $kirimEmail = !empty($_POST['kirim_email']);

        // Saat edit, batas yang sudah lewat TIDAK ditolak (akun otomatis jadi/tetap gugur).
        $errJadwal = validasiJadwalAkun($aktifMulai, $aktifSampai, false);
        if ($errJadwal) {
            flash('peserta_error', $errJadwal);
            header('Location: peserta.php');
            exit;
        }
        // Kalau batas akhir diperpanjang (atau dihapus), akun yang sudah 'gugur' diaktifkan lagi
        $reaktif = ($aktifSampai === null || strtotime($aktifSampai) > time()) ? 1 : 0;

        if ($id > 0 && $nama && $email) {
            $db = getDB();

            // Password lama tidak bisa dibaca ulang (hash), jadi kalau email dikirim
            // dan password dikosongkan -> generate password baru.
            $passwordDikirim = $password;
            if ($kirimEmail && $passwordDikirim === '') {
                $passwordDikirim = substr(bin2hex(random_bytes(5)), 0, 8);
            }

            try {
                if ($passwordDikirim !== '') {
                    $stmt = $db->prepare("
                        UPDATE users
                        SET nama = ?, email = ?, password = ?, jabatan_dilamar = ?, tanggal_lahir = ?, aktif_mulai = ?, aktif_sampai = ?,
                            status = IF(status = 'gugur' AND ? = 1, 'aktif', status)
                        WHERE id = ? AND role = 'peserta'
                    ");
                    $stmt->execute([$nama, $email, password_hash($passwordDikirim, PASSWORD_BCRYPT), $jabatan ?: null, $tanggalLahir, $aktifMulai, $aktifSampai, $reaktif, $id]);
                } else {
                    $stmt = $db->prepare("
                        UPDATE users
                        SET nama = ?, email = ?, jabatan_dilamar = ?, tanggal_lahir = ?, aktif_mulai = ?, aktif_sampai = ?,
                            status = IF(status = 'gugur' AND ? = 1, 'aktif', status)
                        WHERE id = ? AND role = 'peserta'
                    ");
                    $stmt->execute([$nama, $email, $jabatan ?: null, $tanggalLahir, $aktifMulai, $aktifSampai, $reaktif, $id]);
                }
            } catch (PDOException $e) {
                flash('peserta_error', $e->getCode() == 23000
                    ? 'Email "' . $email . '" sudah dipakai peserta lain.'
                    : 'Gagal menyimpan ke database: ' . $e->getMessage());
                header('Location: peserta.php');
                exit;
            }

            if ($kirimEmail) {
                $terkirim = kirimEmailAman($nama, $email, $passwordDikirim, $jabatan ?: null, $aktifMulai, $aktifSampai, true);
                header('Location: peserta.php?success=updated&mail=' . ($terkirim ? '1' : '0'));
            } else {
                header('Location: peserta.php?success=updated');
            }
            exit;
        }
    }

    // ---------- HAPUS ----------
    if ($aksi === 'hapus_peserta') {
        $id = (int)($_POST['peserta_id'] ?? 0);
        if ($id > 0) {
            $stmt = getDB()->prepare("DELETE FROM users WHERE id = ? AND role = 'peserta'");
            $stmt->execute([$id]);
            header('Location: peserta.php?success=deleted');
            exit;
        }
    }
}

$pesertaList = getAllPesertaWithStats();

// Data untuk Export CSV — diambil dari data mentah (BUKAN dari teks tabel di layar)
// supaya tiap kolom terpisah rapi: nama & email sendiri-sendiri, angka tetap angka.
// Key array sama dengan $pesertaList, dipetakan ke baris tabel lewat atribut data-export-idx.
// Kolom TERAKHIR (Status Seleksi) ikut diperbarui oleh JS saat dropdown diubah.
$exportRows = [];
foreach ($pesertaList as $idx => $p) {
    // Jadwal aktif akun
    $jadwalTeks = empty($p['aktif_mulai']) ? 'Langsung aktif' : formatTanggal($p['aktif_mulai']);

    // Batas aktif akun
    if (empty($p['aktif_sampai'])) {
        $batasTeks = 'Tidak ada batas';
    } elseif ((($p['status'] ?? '') === 'gugur') || time() > strtotime($p['aktif_sampai'])) {
        $batasTeks = 'Gugur (batas ' . formatTanggal($p['aktif_sampai']) . ')';
    } else {
        $batasTeks = 'Sampai ' . formatTanggal($p['aktif_sampai']);
    }

    $exportRows[$idx] = [
        $p['nama'],
        $p['email'],
        $p['jabatan_dilamar'] ?? '',
        !empty($p['tanggal_lahir']) ? date('Y-m-d', strtotime($p['tanggal_lahir'])) : '',
        $jadwalTeks,
        $batasTeks,
        (int)$p['total_tes_diambil'],
        (int)$p['sedang_berlangsung'],
        (int)$p['selesai'],
        STATUS_SELEKSI[$p['status_seleksi'] ?? ''] ?? 'Belum ditentukan',
    ];
}

$pageTitle = 'Peserta & Aktivitas';
$pageDesc = 'Kelola akun peserta, tentukan status seleksi, dan pantau pengerjaan tes secara terpusat';
$activeMenu = 'peserta';
include __DIR__ . '/../includes/layout_top.php';

// Hitung total statistik ringkas
$totalPeserta = count($pesertaList);
$totalSedangTes = array_sum(array_map(fn($p) => (int)$p['sedang_berlangsung'], $pesertaList));
$totalSelesai = array_sum(array_map(fn($p) => (int)$p['selesai'], $pesertaList));

// Pesan error (flash dibaca SEKALI di sini supaya bisa dipakai oleh HTML & JS)
$errPeserta = flash('peserta_error');
?>

<!-- Flatpickr (pemilih tanggal & jam) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
        --card-border-radius: 16px;
    }

    /* Stat Cards */
    .stat-card {
        border: 1px solid rgba(226, 232, 240, 0.8);
        border-radius: var(--card-border-radius);
        background: #ffffff;
        transition: all 0.25s ease;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
    }
    .stat-icon {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }

    /* Main Table Container */
    .main-card {
        border: 1px solid rgba(226, 232, 240, 0.8);
        border-radius: var(--card-border-radius);
        box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.03);
    }

    /* Avatar styling */
    .avatar-circle {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
        color: #3730a3;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.9rem;
        letter-spacing: 0.5px;
    }

    /* Badges Modern */
    .badge-soft {
        padding: 5px 12px;
        border-radius: 30px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .badge-soft-ongoing {
        background-color: #eff6ff;
        color: #2563eb;
        border: 1px solid #bfdbfe;
    }
    .badge-soft-done {
        background-color: #f0fdf4;
        color: #16a34a;
        border: 1px solid #bbf7d0;
    }
    .badge-jabatan {
        background-color: #fcf2ff;
        color: #9333ea;
        border: 1px solid #f3e8ff;
        padding: 4px 10px;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    /* Dropdown status seleksi (warna mengikuti pilihan) */
    .sel-seleksi {
        font-size: 0.78rem;
        font-weight: 700;
        border-radius: 20px;
        padding: 5px 28px 5px 12px;
        min-width: 150px;
        cursor: pointer;
        border: 1px solid #e2e8f0;
        background-color: #f8fafc;
        color: #64748b;
        transition: all 0.2s ease;
    }
    .sel-seleksi:focus { box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15); }
    .sel-seleksi.s-diterima    { background-color: #ecfdf5; color: #047857; border-color: #a7f3d0; }
    .sel-seleksi.s-disimpan    { background-color: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .sel-seleksi.s-tidak_lolos { background-color: #fef2f2; color: #b91c1c; border-color: #fecaca; }
    .sel-seleksi:disabled { opacity: .6; }

    /* Badge status ONLINE (peserta sedang akses web) */
    .online-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: .68rem;
        font-weight: 700;
        color: #16a34a;
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        padding: 2px 8px;
        border-radius: 20px;
        margin-top: 3px;
    }
    .online-badge .dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #22c55e;
        animation: onlinePulse 1.5s infinite;
    }
    @keyframes onlinePulse {
        0%   { box-shadow: 0 0 0 0 rgba(34, 197, 94, .6); }
        70%  { box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
        100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
    }
    /* Titik hijau kecil di avatar saat online */
    .avatar-wrap { position: relative; flex-shrink: 0; }
    .avatar-online-dot {
        position: absolute;
        right: -3px;
        bottom: -3px;
        width: 13px;
        height: 13px;
        border-radius: 50%;
        background: #22c55e;
        border: 2px solid #fff;
        display: none;
    }
    .avatar-wrap.is-online .avatar-online-dot { display: block; }

    /* Table Styling (diringkas supaya tidak perlu geser) */
    .table > :not(caption) > * > * {
        padding: .85rem .9rem;
    }
    .table-hover tbody tr {
        transition: background-color 0.15s ease;
    }
    .table-hover tbody tr:hover {
        background-color: #f8fafc;
    }

    /* Modern Inputs & Buttons */
    .search-input {
        background-color: #f8fafc;
        border: 1px solid #e2e8f0;
        transition: all 0.2s;
    }
    .search-input:focus {
        background-color: #ffffff;
        border-color: #6366f1;
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    }

    .btn-gradient-primary {
        background: var(--primary-gradient);
        color: #ffffff;
        border: none;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.25);
        transition: all 0.2s ease;
    }
    .btn-gradient-primary:hover {
        color: #ffffff;
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(99, 102, 241, 0.35);
    }

    /* Pulse animation for active test */
    @keyframes pulse-ring {
        0% { transform: scale(0.95); opacity: 0.8; }
        50% { transform: scale(1.05); opacity: 1; }
        100% { transform: scale(0.95); opacity: 0.8; }
    }
    @keyframes pulse-glow {
        0%   { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.5); }
        70%  { box-shadow: 0 0 0 8px rgba(37, 99, 235, 0); }
        100% { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0); }
    }
    .pulse-dot {
        width: 8px;
        height: 8px;
        background-color: #2563eb;
        border-radius: 50%;
        display: inline-block;
        animation: pulse-ring 1.5s infinite ease-in-out, pulse-glow 1.5s infinite;
    }

    /* ===================== ANIMASI ===================== */
    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(16px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes rowIn {
        from { opacity: 0; transform: translateX(-12px); }
        to   { opacity: 1; transform: translateX(0); }
    }
    @keyframes alertDrop {
        from { opacity: 0; transform: translateY(-14px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes modalPop {
        from { opacity: 0; transform: scale(0.92) translateY(20px); }
        to   { opacity: 1; transform: scale(1) translateY(0); }
    }
    @keyframes menuPop {
        from { opacity: 0; transform: translateY(-6px) scale(0.96); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    .stat-card { animation: fadeUp 0.5s cubic-bezier(.22,.9,.36,1) backwards; }
    .row > div:nth-child(1) .stat-card { animation-delay: 0.05s; }
    .row > div:nth-child(2) .stat-card { animation-delay: 0.15s; }
    .row > div:nth-child(3) .stat-card { animation-delay: 0.25s; }
    .stat-icon { transition: transform 0.25s ease; }
    .stat-card:hover .stat-icon { transform: scale(1.12) rotate(-6deg); }

    .main-card { animation: fadeUp 0.6s cubic-bezier(.22,.9,.36,1) 0.3s backwards; }

    #tabelPeserta tbody tr[data-export-idx] {
        animation: rowIn 0.4s ease backwards;
        animation-delay: calc(0.4s + min(var(--i, 0), 12) * 45ms);
    }
    #tabelPeserta tbody tr.row-pop { animation: rowIn 0.3s ease backwards; animation-delay: 0s; }
    .table-hover tbody tr:hover { box-shadow: inset 3px 0 0 #6366f1; }
    .avatar-circle { transition: transform 0.25s ease, box-shadow 0.25s ease; }
    tr:hover .avatar-circle {
        transform: scale(1.08) rotate(-4deg);
        box-shadow: 0 6px 14px rgba(99, 102, 241, 0.25);
    }

    .alert { animation: alertDrop 0.45s cubic-bezier(.22,.9,.36,1) backwards; }

    .modal.show .modal-dialog { animation: modalPop 0.35s cubic-bezier(.22,.9,.36,1) backwards; }
    .modal.fade .modal-dialog { transform: none; }

    .dropdown-menu.show { animation: menuPop 0.18s ease backwards; transform-origin: top right; }
    .dropdown-item { transition: background-color 0.15s ease, padding-left 0.15s ease; }
    .dropdown-item:hover { padding-left: 1.25rem; }

    .btn { transition: transform 0.15s ease, box-shadow 0.2s ease, background-color 0.2s ease; }
    .btn:active { transform: scale(0.96); }

    .search-input { transition: all 0.3s ease; }
    .search-input:focus { width: 300px !important; }

    /* ===================== DATE-TIME PICKER ===================== */
    .flatpickr-wrapper { display: block; width: 100%; }
    .dt-quick { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .dt-quick .btn { font-size: .72rem; padding: 3px 10px; border-radius: 20px; }
    .flatpickr-calendar { border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,.12); z-index: 1065; }
    .flatpickr-day.selected,
    .flatpickr-day.selected:hover { background: #6366f1; border-color: #6366f1; }
    .flatpickr-day.today { border-color: #6366f1; }
    .flatpickr-time input { font-size: 1.1rem; font-weight: 600; }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { animation: none !important; transition: none !important; }
    }
</style>

<!-- Alert Error (validasi / database) -->
<?php if ($errPeserta): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3 shadow-sm mb-4 p-3" role="alert">
        <div class="d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
            <div><strong>Data belum disimpan.</strong> <?= e($errPeserta) ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Alert Sukses / Notifikasi -->
<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 rounded-3 shadow-sm mb-4 bg-success bg-opacity-10 text-success p-3" role="alert">
        <div class="d-flex align-items-center">
            <i class="bi bi-check-circle-fill fs-5 me-2"></i>
            <div>
                <strong>Berhasil!</strong>
                <?php
                    if ($_GET['success'] === 'added') echo 'Peserta baru berhasil ditambahkan.';
                    elseif ($_GET['success'] === 'updated') echo 'Data peserta berhasil diperbarui.';
                    else echo 'Data peserta telah berhasil dihapus dari sistem.';
                ?>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>

    <?php if (in_array($_GET['success'], ['added', 'updated'], true) && isset($_GET['mail'])): ?>
        <?php if ($_GET['mail'] === '1'): ?>
            <div class="alert alert-info alert-dismissible fade show border-0 rounded-3 shadow-sm mb-4 bg-primary bg-opacity-10 text-primary p-3" role="alert">
                <div class="d-flex align-items-center">
                    <i class="bi bi-send-check-fill fs-5 me-2"></i>
                    <div>Email pemberitahuan akun & password telah sukses dikirim ke peserta.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php else: ?>
            <div class="alert alert-warning alert-dismissible fade show border-0 rounded-3 shadow-sm mb-4 bg-warning bg-opacity-10 text-warning p-3" role="alert">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
                    <div>
                        <strong>Email Gagal Terkirim:</strong> Data peserta tersimpan, namun sistem gagal mengirim email. Harap berikan akun secara manual atau periksa konfigurasi mailer.
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<!-- Ringkasan Statistik -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="stat-card p-3 d-flex align-items-center gap-3">
            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                <i class="bi bi-people-fill"></i>
            </div>
            <div>
                <span class="text-muted small d-block fw-medium">Total Peserta</span>
                <h4 class="fw-bold mb-0 text-dark"><span class="count-up" data-count="<?= $totalPeserta ?>"><?= $totalPeserta ?></span></h4>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card p-3 d-flex align-items-center gap-3">
            <div class="stat-icon bg-info bg-opacity-10 text-info">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <div>
                <span class="text-muted small d-block fw-medium">Sedang Mengerjakan</span>
                <h4 class="fw-bold mb-0 text-dark"><span class="count-up" data-count="<?= $totalSedangTes ?>"><?= $totalSedangTes ?></span> <span class="fs-6 fw-normal text-muted">tes</span></h4>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card p-3 d-flex align-items-center gap-3">
            <div class="stat-icon bg-success bg-opacity-10 text-success">
                <i class="bi bi-check-all"></i>
            </div>
            <div>
                <span class="text-muted small d-block fw-medium">Tes Selesai</span>
                <h4 class="fw-bold mb-0 text-dark"><span class="count-up" data-count="<?= $totalSelesai ?>"><?= $totalSelesai ?></span> <span class="fs-6 fw-normal text-muted">tes</span></h4>
            </div>
        </div>
    </div>
</div>

<!-- Main Table Card -->
<div class="card main-card border-0 bg-white p-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h5 class="fw-bold text-dark mb-1">
                Daftar Peserta
                <span class="badge rounded-pill bg-success-subtle text-success border ms-2 fw-semibold" style="font-size:.7rem; vertical-align: middle;">
                    <span class="online-badge-dot d-inline-block rounded-circle bg-success me-1" style="width:7px;height:7px;"></span>
                    <span id="totalOnline">0</span> online
                </span>
            </h5>
            <p class="text-muted small mb-0">Kelola akun, tentukan status seleksi, dan lihat riwayat pengerjaan tes peserta.</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="position-relative">
                <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                <input type="text" id="searchPeserta" class="form-control form-control-sm rounded-3 ps-5 pe-3 search-input" style="width: 240px; height: 38px;" placeholder="Cari nama, email, jabatan...">
            </div>

            <button class="btn btn-sm btn-light border rounded-3 px-3 fw-medium" style="height: 38px;" onclick="exportTableToCSV('data_peserta.csv')" title="Export peserta yang sedang tampil (ikut filter pencarian)">
                <i class="bi bi-download me-1"></i> Export CSV
            </button>

            <button class="btn btn-sm btn-gradient-primary rounded-3 px-3 fw-medium" style="height: 38px;" data-bs-toggle="modal" data-bs-target="#modalTambahPeserta">
                <i class="bi bi-person-plus-fill me-1"></i> Tambah Peserta
            </button>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="tabelPeserta" style="font-size: 0.875rem;">
            <thead>
                <tr class="bg-light text-uppercase text-muted" style="font-size: 0.725rem; letter-spacing: 0.5px;">
                    <th class="fw-bold py-3 ps-3">Peserta</th>
                    <th class="fw-bold py-3">Jabatan</th>
                    <th class="fw-bold py-3">Tanggal Lahir</th>
                    <th class="fw-bold py-3">Masa Aktif</th>
                    <th class="fw-bold py-3">Progres Tes</th>
                    <th class="fw-bold py-3">Status</th>
                    <th class="fw-bold py-3 text-center pe-3">Aksi</th>
                </tr>
            </thead>
            <tbody class="border-top-0">
                <?php if (empty($pesertaList)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-people fs-1 d-block mb-2 text-secondary opacity-50"></i>
                            Belum ada peserta yang terdaftar di sistem.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($pesertaList as $idx => $p):
                    $labelStatus = STATUS_SELEKSI[$p['status_seleksi'] ?? ''] ?? 'Belum ditentukan';
                    $searchBase = strtolower($p['nama'] . ' ' . $p['email'] . ' ' . ($p['jabatan_dilamar'] ?? ''));
                    $usia = hitungUsiaTahun($p['tanggal_lahir'] ?? null);
                ?>
                <tr data-export-idx="<?= (int)$idx ?>" style="--i: <?= (int)$idx ?>"
                    data-search-base="<?= e($searchBase) ?>"
                    data-search="<?= e($searchBase . ' ' . strtolower($labelStatus)) ?>">
                    <!-- Peserta + status online -->
                    <td class="py-3 ps-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-wrap" data-uid="<?= (int)$p['id'] ?>">
                                <div class="avatar-circle">
                                    <?= e(strtoupper(substr($p['nama'], 0, 1))) ?>
                                </div>
                                <span class="avatar-online-dot"></span>
                            </div>
                            <div>
                                <span class="fw-bold text-dark d-block mb-0"><?= e($p['nama']) ?></span>
                                <span class="text-muted small d-block"><?= e($p['email']) ?></span>
                                <span class="online-badge d-none" data-uid="<?= (int)$p['id'] ?>">
                                    <span class="dot"></span> Sedang akses web
                                </span>
                            </div>
                        </div>
                    </td>

                    <!-- Jabatan -->
                    <td class="py-3">
                        <?php if (!empty($p['jabatan_dilamar'])): ?>
                            <span class="badge-jabatan"><?= e($p['jabatan_dilamar']) ?></span>
                        <?php else: ?>
                            <span class="text-muted small">-</span>
                        <?php endif; ?>
                    </td>

                    <!-- Tanggal lahir (+ usia) -->
                    <td class="py-3 small">
                        <?php if (!empty($p['tanggal_lahir'])): ?>
                            <div class="text-dark"><?= e(formatTanggalSaja($p['tanggal_lahir'])) ?></div>
                            <?php if ($usia !== null): ?>
                                <div class="text-muted" style="font-size:.75rem;"><?= $usia ?> tahun</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>

                    <!-- Masa aktif (mulai + sampai digabung) -->
                    <td class="py-3 small">
                        <div class="text-muted mb-1">
                            <i class="bi bi-play-circle me-1"></i>
                            <?php if (empty($p['aktif_mulai'])): ?>
                                Langsung aktif
                            <?php elseif (strtotime($p['aktif_mulai']) > time()): ?>
                                <span class="text-info fw-semibold" title="Akun belum bisa login sampai jadwal ini tiba">
                                    <?= e(formatTanggal($p['aktif_mulai'])) ?>
                                </span>
                            <?php else: ?>
                                <?= e(formatTanggal($p['aktif_mulai'])) ?>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($p['aktif_sampai'])): ?>
                            <div class="text-muted"><i class="bi bi-stop-circle me-1"></i>Tidak ada batas</div>
                        <?php else:
                            $deadlineTs = strtotime($p['aktif_sampai']);
                            $sudahGugur = (($p['status'] ?? '') === 'gugur') || (time() > $deadlineTs);
                        ?>
                            <?php if ($sudahGugur): ?>
                                <span class="badge bg-danger-subtle text-danger border" title="Batas aktif akun: <?= e(formatTanggal($p['aktif_sampai'])) ?>">
                                    <i class="bi bi-x-octagon-fill me-1"></i> Gugur
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning border" title="Akun otomatis gugur setelah waktu ini">
                                    <i class="bi bi-clock-history me-1"></i> s.d. <?= e(formatTanggal($p['aktif_sampai'])) ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>

                    <!-- Progres tes (diambil + berlangsung + selesai digabung) -->
                    <td class="py-3">
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                            <span class="bg-light px-2 py-1 rounded small fw-bold" title="Tes diambil"><?= (int)$p['total_tes_diambil'] ?> diambil</span>
                            <?php if ((int)$p['sedang_berlangsung'] > 0): ?>
                                <span class="badge-soft badge-soft-ongoing">
                                    <span class="pulse-dot"></span>
                                    <?= (int)$p['sedang_berlangsung'] ?> berlangsung
                                </span>
                            <?php endif; ?>
                            <span class="badge-soft badge-soft-done">
                                <i class="bi bi-check-circle-fill"></i> <?= (int)$p['selesai'] ?> selesai
                            </span>
                        </div>
                    </td>

                    <!-- Status seleksi (ditentukan manual oleh admin) -->
                    <td class="py-3">
                        <?= selectStatusSeleksi((int)$p['id'], $p['status_seleksi'] ?? null) ?>
                    </td>

                    <!-- Aksi -->
                    <td class="py-3 text-center pe-3">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light border rounded-3 dropdown-toggle shadow-sm px-2 py-1" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3">
                                <li>
                                    <a class="dropdown-item small py-2" href="hasil.php?peserta_id=<?= (int)$p['id'] ?>">
                                        <i class="bi bi-eye text-primary me-2"></i> Lihat Hasil Tes
                                    </a>
                                </li>
                                <li>
                                    <button type="button"
                                        class="dropdown-item small py-2 btn-edit-peserta"
                                        data-id="<?= (int)$p['id'] ?>"
                                        data-nama="<?= e($p['nama']) ?>"
                                        data-email="<?= e($p['email']) ?>"
                                        data-jabatan="<?= e($p['jabatan_dilamar'] ?? '') ?>"
                                        data-tanggal-lahir="<?= e(!empty($p['tanggal_lahir']) ? date('Y-m-d', strtotime($p['tanggal_lahir'])) : '') ?>"
                                        data-aktif-mulai="<?= e(!empty($p['aktif_mulai']) ? date('Y-m-d\TH:i', strtotime($p['aktif_mulai'])) : '') ?>"
                                        data-aktif-sampai="<?= e(!empty($p['aktif_sampai']) ? date('Y-m-d\TH:i', strtotime($p['aktif_sampai'])) : '') ?>">
                                        <i class="bi bi-pencil-square text-warning me-2"></i> Edit Peserta
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li>
                                    <form method="POST" onsubmit="return confirm('Yakin ingin menghapus peserta ini? Semua data pengerjaan tes juga akan terhapus.');">
                                        <input type="hidden" name="action" value="hapus_peserta">
                                        <input type="hidden" name="peserta_id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="dropdown-item small py-2 text-danger">
                                            <i class="bi bi-trash me-2"></i> Hapus Peserta
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tambah Peserta -->
<div class="modal fade" id="modalTambahPeserta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold text-dark">Tambah Peserta Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="tambah_peserta">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Nama Lengkap</label>
                        <input type="text" name="nama" class="form-control rounded-3" required placeholder="Contoh: Budi Santoso">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Alamat Email</label>
                        <input type="email" name="email" class="form-control rounded-3" required placeholder="budi@example.com">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-secondary">Jabatan Dilamar</label>
                            <input type="text" name="jabatan_dilamar" class="form-control rounded-3" placeholder="Contoh: Staff Finance">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-secondary">Tanggal Lahir</label>
                            <input type="date" name="tanggal_lahir" class="form-control rounded-3">
                        </div>
                        <div class="form-text small text-muted mt-1">
                            Tanggal lahir digunakan untuk koreksi usia pada tes IQ (WPT).
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Akun Aktif Mulai (tanggal &amp; jam)</label>
                        <input type="text" name="aktif_mulai" class="form-control rounded-3 dt-picker" data-preset="mulai" placeholder="Pilih tanggal &amp; jam" autocomplete="off">
                        <div class="form-text small text-muted mt-1">
                            Akun baru bisa dipakai login mulai tanggal &amp; jam ini. Sebelum waktu tersebut peserta akan melihat
                            pemberitahuan <strong>akun belum aktif</strong>. Kosongkan jika akun boleh langsung dipakai.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Akun Aktif Sampai (tanggal &amp; jam)</label>
                        <input type="text" name="aktif_sampai" class="form-control rounded-3 dt-picker" data-preset="sampai" placeholder="Pilih tanggal &amp; jam" autocomplete="off">
                        <div class="form-text small text-muted mt-1">
                            Batas akhir akun. Lewat dari waktu ini akun otomatis dinyatakan <strong>Gugur</strong> dan tidak bisa login lagi.
                            Masa aktif (mulai &amp; sampai) dicantumkan di email. Kosongkan jika tanpa batas akhir.
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Password</label>
                        <input type="password" name="password" class="form-control rounded-3" placeholder="Kosongkan untuk generator otomatis">
                        <div class="form-text small text-muted mt-1">
                            Jika dikosongkan, sistem akan membuatkan password acak dan mengirimkannya via email.
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn btn-sm btn-light rounded-3 px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-gradient-primary rounded-3 px-4">Simpan &amp; Kirim Email</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Peserta -->
<div class="modal fade" id="modalEditPeserta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold text-dark">Edit Data Peserta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="formEditPeserta">
                <input type="hidden" name="action" value="edit_peserta">
                <input type="hidden" name="peserta_id" id="editPesertaId" value="">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Nama Lengkap</label>
                        <input type="text" name="nama" id="editNama" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Alamat Email</label>
                        <input type="email" name="email" id="editEmail" class="form-control rounded-3" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-secondary">Jabatan Dilamar</label>
                            <input type="text" name="jabatan_dilamar" id="editJabatan" class="form-control rounded-3" placeholder="Contoh: Staff Finance">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-secondary">Tanggal Lahir</label>
                            <input type="date" name="tanggal_lahir" id="editTanggalLahir" class="form-control rounded-3">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Akun Aktif Mulai (tanggal &amp; jam)</label>
                        <input type="text" name="aktif_mulai" id="editAktifMulai" class="form-control rounded-3 dt-picker" data-preset="mulai" placeholder="Pilih tanggal &amp; jam" autocomplete="off">
                        <div class="form-text small text-muted mt-1">
                            Akun baru bisa dipakai login mulai waktu ini. Kosongkan agar akun langsung aktif.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Akun Aktif Sampai (tanggal &amp; jam)</label>
                        <input type="text" name="aktif_sampai" id="editAktifSampai" class="form-control rounded-3 dt-picker" data-preset="sampai" placeholder="Pilih tanggal &amp; jam" autocomplete="off">
                        <div class="form-text small text-muted mt-1">
                            Lewat dari waktu ini akun otomatis <strong>Gugur</strong>. Kosongkan untuk menghapus batas akhir.
                            Memperpanjang batas akhir akan mengaktifkan kembali akun yang sudah gugur.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Password Baru</label>
                        <input type="password" name="password" class="form-control rounded-3" placeholder="Kosongkan jika tidak ingin mengganti password">
                        <div class="form-text small text-muted mt-1">
                            Jika email dikirim ulang dan kolom ini kosong, sistem membuat password baru otomatis
                            (password lama tidak bisa dikirim ulang).
                        </div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="kirim_email" id="editKirimEmail" value="1">
                        <label class="form-check-label small fw-semibold text-secondary" for="editKirimEmail">
                            Kirim ulang email akun ke peserta
                        </label>
                        <div class="form-text small text-muted">
                            Email berisi akun, password, dan masa aktif terbaru.
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn btn-sm btn-light rounded-3 px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-gradient-primary rounded-3 px-4">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Flatpickr (pemilih tanggal & jam) -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/id.js"></script>

<script>
// Kalau ada pesan error dari server, gulung ke atas supaya langsung terlihat
<?php if ($errPeserta): ?>
window.scrollTo({ top: 0, behavior: 'smooth' });
<?php endif; ?>

// Filter pencarian tabel (baris yang muncul kembali ikut beranimasi).
// Pakai atribut data-search (nama, email, jabatan, status) — BUKAN teks sel,
// supaya opsi dropdown status tidak ikut membuat semua baris "cocok".
document.getElementById('searchPeserta').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#tabelPeserta tbody tr[data-export-idx]').forEach(function(row) {
        const cocok = (row.dataset.search || '').includes(q);
        const sebelumnyaHilang = row.style.display === 'none';
        row.style.display = cocok ? '' : 'none';
        if (cocok && sebelumnyaHilang) {
            row.classList.remove('row-pop');
            void row.offsetWidth; // restart animasi
            row.classList.add('row-pop');
        }
    });
});

// Angka statistik menghitung naik dari 0
document.querySelectorAll('.count-up').forEach(function (el) {
    const target = parseInt(el.dataset.count, 10) || 0;
    if (target === 0) return;
    const durasi = 900, mulai = performance.now();
    (function tick(now) {
        const p = Math.min((now - mulai) / durasi, 1);
        el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))); // ease-out
        if (p < 1) requestAnimationFrame(tick);
    })(mulai);
});

// ============== Status Online (polling tiap 10 detik) ==============
// Peserta dianggap online kalau last_seen < 90 detik yang lalu (lihat online_status.php).
async function refreshOnline() {
    try {
        const r = await fetch('online_status.php', { cache: 'no-store' });
        if (!r.ok) return;
        const ids = (await r.json()).map(String);

        document.querySelectorAll('.online-badge').forEach(function (el) {
            el.classList.toggle('d-none', !ids.includes(el.dataset.uid));
        });
        document.querySelectorAll('.avatar-wrap').forEach(function (el) {
            el.classList.toggle('is-online', ids.includes(el.dataset.uid));
        });

        const total = document.getElementById('totalOnline');
        if (total) total.textContent = ids.length;
    } catch (e) { /* abaikan, coba lagi di siklus berikutnya */ }
}
refreshOnline();
setInterval(refreshOnline, 10000);

// ============== Status seleksi (simpan otomatis saat dropdown diubah) ==============
const EXPORT_ROWS = <?= json_encode($exportRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function warnaSeleksi(sel) {
    sel.classList.remove('s-diterima', 's-disimpan', 's-tidak_lolos');
    if (sel.value) sel.classList.add('s-' + sel.value);
}

document.querySelectorAll('.sel-seleksi').forEach(function (sel) {
    sel.dataset.prev = sel.value;
    warnaSeleksi(sel);

    sel.addEventListener('change', async function () {
        sel.disabled = true;
        try {
            const fd = new FormData();
            fd.append('action', 'ubah_status_seleksi');
            fd.append('peserta_id', sel.dataset.user);
            fd.append('status', sel.value);
            const res = await fetch('peserta.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) throw new Error('gagal');

            sel.dataset.prev = sel.value;
            warnaSeleksi(sel);

            // Sinkronkan pencarian & export CSV dengan status terbaru
            const label = sel.options[sel.selectedIndex].text;
            const row = sel.closest('tr');
            if (row) {
                row.dataset.search = (row.dataset.searchBase || '') + ' ' + label.toLowerCase();
                const data = EXPORT_ROWS[row.dataset.exportIdx];
                if (data) data[data.length - 1] = label;
            }
        } catch (err) {
            sel.value = sel.dataset.prev;
            warnaSeleksi(sel);
            alert('Gagal menyimpan status seleksi. Coba lagi atau muat ulang halaman.');
        }
        sel.disabled = false;
    });
});

// ============== Date-time picker (Flatpickr) ==============
// Nilai yang dikirim ke server tetap "Y-m-dTH:i" (sama seperti datetime-local),
// jadi parseDatetimeLocal() & validasi PHP tidak berubah.
// Kalau Flatpickr gagal dimuat/diinisialisasi, input otomatis jadi datetime-local bawaan browser
// sehingga form tetap bisa disimpan.
const dtPickers = {}; // key: id input (atau name + nomor urut)

function initDtPicker(input) {
    const fp = flatpickr(input, {
        enableTime: true,
        time_24hr: true,
        minuteIncrement: 5,
        defaultHour: 8,
        defaultMinute: 0,
        dateFormat: 'Y-m-d\\TH:i',      // dikirim ke server
        altInput: true,
        altFormat: 'd M Y, H:i',        // tampilan user, mis. 25 Sep 2026, 09:00
        altInputClass: 'form-control rounded-3',
        locale: (window.flatpickr && flatpickr.l10ns && flatpickr.l10ns.id) ? 'id' : 'default',
        disableMobile: true,
        appendTo: input.closest('.modal-body') || document.body   // aman di dalam modal Bootstrap
    });

    // Tombol pintas
    const preset = input.dataset.preset;
    const addDays = function (n, h, m) {
        const d = new Date();
        d.setDate(d.getDate() + n);
        d.setHours(h, m, 0, 0);
        return d;
    };
    const opsi = preset === 'mulai'
        ? [
            ['Sekarang',     function () { return new Date(); }],
            ['Besok 08:00',  function () { return addDays(1, 8, 0); }]
          ]
        : [
            ['Hari ini 23:59', function () { return addDays(0, 23, 59); }],
            ['+7 hari',        function () { return addDays(7, 23, 59); }],
            ['+30 hari',       function () { return addDays(30, 23, 59); }]
          ];

    const wrap = document.createElement('div');
    wrap.className = 'dt-quick';
    opsi.forEach(function (o) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-outline-secondary';
        b.textContent = o[0];
        b.addEventListener('click', function () { fp.setDate(o[1](), true); });
        wrap.appendChild(b);
    });
    const hapus = document.createElement('button');
    hapus.type = 'button';
    hapus.className = 'btn btn-outline-danger';
    hapus.textContent = 'Kosongkan';
    hapus.addEventListener('click', function () { fp.clear(); });
    wrap.appendChild(hapus);

    // Letakkan tombol pintas tepat di bawah kolom input tampilan
    fp.altInput.insertAdjacentElement('afterend', wrap);
    return fp;
}

document.querySelectorAll('.dt-picker').forEach(function (el, i) {
    const key = el.id || (el.name + '_' + i);
    try {
        if (typeof flatpickr === 'undefined') throw new Error('flatpickr belum termuat');
        dtPickers[key] = initDtPicker(el);
    } catch (err) {
        console.error('Flatpickr gagal, pakai input bawaan browser:', err);
        try { if (el._flatpickr) el._flatpickr.destroy(); } catch (e) {}
        el.type = 'datetime-local';
        el.removeAttribute('readonly');
    }
});

// Isi nilai picker (kosong = clear). Mendukung fallback input biasa.
function setDt(id, val) {
    const fp = dtPickers[id];
    if (fp) {
        if (val) fp.setDate(val, true); else fp.clear();
        return;
    }
    const el = document.getElementById(id);
    if (el) el.value = val || '';
}

// Isi & buka modal Edit Peserta saat tombol "Edit Peserta" diklik
document.querySelectorAll('.btn-edit-peserta').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('editPesertaId').value = this.dataset.id || '';
        document.getElementById('editNama').value = this.dataset.nama || '';
        document.getElementById('editEmail').value = this.dataset.email || '';
        document.getElementById('editJabatan').value = this.dataset.jabatan || '';
        document.getElementById('editTanggalLahir').value = this.dataset.tanggalLahir || '';
        setDt('editAktifMulai', this.dataset.aktifMulai);
        setDt('editAktifSampai', this.dataset.aktifSampai);

        // reset field password supaya tidak menampilkan sisa isian sebelumnya
        document.getElementById('formEditPeserta').querySelector('input[name="password"]').value = '';
        // default: kirim ulang email TIDAK dicentang (centang manual kalau perlu)
        document.getElementById('editKirimEmail').checked = false;

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditPeserta')).show();
    });
});

// ============== Export CSV ==============
// Data diambil dari array PHP (bukan dari teks tabel), jadi tiap kolom terpisah rapi.
const EXPORT_HEADER = [
    'Nama', 'Email', 'Jabatan Dilamar', 'Tanggal Lahir', 'Aktif Mulai', 'Aktif Sampai',
    'Tes Diambil', 'Sedang Berlangsung', 'Selesai', 'Status Seleksi'
];

// Excel Indonesia memakai ";" sebagai pemisah kolom, sedangkan Excel bahasa Inggris memakai ",".
// Baris "sep=;" di paling atas membuat Excel di locale mana pun otomatis memakai ";".
const CSV_DELIM = ';';

function csvCell(v) {
    if (typeof v === 'number') return String(v);            // angka tetap angka (tidak dikutip)
    let s = (v === null || v === undefined) ? '' : String(v);
    if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;                // cegah CSV/formula injection di Excel
    return '"' + s.replace(/"/g, '""') + '"';
}

function exportTableToCSV(filename) {
    const lines = ['sep=' + CSV_DELIM, EXPORT_HEADER.map(csvCell).join(CSV_DELIM)];

    // Hanya baris yang sedang tampil (ikut filter pencarian)
    document.querySelectorAll('#tabelPeserta tbody tr[data-export-idx]').forEach(function (tr) {
        if (tr.style.display === 'none') return;
        const data = EXPORT_ROWS[tr.dataset.exportIdx];
        if (data) lines.push(data.map(csvCell).join(CSV_DELIM));
    });

    const tanggal = new Date().toISOString().slice(0, 10);
    const namaFile = filename.replace(/\.csv$/i, '') + '_' + tanggal + '.csv';

    // BOM UTF-8 (\uFEFF) supaya huruf/karakter khusus tampil benar di Excel
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = namaFile;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
}
</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>