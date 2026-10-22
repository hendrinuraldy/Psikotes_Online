# Website Psikotes Online (PHP Native + MySQL)

Aplikasi psikotes online dengan dua peran: **Peserta** dan **Admin**.
Admin dapat memantau aktivitas peserta, membuat/mengelola dua jenis tes
(**Tes Logika** dan **Tes Psikotes Kepribadian**), soal berupa pilihan
ganda dan mendukung gambar/pola, serta melihat rekap nilai. Tampilan
dashboard menggunakan Bootstrap 5 + tema custom modern (sidebar gelap,
kartu statistik, badge, dsb).

## Struktur Folder

```
psikotes/
├── admin/              # Halaman khusus admin
├── peserta/             # Halaman khusus peserta
├── includes/             # functions.php, layout header/footer bersama
├── config/database.php   # Konfigurasi koneksi PDO MySQL
├── assets/css/style.css  # Tema dashboard modern
├── uploads/soal/         # Tempat gambar soal & opsi diupload
├── database.sql          # Skema database + data contoh (seed)
├── index.php, login.php, register.php, logout.php
```

## Instalasi (XAMPP / Laragon / hosting PHP+MySQL biasa)

1. Copy folder `psikotes/` ke direktori web server Anda
   (misal `htdocs/psikotes` untuk XAMPP).
2. Buat database dengan mengimpor `database.sql` lewat phpMyAdmin,
   atau via CLI:
   ```
   mysql -u root -p < database.sql
   ```
   Ini otomatis membuat database `psikotes_online`, seluruh tabel,
   dan beberapa data contoh (1 tes logika + 1 tes psikotes beserta
   soal, plus akun demo).
3. Sesuaikan kredensial database di `config/database.php` jika perlu
   (default: host `localhost`, user `root`, password kosong).
4. Pastikan folder `uploads/soal/` dapat ditulis oleh web server
   (permission 755/775).
5. Akses lewat browser: `http://localhost/psikotes/`

## Akun Demo (password: `password123`)

| Role    | Email               |
|---------|---------------------|
| Admin   | admin@psikotes.test |
| Peserta | budi@psikotes.test  |
| Peserta | siti@psikotes.test  |

## Alur Penggunaan

**Admin**
1. Login → Dashboard menampilkan ringkasan (total peserta, tes aktif,
   sedang berlangsung, selesai) dan aktivitas terbaru.
2. Menu **Peserta & Aktivitas** → memantau siapa yang sedang
   mengerjakan tes dan siapa yang sudah selesai, real-time (refresh halaman).
3. Menu **Kelola Tes** → tambah tes baru (judul, jenis logika/psikotes,
   durasi), lalu klik **Soal** untuk menambah pertanyaan pilihan ganda
   (boleh upload gambar untuk soal berupa pola/gambar), tandai jawaban
   benar (tes logika) atau atur bobot nilai tiap opsi (tes psikotes).
4. Menu **Hasil & Nilai** → rekap semua hasil, bisa difilter per
   peserta/tes, dan lihat rincian jawaban tiap peserta.

**Peserta**
1. Register akun baru atau login dengan akun demo.
2. Dashboard menampilkan tes yang tersedia beserta status
   (belum dikerjakan / sedang berlangsung / selesai).
3. Klik **Mulai Tes** → mengerjakan soal dengan timer mundur sesuai
   durasi tes; jawaban tersimpan sampai waktu habis atau ditekan
   "Kumpulkan Jawaban".
4. Setelah selesai, peserta langsung melihat skor & rincian jawaban.
   Riwayat lengkap ada di menu **Riwayat & Nilai**.

## Catatan Teknis

- Menggunakan PHP native (tanpa framework) + PDO MySQL (prepared
  statements) agar aman dari SQL injection.
- Password di-hash dengan `password_hash()` (bcrypt).
- Sistem skor:
  - **Tes Logika**: setiap opsi punya flag `is_benar`; skor = jumlah
    jawaban benar.
  - **Tes Psikotes**: setiap opsi punya `nilai` (bobot), skor = total
    penjumlahan nilai opsi yang dipilih — cocok untuk skala
    kepribadian yang tidak mengenal "benar/salah".
- Timer tes berjalan di sisi klien (JS) tapi divalidasi ulang di
  server berdasarkan `waktu_mulai` + durasi, sehingga peserta tidak
  bisa mengakali dengan mengubah waktu di browser.
