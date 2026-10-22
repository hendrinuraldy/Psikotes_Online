-- =========================================================
-- DATABASE: psikotes_online
-- Website Psikotes Online (Peserta & Admin)
-- =========================================================

CREATE DATABASE IF NOT EXISTS psikotes_online CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE psikotes_online;

-- ---------------------------------------------------------
-- Tabel Users (Admin & Peserta)
-- ---------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','peserta') NOT NULL DEFAULT 'peserta',
    foto VARCHAR(255) DEFAULT NULL,
    status ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Tabel Tes (Kategori: logika / psikotes kepribadian)
-- tipe_psikotes: 'disc' / 'mbti', hanya relevan kalau jenis = 'psikotes'
-- ---------------------------------------------------------
CREATE TABLE tes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    judul VARCHAR(150) NOT NULL,
    jenis ENUM('logika','psikotes') NOT NULL,
    tipe_psikotes ENUM('disc','mbti') DEFAULT NULL,
    deskripsi TEXT,
    durasi_menit INT NOT NULL DEFAULT 30,
    status ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Tabel Soal
-- ---------------------------------------------------------
CREATE TABLE soal (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tes_id INT NOT NULL,
    pertanyaan TEXT NOT NULL,
    gambar VARCHAR(255) DEFAULT NULL,
    urutan INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tes_id) REFERENCES tes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Tabel Opsi Jawaban
-- is_benar  : dipakai untuk tes logika (auto-scoring benar/salah)
-- nilai     : cadangan bobot skala (tidak dipakai untuk skor akhir psikotes,
--             skor psikotes dihitung dari jumlah dimensi terjawab)
-- dimensi   : dipakai untuk tes psikotes (mis. 'D','I','S','C' untuk DISC,
--             atau 'E','I','S','N','T','F','J','P' untuk MBTI)
-- ---------------------------------------------------------
CREATE TABLE opsi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    soal_id INT NOT NULL,
    label CHAR(1) NOT NULL,
    teks VARCHAR(255) DEFAULT NULL,
    gambar VARCHAR(255) DEFAULT NULL,
    is_benar TINYINT(1) NOT NULL DEFAULT 0,
    nilai INT NOT NULL DEFAULT 0,
    dimensi VARCHAR(2) DEFAULT NULL,
    FOREIGN KEY (soal_id) REFERENCES soal(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Tabel Attempt (sesi pengerjaan tes oleh peserta)
-- profil_hasil       : hasil profil DISC/MBTI (khusus tes jenis psikotes)
-- flag_curang        : ditandai 1 kalau attempt dipaksa selesai karena
--                      pelanggaran anti-curang melebihi batas
-- pelanggaran_count  : jumlah insiden berpindah tab/aplikasi selama tes
-- ---------------------------------------------------------
CREATE TABLE attempt (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tes_id INT NOT NULL,
    waktu_mulai DATETIME NOT NULL,
    waktu_selesai DATETIME DEFAULT NULL,
    status ENUM('berlangsung','selesai') NOT NULL DEFAULT 'berlangsung',
    total_skor INT DEFAULT 0,
    total_benar INT DEFAULT 0,
    total_soal INT DEFAULT 0,
    profil_hasil VARCHAR(10) DEFAULT NULL,
    flag_curang TINYINT(1) NOT NULL DEFAULT 0,
    pelanggaran_count INT NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (tes_id) REFERENCES tes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Tabel Jawaban Peserta
-- opsi_id nullable  : peserta bisa tandai ragu-ragu tanpa pilih jawaban
-- ragu_ragu         : tanda soal ditandai ragu-ragu oleh peserta
-- UNIQUE(attempt_id, soal_id) : wajib, dipakai oleh simpan_jawaban.php
--                      (ON DUPLICATE KEY UPDATE) supaya autosave meng-update
--                      baris yang sama, bukan bikin baris baru tiap kali
-- ---------------------------------------------------------
CREATE TABLE jawaban (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL,
    soal_id INT NOT NULL,
    opsi_id INT DEFAULT NULL,
    ragu_ragu TINYINT(1) NOT NULL DEFAULT 0,
    dijawab_pada TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_attempt_soal (attempt_id, soal_id),
    FOREIGN KEY (attempt_id) REFERENCES attempt(id) ON DELETE CASCADE,
    FOREIGN KEY (soal_id) REFERENCES soal(id) ON DELETE CASCADE,
    FOREIGN KEY (opsi_id) REFERENCES opsi(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================================================
-- SEED DATA
-- =========================================================

-- Akun admin. Password: "admin123" (hash bcrypt di bawah dibuat untuk password ini)
INSERT INTO users (nama, email, password, role) VALUES
('Administrator', 'admin@ssktama.com', '$2b$10$1WI4BOM4Gs3Z/m/9j7UaGe6LT8QhQp2t7XxHUzm1QJkeE4A/UeZoy', 'admin');

-- Catatan: akun peserta sengaja tidak di-seed. Peserta mendaftar sendiri
-- lewat register.php.

INSERT INTO tes (judul, jenis, tipe_psikotes, deskripsi, durasi_menit) VALUES
('Tes Logika Dasar', 'logika', NULL, 'Mengukur kemampuan berpikir logis, pola gambar, dan penalaran.', 20),
('Tes Psikotes Kepribadian', 'psikotes', 'disc', 'Mengukur karakter dan kecenderungan kepribadian peserta.', 15);

INSERT INTO soal (tes_id, pertanyaan, urutan) VALUES
(1, 'Lanjutkan pola angka berikut: 2, 4, 8, 16, ...?', 1),
(1, 'Jika semua kucing adalah hewan, dan sebagian hewan adalah mamalia, maka...', 2);

INSERT INTO opsi (soal_id, label, teks, is_benar) VALUES
(1, 'A', '18', 0),
(1, 'B', '32', 1),
(1, 'C', '24', 0),
(1, 'D', '20', 0),
(2, 'A', 'Semua kucing adalah mamalia', 0),
(2, 'B', 'Sebagian kucing mungkin mamalia', 1),
(2, 'C', 'Tidak ada kucing yang mamalia', 0),
(2, 'D', 'Semua mamalia adalah kucing', 0);

INSERT INTO soal (tes_id, pertanyaan, urutan) VALUES
(2, 'Saat menghadapi masalah besar, saya cenderung...', 1);

-- Untuk tes psikotes DISC, tiap opsi diberi dimensi (D/I/S/C), bukan nilai/is_benar
INSERT INTO opsi (soal_id, label, teks, dimensi) VALUES
(3, 'A', 'Langsung mencari solusi sendiri', 'D'),
(3, 'B', 'Berdiskusi dengan orang lain terlebih dahulu', 'I'),
(3, 'C', 'Menunda dan memikirkannya nanti', 'S'),
(3, 'D', 'Merasa cemas dan sulit fokus', 'C');