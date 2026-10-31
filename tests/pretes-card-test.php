<?php
/** Jalankan: php -n tests/pretes-card-test.php [direktori-preview]. Tidak mengakses database. */
$root = dirname(__DIR__);
$sandbox = sys_get_temp_dir() . '/lppai-pretes-card-' . bin2hex(random_bytes(6));
mkdir($sandbox . '/includes', 0700, true);
copy($root . '/cetak-kartu-pretes.php', $sandbox . '/cetak-kartu-pretes.php');
copy($root . '/includes/pretes-card.php', $sandbox . '/includes/pretes-card.php');
$stub = <<<'PHP'
<?php
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
http_response_code(200);
register_shutdown_function(static function () { echo "\nHTTP_STATUS=" . http_response_code(); });
define('BASE_URL', 'https://lppai.quizb.my.id');
$_GET = ['user_id' => 999, 'id' => 999];
function requireLogin() {
    if (getenv('PRETES_CARD_CASE') === 'anonymous') {
        http_response_code(302);
        exit;
    }
}
function getCurrentUser() {
    return [
        'id' => 42, 'role' => getenv('PRETES_CARD_CASE') === 'forbidden' ? 'dosen' : 'mahasiswa',
        'nama_lengkap' => 'Mahasiswa <script>alert("x")</script> & Contoh',
        'nim' => '2400123456', 'program_studi' => 'Pendidikan Agama Islam', 'fakultas' => 'Agama Islam',
    ];
}
function sanitize($value) { return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8'); }
function getDBConnection() {
    if (in_array(getenv('PRETES_CARD_CASE'), ['forbidden', 'anonymous'], true)) {
        throw new RuntimeException('Akses database sebelum otorisasi.');
    }
    return new class {
        public function prepare($sql) {
            if (strpos($sql, 'WHERE pr.user_id = ?') === false || strpos($sql, 'ps.id = pr.pretes_schedule_id') === false) {
                throw new RuntimeException('Kartu harus memakai pemilik dan jadwal yang tepat.');
            }
            return new class {
                public function execute($params) {
                    if ($params !== [42]) throw new RuntimeException('Parameter URL tidak boleh memilih pemilik kartu.');
                }
                public function fetch() {
                    $case = getenv('PRETES_CARD_CASE');
                    if ($case === 'empty') return false;
                    $data = ['id' => 123, 'periode' => 'Pretes 2026/2027', 'status' => 'terdaftar',
                        'hari_pilihan' => 'Senin', 'jam' => '13.00 WIB (Jam 1)', 'tanggal' => '2026-11-02',
                        'ruangan' => 'Ruang A', 'ruangan_jadwal' => 'Ruang Jadwal', 'dosen_penguji' => 'Penguji & Contoh',
                        'username_tes' => 'SECRET_USERNAME', 'password_tes' => 'SECRET_PASSWORD'];
                    if ($case === 'pending') {
                        foreach (['hari_pilihan', 'jam', 'tanggal', 'ruangan', 'ruangan_jadwal', 'dosen_penguji'] as $key) $data[$key] = null;
                    }
                    if ($case === 'legacy') {
                        $data['jam'] = '';
                        $data['ruangan'] = '';
                        $data['waktu_mulai'] = '09:30:00';
                        $data['waktu_selesai'] = '10:30:00';
                    }
                    return $data;
                }
            };
        }
    };
}
PHP;
file_put_contents($sandbox . '/includes/auth.php', $stub);
function assertCard($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
try {
    foreach (['full' => 200, 'pending' => 200, 'legacy' => 200, 'empty' => 404, 'forbidden' => 403, 'anonymous' => 302] as $case => $status) {
        putenv('PRETES_CARD_CASE=' . $case);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-n', $sandbox . '/cetak-kartu-pretes.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        assertCard(is_resource($process), 'Gagal menjalankan skenario.');
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        assertCard($exit === 0 && $errors === '', "$case: PHP error: $errors");
        assertCard(strpos($output, 'HTTP_STATUS=' . $status) !== false, "$case: status HTTP salah.");
        assertCard(strpos($output, '<script>alert') === false, "$case: data HTML harus di-escape.");
        assertCard(strpos($output, 'SECRET_') === false, "$case: kredensial CBT tidak boleh tercetak.");
        if ($status === 200) {
            assertCard(strpos($output, 'PRE-000123') !== false, "$case: nomor peserta hilang.");
            assertCard(strpos($output, '&lt;script&gt;') !== false, "$case: identitas harus aman.");
            assertCard(strpos($output, 'window.print()') !== false, "$case: aksi cetak hilang.");
        } else {
            assertCard(strpos($output, 'window.print()') === false, "$case: kartu tanpa akses tidak boleh dicetak.");
        }
        if ($case === 'full') {
            assertCard(strpos($output, '02/11/2026') !== false && strpos($output, 'Ruang A') !== false, 'Jadwal peserta salah.');
        }
        if ($case === 'pending') {
            assertCard(strpos($output, 'Menunggu pembagian') !== false && strpos($output, 'Menunggu penetapan') !== false, 'Data belum ditetapkan harus jelas.');
        }
        if ($case === 'legacy') {
            assertCard(strpos($output, '09:30 – 10:30 WIB') !== false && strpos($output, 'Ruang Jadwal') !== false, 'Jadwal lama harus tetap didukung.');
        }
        if ($case === 'full' && isset($argv[1])) {
            if (!is_dir($argv[1])) mkdir($argv[1], 0700, true);
            file_put_contents($argv[1] . '/pretes-card-preview.html', preg_replace('/\nHTTP_STATUS=\d+$/', '', $output));
        }
        echo "PASS: $case (HTTP $status).\n";
    }
} finally {
    putenv('PRETES_CARD_CASE');
    // Hanya hapus file fixture yang dibuat tes ini; tidak melakukan penghapusan rekursif.
    unlink($sandbox . '/includes/auth.php');
    unlink($sandbox . '/includes/pretes-card.php');
    unlink($sandbox . '/cetak-kartu-pretes.php');
    rmdir($sandbox . '/includes');
    rmdir($sandbox);
}
