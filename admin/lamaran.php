<?php
// Simpan file ini sebagai: admin/lamaran.php

require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db = getDB();

// Sesuaikan nama kolom 'role' pada tabel users jika berbeda di sistem Anda
$data = $db->query("
    SELECT u.id AS user_id, u.nama, u.email,
           l.id AS lamaran_id, l.cv_filename, l.lamaran_filename, l.uploaded_at, l.updated_at
    FROM users u
    LEFT JOIN lamaran l ON l.user_id = u.id
    WHERE u.role = 'peserta'
    ORDER BY (l.id IS NULL) ASC, l.uploaded_at DESC, u.nama ASC
")->fetchAll();

$pageTitle  = 'CV & Form Lamaran Peserta';
$pageDesc   = 'Daftar dokumen yang diunggah peserta sebelum mengikuti tes';
$activeMenu = 'lamaran';
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

    /* Container Animations */
    .card-anim {
        animation: fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
    }

    /* Avatar Icon Styling & Interactions */
    .avatar-icon {
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
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s ease;
        flex-shrink: 0;
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

    /* Action Buttons Micro-interactions */
    .btn-action {
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
    }

    /* Badge Animations */
    .badge-status {
        transition: transform 0.2s ease;
    }
    tr:hover .badge-status {
        transform: scale(1.03);
    }
</style>

<div class="table-modern-wrapper card-anim" style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h5 class="fw-bold text-dark mb-1">Dokumen Lamaran Peserta</h5>
            <p class="text-muted small mb-0">CV dan Form Lamaran yang diunggah sebelum peserta memulai tes</p>
        </div>
        <div>
            <span class="badge bg-light text-dark border font-monospace px-3 py-2" style="font-size: 0.8rem;">
                Total <?= count($data) ?> Peserta
            </span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
            <thead>
                <tr class="bg-light text-uppercase text-muted" style="font-size:0.725rem;letter-spacing:0.5px;">
                    <th class="py-3 ps-3">Peserta</th>
                    <th class="py-3">Status Upload</th>
                    <th class="py-3">Waktu Upload</th>
                    <th class="py-3 pe-3">Dokumen</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($data as $row): ?>
                <tr>
                    <td class="py-3 ps-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-icon">
                                <?= e(strtoupper(substr($row['nama'], 0, 1))) ?>
                            </div>
                            <div>
                                <span class="fw-bold text-dark d-block"><?= e($row['nama']) ?></span>
                                <span class="text-muted small" style="font-size: 0.8rem;"><?= e($row['email']) ?></span>
                            </div>
                        </div>
                    </td>
                    <td class="py-3">
                        <?php if ($row['lamaran_id']): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1-5 badge-status">
                                <i class="bi bi-check-circle-fill me-1"></i> Sudah Upload
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-1-5 badge-status">
                                <i class="bi bi-dash-circle me-1"></i> Belum Upload
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 text-muted small">
                        <?= $row['lamaran_id'] ? formatTanggal($row['updated_at'] ?: $row['uploaded_at']) : '-' ?>
                    </td>
                    <td class="py-3 pe-3">
                        <?php if ($row['lamaran_id']): ?>
                            <div class="d-inline-flex gap-1">
                                <a href="download_lamaran.php?id=<?= (int)$row['lamaran_id'] ?>&type=cv"
                                   class="btn btn-sm btn-outline-primary rounded-3 px-2-5 btn-action" target="_blank" title="Unduh CV">
                                    <i class="bi bi-file-earmark-person me-1"></i> CV
                                </a>
                                <a href="download_lamaran.php?id=<?= (int)$row['lamaran_id'] ?>&type=lamaran"
                                   class="btn btn-sm btn-outline-secondary rounded-3 px-2-5 btn-action" target="_blank" title="Unduh Form Lamaran">
                                    <i class="bi bi-file-earmark-text me-1"></i> Form Lamaran
                                </a>
                            </div>
                        <?php else: ?>
                            <span class="text-muted small font-monospace">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($data)): ?>
                <tr>
                    <td colspan="4" class="text-center text-muted py-5">
                        <i class="bi bi-folder-x fs-1 d-block mb-2 text-secondary opacity-50"></i>
                        <div class="fw-semibold text-secondary">Belum ada data peserta.</div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>