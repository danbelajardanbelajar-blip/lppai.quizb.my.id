# Log Perubahan Pengembangan (CHANGELOG-DEV)

Catatan sistematis setiap perubahan kode. Entri terbaru di atas.
Format: waktu (WIB, sampai detik) · file · baris · bagian · perubahan · alasan · verifikasi · commit.

---

## 2026-11-12 18:33:15 +0700 — Sinkronisasi 123 Data Mahasiswa RPL Angkatan 2025 & Perbaikan Impor Excel SIAKAD

**Ringkasan:** Mengekstrak 123 data mahasiswa RPL Angkatan 2025 dari ekspor Excel SIA UNISDA (`laporan_daftarmhs.xls`) ke dataset versi-kontrol dan skrip migrasi idempoten (`migration_rpl_2025.php`), serta memutakhirkan importer pengguna (`admin/ajax-import-users.php` & modal `#modal-import` di `admin/users.php`) agar tahan terhadap perbedaan urutan kolom SIAKAD (termasuk mencegah `NAMA` tertimpa `NAMA IBU`), mengenali kolom `JURUSAN`, format `.xls` berbasis HTML table, tanggal bulan berbahasa Indonesia (`4 April 1997`, `7 Nopember 1983`), dan mode update `jenis_program` bagi NIM yang sudah terdaftar.

### 1. Helper Impor SIAKAD, Dataset 123 RPL 2025, & Skrip Migrasi

| File | Baris (setelah edit) | Bagian | Perubahan |
|---|---|---|---|
| `includes/user-helper.php` | 135–190 | `parseFlexibleDateToYmd()` | Menambahkan parser tanggal fleksibel ke `YYYY-MM-DD` (dukungan serial Excel, `ddmmyyyy`, `d/m/Y`, `Y-m-d`, dan nama bulan Indonesia termasuk variasi ejaan `Pebruari`/`Nopember`). |
| `includes/user-helper.php` | 192–272 | `detectUserImportHeaderAndMap()` | Deteksi otomatis baris header (scan 25 baris teratas) dan pemetaan kolom tahan urutan SIAKAD: mengabaikan `NAMA IBU`/`NAMA AYAH`, membedakan `jenis_program` sebelum `program_studi`/`jurusan`, serta mendeteksi kolom `kelas`. |
| `includes/user-helper.php` | 274–396 | `upsertRplUserRecords()` | Helper transaksional idempoten: mencocokkan NIM/username eksisting (skip jika sudah `RPL`, update **hanya** `jenis_program = 'RPL'` tanpa menimpa data lain jika non-RPL) serta insert akun mahasiswa baru (`angkatan = '2025'`, `jenis_program = 'RPL'`, password `ddmmyyyy` BCRYPT cost 8, `is_active = 0`). |
| `data/rpl-angkatan-2025.php` | 1–131 | Dataset RPL 2025 (file baru) | Menyimpan seluruh 123 mahasiswa RPL Angkatan 2025 unik dari `laporan_daftarmhs.xls` (`2501101046` s.d. `25071002`) lengkap dengan NIM, nama, prodi, tempat/tanggal lahir, no HP, dan email. |
| `migration_rpl_2025.php` | 1–65 | Runner migrasi (file baru) | Skrip CLI (`php migration_rpl_2025.php`) maupun browser Admin (`requireAdmin()`) yang mengeksekusi `upsertRplUserRecords()` terhadap `data/rpl-angkatan-2025.php` dan mencetak ringkasan `Total`, `Inserted`, `Updated`, `Skipped`. |

### 2. Impor Pengguna Fleksibel (`admin/ajax-import-users.php` & `admin/users.php`)

| File | Baris (setelah edit) | Bagian | Perubahan |
|---|---|---|---|
| `admin/ajax-import-users.php` | 10–13, 40–117 | Pembacaan file & HTML `.xls` | Menambahkan `PhpOffice\PhpSpreadsheet\Reader\Html`, dukungan ekstensi `.html`/`.htm`, serta auto-deteksi dan fallback `HtmlReader` (dg `libxml_use_internal_errors`) saat ekspor `.xls` SIAKAD ternyata berformat HTML table. |
| `admin/ajax-import-users.php` | 124–278 | Pemetaan dinamis, tanggal ID, & mode duplikat | Menggunakan `detectUserImportHeaderAndMap()` + `parseFlexibleDateToYmd()`, dukungan `default_jenis_program` & auto-deteksi kolom `kelas` berisi `RPL`, serta `duplicate_mode = 'update_jenis_program'` untuk memperbarui `jenis_program` berdasarkan `nim`/`username` pada data duplikat. |
| `admin/users.php` | 259–297 | Modal `#modal-import` | Memperbarui `accept=".xlsx,.xls,.html,.htm"`, teks panduan format SIAKAD, serta menambahkan dropdown `default_jenis_program` dan `duplicate_mode`. |

### 3. Pengujian & Verifikasi

| File | Baris | Bagian | Verifikasi |
|---|---|---|---|
| `tests/rpl-2025-import-test.php` | 1–143 | Test baru RPL 2025 & SIAKAD | Verifikasi 123 NIM unik dataset, parsing tanggal Indonesia (`4 April 1997`, `7 Nopember 1983`), header SIAKAD 15+ kolom (`NAMA` vs `NAMA IBU`, `JURUSAN`), update `jenis_program` non-destruktif, serta idempotensi run ke-2 di SQLite (`OK`). |
| `tests/user-jenis-program-test.php` | 85–95 | Penyesuaian test importer | Memverifikasi integrasi `detectUserImportHeaderAndMap()`, `parseFlexibleDateToYmd()`, dan `update_jenis_program` pada `admin/ajax-import-users.php`. |
| Seluruh file terubah + test suite | — | `php -l` & `tests/*test*.php` | `No syntax errors` di seluruh 7 file PHP dan **21/21 file test lulus** (`TOTAL_FAILED=0`). |

---

## 2026-11-12 16:44:25 +0700 — Tambah kolom `jenis_program` (`RPL` / `Reguler`) di database, Kelola Pengguna, dan seluruh halaman prodi

**Ringkasan:** Menambahkan kolom `jenis_program` (`ENUM('RPL', 'Reguler') NOT NULL DEFAULT 'Reguler' AFTER program_studi`) pada tabel `users`, auto-migrasi skema saat runtime, pengelolaan penuh (CRUD, filter, DataTables, import & template Excel) di Kelola Pengguna, propagasi sesi login/impersonasi, serta tampilan indikator `RPL` / `Reguler` pada seluruh halaman, laporan, kartu, dan ekspor yang menampilkan Program Studi / Jurusan.

### 1. Skema Database, Helper, & Sesi Auth

| File | Baris (setelah edit) | Bagian | Perubahan |
|---|---|---|---|
| `includes/user-helper.php` | 1–122 | Helper terpusat (file baru) | Fungsi `getAllowedJenisProgramOptions()`, `normalizeJenisProgram()` (ketat hanya `'RPL'` / `'Reguler'`), `ensureJenisProgramColumn()` (auto-ALTER tabel `users` MySQL `ENUM('RPL','Reguler')` & SQLite `TEXT` dg cache static + session), `renderJenisProgramBadge()`, dan `formatProdiDisplay()`. |
| `migration_jenis_program.php` | 1–20 | Skrip migrasi standalone (file baru) | Menambahkan kolom `jenis_program ENUM('RPL', 'Reguler') NOT NULL DEFAULT 'Reguler' AFTER program_studi` dan menormalkan baris null/non-valid menjadi `'Reguler'`. |
| `includes/auth.php` | 8–9, 33–35, 124–125, 205–206, 267–268 | Auth & session init | Memuat `user-helper.php`, menjalankan `ensureJenisProgramColumn($pdo)` setelah `session_start()`, serta menyimpan/memulihkan `$_SESSION['jenis_program']` di `loginUser()`, `getCurrentUser()`, dan `action=return_admin`. |
| `api/return-admin.php` | 19–20 | Pemulihan sesi admin | Menambahkan `$_SESSION['jenis_program'] = normalizeJenisProgram($user['jenis_program'] ?? 'Reguler')` saat kembali dari mode Login As. |

### 2. Kelola Pengguna (`admin/users.php` & endpoint terkait)

| File | Baris (setelah edit) | Bagian | Perubahan |
|---|---|---|---|
| `admin/users.php` | 10 | Init DB | Memanggil `ensureJenisProgramColumn($pdo)`. |
| `admin/users.php` | 30–31, 59–60, 73–74, 88–89 | Handler `create` & `update` | Membaca, menormalkan, dan menyimpan `jenis_program` ke `INSERT`/`UPDATE users`. |
| `admin/users.php` | 159–160 | Handler `login_as` | Meneruskan `$_SESSION['jenis_program']` saat admin masuk sebagai user target. |
| `admin/users.php` | 406–413, 428–436 | Form Tambah Pengguna & `toggleFields()` | Menambahkan dropdown `<select name="jenis_program">` (`Reguler` / `RPL`) pada `#group_jenis_program` yang muncul bersamaan dengan Prodi untuk mahasiswa. |
| `admin/users.php` | 485–494, 737, 768 | Filter Bar & DataTables AJAX | Menambahkan `#filter_jenis_program` (`Semua Jenis` / `Reguler` / `RPL`), mengirim `d.filterJenisProgram`, dan memicu reload DataTables. |
| `admin/users.php` | 528–529, 750 | Header Tabel & `columnDefs` | Menambah kolom `<th>Jenis Program</th>` setelah `Prodi` dan menggeser target non-orderable `Aksi` dari `9` menjadi `10`. |
| `admin/users.php` | 580–586, 635–661 | Modal Edit & `openUserModal()` | Menambah `<select name="jenis_program" id="edit_user_jenis_program">` serta binding `data-jenis-program`. |
| `admin/ajax-users.php` | 14, 25, 43–45, 68–71, 95–107 | Query & Sort/Filter Server-Side | Auto-cek kolom, filter `$filterJenisProgram`, map sort `7 => 'u.jenis_program'`, geser `angkatan` (8) & `role` (9), serta tambah klausa pencarian `u.jenis_program LIKE ?`. |
| `admin/ajax-users.php` | 142–144, 206, 258–260 | Output Baris & Atribut Edit | Merender badge `renderJenisProgramBadge()` pada kolom ke-7 dan atribut `data-jenis-program` pada `.btn-edit-user`. |
| `admin/ajax-import-users.php` | 105–106, 118, 134–135, 189–190 | Import Excel Pengguna | Mendeteksi header `jenis`/`jalur`/`rpl`/`reguler` sebelum `program_studi`, menormalkan nilai ke `RPL`/`Reguler`, dan menyimpan ke kolom `jenis_program`. |
| `admin/download-template-users.php` | 63, 70, 84–106 | Template Excel Import | Menambahkan kolom H `Jenis Program (RPL/Reguler)` (contoh `Reguler` & `RPL`) dan menggeser `Role` ke kolom I (`A1:I1`, `A2:I3`). |

### 3. Seluruh Halaman yang Menampilkan Jurusan / Program Studi

| File | Baris (setelah edit) | Bagian | Perubahan |
|---|---|---|---|
| `pages/dashboard.php` | 189 | Chip selamat datang | Menampilkan badge `renderJenisProgramBadge()` di samping nama prodi mahasiswa. |
| `admin/dashboard.php` | 88, 113, 128, 142, 322, 401 | Ringkasan Pendaftar Tutorial & Pretes | Menarik `u.jenis_program` dan menampilkan `formatProdiDisplay()` pada kolom prodi/jurusan. |
| `admin/kelola-nilai.php` | 188–195, 410, 427 | Filter Kelola Nilai | Menambahkan dropdown `#filterJenisProgram` serta mengirim `filterJenisProgram` ke DataTables AJAX. |
| `admin/ajax-kelola-nilai.php` | 24, 82–85, 105, 116, 126, 218 | Server-side Kelola Nilai | Menambah filter `u.jenis_program = ?`, pencarian `u.jenis_program LIKE ?` (`array_merge` 12 param), dan menampilkan `formatProdiDisplay()` pada kolom Jurusan tanpa mengubah jumlah kolom DataTables. |
| `rekap-nilai.php` | 191, 237–245, 253, 263–288, 460, 479 | Tabel & Export Excel Rekap Nilai | Memuat `u.jenis_program`, menambah kolom `Jenis Program` pada HTML (`.datatable`) dan export XLSX (`G1`, kolom `A1:N1`). |
| `admin/ajax-import-nilai.php` | 95, 115, 142, 176, 184, 194–200 | Import Nilai Excel | Dukungan opsional kolom `Jenis Program` untuk insert user baru maupun update user yang sudah ada. |
| `admin/pretes-peserta.php` | 281, 756 | Tabel Peserta Pretes | Menarik `u.jenis_program`, menambahkan kata `RPL`/`Reguler` di `data-search`, dan merender `formatProdiDisplay()`. |
| `admin/pretes-peserta-export.php` | 20, 38, 73–76, 102, 275–277, 408–410, 646–654, 816–818 | Filter, Preview & Export Peserta Pretes | Menambahkan opsi kolom `jenis_program`, filter `jenis_program`, output di XLSX/CSV, dan preview tabel HTML. |
| `admin/pretes-jadwal.php` | 402, 855 | Tabel Jadwal Peserta Pretes | Menarik `u.jenis_program` dan merender `formatProdiDisplay()`. |
| `admin/pretes-absensi-export.php` | 50, 173 | Cetak Presensi A4 Pretes | Menarik `u.jenis_program` dan menampilkan `Prodi (RPL/Reguler)`. |
| `admin/pretes-rekap-nilai.php` | 199, 315, 347, 504–505, 621, 645–646, 691, 712–713, 802–803, 978, 1426 | Rekap, Cetak, Search & 4 Format Export | Menarik `u.jenis_program`, memasukkan ke keyword pencarian, menambah kolom `Jenis Program` pada XLSX/Excel XML/CSV/PhpSpreadsheet, serta `formatProdiDisplay()` di tabel utama & tampilan cetak. |
| `includes/pretes-card.php` | 807 | Kartu Ujian Pretes | Menampilkan pill `RPL`/`Reguler` secara inline dan aman terhadap null key maupun sandbox test. |
| `includes/pretes-panel.php` | 183, 1293 | Panel Accordion Penilaian Pretes | Menarik `u.jenis_program` dan merender badge di baris info Prodi. |
| `pages/pretes-hasil.php` | 173, 763 | Tabel Pengumuman Hasil Pretes | Menarik `u.jenis_program` dan menampilkan `formatProdiDisplay()`. |
| `admin/tutorial-data-pendaftar.php` | 499, 548, 565, 685, 1203, 1320 | Data Pendaftar & Belum Daftar Tutorial | Menarik `u.jenis_program` di semua query peserta dan menampilkan `formatProdiDisplay()` tanpa mengubah `data-jurusan` filter. |
| `admin/tutorial-pendaftaran-kolektif.php` | 501, 684 | Daftar Mahasiswa Pendaftaran Kolektif | Menarik `u.jenis_program` dan merender `formatProdiDisplay()`. |
| `dosen/detail-kelas.php` | 140, 240 | Daftar Mahasiswa per Kelas Dosen | Menarik `u.jenis_program` dan merender badge di samping NIM - Prodi. |
| `includes/announcement-helper.php` | 41, 371 | Daftar Lulus Tutorial pada Pengumuman | Menarik `u.jenis_program` dan menampilkan `formatProdiDisplay()`. |
| `admin/absensi-alkhidmah.php` | 119, 281 | Kartu Presensi Al Khidmah | Menarik `u.jenis_program` dan menampilkan badge di samping NIM - Prodi. |
| `admin/aktivasi-mahasiswa.php` | 21, 87 | Tinjau Aktivasi Akun Mahasiswa | Menarik `jenis_program` dan menampilkan `formatProdiDisplay()`. |
| `admin/cetak-sertifikat.php` | 28, 579 | Cetak Sertifikat | Menarik `u.jenis_program` dan menampilkan `Prodi (RPL/Reguler)`. |
| `admin/cetak-tanda-terima-sertifikat.php` | 22, 30, 230, 282, 476 | Slip & Berita Acara Tanda Terima | Menarik `u.jenis_program` pada mode tunggal maupun massal dan menampilkan `Prodi (RPL/Reguler)`. |
| `admin/pengambilan-sertifikat.php` | 99, 140, 264, 283, 296, 346, 363, 889, 985, 1289 | Monitor, Lookup QR, Log & CSV Sertifikat | Menarik `u.jenis_program`, menambah kolom `Jenis Program` di ekspor CSV, merender `formatProdiDisplay()` di kedua tabel, dan menampilkan jenis program di preview QR lookup. |
| `pages/ambil-sertifikat.php` | 80, 141, 204, 226, 385, 618 | Halaman Ambil Sertifikat & Scan Mahasiswa | Menarik `u.jenis_program`, merender badge pada profil kartu, dan menyertakan jenis program pada hasil scan QR. |
| `sertifikat.php` | 176, 910 | Pratinjau Sertifikat Mahasiswa | Menarik `u.jenis_program` dan merender badge di samping Program Studi. |
| `tests/user-jenis-program-test.php` | 1–137 | Pengujian otomatis (file baru) | Unit test normalisasi nilai ketat (`RPL`/`Reguler`), auto-migrasi SQLite, badge/format helper, dan validasi integrasi seluruh file prodi & CRUD pengguna. |

**Alasan:** Kebutuhan pemisahan klasifikasi mahasiswa antara program **RPL** dan **Reguler** di tingkat basis data (`users.jenis_program`), pengelolaan admin (`admin/users.php` + import/export), serta visibilitas konsisten di setiap halaman yang menampilkan jurusan/prodi.
**Dampak:** Tabel `users` otomatis mendapat kolom `jenis_program ENUM('RPL', 'Reguler') NOT NULL DEFAULT 'Reguler'` (default `'Reguler'` untuk seluruh akun lama). Seluruh indeks kolom DataTables yang sensitif (`tutorial-pengaturan-generate.php`, `kelola-nilai.php`) tetap terjaga.
**Verifikasi:**
- `php -l` seluruh 34 file terubah/baru → 0 syntax error.
- Seluruh 20 test suite (`tests/*test*.php`) → PASS (`TOTAL_FAILED=0`), termasuk `tests/user-jenis-program-test.php` (24 file prodi + SQLite auto-migration + CRUD/import checks).

---

## 2026-11-10 16:43:23 +0700 — Perbaiki HTTP 500 saat "Sinkronkan ke Hasil"

**File:** `admin/pretes-rekap-nilai.php`
**Bagian:** handler POST `action=sync_to_results` (blok "Handle Action POST: Sinkronisasi Nilai ke Hasil Pretes")

| Baris (setelah edit) | Perubahan |
|---|---|
| 94–127 | **Baru:** `try {` + deteksi kolom `pretes_results` (`SHOW COLUMNS` / `PRAGMA table_info`). Auto-tambah kolom `nilai` dan `keterangan` jika tidak ada; ubah `status_lulus` ENUM tanpa `'tidak_lulus'` menjadi `VARCHAR(30)`; jadikan `pretes_schedule_id` nullable bila NOT NULL tanpa default. Set flag `$hasKet`, `$hasUpd`. `$pdo->beginTransaction()`. |
| 153–156 | UPDATE dibuat dinamis: `keterangan` / `updated_at` hanya ikut jika kolomnya ada. |
| 158–164 | INSERT dibuat dinamis: dengan / tanpa kolom `keterangan`. |
| 168 | **Baru:** `$pdo->commit()`. |
| 172–177 | **Baru:** `catch (Throwable $e)` → rollback, `error_log('[pretes-rekap-nilai sync] …')`, tampilkan pesan "Sinkronisasi gagal: …" (bukan blank 500). |

**Alasan:** tombol "Sinkronkan ke Hasil" memicu HTTP 500 karena query tulis ke `pretes_results` gagal (skema server berbeda dari yang diasumsikan kode) dan tidak ada penanganan error.
**Dampak:** sinkronisasi mengubah struktur tabel `pretes_results` di server bila kolom/tipe tidak sesuai (lihat baris 94–127).
**Verifikasi:** `php -l` OK; `php tests/pretes-rekap-nilai-test.php` → PASS (151 skenario).
**Catatan terkait:** angka "Lulus Pretes" di `admin/dashboard.php:63` dibaca dari `pretes_results` (hasil sync terakhir, semua periode), sedangkan rekap menghitung live per periode — karena itu bisa berbeda.
