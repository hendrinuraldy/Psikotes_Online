<?php
/**
 * Mesin skor untuk Tes Papikostik (PAPI Kostick), format single-choice
 * biasa (bukan forced-choice seperti DISC).
 *
 * VERSI INI DIPERBAIKI berdasarkan file sumber resmi
 * "Papikostic_Advance_Master_New.xls" (sheet DATA & URAIAN PAPIKOSTIK).
 * Tabel PAPIKOSTIK_RENTANG di bawah diambil LANGSUNG dari rumus Excel
 * asli (nested IF di sheet URAIAN PAPIKOSTIK, kolom F, baris 10-89),
 * bukan tebakan lagi. Titik potong (batas rentang) dan teks
 * interpretasinya sudah dicocokkan satu-satu ke rumus dan ke isi sel
 * DATA!V:Y baris 122-204 pada file sumber tsb.
 *
 * CARA KERJA TES INI (berdasarkan soal.php & hasil_detail.php yang
 * sudah ada di proyek ini):
 * - Tiap soal punya sampai 5 opsi (A-E), masing-masing opsi sudah
 *   dipetakan ke satu dari 20 huruf dimensi PAPI lewat kolom
 *   opsi.dimensi (lihat admin/soal.php - dimensiValid untuk
 *   tipe_psikotes = 'papikostik').
 * - Peserta memilih SATU opsi per soal (j.tipe_pilihan = 'single').
 * - Skor mentah tiap dimensi = jumlah opsi dengan dimensi itu yang
 *   dipilih peserta di seluruh soal.
 *
 * PENTING - PERBEDAAN DENGAN FILE SUMBER RESMI (WAJIB DIBACA):
 * File Excel sumber ("Advance Master") menghitung skor mentah tiap
 * dimensi lewat HLOOKUP ke tabel kunci jawaban baku 90 soal resmi PAPI
 * Kostick (bukan sekadar menjumlahkan berapa kali opsi suatu dimensi
 * dipilih). Bank soal di sistem web ini FLEKSIBEL - admin bisa
 * menambah soal & memetakan opsi ke dimensi bebas lewat admin/soal.php
 * - sehingga skor mentah di sini dihitung dengan cara tally sederhana
 * (hitung kemunculan tiap huruf dimensi di jawaban peserta), BUKAN
 * replikasi persis algoritma kunci jawaban baku 90-soal resminya.
 * Konsekuensinya: kalau bank soal di sistem ini tidak didesain ulang
 * supaya persis meniru struktur 90 soal resmi (jumlah soal per
 * dimensi seimbang, dsb), angka skor mentah tiap peserta TIDAK bisa
 * dijamin identik dengan hasil dari software "Advance Master" untuk
 * peserta yang sama. Yang sudah dipastikan sama persis dengan sumber
 * resmi di sini HANYA bagian konversi "skor mentah (0-9) -> teks
 * interpretasi" (tabel PAPIKOSTIK_RENTANG), karena itu yang bisa
 * diekstrak langsung dari rumus Excel-nya.
 *
 * Tidak ada koreksi usia (tanggal lahir) pada skoring Papikostik -
 * tidak ditemukan satupun rumus terkait usia di file sumber. Koreksi
 * usia hanya berlaku untuk tes IQ WPT (lihat includes/wpt_scoring.php).
 */

const PAPIKOSTIK_DIMENSI = [
    'A', 'N', 'G', 'C', 'D', 'R', 'T', 'V', 'W', 'F',
    'L', 'P', 'I', 'S', 'B', 'O', 'X', 'E', 'K', 'Z',
];

const PAPIKOSTIK_LABEL = [
    'A' => 'Kebutuhan Berprestasi (Need to Achieve)',
    'N' => 'Kebutuhan Menyelesaikan Tugas Secara Mandiri (Need to Finish Task)',
    'G' => 'Peran Pekerja Keras (Hard Worker Type)',
    'C' => 'Peran Mengatur (Organized Type)',
    'D' => 'Peran Bekerja dengan Hal-hal Rinci (Interest in Working With Details)',
    'R' => 'Peran Orang yang Teoritis (Theoretical Type)',
    'T' => 'Peran Sibuk (Pace)',
    'V' => 'Peran Penuh Semangat (Vigorous Type)',
    'W' => 'Kebutuhan Mengikuti Aturan dan Pengawasan (Need for Rules and Supervision)',
    'F' => 'Kebutuhan Membantu Atasan (Need to Support Authority)',
    'L' => 'Peran Pemimpin (Leadership Role)',
    'P' => 'Kebutuhan Mengatur Orang Lain (Need to Control Others)',
    'I' => 'Peran Membuat Keputusan (Ease in Decision Making)',
    'S' => 'Peran Hubungan Sosial (Social Extension)',
    'B' => 'Kebutuhan untuk Diterima dalam Kelompok (Need to Belong to Groups)',
    'O' => 'Kebutuhan Kedekatan dan Kasih Sayang (Need for Closeness and Affection)',
    'X' => 'Kebutuhan untuk Diperhatikan (Need to be Noticed)',
    'E' => 'Peran Pengendalian Emosi (Emotional Restraint)',
    'K' => 'Kebutuhan untuk Agresif (Need to be Forceful)',
    'Z' => 'Kebutuhan untuk Berubah (Need for Change)',
];

/**
 * Rentang interpretasi per dimensi, skala 0-9, diambil LANGSUNG dari
 * rumus nested-IF sheet "URAIAN PAPIKOSTIK" pada file sumber resmi.
 * Format tiap baris: [min, max, teks interpretasi]. Setiap dimensi
 * mencakup penuh 0-9 tanpa celah dan tanpa tumpang tindih (beda dari
 * versi sebelumnya).
 */
const PAPIKOSTIK_RENTANG = [
    'A' => [
        [0, 4, 'Tidak kompetitif, mapan, puas. Tidak terdorong untuk menghasilkan prestasi, tdk berusaha utk mencapai sukses, membutuhkan dorongan dari luar diri, tidak berinisiatif, tidak memanfaatkan kemampuan diri secara optimal, ragu akan tujuan diri, misalnya sbg akibat promosi / perubahan struktur jabatan.'],
        [5, 7, 'Tahu akan tujuan yang ingin dicapainya dan dapat merumuskannya, realistis akan kemampuan diri, dan berusaha untuk mencapai target.'],
        [8, 9, 'Sangat berambisi utk berprestasi dan menjadi yg terbaik, menyukai tantangan, cenderung mengejar kesempurnaan, menetapkan target yg tinggi, \'self-starter\', merumuskan kerja dg baik. Tdk realistis akan kemampuannya, sulit dipuaskan, mudah kecewa, harapan yg tinggi mungkin mengganggu org lain.'],
    ],
    'N' => [
        [0, 2, 'Tidak terlalu merasa perlu untuk menuntaskan sendiri tugas-tugasnya, senang menangani beberapa pekerjaan sekaligus, mudah mendelegasikan tugas. Komitmen rendah, cenderung meninggalkan tugas sebelum tuntas, konsentrasi mudah buyar, mungkin suka berpindah pekerjaan.'],
        [3, 5, 'Cukup memiliki komitmen untuk menuntaskan tugas, akan tetapi jika memungkinkan akan mendelegasikan sebagian dari pekerjaan nya kepada orang lain.'],
        [6, 7, 'Komitmen tinggi, lebih suka menangani pekerjaan satu demi satu, akan tetapi masih dapat mengubah prioritas jika terpaksa.'],
        [8, 9, 'Memiliki komitmen yg sangat tinggi thd tugas, sangat ingin menyelesaikan tugas, tekun dan tuntas dlm menangani pekerjaan satu demi satu hingga tuntas. Perhatian terpaku pada satu tugas, sulit utk menangani beberapa pekerjaan sekaligus, sulit di interupsi, tidak melihat masalah sampingan.'],
    ],
    'G' => [
        [0, 2, 'Santai, kerja adalah sesuatu yang menyenangkan-bukan beban yg membutuhkan usaha besar. Mungkin termotivasi utk mencari cara atau sistem yg dpt mempermudah dirinya dlm menyelesaikan pekerjaan, akan berusaha menghindari kerja keras, sehingga dapat memberi kesan malas.'],
        [3, 4, 'Bekerja keras sesuai tuntutan, menyalurkan usahanya untuk hal-hal yang bermanfaat / menguntungkan.'],
        [5, 7, 'Bekerja keras, tetapi jelas tujuan yg ingin dicapainya.'],
        [8, 9, 'Ingin tampil sbg pekerja keras, sangat suka bila orang lain memandangnya sbg pekerja keras. Cenderung menciptakan pekerjaan yang tidak perlu agar terlihat tetap sibuk, kadang kala tanpa tujuan yang jelas.'],
    ],
    'C' => [
        [0, 2, 'Lebih mementingkan fleksibilitas daripada struktur, pendekatan kerja lebih ditentukan oleh situasi daripada oleh perencanaan sebelumnya, mudah beradaptasi. Tidak mempedulikan keteraturan atau kerapihan, ceroboh.'],
        [3, 4, 'Fleksibel tapi masih cukup memperhatikan keteraturan atau sistematika kerja.'],
        [5, 6, 'Memperhatikan keteraturan dan sistematika kerja, tapi cukup fleksibel.'],
        [7, 9, 'Sistematis, bermetoda, berstruktur, rapi dan teratur, dapat menata tugas dengan baik. Cenderung kaku, tidak fleksibel.'],
    ],
    'D' => [
        [0, 1, 'Melihat pekerjaan scr makro, membedakan hal penting dari yg kurang penting, mendelegasikan detil pd org lain, generalis. Menghindari detail, konsekuensinya mungkin bertindak tanpa data yg cukup/akurat, bertindak ceroboh pd hal yg butuh kecermatan. Dpt mengabaikan proses yg vital dlm evaluasi data.'],
        [2, 3, 'Cukup peduli akan akurasi dan kelengkapan data.'],
        [4, 6, 'Tertarik untuk menangani sendiri detail.'],
        [7, 9, 'Sangat menyukai detail, sangat peduli akan akurasi dan kelengkapan data. Cenderung terlalu terlibat dengan detail sehingga melupakan tujuan utama.'],
    ],
    'R' => [
        [0, 3, 'Tipe pelaksana, praktis - pragmatis, mengandalkan pengalaman masa lalu dan intuisi. Bekerja tanpa perencanaan, mengandalkan perasaan.'],
        [4, 5, 'Pertimbangan mencakup aspek teoritis ( konsep atau pemikiran baru ) dan aspek praktis ( pengalaman ) secara berimbang'],
        [6, 7, 'Suka memikirkan suatu problem secara mendalam, merujuk pada teori dan konsep.'],
        [8, 9, 'Tipe pemikir, sangat berminat pada gagasan, konsep, teori,mencari alternatif baru, menyukai perencanaan. Mungkin sulit dimengerti oleh orang lain, terlalu teoritis dan tidak praktis, mengawang-awang dan berbelit-belit.'],
    ],
    'T' => [
        [0, 3, 'Santai. Kurang peduli akan waktu, kurang memiliki rasa urgensi, membuang-buang waktu, bukan pekerja yang tepat waktu.'],
        [4, 6, 'Cukup aktif dalam segi mental, dapat menyesuaikan tempo kerjanya dengan tuntutan pekerjaan / lingkungan.'],
        [7, 9, 'Cekatan, selalu siaga, bekerja cepat, ingin segera menyelesaikan tugas. Negatifnya : Tegang, cemas, impulsif, mungkin ceroboh, banyak gerakan yang tidak perlu.'],
    ],
    'V' => [
        [0, 2, 'Cocok untuk pekerjaan \' di belakang meja \'. Cenderung lamban, tidak tanggap, mudah lelah, daya tahan lemah.'],
        [3, 6, 'Dapat bekerja di belakang meja dan senang jika sesekali harus terjun ke lapangan atau melaksanakan tugas-tugas yang bersifat mobile.'],
        [7, 9, 'Menyukai aktifitas fisik ( a.l. : olah raga), enerjik, memiliki stamina untuk menangani tugas-tugas berat, tidak mudah lelah. Tidak betah duduk lama, kurang dapat konsentrasi \'di belakang meja\'.'],
    ],
    'W' => [
        [0, 3, 'Hanya butuh gambaran ttg kerangka tugas scr garis besar, berpatokan pd tujuan, dpt bekerja dlm suasana yg kurang berstruktur, berinsiatif, mandiri. Tdk patuh, cenderung mengabaikan/tdk paham pentingnya peraturan/prosedur, suka membuat peraturan sendiri yg bisa bertentangan dg yg telah ada.'],
        [4, 5, 'Perlu pengarahan awal dan tolok ukur keberhasilan.'],
        [6, 7, 'Membutuhkan uraian rinci mengenai tugas, dan batasan tanggung jawab serta wewenang.'],
        [8, 9, 'Patuh pada kebijaksanaan, peraturan dan struktur organisasi. Ingin segala sesuatunya diuraikan secara rinci, kurang memiliki inisiatif, tdk fleksibel, terlalu tergantung pada organisasi, berharap \'disuapi\'.'],
    ],
    'F' => [
        [0, 3, 'Otonom, dapat bekerja sendiri tanpa campur tangan orang lain, motivasi timbul krn pekerjaan itu sendiri - bukan krn pujian dr otoritas. Mempertanyakan otoritas, cenderung tidak puas thdp atasan, loyalitas lebih didasari kepentingan pribadi.'],
        [4, 6, 'Loyal pada Perusahaan.'],
        [7, 7, 'Loyal pada pribadi atasan.'],
        [8, 9, 'Loyal, berusaha dekat dg pribadi atasan, ingin menyenangkan atasan, sadar akan harapan atasan akan dirinya. Terlalu memperhatikan cara menyenangkan atasan, tidak berani berpendirian lain, tidak mandiri.'],
    ],
    'L' => [
        [0, 1, 'Puas dengan peran sebagai bawahan, memberikan kesempatan pada orang lain untuk memimpin, tidak dominan. Tidak percaya diri; sama sekali tidak berminat untuk berperan sebagai pemimpin; bersikap pasif dalam kelompok.'],
        [2, 3, 'Tidak percaya diri dan tidak ingin memimpin atau mengawasi orang lain.'],
        [4, 4, 'Kurang percaya diri dan kurang berminat utk menjadi pemimpin'],
        [5, 5, 'Cukup percaya diri, tidak secara aktif mencari posisi kepemimpinan akan tetapi juga tidak akan menghindarinya.'],
        [6, 7, 'Percaya diri dan ingin berperan sebagai pemimpin'],
        [8, 9, 'Sangat percaya diri utk berperan sbg atasan & sangat mengharapkan posisi tersebut. Lebih mementingkan citra & status kepemimpinannya dari pada efektifitas kelompok, mungkin akan tampil angkuh atau terlalu percaya diri.'],
    ],
    'P' => [
        [0, 1, 'Permisif, akan memberikan kesempatan pada orang lain untuk memimpin. Tidak mau mengontrol orang lain dan tidak mau mempertanggung jawabkan hasil kerja bawahannya.'],
        [2, 3, 'Enggan mengontrol org lain & tidak mau mempertanggung jawabkan hasil kerja bawahannya, lebih memberi kebebasan kpd bawahan utk memilih cara sendiri dlm penyelesaian tugas dan meminta bawahan utk mempertanggungjawabkan hasilnya masing-masing.'],
        [4, 4, 'Cenderung enggan melakukan fungsi pengarahan, pengendalian dan pengawasan, kurang aktif memanfaatkan kapasitas bawahan secara optimal, cenderung bekerja sendiri dalam mencapai tujuan kelompok.'],
        [5, 5, 'Bertanggung jawab, akan melakukan fungsi pengarahan, pengendalian dan pengawasan, tapi tidak mendominasi.'],
        [6, 7, 'Dominan dan bertanggung jawab, akan melakukan fungsi pengarahan, pengendalian dan pengawasan'],
        [8, 9, 'Sangat dominan, sangat mempengaruhi & mengawasi org lain, bertanggung jawab atas tindakan & hasil kerja bawahan. Posesif, tdk ingin berada di bawah pimpinan org lain, cemas bila tdk berada di posisi pemimpin, mungkin sulit utk bekerja sama dgn rekan yg sejajar kedudukannya.'],
    ],
    'I' => [
        [0, 1, 'Sangat berhati - hati, memikirkan langkahlangkahnya secara bersungguh - sungguh. Lamban dlm mengambil keputusan, terlalu lama merenung, cenderung menghindar mengambil keputusan.'],
        [2, 3, 'Enggan mengambil keputusan.'],
        [4, 5, 'Berhati - hati dlm pengambilan keputusan.'],
        [6, 7, 'Cukup percaya diri dlm pengambilan keputusan, mau mengambil resiko, dpt memutuskan dgn cepat, mengikuti alur logika.'],
        [8, 9, 'Sangat yakin dl mengambil keputusan, cepat tanggap thd situasi, berani mengambil resiko, mau memanfaatkan kesempatan. Impulsif, dpt membuat keputusan yg tdk praktis, cenderung lebih mementingkan kecepatan daripada akurasi, tdk sabar, cenderung meloncat pd keputusan.'],
    ],
    'S' => [
        [0, 2, 'Dpt. bekerja sendiri, tdk membutuhkan kehadiran org lain. Menarik diri, kaku dlm bergaul, canggung dlm situasi sosial, lebih memperhatikan hal - hal lain daripada manusia.'],
        [3, 4, 'Kurang percaya diri & kurang aktif dlm menjalin hubungan sosial.'],
        [5, 9, 'Percaya diri & sangat senang bergaul, menyukai interaksi sosial, bisa menciptakan suasana yg menyenangkan, mempunyai inisiatif & mampu menjalin hubungan & komunikasi, memperhatikan org lain. Mungkin membuangbuang waktu utk aktifitas sosial, kurang peduli akan penyelesaian tugas.'],
    ],
    'B' => [
        [0, 2, 'Mandiri ( dari segi emosi ) , tdk mudah dipengaruhi oleh tekanan kelompok. Penyendiri, kurang peka akan sikap & kebutuhan kelompok, mungkin sulit menyesuaikan diri.'],
        [3, 5, 'Selektif dlm bergabung dg kelompok, hanya mau berhubungan dg kelompok di lingkungan kerja apabila bernilai & sesuai minat, tdk terlalu mudah dipengaruhi.'],
        [6, 9, 'Suka bergabung dlm kelompok, sadar akan sikap & kebutuhan kelompok, suka bekerja sama, ingin menjadi bagian dari kelompok, ingin disukai & diakui oleh lingkungan; sangat tergantung pd kelompok, lebih memperhatikan kebutuhan kelompok daripada pekerjaan.'],
    ],
    'O' => [
        [0, 2, 'Menjaga jarak, lebih memperhatikan hal - hal kedinasan, tdk mudah dipengaruhi oleh individu tertentu, objektif & analitis. Tampil dingin, tdk acuh, tdk ramah, suka berahasia, mungkin tdk sadar akan perasaan org lain, & mungkin sulit menyesuaikan diri.'],
        [3, 5, 'Tidak mencari atau menghindari hubungan antar pribadi di lingkungan kerja, masih mampu menjaga jarak.'],
        [6, 9, 'Peka akan kebutuhan org lain, sangat memikirkan hal - hal yg dibutuhkan org lain, suka menjalin hub persahabatan yg hangat & tulus. Sangat perasa, mudah tersinggung, cenderung subjektif, dpt terlibat terlalu dlm/intim dg individu tertentu dlm pekerjaan, sangat tergantung pd individu tertentu.'],
    ],
    'X' => [
        [0, 1, 'Sederhana, rendah hati, tulus, tidak sombong dan tidak suka menampilkan diri. Terlalu sederhana, cenderung merendahkan kapasitas diri, tidak percaya diri, cenderung menarik diri dan pemalu.'],
        [2, 3, 'Sederhana, cenderung diam, cenderung pemalu, tidak suka menonjolkan diri.'],
        [4, 5, 'Mengharapkan pengakuan lingkungan dan tidak mau diabaikan tetapi tidak mencari-cari perhatian.'],
        [6, 9, 'Bangga akan diri dan gayanya sendiri, senang menjadi pusat perhatian, mengharapkan penghargaan dari lingkungan. Mencari-cari perhatian dan suka menyombongkan diri.'],
    ],
    'E' => [
        [0, 1, 'Sangat terbuka, terus terang, mudah terbaca (dari air muka, tindakan, perkataan, sikap). Tidak dapat mengendalikan emosi, cepat bereaksi, kurang mengindahkan/tidak mempunyai \'nilai\' yg mengharuskannya menahan emosi.'],
        [2, 3, 'Terbuka, mudah mengungkap pendapat atau perasaannya mengenai suatu hal kepada org lain.'],
        [4, 6, 'Mampu mengungkap atau menyimpan perasaan, dapat mengendalikan emosi.'],
        [7, 9, 'Mampu menyimpan pendapat atau perasaannya, tenang, dapat mengendalikan emosi, menjaga jarak. Tampil pasif dan tidak acuh, mungkin sulit mengungkapkan emosi/perasaan/pandangan.'],
    ],
    'K' => [
        [0, 1, 'Sabar, tidak menyukai konflik. Mengelak atau menghindar dari konflik, pasif, menekan atau menyembunyikan perasaan sesungguhnya, menghindari konfrontasi, lari dari konflik, tidak mau mengakui adanya konflik.'],
        [2, 3, 'Lebih suka menghindari konflik, akan mencari rasionalisasi untuk dapat menerima situasi dan melihat permasalahan dari sudut pandang orang lain.'],
        [4, 5, 'Tidak mencari atau menghindari konflik, mau mendengarkan pandangan orang lain tetapi dapat menjadi keras kepala saat mempertahankan pandangannya.'],
        [6, 7, 'Akan menghadapi konflik, mengungkapkan serta memaksakan pandangan dengan cara positif.'],
        [8, 9, 'Terbuka, jujur, terus terang, asertif, agresif, reaktif, mudah tersinggung, mudah meledak, curiga, berprasangka, suka berkelahi atau berkonfrontasi, berpikir negatif.'],
    ],
    'Z' => [
        [0, 1, 'Mudah beradaptasi dg pekerjaan rutin tanpa merasa bosan, tidak membutuhkan variasi, menyukai lingkungan stabil dan tidak berubah. Konservatif, menolak perubahan, sulit menerima hal-hal baru, tidak dapat beradaptasi dengan situasi yg berbeda-beda.'],
        [2, 3, 'Enggan berubah, tidak siap untuk beradaptasi, hanya mau menerima perubahan jika alasannya jelas dan meyakinkan.'],
        [4, 5, 'Mudah beradaptasi, cukup menyukai perubahan.'],
        [6, 7, 'Antusias terhadap perubahan dan akan mencari hal-hal baru, tetapi masih selektif ( menilai kemanfaatannya ).'],
        [8, 9, 'Sangat menyukai perubahan, gagasan baru/variasi, aktif mencari perubahan, antusias dg hal-hal baru, fleksibel dlm berpikir, mudah beradaptasi pd situasi yg berbeda-beda. Gelisah, frustasi, mudah bosan, sangat membutuhkan variasi, tidak menyukai tugas/situasi yg rutin-monoton.'],
    ],
];

/**
 * Cari teks interpretasi untuk satu dimensi & skor mentah tertentu.
 * Skor di luar 0-9 dibatasi (clamp) ke ujung skala terdekat, karena
 * tabel sumber hanya mendefinisikan rentang 0-9.
 */
function papikostikInterpretasi(string $dimensi, int $skor): string
{
    $rentangList = PAPIKOSTIK_RENTANG[$dimensi] ?? [];
    if (empty($rentangList)) {
        return '-';
    }
    
    $skor = max(0, min(9, $skor));

    foreach ($rentangList as $r) {
        [$min, $max, $teks] = $r;
        if ($skor >= $min && $skor <= $max) {
            return $teks;
        }
    }

    return $rentangList[0][2];
}

/**
 * Hitung skor Papikostik dari kumpulan baris jawaban satu attempt.
 *
 * @param string[] $dimensiTerpilih Daftar huruf dimensi dari setiap opsi
 *   yang dipilih peserta (satu entri per soal yang dijawab), contoh
 *   hasil query:
 *     SELECT o.dimensi FROM jawaban j JOIN opsi o ON o.id = j.opsi_id
 *     WHERE j.attempt_id = ? AND o.dimensi IS NOT NULL
 * @return array{
 *   skor: array<string,int>,          // skor mentah tiap dimensi (tally, di-clamp 0-9 saat interpretasi)
 *   interpretasi: array<string,string>, // teks interpretasi tiap dimensi
 *   dominan: string[]                 // 3 dimensi dgn skor tertinggi
 * }
 */
function hitungSkorPapikostik(array $dimensiTerpilih): array
{
    $skor = array_fill_keys(PAPIKOSTIK_DIMENSI, 0);

    foreach ($dimensiTerpilih as $d) {
        if (in_array($d, PAPIKOSTIK_DIMENSI, true)) {
            $skor[$d]++;
        }
    }

    $interpretasi = [];
    foreach (PAPIKOSTIK_DIMENSI as $d) {
        $interpretasi[$d] = papikostikInterpretasi($d, $skor[$d]);
    }

    $urutanDominan = PAPIKOSTIK_DIMENSI;
    usort($urutanDominan, fn($a, $b) => $skor[$b] <=> $skor[$a]);
    $dominan = array_slice($urutanDominan, 0, 3);

    return [
        'skor' => $skor,
        'interpretasi' => $interpretasi,
        'dominan' => $dominan,
    ];
}

/**
 * Simpan hasil akhir Papikostik ke tabel attempt (dipanggil setelah
 * peserta submit semua jawaban). Panggil fungsi ini di tempat yang sama
 * dengan simpanHasilDisc() dipanggil untuk tes DISC - biasanya di
 * peserta/proses_tes.php setelah attempt ditandai selesai, contoh:
 *
 *   if ($tes['tipe_psikotes'] === 'disc') {
 *       simpanHasilDisc($attemptId, $db);
 *   } elseif ($tes['tipe_psikotes'] === 'papikostik') {
 *       simpanHasilPapikostik($attemptId, $db);
 *   }
 */
function simpanHasilPapikostik(int $attemptId, $db): array
{
    $stmt = $db->prepare("
        SELECT o.dimensi
        FROM jawaban j
        JOIN opsi o ON o.id = j.opsi_id
        WHERE j.attempt_id = ? AND o.dimensi IS NOT NULL
    ");
    $stmt->execute([$attemptId]);
    $dimensiTerpilih = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $hasil = hitungSkorPapikostik($dimensiTerpilih);

    // Ringkasan singkat 3 dimensi tertinggi sebagai profil_hasil, mis. "L-A-G"
    $profilHasil = implode('-', $hasil['dominan']);
    $profilHasil = mb_substr($profilHasil, 0, 20);

    $stmt = $db->prepare("
        UPDATE attempt
        SET profil_hasil = ?, detail_skor = ?, status = 'selesai', waktu_selesai = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$profilHasil, json_encode($hasil, JSON_UNESCAPED_UNICODE), $attemptId]);

    return $hasil;
}