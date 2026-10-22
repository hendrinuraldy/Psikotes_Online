<?php
// Simpan file ini sebagai: admin/kelola_admin.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireRole('admin');

/** Kirim email dengan aman: exception SMTP tidak boleh menghentikan halaman. */
function kirimEmailAdminAman(...$args): bool {
    try {
        return (bool)kirimEmailAkunAdmin(...$args);
    } catch (Throwable $e) {
        error_log('Gagal kirim email admin: ' . $e->getMessage());
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ---------- TAMBAH ----------
    if (isset($_POST['action']) && $_POST['action'] === 'tambah_admin') {
        $nama = trim($_POST['nama'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');

        // Password boleh dikosongkan -> sistem generate otomatis (lalu dikirim via email)
        $passwordDikirim = $password;
        if ($password === '') {
            $passwordDikirim = substr(bin2hex(random_bytes(5)), 0, 8); // 8 karakter acak
        }

        if ($nama && $email) {
            $hashedPassword = password_hash($passwordDikirim, PASSWORD_BCRYPT);
            try {
                $stmt = getDB()->prepare("
                    INSERT INTO users (nama, email, password, role, created_at)
                    VALUES (?, ?, ?, 'admin', NOW())
                ");
                $stmt->execute([$nama, $email, $hashedPassword]);
            } catch (PDOException $e) {
                header('Location: kelola_admin.php?error=' . ($e->getCode() == 23000 ? 'email_exists' : 'db'));
                exit;
            }

            $terkirim = kirimEmailAdminAman($nama, $email, $passwordDikirim);

            header('Location: kelola_admin.php?success=added&mail=' . ($terkirim ? '1' : '0'));
            exit;
        }
    }

    // ---------- EDIT ----------
    // Password opsional (kosong = tidak diubah, kecuali kirim email dicentang)
    if (isset($_POST['action']) && $_POST['action'] === 'edit_admin') {
        $id = (int)($_POST['admin_id'] ?? 0);
        $nama = trim($_POST['nama'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $kirimEmail = !empty($_POST['kirim_email']);

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
                    $hashedPassword = password_hash($passwordDikirim, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("UPDATE users SET nama = ?, email = ?, password = ? WHERE id = ? AND role = 'admin'");
                    $stmt->execute([$nama, $email, $hashedPassword, $id]);
                } else {
                    $stmt = $db->prepare("UPDATE users SET nama = ?, email = ? WHERE id = ? AND role = 'admin'");
                    $stmt->execute([$nama, $email, $id]);
                }
            } catch (PDOException $e) {
                header('Location: kelola_admin.php?error=' . ($e->getCode() == 23000 ? 'email_exists' : 'db'));
                exit;
            }

            if ($kirimEmail) {
                // Argumen ke-4 (true) = penanda "kirim ulang / akun diperbarui"
                $terkirim = kirimEmailAdminAman($nama, $email, $passwordDikirim, true);
                header('Location: kelola_admin.php?success=updated&mail=' . ($terkirim ? '1' : '0'));
            } else {
                header('Location: kelola_admin.php?success=updated');
            }
            exit;
        }
    }

    // ---------- HAPUS ----------
    if (isset($_POST['action']) && $_POST['action'] === 'hapus_admin') {
        $id = (int)($_POST['admin_id'] ?? 0);

        // Safety: jangan biarkan admin menghapus akunnya sendiri
        if ($id === (int)$_SESSION['user_id']) {
            header('Location: kelola_admin.php?error=cannot_delete_self');
            exit;
        }

        // Safety: minimal harus tersisa 1 admin di sistem
        $jumlahAdmin = (int)getDB()->query("SELECT COUNT(*) c FROM users WHERE role = 'admin'")->fetch()['c'];
        if ($jumlahAdmin <= 1) {
            header('Location: kelola_admin.php?error=last_admin');
            exit;
        }

        if ($id > 0) {
            $stmt = getDB()->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'");
            $stmt->execute([$id]);
            header('Location: kelola_admin.php?success=deleted');
            exit;
        }
    }
}

$adminList = getAllAdminAccounts();

$pageTitle = 'Kelola Admin';
$pageDesc = 'Kelola akun admin yang memiliki akses ke panel ini';
$activeMenu = 'kelola_admin';
include __DIR__ . '/../includes/layout_top.php';
?>

<style>
    /* Keyframe Animations */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Container Animation */
    .card-anim {
        animation: fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
    }

    /* Avatar Hover Micro-interaction */
    .avatar-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
        color: #3730a3;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s ease;
    }

    tr:hover .avatar-icon {
        transform: scale(1.1) rotate(-3deg);
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.25);
    }

    /* Table Hover Transition */
    .table-hover tbody tr {
        transition: background-color 0.2s ease;
    }
    .table-hover tbody tr:hover {
        background-color: #f8fafc;
    }

    /* Button Interactions */
    .btn-action {
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
    }

    /* Modal Animation Enhancement */
    .modal.fade .modal-dialog {
        transform: scale(0.95) translateY(10px);
        transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .modal.show .modal-dialog {
        transform: scale(1) translateY(0);
    }
</style>

<!-- Alert Error -->
<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3 shadow-sm mb-4 card-anim" role="alert">
        <i class="bi bi-exclamation-octagon-fill me-2"></i>
        <?php
            if ($_GET['error'] === 'cannot_delete_self') echo 'Anda tidak bisa menghapus akun Anda sendiri.';
            elseif ($_GET['error'] === 'last_admin') echo 'Tidak bisa menghapus, minimal harus ada 1 akun admin di sistem.';
            elseif ($_GET['error'] === 'email_exists') echo 'Email tersebut sudah terdaftar di sistem. Gunakan email lain.';
            elseif ($_GET['error'] === 'db') echo 'Gagal menyimpan ke database.';
            else echo 'Terjadi kesalahan.';
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Alert Sukses -->
<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 rounded-3 shadow-sm mb-4 card-anim" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php
            if ($_GET['success'] === 'added') echo 'Admin baru berhasil ditambahkan.';
            elseif ($_GET['success'] === 'updated') echo 'Data admin berhasil diperbarui.';
            else echo 'Akun admin berhasil dihapus.';
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>

    <?php if (in_array($_GET['success'], ['added', 'updated'], true) && isset($_GET['mail'])): ?>
        <?php if ($_GET['mail'] === '1'): ?>
            <div class="alert alert-info alert-dismissible fade show border-0 rounded-3 shadow-sm mb-4 card-anim" role="alert">
                <i class="bi bi-send-check-fill me-2"></i> Email berisi kredensial login telah terkirim ke admin.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php else: ?>
            <div class="alert alert-warning alert-dismissible fade show border-0 rounded-3 shadow-sm mb-4 card-anim" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Email Gagal Terkirim:</strong> Data tersimpan, namun sistem gagal mengirim email. Sampaikan kredensial secara manual.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<div class="card border-0 bg-white p-4 card-anim" style="border-radius:16px;border:1px solid #e2e8f0;">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h5 class="fw-bold text-dark mb-1">Daftar Admin</h5>
            <p class="text-muted small mb-0">Akun-akun ini punya akses penuh ke seluruh panel admin.</p>
        </div>
        <button class="btn btn-dark rounded-3 px-3 fw-medium btn-action" data-bs-toggle="modal" data-bs-target="#modalTambahAdmin">
            <i class="bi bi-person-plus-fill me-1"></i> Tambah Admin
        </button>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
            <thead>
                <tr class="bg-light text-uppercase text-muted" style="font-size:0.725rem;letter-spacing:0.5px;">
                    <th class="py-3 ps-3">Admin</th>
                    <th class="py-3">Dibuat Pada</th>
                    <th class="py-3 text-center pe-3">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($adminList)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-5">Belum ada akun admin.</td></tr>
                <?php endif; ?>
                <?php foreach ($adminList as $a): ?>
                <tr>
                    <td class="py-3 ps-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-icon">
                                <?= e(strtoupper(substr($a['nama'], 0, 1))) ?>
                            </div>
                            <div>
                                <span class="fw-bold text-dark d-block"><?= e($a['nama']) ?></span>
                                <span class="text-muted small"><?= e($a['email']) ?></span>
                                <?php if ((int)$a['id'] === (int)$_SESSION['user_id']): ?>
                                    <span class="badge bg-primary-subtle text-primary border ms-1">Anda</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="py-3 text-muted small"><?= formatTanggal($a['created_at']) ?></td>
                    <td class="py-3 text-center pe-3">
                        <button type="button" class="btn btn-sm btn-light border rounded-3 me-1 btn-edit-admin btn-action"
                            data-id="<?= (int)$a['id'] ?>"
                            data-nama="<?= e($a['nama']) ?>"
                            data-email="<?= e($a['email']) ?>">
                            <i class="bi bi-pencil-square text-warning"></i>
                        </button>
                        <?php if ((int)$a['id'] !== (int)$_SESSION['user_id']): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus akun admin ini?');">
                                <input type="hidden" name="action" value="hapus_admin">
                                <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-light border rounded-3 text-danger btn-action">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tambah Admin -->
<div class="modal fade" id="modalTambahAdmin" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold text-dark">Tambah Admin Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="tambah_admin">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Nama Lengkap</label>
                        <input type="text" name="nama" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Alamat Email</label>
                        <input type="email" name="email" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Password</label>
                        <input type="password" name="password" class="form-control rounded-3" placeholder="Kosongkan untuk generator otomatis">
                        <div class="form-text small text-muted mt-1">
                            Jika dikosongkan, sistem akan membuatkan password acak dan mengirimkannya via email ke admin baru.
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn btn-sm btn-light rounded-3 px-3 btn-action" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-dark rounded-3 px-4 btn-action">Simpan &amp; Kirim Email</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Admin -->
<div class="modal fade" id="modalEditAdmin" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold text-dark">Edit Admin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="formEditAdmin">
                <input type="hidden" name="action" value="edit_admin">
                <input type="hidden" name="admin_id" id="editAdminId">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Nama Lengkap</label>
                        <input type="text" name="nama" id="editAdminNama" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Alamat Email</label>
                        <input type="email" name="email" id="editAdminEmail" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Password Baru</label>
                        <input type="password" name="password" class="form-control rounded-3" placeholder="Kosongkan jika tidak ingin mengganti">
                        <div class="form-text small text-muted mt-1">
                            Jika email dikirim ulang dan kolom ini kosong, sistem membuat password baru otomatis
                            (password lama tidak bisa dikirim ulang).
                        </div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="kirim_email" id="editAdminKirimEmail" value="1">
                        <label class="form-check-label small fw-semibold text-secondary" for="editAdminKirimEmail">
                            Kirim ulang email akun ke admin
                        </label>
                        <div class="form-text small text-muted">
                            Email berisi alamat login dan password terbaru.
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn btn-sm btn-light rounded-3 px-3 btn-action" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-dark rounded-3 px-4 btn-action">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.btn-edit-admin').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('editAdminId').value = this.dataset.id || '';
        document.getElementById('editAdminNama').value = this.dataset.nama || '';
        document.getElementById('editAdminEmail').value = this.dataset.email || '';
        document.getElementById('formEditAdmin').querySelector('input[name="password"]').value = '';
        // default: kirim ulang email TIDAK dicentang (centang manual kalau perlu)
        document.getElementById('editAdminKirimEmail').checked = false;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditAdmin')).show();
    });
});
</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>