<?php
/**
 * includes/mbti_data.php
 * Deskripsi 16 tipe kepribadian (MBTI / 16Personalities).
 * Sumber: MBTI_16_Personalities.docx. Untuk mengubah teks, edit langsung array di bawah.
 * Kunci = 4 huruf tipe (tanpa akhiran -A / -T).
 */
const MBTI_DATA = [
    'INTJ' => [
        'nama'      => 'Arsitek',
        'deskripsi' => 'INTJ (Arsitek) adalah tipe kepribadian yang cerdas, strategis, mandiri, dan sangat berorientasi pada tujuan. Mereka senang belajar, berpikir logis, serta merencanakan segala sesuatu dengan matang untuk mencapai hasil terbaik. Meski sering terlihat dingin, kritis, dan kurang menyukai basa-basi, INTJ sebenarnya menghargai hubungan yang mendalam dan bermakna. Secara keseluruhan, mereka adalah pemecah masalah yang visioner, selalu mencari cara yang lebih baik untuk berkembang dan mencapai kesuksesan.',
    ],
    'INTP' => [
        'nama'      => 'Ahli Logika',
        'deskripsi' => 'INTP (Ahli Logika) adalah tipe kepribadian yang sangat penasaran, analitis, kreatif, dan senang mengeksplorasi ide-ide baru. Mereka menikmati berpikir mendalam, mencari pola, serta memahami bagaimana sesuatu bekerja, sehingga sering unggul dalam pemecahan masalah dan inovasi. INTP cenderung mandiri dan lebih nyaman menghabiskan waktu dengan pemikiran mereka sendiri, namun dapat menjadi sangat antusias saat berdiskusi dengan orang yang memiliki minat intelektual serupa. Tantangan mereka adalah kecenderungan untuk terlalu banyak berpikir, menunda tindakan, dan kesulitan memahami atau mengekspresikan emosi. Secara keseluruhan, INTP adalah pemikir yang inovatif dan logis yang selalu terdorong untuk memahami dunia melalui pengetahuan dan eksplorasi ide.',
    ],
    'ENTJ' => [
        'nama'      => 'Komandan',
        'deskripsi' => 'ENTJ (Komandan) adalah tipe kepribadian yang tegas, percaya diri, ambisius, dan memiliki bakat alami dalam memimpin. Mereka senang menetapkan tujuan besar, menyusun strategi yang efektif, lalu menggerakkan orang lain untuk mencapainya. ENTJ cenderung berpikir logis, fokus pada hasil, dan tidak ragu mengambil keputusan sulit ketika diperlukan. Mereka unggul dalam organisasi, kepemimpinan, dan pemecahan masalah, tetapi terkadang bisa terlihat terlalu dominan, tidak sabar, atau kurang peka terhadap perasaan orang lain. Secara keseluruhan, ENTJ adalah pemimpin visioner yang terdorong untuk menciptakan kemajuan dan mencapai kesuksesan melalui perencanaan, efisiensi, dan tindakan yang tegas.',
    ],
    'ENTP' => [
        'nama'      => 'Ahli Debat',
        'deskripsi' => 'ENTP (Ahli Debat) adalah tipe kepribadian yang cerdas, kreatif, penuh rasa ingin tahu, dan senang menantang cara berpikir yang sudah umum. Mereka menikmati diskusi, perdebatan, dan eksplorasi ide-ide baru, serta memiliki kemampuan melihat berbagai sudut pandang dengan cepat. ENTP dikenal inovatif, percaya diri, dan fleksibel, tetapi terkadang mudah bosan, sulit fokus pada pelaksanaan jangka panjang, dan dapat terlihat terlalu suka berargumen atau kurang peka terhadap perasaan orang lain. Secara keseluruhan, ENTP adalah pemikir yang dinamis dan visioner yang terdorong untuk menemukan kemungkinan baru, mendorong perubahan, dan terus mempertanyakan batas-batas yang ada.',
    ],
    'INFJ' => [
        'nama'      => 'Advokat',
        'deskripsi' => 'INFJ (Advokat) adalah tipe kepribadian yang idealis, penuh empati, dan memiliki dorongan kuat untuk memberikan dampak positif bagi orang lain. Mereka menghargai nilai-nilai, integritas, serta hubungan yang mendalam dan bermakna, sambil terus mencari tujuan hidup yang lebih besar. INFJ dikenal sebagai pribadi yang bijaksana, intuitif, dan peduli terhadap keadilan, sering menggunakan kreativitas dan kepedulian mereka untuk membantu orang lain. Namun, karena terlalu fokus pada kebutuhan orang lain dan standar ideal yang tinggi, mereka terkadang rentan terhadap stres, kelelahan, dan terlalu sensitif terhadap kritik. Secara keseluruhan, INFJ adalah sosok yang menginspirasi, berprinsip, dan berdedikasi untuk membuat dunia menjadi tempat yang lebih baik.',
    ],
    'INFP' => [
        'nama'      => 'Mediator',
        'deskripsi' => 'INFP (Mediator) adalah tipe kepribadian yang idealis, empatik, kreatif, dan sangat dipandu oleh nilai-nilai pribadi yang mereka yakini. Mereka berusaha menemukan makna dan tujuan dalam hidup, serta memiliki kepedulian yang tulus terhadap orang lain dan dunia di sekitar mereka. INFP dikenal imajinatif, berpikiran terbuka, dan menghargai keaslian dalam hubungan, namun terkadang cenderung terlalu idealis, sensitif terhadap kritik, dan kesulitan menghadapi kenyataan yang tidak sesuai dengan harapan mereka. Secara keseluruhan, INFP adalah pribadi yang hangat dan penuh empati yang ingin hidup selaras dengan nilai-nilai mereka sambil memberikan dampak positif bagi orang lain.',
    ],
    'ENFJ' => [
        'nama'      => 'Protagonis',
        'deskripsi' => 'ENFJ (Protagonis) adalah tipe kepribadian yang karismatik, empatik, dan inspiratif, dengan dorongan kuat untuk membantu orang lain berkembang dan menciptakan dampak positif bagi lingkungan sekitarnya. Mereka memiliki kemampuan alami dalam memimpin, membangun hubungan, serta memahami perasaan dan motivasi orang lain, sehingga sering menjadi sumber dukungan dan motivasi bagi banyak orang. ENFJ dikenal idealis, peduli, dan berorientasi pada nilai-nilai, namun terkadang terlalu terlibat dalam masalah orang lain atau memiliki ekspektasi yang tinggi terhadap mereka. Secara keseluruhan, ENFJ adalah pemimpin yang hangat dan visioner yang berupaya menginspirasi, membimbing, dan membawa perubahan positif bagi orang-orang di sekitarnya.',
    ],
    'ENFP' => [
        'nama'      => 'Juru Kampanye',
        'deskripsi' => 'ENFP (Juru Kampanye) adalah tipe kepribadian yang antusias, kreatif, ramah, dan penuh empati, dengan dorongan kuat untuk menemukan makna serta membangun hubungan yang mendalam dengan orang lain. Mereka dikenal sebagai pribadi yang optimis, berpikiran terbuka, dan mampu menginspirasi orang di sekitarnya melalui energi positif dan ide-ide kreatifnya. ENFP senang mengeksplorasi kemungkinan baru, menghargai kebebasan, dan percaya bahwa setiap orang memiliki potensi untuk berkembang. Namun, mereka terkadang kesulitan menjaga konsistensi, mudah kehilangan minat setelah antusiasme awal memudar, dan cenderung terlalu memikirkan perasaan atau tindakan orang lain. Secara keseluruhan, ENFP adalah pribadi yang hangat, inspiratif, dan penuh semangat yang berusaha membawa makna, kebahagiaan, dan koneksi yang lebih dalam ke dalam kehidupan mereka dan orang-orang di sekitarnya.',
    ],
    'ISTJ' => [
        'nama'      => 'Ahli Logistik',
        'deskripsi' => 'ISTJ (Ahli Logistik) adalah tipe kepribadian yang praktis, disiplin, bertanggung jawab, dan sangat dapat diandalkan. Mereka menghargai struktur, aturan, serta komitmen, dan dikenal sebagai pribadi yang konsisten dalam menyelesaikan tugas dengan teliti dan penuh integritas. ISTJ cenderung berpikir berdasarkan fakta dan logika, lebih memilih perencanaan yang matang daripada tindakan impulsif. Mereka setia, jujur, dan memiliki etos kerja yang kuat, namun terkadang dapat terlihat kaku, terlalu kritis, atau kesulitan mengekspresikan emosi. Secara keseluruhan, ISTJ adalah sosok yang stabil dan terpercaya yang menjadi fondasi kuat bagi keluarga, tim, maupun organisasi berkat dedikasi dan rasa tanggung jawabnya yang tinggi.',
    ],
    'ISFJ' => [
        'nama'      => 'Pembela',
        'deskripsi' => 'ISFJ (Pembela) adalah tipe kepribadian yang hangat, setia, peduli, dan sangat berdedikasi dalam membantu serta melindungi orang-orang yang mereka sayangi. Mereka dikenal bertanggung jawab, dapat diandalkan, teliti, dan memiliki perhatian besar terhadap kebutuhan orang lain, sering kali bekerja tanpa mengharapkan pengakuan. ISFJ menghargai stabilitas, tradisi, dan hubungan yang kuat, serta senang menciptakan lingkungan yang aman dan nyaman bagi orang di sekitarnya. Namun, mereka terkadang sulit mengatakan "tidak", mudah merasa terbebani oleh tanggung jawab, dan kurang nyaman menghadapi perubahan yang mendadak. Secara keseluruhan, ISFJ adalah sosok yang penuh kasih, rendah hati, dan dapat dipercaya, yang memberikan dukungan nyata melalui kesetiaan serta kepedulian mereka yang tulus.',
    ],
    'ESTJ' => [
        'nama'      => 'Eksekutif',
        'deskripsi' => 'ESTJ (Eksekutif) adalah tipe kepribadian yang tegas, terorganisir, bertanggung jawab, dan memiliki kemampuan alami dalam memimpin serta mengelola orang maupun pekerjaan. Mereka menghargai aturan, tradisi, kejujuran, dan kerja keras, serta dikenal karena kemampuannya menyusun dan menjalankan rencana secara efektif. ESTJ sangat dapat diandalkan, berorientasi pada hasil, dan senang memastikan segala sesuatu berjalan dengan tertib dan efisien. Namun, mereka terkadang dapat terlihat kaku, kurang fleksibel, atau terlalu menuntut orang lain untuk bekerja sesuai standar mereka. Secara keseluruhan, ESTJ adalah pemimpin yang praktis dan terpercaya yang berfokus pada menciptakan keteraturan, mencapai tujuan, dan menjaga agar tim atau komunitas tetap berjalan dengan baik.',
    ],
    'ESFJ' => [
        'nama'      => 'Konsul',
        'deskripsi' => 'ESFJ (Konsul) adalah tipe kepribadian yang ramah, hangat, peduli, dan sangat berorientasi pada hubungan sosial. Mereka senang membantu orang lain, menjaga keharmonisan, serta menciptakan lingkungan yang nyaman dan suportif bagi keluarga, teman, maupun komunitas. ESFJ dikenal bertanggung jawab, setia, dan peka terhadap kebutuhan orang lain, sehingga sering menjadi sosok yang diandalkan dalam kelompok. Namun, mereka terkadang terlalu memikirkan pendapat orang lain, sulit menghadapi konflik, dan dapat merasa terluka jika usaha atau kepedulian mereka tidak dihargai. Secara keseluruhan, ESFJ adalah pribadi yang penuh perhatian dan berjiwa sosial yang berusaha membuat orang-orang di sekitarnya merasa dihargai, didukung, dan terhubung satu sama lain.',
    ],
    'ISTP' => [
        'nama'      => 'Virtuoso',
        'deskripsi' => 'ISTP (Virtuoso) adalah tipe kepribadian yang mandiri, praktis, dan sangat suka memahami dunia melalui pengalaman langsung. Mereka dikenal sebagai pemecah masalah yang logis, senang bereksperimen, memperbaiki sesuatu, dan mencari solusi yang efisien tanpa kerumitan yang tidak perlu. ISTP menghargai kebebasan, fleksibilitas, dan lebih suka bekerja dengan cara mereka sendiri daripada mengikuti aturan atau rutinitas yang terlalu ketat. Mereka cenderung tenang, lugas, dan fokus pada tindakan, namun terkadang terlihat impulsif, sulit diprediksi, atau kurang ekspresif dalam menunjukkan perasaan. Secara keseluruhan, ISTP adalah pribadi yang adaptif, inovatif, dan berorientasi pada praktik, yang menikmati tantangan serta kebebasan untuk mengeksplorasi minat mereka sendiri.',
    ],
    'ISFP' => [
        'nama'      => 'Petualang',
        'deskripsi' => 'ISFP (Petualang) adalah tipe kepribadian yang kreatif, hangat, fleksibel, dan menghargai kebebasan untuk mengekspresikan diri. Mereka menikmati pengalaman baru, hidup di masa kini, serta memiliki rasa empati dan toleransi yang tinggi terhadap orang lain. ISFP dikenal sebagai pribadi yang ramah, peka, dan mendukung, sering menemukan keindahan dalam hal-hal sederhana serta mendorong orang lain untuk mengikuti passion mereka. Namun, mereka terkadang kesulitan membuat atau mengikuti rencana jangka panjang dan mudah terpengaruh oleh penilaian orang lain. Secara keseluruhan, ISFP adalah sosok yang autentik, penuh kasih, dan berjiwa bebas yang berusaha menjalani hidup secara harmonis sesuai dengan nilai dan perasaannya sendiri.',
    ],
    'ESTP' => [
        'nama'      => 'Pengusaha',
        'deskripsi' => 'ESTP (Pengusaha) adalah tipe kepribadian yang energik, berani, spontan, dan sangat berorientasi pada tindakan. Mereka menikmati tantangan, suka mengambil peluang secara langsung, dan cenderung belajar melalui pengalaman nyata daripada teori. ESTP dikenal percaya diri, cepat beradaptasi, jujur, serta memiliki kemampuan observasi yang tajam terhadap situasi dan orang-orang di sekitarnya. Mereka sering menjadi pusat energi dalam kelompok dan mampu memimpin secara alami, namun terkadang dapat bertindak impulsif, kurang menyukai aturan yang terlalu ketat, atau lebih fokus pada saat ini daripada perencanaan jangka panjang. Secara keseluruhan, ESTP adalah pribadi yang dinamis, praktis, dan penuh inisiatif yang senang menghadapi kehidupan sebagai petualangan yang penuh peluang dan pengalaman baru.',
    ],
    'ESFP' => [
        'nama'      => 'Penghibur',
        'deskripsi' => 'ESFP (Penghibur) adalah tipe kepribadian yang ceria, spontan, energik, dan sangat menikmati interaksi dengan orang lain. Mereka memiliki kemampuan alami untuk menciptakan suasana yang menyenangkan, peka terhadap perasaan orang di sekitarnya, serta senang membantu dan memberi semangat kepada orang lain. ESFP menghargai pengalaman, kebebasan, dan menikmati hidup pada saat ini, sering kali membawa energi positif ke mana pun mereka pergi. Namun, mereka terkadang kurang menyukai perencanaan jangka panjang, mudah terdistraksi oleh kesenangan sesaat, dan cenderung menghindari konflik atau kritik. Secara keseluruhan, ESFP adalah pribadi yang hangat, ekspresif, dan penuh antusiasme yang membuat hidup terasa lebih berwarna bagi orang-orang di sekitarnya.',
    ],
];

/**
 * Ambil data tipe dari kode seperti "ENFJ-T" atau "enfj". Null kalau tidak dikenal.
 */
function mbtiDataTipe(?string $tipe): ?array
{
    if (!$tipe) return null;
    $kode = strtoupper(substr(trim($tipe), 0, 4));
    return MBTI_DATA[$kode] ?? null;
}

const MBTI_NAMA_SIFAT = ['Energi', 'Pikiran', 'Perasaan', 'Taktik', 'Identitas'];
const MBTI_LABEL_SISI = [
    ['E' => 'Ekstrovert',  'I' => 'Introvert'],
    ['N' => 'Visioner',    'S' => 'Realistik'],
    ['T' => 'Rasional',    'F' => 'Emosional'],
    ['J' => 'Perencanaan', 'P' => 'Improvisasi'],
    ['A' => 'Tegas',       'T' => 'Waspada'],
];

/**
 * Hasil tes eksternal 16Personalities yang diisi peserta (dari attempt.profil_hasil
 * + attempt.detail_skor['tipe16']). Dipakai oleh export PDF / Excel per attempt.
 * Mengembalikan: ['tipe' => ?string, 'rows' => [[sifat, sisi, 'NN%'], ...],
 *                 'link' => ?string, 'desk' => ?array (nama, deskripsi)]
 */
function mbtiHasilEksternal(array $attempt): array
{
    $hasil = ['tipe' => null, 'rows' => [], 'link' => null, 'desk' => null];

    $t16 = [];
    if (!empty($attempt['detail_skor'])) {
        $dd = json_decode($attempt['detail_skor'], true);
        if (is_array($dd) && isset($dd['tipe16']) && is_array($dd['tipe16'])) $t16 = $dd['tipe16'];
    }
    $tipe = strtoupper(trim((string)($t16['tipe'] ?? ($attempt['profil_hasil'] ?? ''))));
    if ($tipe === '') return $hasil;
    $hasil['tipe'] = $tipe;
    $hasil['desk'] = mbtiDataTipe($tipe);

    $sisi   = is_array($t16['sisi'] ?? null)   ? array_values($t16['sisi'])   : [];
    $persen = is_array($t16['persen'] ?? null) ? array_values($t16['persen']) : [];
    if (count($sisi) !== 5 && preg_match('/^([EI])([NS])([TF])([JP])-([AT])$/', $tipe, $m)) {
        $sisi = [$m[1], $m[2], $m[3], $m[4], $m[5]];
    }
    if (count($sisi) === 5 && count($persen) === 5) {
        foreach ($sisi as $i => $kode) {
            $hasil['rows'][] = [MBTI_NAMA_SIFAT[$i], MBTI_LABEL_SISI[$i][$kode] ?? $kode, (int)$persen[$i] . '%'];
        }
    }

    $link = $t16['link'] ?? null;
    if ($link) {
        $pl = parse_url($link);
        if (($pl['scheme'] ?? '') === 'https'
            && in_array(strtolower($pl['host'] ?? ''), ['16personalities.com', 'www.16personalities.com'], true)) {
            $hasil['link'] = $link;
        }
    }
    return $hasil;
}