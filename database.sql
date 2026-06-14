-- LPPAI Corner Database
-- Jalankan SQL ini di MySQL/phpMyAdmin

CREATE DATABASE IF NOT EXISTS lppai_corner CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lppai_corner;

-- Tabel Users (Mahasiswa + Admin + Dosen)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nama_lengkap VARCHAR(150) NOT NULL,
    nim VARCHAR(20) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    no_hp VARCHAR(20) DEFAULT NULL,
    program_studi VARCHAR(100) DEFAULT NULL,
    fakultas VARCHAR(100) DEFAULT NULL,
    tanggal_lahir DATE DEFAULT NULL,
    role ENUM('mahasiswa', 'admin', 'dosen') NOT NULL DEFAULT 'mahasiswa',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Jika database sudah ada, jalankan ALTER ini:
-- ALTER TABLE users ADD COLUMN tanggal_lahir DATE DEFAULT NULL AFTER fakultas;

-- Tabel Pretes Registration
CREATE TABLE pretes_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    pretes_schedule_id INT DEFAULT NULL,
    periode VARCHAR(20) NOT NULL,
    tanggal_daftar DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('terdaftar', 'hadir', 'tidak_hadir') DEFAULT 'terdaftar',
    username_tes VARCHAR(100) DEFAULT NULL COMMENT 'Username untuk tes tulis pretes',
    password_tes VARCHAR(100) DEFAULT NULL COMMENT 'Password untuk tes tulis pretes',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (pretes_schedule_id) REFERENCES pretes_schedules(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Jika database sudah ada, jalankan ALTER ini:
-- ALTER TABLE pretes_registrations
--   ADD COLUMN pretes_schedule_id INT DEFAULT NULL AFTER user_id,
--   ADD COLUMN username_tes VARCHAR(100) DEFAULT NULL COMMENT 'Username untuk tes tulis pretes',
--   ADD COLUMN password_tes VARCHAR(100) DEFAULT NULL COMMENT 'Password untuk tes tulis pretes',
--   ADD FOREIGN KEY (pretes_schedule_id) REFERENCES pretes_schedules(id) ON DELETE SET NULL;

-- Tabel Jadwal Pretes
CREATE TABLE pretes_schedules (
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
) ENGINE=InnoDB;

-- Tabel Hasil Pretes
CREATE TABLE pretes_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    pretes_schedule_id INT DEFAULT NULL,
    nilai DECIMAL(5,2) DEFAULT NULL,
    status_lulus ENUM('lulus', 'tidak_lulus', 'belum_diumumkan') DEFAULT 'belum_diumumkan',
    keterangan TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (pretes_schedule_id) REFERENCES pretes_schedules(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tabel Announcements (untuk semua jenis pengumuman)
CREATE TABLE announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    judul VARCHAR(255) NOT NULL,
    konten TEXT NOT NULL,
    tipe ENUM(
        'pendaftaran_gel1', 'pembagian_kelas_gel1', 'kelulusan_gel1',
        'pendaftaran_gel2', 'pembagian_kelas_gel2', 'kelulusan_gel2',
        'pendaftaran_mandiri', 'pembagian_kelas_mandiri', 'kelulusan_mandiri',
        'umum'
    ) NOT NULL,
    file_lampiran VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tabel Tutorial Classes
CREATE TABLE tutorial_classes (
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
) ENGINE=InnoDB;

-- Tabel Tutorial Registrations
CREATE TABLE tutorial_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tutorial_class_id INT NOT NULL,
    status ENUM('terdaftar', 'aktif', 'lulus', 'tidak_lulus', 'mengundurkan_diri') DEFAULT 'terdaftar',
    nilai_thaharah DECIMAL(5,2) DEFAULT NULL,
    nilai_shalat DECIMAL(5,2) DEFAULT NULL,
    nilai_surat_pendek DECIMAL(5,2) DEFAULT NULL,
    nilai_amaliyah DECIMAL(5,2) DEFAULT NULL,
    nilai_jenazah DECIMAL(5,2) DEFAULT NULL,
    nilai_akhir DECIMAL(5,2) DEFAULT NULL,
    nilai_detail TEXT DEFAULT NULL,
    keterangan TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (tutorial_class_id) REFERENCES tutorial_classes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Jika database sudah ada, jalankan ALTER ini:
-- ALTER TABLE tutorial_registrations 
-- ADD COLUMN nilai_thaharah DECIMAL(5,2) DEFAULT NULL AFTER status,
-- ADD COLUMN nilai_shalat DECIMAL(5,2) DEFAULT NULL AFTER nilai_thaharah,
-- ADD COLUMN nilai_surat_pendek DECIMAL(5,2) DEFAULT NULL AFTER nilai_shalat,
-- ADD COLUMN nilai_amaliyah DECIMAL(5,2) DEFAULT NULL AFTER nilai_surat_pendek,
-- ADD COLUMN nilai_jenazah DECIMAL(5,2) DEFAULT NULL AFTER nilai_amaliyah,
-- ADD COLUMN nilai_detail TEXT DEFAULT NULL AFTER nilai_akhir;

-- Tabel Tutorial Attendance
CREATE TABLE tutorial_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tutorial_class_id INT NOT NULL,
    user_id INT NOT NULL,
    pertemuan_ke INT NOT NULL,
    tanggal DATE NOT NULL,
    status_hadir ENUM('hadir', 'absen', 'izin', 'sakit') DEFAULT 'hadir',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tutorial_class_id) REFERENCES tutorial_classes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_attendance (tutorial_class_id, user_id, pertemuan_ke)
) ENGINE=InnoDB;

-- =====================
-- DATA DUMMY
-- =====================

-- Admin account (username: umami, password: 123)
-- Password di-hash dengan password_hash() PHP, ini hash dari '123'
INSERT INTO users (username, password, nama_lengkap, role) VALUES
('umami', '$2y$10$.ub4SeMVyiGNCwET1zTinOaZ6Xlibi/E0AuyMOiQkX1biQ4xqu5nm', 'Admin LPPAI', 'admin');

-- Mahasiswa dummy
INSERT INTO users (username, password, nama_lengkap, nim, email, no_hp, program_studi, fakultas, role) VALUES
('mhs001', '$2y$10$.ub4SeMVyiGNCwET1zTinOaZ6Xlibi/E0AuyMOiQkX1biQ4xqu5nm', 'Ahmad Fauzi', '2024010001', 'ahmad@mail.com', '081234567890', 'Teknik Informatika', 'Fakultas Teknik', 'mahasiswa'),
('mhs002', '$2y$10$.ub4SeMVyiGNCwET1zTinOaZ6Xlibi/E0AuyMOiQkX1biQ4xqu5nm', 'Siti Nurhaliza', '2024010002', 'siti@mail.com', '081234567891', 'Sistem Informasi', 'Fakultas Teknik', 'mahasiswa'),
('mhs003', '$2y$10$.ub4SeMVyiGNCwET1zTinOaZ6Xlibi/E0AuyMOiQkX1biQ4xqu5nm', 'Budi Santoso', '2024010003', 'budi@mail.com', '081234567892', 'Manajemen', 'Fakultas Ekonomi', 'mahasiswa'),
('mhs004', '$2y$10$.ub4SeMVyiGNCwET1zTinOaZ6Xlibi/E0AuyMOiQkX1biQ4xqu5nm', 'Dewi Lestari', '2024010004', 'dewi@mail.com', '081234567893', 'Akuntansi', 'Fakultas Ekonomi', 'mahasiswa'),
('mhs005', '$2y$10$.ub4SeMVyiGNCwET1zTinOaZ6Xlibi/E0AuyMOiQkX1biQ4xqu5nm', 'Rizki Pratama', '2024010005', 'rizki@mail.com', '081234567894', 'Hukum', 'Fakultas Hukum', 'mahasiswa');

-- Jadwal Pretes dummy
INSERT INTO pretes_schedules (periode, tanggal, waktu_mulai, waktu_selesai, ruangan, kuota, terisi) VALUES
('2025/2026-Ganjil', '2026-05-10', '08:00:00', '10:00:00', 'Gedung A Lantai 3 - Ruang 301', 50, 12),
('2025/2026-Ganjil', '2026-05-10', '10:30:00', '12:30:00', 'Gedung A Lantai 3 - Ruang 302', 50, 8),
('2025/2026-Ganjil', '2026-05-11', '08:00:00', '10:00:00', 'Gedung B Lantai 2 - Ruang 201', 40, 5);

-- Tutorial Classes dummy
INSERT INTO tutorial_classes (nama_kelas, mata_kuliah, dosen_pengampu, hari, jam, ruangan, gelombang, semester, kuota) VALUES
('Kelas A', 'Bahasa Arab Dasar', 'Dr. Abdul Rahman', 'Senin', '08:00 - 09:30', 'Ruang 101', 'gel1', '2025/2026-Ganjil', 30),
('Kelas B', 'Bahasa Arab Dasar', 'Ustadz Hamid', 'Selasa', '10:00 - 11:30', 'Ruang 102', 'gel1', '2025/2026-Ganjil', 30),
('Kelas A', 'Bahasa Arab Lanjutan', 'Dr. Fatimah', 'Rabu', '13:00 - 14:30', 'Ruang 201', 'gel2', '2025/2026-Genap', 25),
('Kelas B', 'Bahasa Arab Lanjutan', 'Dr. Yusuf', 'Kamis', '08:00 - 09:30', 'Ruang 202', 'gel2', '2025/2026-Genap', 25),
('Kelas Mandiri A', 'Baca Tulis Al-Quran', 'Ustadz Ali', 'Jumat', '08:00 - 09:30', 'Ruang 301', 'mandiri', '2025/2026', 20);

-- Announcements dummy
INSERT INTO announcements (judul, konten, tipe, is_active, created_by) VALUES
('Pendaftaran Tutorial Gelombang 1 Semester Ganjil 2025/2026', 'Pendaftaran tutorial gelombang 1 semester ganjil tahun akademik 2025/2026 dibuka mulai tanggal 1 September 2025 sampai 15 September 2025. Silakan daftar melalui menu yang tersedia.', 'pendaftaran_gel1', 1, 1),
('Pembagian Kelas Tutorial Gelombang 1', 'Berikut adalah pembagian kelas tutorial gelombang 1 semester ganjil. Silakan cek nama Anda pada daftar di bawah ini.', 'pembagian_kelas_gel1', 1, 1),
('Pengumuman Kelulusan Tutorial Gelombang 1', 'Pengumuman kelulusan tutorial gelombang 1 semester ganjil 2025/2026. Bagi yang belum lulus, silakan mendaftar ulang di gelombang berikutnya.', 'kelulusan_gel1', 1, 1),
('Pendaftaran Tutorial Gelombang 2 Semester Genap 2025/2026', 'Pendaftaran tutorial gelombang 2 semester genap tahun akademik 2025/2026 telah dibuka.', 'pendaftaran_gel2', 1, 1),
('Pembagian Kelas Tutorial Gelombang 2', 'Pembagian kelas tutorial gelombang 2 semester genap 2025/2026.', 'pembagian_kelas_gel2', 1, 1),
('Pengumuman Kelulusan Tutorial Gelombang 2', 'Hasil kelulusan tutorial gelombang 2 semester genap 2025/2026.', 'kelulusan_gel2', 1, 1),
('Pendaftaran Tutorial Mandiri', 'Pendaftaran tutorial mandiri untuk mahasiswa yang belum lulus pada gelombang 1 dan 2.', 'pendaftaran_mandiri', 1, 1),
('Pembagian Kelas Tutorial Mandiri', 'Berikut pembagian kelas tutorial mandiri.', 'pembagian_kelas_mandiri', 1, 1);
