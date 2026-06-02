<?php
/**
 * LPPAI Corner - Setup Script
 * Jalankan file ini sekali untuk membuat database dan data dummy.
 * Akses: http://localhost/lppai-corner/web/setup.php
 */

require_once __DIR__ . '/config/database.php';

echo "<h2>LPPAI Corner - Setup Database</h2>";

try {
    // Connect without database first
    $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");
    echo "<p>✅ Database '{DB_NAME}' berhasil dibuat.</p>";

    // Create tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            nama_lengkap VARCHAR(150) NOT NULL,
            nim VARCHAR(20) DEFAULT NULL,
            email VARCHAR(100) DEFAULT NULL,
            no_hp VARCHAR(20) DEFAULT NULL,
            program_studi VARCHAR(100) DEFAULT NULL,
            fakultas VARCHAR(100) DEFAULT NULL,
            role ENUM('mahasiswa', 'admin') NOT NULL DEFAULT 'mahasiswa',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pretes_registrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            periode VARCHAR(20) NOT NULL,
            tanggal_daftar DATETIME DEFAULT CURRENT_TIMESTAMP,
            status ENUM('terdaftar', 'hadir', 'tidak_hadir') DEFAULT 'terdaftar',
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pretes_schedules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            periode VARCHAR(20) NOT NULL,
            tanggal DATE NOT NULL,
            waktu_mulai TIME NOT NULL,
            waktu_selesai TIME NOT NULL,
            ruangan VARCHAR(100) NOT NULL,
            kuota INT DEFAULT 0,
            terisi INT DEFAULT 0,
            status ENUM('aktif', 'selesai', 'dibatalkan') DEFAULT 'aktif',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pretes_results (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            pretes_schedule_id INT DEFAULT NULL,
            nilai DECIMAL(5,2) DEFAULT NULL,
            status_lulus ENUM('lulus', 'tidak_lulus', 'belum_diumumkan') DEFAULT 'belum_diumumkan',
            keterangan TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (pretes_schedule_id) REFERENCES pretes_schedules(id) ON DELETE SET NULL
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            judul VARCHAR(255) NOT NULL,
            konten TEXT NOT NULL,
            tipe ENUM(
                'pendaftaran_gel1', 'pembagian_kelas_gel1', 'kelulusan_gel1',
                'pendaftaran_gel2', 'pembagian_kelas_gel2', 'kelulusan_gel2',
                'pendaftaran_mandiri', 'pembagian_kelas_mandiri',
                'umum'
            ) NOT NULL,
            file_lampiran VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tutorial_classes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama_kelas VARCHAR(100) NOT NULL,
            mata_kuliah VARCHAR(150) NOT NULL,
            dosen_pengampu VARCHAR(150) DEFAULT NULL,
            hari VARCHAR(20) DEFAULT NULL,
            jam VARCHAR(30) DEFAULT NULL,
            ruangan VARCHAR(100) DEFAULT NULL,
            gelombang ENUM('gel1', 'gel2', 'mandiri') NOT NULL,
            semester VARCHAR(20) DEFAULT NULL,
            kuota INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tutorial_registrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            tutorial_class_id INT NOT NULL,
            status ENUM('terdaftar', 'aktif', 'lulus', 'tidak_lulus', 'mengundurkan_diri') DEFAULT 'terdaftar',
            nilai_akhir DECIMAL(5,2) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (tutorial_class_id) REFERENCES tutorial_classes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");

    echo "<p>✅ Semua tabel berhasil dibuat.</p>";

    // Hash passwords properly
    $hash = password_hash('123', PASSWORD_DEFAULT);

    // Check if admin exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'umami'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        // Insert Admin
        $stmt = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, role) VALUES (?, ?, ?, ?)");
        $stmt->execute(['umami', $hash, 'Admin LPPAI', 'admin']);
        echo "<p>✅ Admin (umami/123) berhasil dibuat.</p>";
    } else {
        echo "<p>ℹ️ Admin sudah ada.</p>";
    }

    // Insert Mahasiswa dummy
    $mahasiswa = [
        ['mhs001', 'Ahmad Fauzi', '2024010001', 'ahmad@mail.com', '081234567890', 'Teknik Informatika', 'Fakultas Teknik'],
        ['mhs002', 'Siti Nurhaliza', '2024010002', 'siti@mail.com', '081234567891', 'Sistem Informasi', 'Fakultas Teknik'],
        ['mhs003', 'Budi Santoso', '2024010003', 'budi@mail.com', '081234567892', 'Manajemen', 'Fakultas Ekonomi'],
        ['mhs004', 'Dewi Lestari', '2024010004', 'dewi@mail.com', '081234567893', 'Akuntansi', 'Fakultas Ekonomi'],
        ['mhs005', 'Rizki Pratama', '2024010005', 'rizki@mail.com', '081234567894', 'Hukum', 'Fakultas Hukum'],
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO users (username, password, nama_lengkap, nim, email, no_hp, program_studi, fakultas, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'mahasiswa')");
    foreach ($mahasiswa as $m) {
        $stmt->execute([$m[0], $hash, $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]]);
    }
    echo "<p>✅ 5 Mahasiswa dummy berhasil dibuat (password semua: 123).</p>";

    // Insert Jadwal Pretes
    $pdo->exec("INSERT IGNORE INTO pretes_schedules (id, periode, tanggal, waktu_mulai, waktu_selesai, ruangan, kuota, terisi) VALUES
        (1, '2025/2026-Ganjil', '2026-05-10', '08:00:00', '10:00:00', 'Gedung A Lt.3 - R.301', 50, 12),
        (2, '2025/2026-Ganjil', '2026-05-10', '10:30:00', '12:30:00', 'Gedung A Lt.3 - R.302', 50, 8),
        (3, '2025/2026-Ganjil', '2026-05-11', '08:00:00', '10:00:00', 'Gedung B Lt.2 - R.201', 40, 5)
    ");
    echo "<p>✅ Jadwal pretes dummy berhasil dibuat.</p>";

    // Insert Tutorial Classes
    $pdo->exec("INSERT IGNORE INTO tutorial_classes (id, nama_kelas, mata_kuliah, dosen_pengampu, hari, jam, ruangan, gelombang, semester, kuota) VALUES
        (1, 'Kelas A', 'Bahasa Arab Dasar', 'Dr. Abdul Rahman', 'Senin', '08:00-09:30', 'Ruang 101', 'gel1', '2025/2026-Ganjil', 30),
        (2, 'Kelas B', 'Bahasa Arab Dasar', 'Ustadz Hamid', 'Selasa', '10:00-11:30', 'Ruang 102', 'gel1', '2025/2026-Ganjil', 30),
        (3, 'Kelas A', 'Bahasa Arab Lanjutan', 'Dr. Fatimah', 'Rabu', '13:00-14:30', 'Ruang 201', 'gel2', '2025/2026-Genap', 25),
        (4, 'Kelas B', 'Bahasa Arab Lanjutan', 'Dr. Yusuf', 'Kamis', '08:00-09:30', 'Ruang 202', 'gel2', '2025/2026-Genap', 25),
        (5, 'Kelas Mandiri A', 'Baca Tulis Al-Quran', 'Ustadz Ali', 'Jumat', '08:00-09:30', 'Ruang 301', 'mandiri', '2025/2026', 20)
    ");
    echo "<p>✅ Kelas tutorial dummy berhasil dibuat.</p>";

    // Insert Announcements
    $adminId = $pdo->query("SELECT id FROM users WHERE username='umami'")->fetchColumn();
    $announcements = [
        ['Pendaftaran Tutorial Gelombang 1 Semester Ganjil 2025/2026', 'Pendaftaran tutorial gelombang 1 semester ganjil tahun akademik 2025/2026 dibuka mulai tanggal 1 September 2025 sampai 15 September 2025. Silakan daftar melalui menu yang tersedia.', 'pendaftaran_gel1'],
        ['Pembagian Kelas Tutorial Gelombang 1', 'Berikut adalah pembagian kelas tutorial gelombang 1 semester ganjil. Silakan cek nama Anda pada daftar di bawah ini.', 'pembagian_kelas_gel1'],
        ['Pengumuman Kelulusan Tutorial Gelombang 1', 'Pengumuman kelulusan tutorial gelombang 1 semester ganjil 2025/2026.', 'kelulusan_gel1'],
        ['Pendaftaran Tutorial Gelombang 2 Semester Genap 2025/2026', 'Pendaftaran tutorial gelombang 2 semester genap tahun akademik 2025/2026 telah dibuka.', 'pendaftaran_gel2'],
        ['Pembagian Kelas Tutorial Gelombang 2', 'Pembagian kelas tutorial gelombang 2 semester genap 2025/2026.', 'pembagian_kelas_gel2'],
        ['Pengumuman Kelulusan Tutorial Gelombang 2', 'Hasil kelulusan tutorial gelombang 2 semester genap 2025/2026.', 'kelulusan_gel2'],
        ['Pendaftaran Tutorial Mandiri', 'Pendaftaran tutorial mandiri untuk mahasiswa yang belum lulus pada gelombang 1 dan 2.', 'pendaftaran_mandiri'],
        ['Pembagian Kelas Tutorial Mandiri', 'Berikut pembagian kelas tutorial mandiri.', 'pembagian_kelas_mandiri'],
    ];

    $stmt = $pdo->prepare("INSERT INTO announcements (judul, konten, tipe, is_active, created_by) VALUES (?, ?, ?, 1, ?)");
    // Clear old announcements first
    $pdo->exec("DELETE FROM announcements");
    foreach ($announcements as $a) {
        $stmt->execute([$a[0], $a[1], $a[2], $adminId]);
    }
    echo "<p>✅ Pengumuman dummy berhasil dibuat.</p>";

    // Insert pretes results for some students
    $pdo->exec("INSERT IGNORE INTO pretes_results (id, user_id, pretes_schedule_id, nilai, status_lulus, keterangan) VALUES
        (1, 2, 1, 85.50, 'lulus', 'Selamat, Anda lulus pretes!'),
        (2, 3, 1, 72.00, 'lulus', 'Selamat, Anda lulus pretes!'),
        (3, 4, 2, 45.00, 'tidak_lulus', 'Mohon maaf, Anda belum lulus. Silakan ikuti tutorial.'),
        (4, 5, 2, 90.00, 'lulus', 'Selamat, Anda lulus pretes dengan nilai sangat baik!'),
        (5, 6, 3, NULL, 'belum_diumumkan', NULL)
    ");
    echo "<p>✅ Hasil pretes dummy berhasil dibuat.</p>";

    // Insert some tutorial registrations
    $pdo->exec("INSERT IGNORE INTO tutorial_registrations (id, user_id, tutorial_class_id, status, nilai_akhir) VALUES
        (1, 4, 1, 'aktif', NULL),
        (2, 3, 2, 'lulus', 82.00),
        (3, 5, 3, 'terdaftar', NULL)
    ");
    echo "<p>✅ Registrasi tutorial dummy berhasil dibuat.</p>";

    echo "<hr><p><strong>🎉 Setup selesai! </strong><a href='index.php'>Klik di sini untuk masuk ke LPPAI Corner</a></p>";
    echo "<p><strong>Login Admin:</strong> username: <code>umami</code>, password: <code>123</code></p>";
    echo "<p><strong>Login Mahasiswa:</strong> username: <code>mhs001</code> s/d <code>mhs005</code>, password: <code>123</code></p>";

} catch (PDOException $e) {
    echo "<p>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
