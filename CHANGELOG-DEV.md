# Log Perubahan Pengembangan (CHANGELOG-DEV)

Catatan sistematis setiap perubahan kode. Entri terbaru di atas.
Format: waktu (WIB, sampai detik) · file · baris · bagian · perubahan · alasan · verifikasi · commit.

---

## 2026-11-13 06:41:05 +0700 — Perbaikan Elemen Status AI & Resilience Engine Verifikasi Wajah Pretes

**Ringkasan:** Memperbaiki insiden di mana tombol pendaftaran pretes tetap terkunci meskipun pasfoto resmi yang diunggah sudah jelas. Akar masalah teridentifikasi pada skrip global `assets/js/app.js` yang secara otomatis menghapus elemen berkategori `.alert` pada saat DOMContentLoaded, sehingga kontainer status `#verifStatusAlert` terhapus dan menyebabkan JavaScript error saat memproses hasil AI. Dilakukan perbaikan arsitektural: melepas class `.alert` dari `#verifStatusAlert`, menambahkan pengecualian `:not(#verifStatusAlert)` di `app.js`, menyematkan engine `PretesFaceVerifier` secara inline di `pages/pretes-daftar.php` dengan deteksi kulit adaptif untuk jas almamater/busana muslim, safety timeout 450ms pada API eksperimental peramban, serta mekanisme fallback otomatis agar tombol pendaftaran selalu terbuka begitu foto resmi valid dimuat.

| File | Baris (setelah edit) | Bagian | Perubahan |
|---|---|---|---|
| `pages/pretes-daftar.php` | 460–462 | Kontainer Status Verifikasi | Mengubah elemen `#verifStatusAlert` menjadi independen tanpa class `.alert` agar tidak dihapus oleh pembersih alert global di `app.js`. |
| `pages/pretes-daftar.php` | 525–760 | Script Verifikasi Wajah Inline & Fallback | Menyematkan mesin `PretesFaceVerifier` langsung di halaman dengan skin threshold adaptif, penanganan error komprehensif (`try/catch`), dan jaminan tombol terbuka saat foto valid. |
| `assets/js/app.js` | 493, 568 | Global Alert Cleaner Protection | Menambahkan pengecualian `:not(#verifStatusAlert):not(.keep-alert)` pada query selector `document.querySelectorAll('.alert')` agar tidak menghapus alert dinamis halaman. |
| `assets/js/pretes-face-verifier.js` | 130–240 | Modul Face Verifier Standalone | Menambahkan safety timer 450ms pada `window.FaceDetector` Chrome, memperluas cakupan warna kulit YCbCr/RGB, dan melonggarkan toleransi proporsi pasfoto resmi. |
| Seluruh file terubah + test suite | — | `php -l` & `tests/*test*.php` | `No syntax errors` pada seluruh file dan **26/26 file test lulus** (`TOTAL_FAILED=0`). |

---

## 2026-11-13 06:26:13 +0700 — Fitur Unggah & Ambil Pasfoto Resmi Berbasis AI Face Verification pada Pendaftaran Pretes

**Ringkasan:** Membangun sistem wajib pasfoto resmi bagi calon peserta pretes pada `pages/pretes-daftar.php` dengan opsi ganda (unggah file dari galeri/PC atau jepret langsung via webcam/kamera HP dengan panduan oval) disertai engine client-side AI Face Verification (`assets/js/pretes-face-verifier.js`). Sistem secara otomatis mendeteksi keberadaan wajah tunggal, posisi tengah, proporsi pasfoto, dan memastikan tidak ada bagian wajah yang terpotong di tepi. Tombol konfirmasi pendaftaran terkunci secara ketat sampai foto dinyatakan valid oleh AI, file foto disimpan dengan aman ke `uploads/pretes_foto/` berpelindung `.htaccess`, dan otomatis dicetak langsung pada kolom Pasfoto 3×4 Kartu Ujian Pretes (`includes/pretes-card.php`) serta tabel data peserta admin & dosen penguji.

| File | Baris (setelah edit) | Bagian | Perubahan |
|---|---|---|---|
| `assets/js/pretes-face-verifier.js` | 1–280 | Modul AI Face Verification & Kamera (File Baru) | Menangani video stream kamera depan/belakang, snapshot aspek rasio 3:4, integrasi `window.FaceDetector` native browser dan fallback universal Canvas Pixel Skin-Tone Analyzer untuk memeriksa kelengkapan wajah. |
| `includes/pretes-helper.php` | 287–423 | Auto-Migration Kolom Foto & Storage Engine | Menambahkan `ensurePretesRegistrationPhotoColumn()` (idempoten SQLite/MySQL) dan `savePretesRegistrationPhoto()` untuk memproses base64/file, validasi ekstensi GD, penyimpanan ke `uploads/pretes_foto/`, dan proteksi `.htaccess`. |
| `pages/pretes-daftar.php` | 49–54, 126–187, 219–233, 355–480, 520–700 | Form Pendaftaran & UI Verifikasi Foto | Mengintegrasikan auto-migration, validasi ketat upload foto pada POST, kartu status terdaftar dengan foto terverifikasi, form selector Unggah vs Kamera, preview hasil verifikasi AI, dan gembok tombol submit sampai foto valid. |
| `includes/pretes-card.php` | 326–345, 868–890 | Pencetakan Kartu Ujian Pretes | Menambahkan styling `.photo-box.has-photo` dan merender pasfoto resmi mahasiswa dengan `object-fit:cover` di dalam kotak 3×4 kartu peserta ujian jika telah diunggah. |
| `admin/pretes-peserta.php` | 336–338, 882–895 | Daftar Peserta Pretes Admin | Menyertakan `pr.foto` dalam query SELECT dan menampilkan thumbnail pasfoto peserta 3×4 dengan tautan modal/preview penuh di samping nama mahasiswa. |
| `admin/pretes-jadwal.php` | 402–404, 856–869 | Lembar Ujian Dosen Penguji | Menyertakan `pr.foto` dan menampilkan thumbnail foto resmi peserta di lembar plotting penguji pretes untuk pencocokan fisik wajah saat ujian praktek. |
| `tests/pretes-foto-verifikasi-test.php` | 1–95 | Unit & Integration Test Suite (File Baru) | Menguji auto-migration SQLite, helper `savePretesRegistrationPhoto`, keamanan `.htaccess`, integrasi skrip JS, dan integrasi antar file PHP. |
| Seluruh file terubah + test suite | — | `php -l` & `tests/*test*.php` | `No syntax errors` pada seluruh file dan **26/26 file test lulus** (`TOTAL_FAILED=0`). |

---

## 2026-11-13 05:50:25 +0700 — Penambahan Opsi Sasaran Pengumuman "Khusus Belum Pernah Pretes" (`belum_pretes`) & Klarifikasi Label

**Ringkasan:** Menambahkan opsi target sasaran baru `belum_pretes` ("Khusus Belum Pernah Pretes") pada form pembuatan/edit pengumuman dan memperjelas label opsi yang telah ada agar admin tidak ambigu saat memilih target (misal: "Peserta Pretes (Sudah Terdaftar/Ikut)" vs "Khusus Belum Lulus Pretes (Sudah Pretes tapi Tidak Lulus)"). Logika penyaringan memastikan pengumuman dengan target ini hanya muncul pada dashboard mahasiswa yang belum pernah mendaftar pretes dan belum lulus pretes.

| File | Baris (setelah edit) | Bagian | Perubahan |
|---|---|---|---|
| `includes/announcement-helper.php` | 39–48 | Kamus Pilihan Target Peserta | Menambahkan opsi `'belum_pretes' => 'Khusus Belum Pernah Pretes'` pada `getAllowedAnnouncementTargetPeserta()` serta memperjelas label opsi lainnya. |
| `includes/announcement-helper.php` | 78–95 | Visualisasi Badge Pengumuman | Menambahkan badge penanda khusus berwarna amber/kuning muda (`#fef9c3` border `#fef08a`) untuk target `belum_pretes` di tabel daftar pengumuman admin. |
| `includes/announcement-helper.php` | 132–150 | Evaluasi Eligibilitas Pengumuman | Memeriksa kriteria `($hasPretesReg !== true) && ($isPretesLulus !== true)` saat `target_peserta === 'belum_pretes'`, sehingga hanya mahasiswa yang belum terdaftar pretes dan belum lulus yang menerima pengumuman. |
| `tests/announcement-target-filter-test.php` | 22–24, 134–157 | Unit & Integration Test Suite | Menambahkan pengujian normalisasi `belum_pretes` serta verifikasi kelayakan bagi user belum pretes vs user yang sudah terdaftar pretes / lulus pretes. |
| Seluruh file terubah + test suite | — | `php -l` & `tests/*test*.php` | `No syntax errors` pada seluruh file dan **25/25 file test lulus** (`TOTAL_FAILED=0`). |

---

## 2026-11-13 05:40:59 +0700 — Filter Sasaran Pengumuman per Program (RPL / Reguler) & Kategori Peserta (Pretes / Tutorial)

**Ringkasan:** Menambahkan kontrol target audiens pada sistem pengumuman (`announcements`) melalui kolom `target_program` (`Semua`, `RPL`, `Reguler`) dan `target_peserta` (`semua`, `pretes`, `tutorial`, `belum_lulus_pretes`, `lulus_pretes`), memutakhirkan form tambah & modal edit di `admin/pengumuman.php`, menormalisasi pengumuman tutorial/pretes reguler lama, serta menyaring feed pengumuman di `pages/dashboard.php` sehingga mahasiswa RPL tidak lagi menerima pengumuman pendaftaran tutorial reguler maupun hasil pretes reguler.

| File | Baris (setelah edit) | Bagian | Perubahan |
|---|---|---|---|
| `includes/announcement-helper.php` | 6–207, 213–222 | Helper Target Pengumuman & Gating Hak Akses | Menambahkan `ensureAnnouncementTargetColumns()`, `normalizeAnnouncementTargetProgram()`, `normalizeAnnouncementTargetPeserta()`, `renderAnnouncementTargetBadges()`, dan `isUserEligibleForAnnouncement()` untuk menyaring pengumuman berdasarkan program mahasiswa dan status kepesertaan. |
| `migration_announcement_target.php` | 1–45 | Runner Migrasi Skema (File Baru) | Skrip migrasi CLI (`php migration_announcement_target.php`) dan web admin untuk menambahkan kolom `target_program` & `target_peserta` dan menormalisasi pengumuman lama. |
| `admin/pengumuman.php` | 10–12, 83–122, 172–192 | CRUD Pengumuman & Target Audiens | Memanggil auto-migration, membaca dan menyimpan `target_program` dan `target_peserta` pada form `create` & `update`, serta menambahkan input pilihan target pengiriman pada form tambah. |
| `admin/pengumuman.php` | 225, 244, 261, 318–337, 364–410 | Tabel Daftar & Modal Edit Pengumuman | Menambahkan kolom `Target Audiens` dengan badge program & peserta, binding input pada modal edit, serta pembaruan fungsi JS `openAnnModal()` dan saran otomatis `bindAutoLink()`. |
| `pages/dashboard.php` | 121–155 | Feed Pengumuman Dashboard Mahasiswa | Memanggil `ensureAnnouncementTargetColumns()`, menyiapkan konteks kepesertaan (`$annContext`), dan menyaring pengumuman dengan `isUserEligibleForAnnouncement()` agar mahasiswa RPL tidak terpapar pengumuman reguler. |
| `tests/announcement-target-filter-test.php` | 1–190 | Unit & Integration Test Suite (File Baru) | Menguji normalisasi input, isolasi ketat pengumuman RPL vs Reguler, target kelulusan/kepesertaan, auto-migration SQLite, dan integrasi UI file PHP. |
| Seluruh file terubah + test suite | — | `php -l` & `tests/*test*.php` | `No syntax errors` pada seluruh file dan **24/24 file test lulus** (`TOTAL_FAILED=0`). |

---

## 2026-11-13 05:08:20 +0700 — Fitur Sinkronisasi Nilai Peserta Lulus Pretes ke Kelola Nilai & Siap Cetak Sertifikat

**Ringkasan:** Membangun fitur jembatan sinkronisasi otomatis dari hasil Rekapitulasi Pretes (`admin/pretes-rekap-nilai.php`) langsung ke tabel `tutorial_registrations` pada halaman Kelola Nilai (`admin/kelola-nilai.php`) sehingga peserta pretes yang dinyatakan LULUS (seluruh 12 komponen $\ge 80$) langsung terdaftar dengan `tipe_nilai = 'pretest'`, nilai transkrip 6 komponen terhitung akurat, dan nomor sertifikat resmi serta pencetakan sertifikat (`admin/cetak-sertifikat.php`) dapat langsung diproses tanpa perlu impor manual via Excel.

| File | Baris (setelah edit) | Bagian | Perubahan |
|---|---|---|---|
| `includes/pretes-helper.php` | 434–485 | Pemetaan 12 ke 6 Komponen | Menambahkan fungsi `mapPretesScoresToCertificate($scores)` yang mengelompokkan 12 komponen pretes secara tepat: Thaharah (4 komponen wudlu/tayammum/mandi), Shalat (2 komponen jamak/subuh), Surat Pendek (3 surat pendek), Amaliyah (istighasah), Jenazah (shalat jenazah), dan Ujian Tulis (CBT). |
| `includes/pretes-helper.php` | 487–713 | Mesin Sinkronisasi Database | Menambahkan `syncPretesToKelolaNilai($pdo, $periodeName, $tahunAjaran)` berbasis transaksi database atomik: memastikan kelulusan ketat ($\ge 80$), menginsert peserta baru atau mengupdate nilai peserta yang sudah ada secara idempoten, dan menjaga integritas `nomor_sertifikat` yang sudah pernah di-lock. |
| `admin/pretes-rekap-nilai.php` | 28, 179–200, 1219, 1254–1268 | Handler POST & Tombol Toolbar Admin | Menambahkan tombol aksi hijau `🎓 Sinkronkan ke Kelola Nilai (Sertifikat)` di toolbar rekapitulasi, menangani `action === 'sync_to_kelola_nilai'`, merender alert hasil sinkronisasi dengan link langsung ke halaman `kelola-nilai.php`, serta memastikan `$selectedPeriodId` membaca fallback POST. |
| `admin/kelola-nilai.php` | 102, 114, 149–203 | UI Header, Filter Dinamis, & Pre-selection URL | Membaca parameter `$_GET['tahun_ajaran']` & `$_GET['tipe']`, menyajikan daftar Tahun Ajaran dinamis dari database dan rentang tahun berjalan (termasuk format `2026/2027`), serta memperjelas deskripsi bahwa halaman melayani transkrip kelulusan resmi Pretest maupun Tutorial. |
| `tests/pretes-sync-kelola-nilai-test.php` | 1–178 | Unit & Integration Test Suite (File Baru) | Menguji akurasi pemetaan 12->6 komponen, transaksi SQLite sync (insert lulus, skip tidak lulus/belum lengkap, update idempoten mempertahankan lock sertifikat), dan integrasi komponen UI. |
| Seluruh file terubah + test suite | — | `php -l` & `tests/*test*.php` | `No syntax errors` pada seluruh 4 file terubah dan **23/23 file test lulus** (`TOTAL_FAILED=0`). |

---

## 2026-11-12 19:17:15 +0700 — Isolasi Periode, Filter Program RPL/Reguler, & Export per Periode di `Data Peserta Pretes`

**Ringkasan:** Memutakhirkan `admin/pretes-peserta.php` dan `admin/pretes-peserta-export.php` agar halaman Data Peserta Pretes beserta generate/regenerate credentials dan export Excel tidak mencampur peserta periode RPL 2026/2027 dengan periode 2026/2027 sebelumnya, serta memperbaiki bug filter Program Studi exact-match akibat penggabungan teks Prodi dan Jenis Program.

| File | Baris (setelah edit) | Bagian | Perubahan |
|---|---|---|---|
| `admin/pretes-peserta.php` | 9–16, 290–370, 384–431 | Backend scoping periode & card pemilih periode | Memuat `includes/pretes-helper.php` (`ensurePretesPeriodTargetColumns`), membaca `?periode=` (default periode aktif `master_pretes`, opsi `__all__` untuk arsip gabungan), menambahkan `$jenisCounts` (`RPL` / `Reguler`), dan memfilter query `$participants` berdasarkan `pr.periode = ?`. |
| `admin/pretes-peserta.php` | 99–133, 662–708 | Generate/Regenerate All Credentials per periode | Menyisipkan `target_periode` tersembunyi pada `#form-gen-all` & `#form-regen-all` beserta konfirmasi periode spesifik dan klausa `WHERE pr.periode = ?` agar regenerate credentials periode RPL tidak menimpa credentials peserta periode lain. |
| `admin/pretes-peserta.php` | 791–915, 1174–1332, 1593–1688 | Filter Prodi, Jenis Program, & Export Excel | Menambahkan dropdown `#filter-jenis-program` (`RPL` / `Reguler`), atribut `data-prodi` & `data-jenis` pada baris tabel (beserta sub-label periode saat mode arsip), memperbaiki exact-match `#filter-prodi` via `$.fn.dataTable.ext.search`, dan meneruskan parameter `periode` & `jenis_program` ke `pretes-peserta-export.php` (link header, modal export, dan `exportCurrentFiltered()`). |
| `admin/pretes-peserta-export.php` | 10–22, 45–79, 148–216 | Query & metadata export per periode/program | Membaca parameter `$_GET['periode']` (default periode aktif, `__all__` = semua periode), menambah kondisi `pr.periode = ?`, memasukkan slug periode pada nama file export, dan menambahkan `Periode` serta `Jenis Program` di sub-header XLSX. |
| `admin/pretes-peserta-export.php` | 479–544, 603–664 | Ringkasan UI, quick export, & dropdown periode | Mengisolasi statistik jumlah peserta, hari, dan prodi di halaman web export sesuai periode terpilih, meneruskan `&periode=...` ke link kembali & tombol export cepat, serta menambah `<select name="periode">` pada `#form-export`. |
| `tests/pretes-target-program-test.php` | 105–123 | Regression test `pretes-peserta` & export | Menambahkan assertion bahwa `admin/pretes-peserta.php` dan `admin/pretes-peserta-export.php` mengisolasi query per periode, mendukung filter `jenis_program`, dan meneruskan parameter `periode`. |
| Seluruh file terubah + test suite | — | `php -l` & `tests/*test*.php` | `No syntax errors` pada ketiga file terubah dan **22/22 file test lulus** (`TOTAL_FAILED=0`). |

---

## 2026-11-12 19:04:30 +0700 — Pembatasan Pendaftaran Pretes per Program (`Semua` / `RPL` / `Reguler`), Jadwal Tanggal Dinamis (16–20 Nov 2026), & Isolasi Kuota Periode

**Ringkasan:** Menambahkan kontrol `target_program` (`Semua`, `RPL`, `Reguler`) dan `tanggal_mulai` pada tabel `master_pretes`, memblokir akses & submit pendaftaran pretes bagi program di luar target (termasuk menyembunyikan menu `Daftar Pretes` di sidebar, pengumuman dashboard, dan tombol `Daftar Pretes Sekarang` di halaman hasil pretes), membuat rentang tanggal pelaksanaan Senin–Jumat dinamis (mis. `16–20 November 2026` dari `tanggal_mulai = 2026-11-16`), serta mengisolasi query kuota/plotting pretes agar pembukaan periode RPL di tahun ajaran sama (`2026/2027`) tidak tercampur pendaftar periode sebelumnya.

### 1. Skema, Helper Terpusat, & Migrasi

| File | Baris (setelah edit) | Bagian | Perubahan |
|---|---|---|---|
| `includes/pretes-helper.php` | 211–433 | Helper target program & jadwal dinamis | Menambahkan `getAllowedPretesTargetPrograms()`, `normalizePretesTargetProgram()`, `ensurePretesPeriodTargetColumns()` (auto-ALTER `master_pretes` menambah `target_program` & `tanggal_mulai`), `getActivePretesPeriod()`, `isUserEligibleForPretesPeriod()`, `renderPretesTargetProgramBadge()`, `getPretesPeriodDayLabels()`, dan `shouldShowDaftarPretesToStudent()`. |
| `migration_pretes_target_program.php` | 1–45 | Skrip migrasi standalone (file baru) | Runner CLI (`php migration_pretes_target_program.php`) dan Admin browser untuk memanggil `ensurePretesPeriodTargetColumns()`. |

### 2. Pengaturan Admin, Isolasi Periode 2026/2027, & Akses Mahasiswa

| File | Baris (setelah edit) | Bagian | Perubahan |
|---|---|---|---|
| `admin/pretes-dosen.php` | 6–40, 81–195 | CRUD `master_pretes` | Auto-migrasi kolom, baca/simpan `target_program` dan `tanggal_mulai` pada `create_periode` & `edit_periode`, serta mengisolasi query kuota `$registeredCounts` menjadi `WHERE periode = ?` (menghapus `OR periode LIKE '%2026%'`). |
| `admin/pretes-dosen.php` | 275–450, 477–519, 557–592, 798–882 | UI Card Aktif, Tabel, Form Tambah, & Modal Edit | Menampilkan badge `target_program` & rentang tanggal pada banner/tabel, menambah `<select name="target_program">` dan `<input type="date" name="tanggal_mulai">` pada form tambah & modal edit beserta binding JS. |
| `admin/pretes-jadwal.php` | 5–11, 267–274, 348–354, 386–414 | Generate Jadwal, Reset Plotting, & Tabel Peserta | Memanggil `ensurePretesPeriodTargetColumns()` serta mengisolasi 4 query `pretes_registrations` strictly dengan `periode = ?` (mencegah plotting/reset periode RPL menyentuh peserta 2026/2027 sebelumnya). |
| `pages/pretes-daftar.php` | 5–41, 68–186, 268–377 | Halaman Pendaftaran Pretes Mahasiswa | Mengisolasi cek `$existingReg` & `$registeredCounts` pada periode aktif (`periode = ?`), menolak `POST` bila `!$isEligibleForPretes`, merender tanggal dinamis dari `getPretesPeriodDayLabels()` (menggantikan hardcode `2–6 November 2026`), dan menampilkan panel terkunci khusus program target bagi non-RPL. |
| `includes/sidebar.php` | 160–177 | Navigasi Sidebar Mahasiswa | Menyembunyikan tautan `Daftar Pretes` menggunakan `shouldShowDaftarPretesToStudent()`. |
| `pages/dashboard.php` | 34–55, 152–158 | Dashboard & Pengumuman Mahasiswa | Menghitung `$pretesRegisteredActive` per periode aktif dan menyembunyikan pengumuman bertipe `pendaftaran_pretes` bila `!$isEligibleActivePretes`. |
| `pages/pretes-hasil.php` | 69–108 | Status & CTA `Daftar Pretes` | Menilai `$isEligibleActivePretes` dan tidak menutup pendaftaran periode bertarget khusus (mis. `RPL`) hanya karena gelombang tutorial umum sedang aktif. |

### 3. Pengujian & Verifikasi

| File | Baris | Bagian | Verifikasi |
|---|---|---|---|
| `tests/pretes-target-program-test.php` | 1–112 | Test suite baru target program & tanggal | Memverifikasi `normalizePretesTargetProgram()`, SQLite auto-migration, gating `RPL` vs `Reguler`, format tanggal `16–20 November 2026` & lintas bulan, serta tidak adanya query `OR periode LIKE '%2026%'` di endpoint pretes terkait. |
| Seluruh file terubah + test suite | — | `php -l` & `tests/*test*.php` | `No syntax errors` di seluruh 9 file PHP dan **22/22 file test lulus** (`TOTAL_FAILED=0`). |

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
