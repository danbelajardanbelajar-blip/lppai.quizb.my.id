## Pembaruan: restore terverifikasi dan latihan skema (25 November 2026)

Pengguna melaporkan restore akademik lulus pada database uji quic1934_uji: 78 tabel total, 43 tabel akademik identik dengan baseline, different_tables kosong. SHA256 SQL c30a4a8a54a574c5b63e3670b193537b7997621cc12f45c251f004b91af8e0ac. Report private restore-verification-20261125-174226-b6d56ecc.json. Bukti ini hanya isi tabel akademik historis; tidak menyatakan seluruh skema/data nonakademik terverifikasi.

Paket berikut menyiapkan latihan skema pada salinan itu saja: enam kolom nullable untuk scope periode/sesi/paket serta snapshot program dan ID periode pendaftaran/attempt. Data lama tidak dipindah atau dihapus. Program hanya diisi bila terbukti dari target periode tunggal; akun mahasiswa saat ini tidak dipakai untuk memindahkan sejarah. Periode Semua, label ambigu/hilang, tryout dan pemilik pendaftaran konflik tetap tidak diberi program secara tebakan. Kepemilikan sesi/paket belum diisi otomatis. Seluruh nilai kolom lama pada seluruh tabel wajib identik sebelum/sesudah. Latihan ini belum dijalankan hosting dan tidak mengaktifkan routing/CBT atau cutover produksi. Lihat checkpoint hosting untuk perintah dan batas DDL.

## Pembaruan: portal admin per program (25 November 2026)

Route baru `/admin/pretes-rpl.php` dan `/admin/pretes-reguler.php` menyediakan periode, peserta, hasil CBT, review snapshot, pencarian, pagination, dan ekspor CSV yang dibatasi program di server. Sidebar admin memiliki grup Pretes per Program. Pengelolaan tugas melalui wrapper tetap RPL/Reguler menolak perubahan program lewat GET/POST dan ID tugas lintas program; CSRF wajib. CRUD tugas menggunakan helper lama dengan filter program.

Pembacaan portal tidak memanggil initializer CBT, migrasi, finalisasi attempt, atau perhitungan ulang nilai. Pengelompokan riwayat sementara berdasarkan `users.jenis_program` saat ini, bukan bukti program historis. Periode target Semua tetap ditampilkan sebagai Campuran lama. Nama periode duplikat ditolak untuk filter, bukan ditebak. Route lama tetap tersedia untuk maintenance arsip.

Ini pemisahan akses admin awal, belum pemisahan total: migrasi periode campuran, penetapan program historis, routing mahasiswa/dosen, pengelolaan sesi/paket dan peralihan produksi masih belum selesai. Restore backup pada database uji terisolasi masih perlu dijalankan serta diverifikasi. Database uji tidak menjadi database aplikasi. Bagian rencana awal di bawah adalah catatan tahap sebelumnya.

# Pemisahan RPL dan Reguler — rencana pelaksanaan dan paket persiapan

## Status paket ini

**Pembaruan setelah penutupan ujian:** Pengguna telah menutup/nonaktifkan sesi dan mengizinkan pekerjaan dilanjutkan. Batas berikutnya adalah akses shared hosting: SSH dari agen ditolak dan pengguna meminta seluruh tindakan eksternal berupa perintah terminal. Lihat `docs/program-separation-hosting-checkpoint.md` untuk capture/backup yang harus dijalankan di terminal hosting sebelum migrasi data. Jalur inisialisasi CBT telah diperbaiki agar tidak otomatis mengaktifkan sesi/menghapus attempt melalui maintenance lama; scope empat modul masih belum diaktifkan produksi.

Permintaan pengguna: pisahkan RPL dan Reguler pada Pretes maupun Tutorial, dengan syarat ujian yang sedang berjalan tidak terganggu.

Paket ini menyelesaikan **audit kode awal, rancangan pemisahan, fondasi scope terisolasi, dan pemeriksa snapshot offline**. Tidak mengaktifkan pemisahan produksi, membuat menu pengganti, mengubah database, atau mengalihkan peserta. Tidak ada file runtime lama yang diubah. Berkas scope/audit baru tidak dimuat oleh route aktif mana pun. Keberadaan paket di Git/hosting tidak mengaktifkannya.

Tahap 2 sampai 7 di bawah masih memerlukan implementasi/integrasi lanjutan. Secara khusus, aktivasi dan migrasi produksi ditahan selama ujian berlangsung. Menyalin halaman lalu mengganti judul tidak menghasilkan isolasi data yang benar.

## Empat modul tujuan

| ID modul | Menu admin | Portal mahasiswa | Tujuan setelah peralihan |
|---|---|---|---|
| `pretes-rpl` | Pretes RPL | Pretes RPL | Pendaftaran, periode, dosen/hari/kuota, CBT, tugas, hasil, sertifikat RPL |
| `pretes-reguler` | Pretes Reguler | Pretes Reguler | Perangkat yang sama dengan kebijakan Reguler sendiri |
| `tutorial-rpl` | Tutorial RPL | Info & Kelas RPL | Pertahankan modul yang sudah terpisah; audit seluruh akses dan integrasi |
| `tutorial-reguler` | Tutorial Reguler | Tutorial Reguler | Pastikan data legacy RPL tidak mengalir ke pendaftaran/kelas Reguler baru |

Satu database dan akun/login bersama. Pembatas program berada di server, bukan sekadar parameter filter browser. Mesin CBT, kamera, CSS, PDF/XLSX, dan utilitas teknis boleh bersama dengan scope eksplisit. Nilai lulus dan aturan bebas tutorial tetap milik kebijakan program masing-masing; pemisahan ini tidak menetapkan ulang ambang nilai.

## Audit kode awal: titik hubungan yang harus dipisahkan

| Bagian | Bukti file saat audit | Temuan dan kebutuhan |
|---|---|---|
| Periode/jadwal Pretes | `admin/pretes-jadwal.php`, `admin/pretes-dosen.php`, `admin/pretes-pengaturan-tutor.php`, `includes/pretes-helper.php` | `master_pretes.target_program` menerima Semua/RPL/Reguler. Halaman memiliki DDL otomatis. Periode baru setelah peralihan harus satu program. Hari, penugasan, kuota dan ruangan perlu mengikuti pemilik periode. |
| Pendaftaran mahasiswa | `pages/pretes-daftar.php`, `includes/pretes-exam-access.php` | Pendaftaran legacy memakai `periode` berupa nama. Pencarian periode aktif dan pendaftaran menggunakan nama; duplikasi nama tidak boleh diselesaikan dengan memilih ID terbaru tanpa review. |
| Komponen/tugas Pretes | `includes/pretes-components.php`, `admin/pretes-tugas.php` | Sudah ada `program` dan keunikan `(program, comp_key)`. Ada fungsi gabungan semua program untuk rekap; panggilan tanpa program harus diaudit. |
| CBT Pretes | `includes/pretes-cbt.php`, `includes/pretes-cbt-interruption.php`, `includes/pretes-tryout.php`, `api/pretes-cbt.php` | Paket/sesi/soal/attempt/item bersama. Attempt mempunyai `registration_id`, `session_id`; item menyimpan snapshot soal dan jawaban. Paket/sesi tidak mempunyai kepemilikan program eksplisit pada definisi dasar yang diaudit. Mode resmi/tryout berbeda dari program. |
| Efek inisialisasi CBT | `includes/pretes-cbt.php::ensurePretesCbtTables`, `ensurePaket2KristenProtestan` | Helper dapat membuat skema, memperbarui paket sesi dan menghapus attempt yang dianggap tercampur. Audit offline tidak boleh memanggil helper ini atau melakukan HTTP probe halaman yang memanggilnya. Tidak mengubah perilaku tersebut dalam paket ini. |
| Kamera/pengawasan | `pages/pretes-ujian.php`, `api/pretes-proctoring-check.php`, `includes/pretes-face-engine.php`, `services/face-engine/` | Tantangan dan log terkait pendaftaran. Jaga ID, namespace sesi, foto acuan, timeout dan alur pengawasan peserta aktif. |
| Hasil/sertifikat | `includes/pretes-rpl-flow.php`, `includes/pretes-certificate.php`, `includes/pretes-helper.php`, `admin/pretes-nilai.php`, `admin/pretes-rekap-nilai.php` | Sinkronisasi ke nilai dan sertifikat dapat memiliki efek tulis. Program akun saat ini belum tentu membuktikan program saat ujian lama. |
| Ekspor/cetak | `admin/export-pretes-peserta.php`, `admin/pretes-peserta-export.php`, `admin/pretes-peserta-pdf.php`, `admin/pretes-absensi-export.php`, helper ekspor nilai | Isolasi harus diterapkan pada query dan endpoint unduhan, bukan hanya tabel HTML. |
| Tutorial Reguler | `includes/tutorial-membership.php`, `admin/tutorial-generate.php`, `admin/tutorial-hasil-plotting.php`, `admin/kelola-nilai.php` | `tutorial_registrations`, `tutorial_classes`, `master_gelombang` serta nilai legacy dipakai bersama sejumlah alur. Riwayat RPL dalam tabel lama harus dilaporkan, bukan otomatis dipindah/dihapus. |
| Tutorial RPL | `includes/tutorial-rpl-helper.php`, `includes/tutorial-rpl-periods.php`, `includes/tutorial-rpl-registration.php` | Tabel khusus sudah ada. Audit pendaftaran, penugasan, kelas, nilai dan endpoint CBT tetap diperlukan. Jangan menyamakan hanya-pendaftaran dengan anggota kelas aktif. |
| Ketergantungan Tutorial RPL | `pages/tutorial-rpl-cbt.php`, `includes/tutorial-rpl-tasks.php` | Portal CBT memakai aset/utility Pretes; penyalinan awal tugas membaca Pretes RPL. Salinan tugas adalah operasi eksplisit/sekali, bukan sinkronisasi dua arah. Pemisahan tidak memutus dependensi ini tanpa pengganti teruji. |
| Navigasi/identitas | `includes/sidebar.php`, `pages/dashboard.php`, `includes/user-helper.php`, `.htaccess` | Sidebar telah memisahkan menu Tutorial RPL. Dashboard, direct URL, role dosen dan export harus mengikuti scope, dengan jalur legacy tetap tersedia untuk riwayat. |

Ini audit kode checkout, bukan hasil pemeriksaan database atau sesi ujian hosting. Daftar tabel/versi hosting harus dicocokkan dari salinan offline sebelum menyusun SQL final.

## Rancangan kepemilikan data

1. Pada data baru, simpan `program`/`program_snapshot` eksplisit di periode, pendaftaran, sesi dan paket; `periode_id` menjadi referensi stabil untuk pendaftaran baru. Label periode bukan foreign key.
2. Bentuk pemilik resource server-side: aktivitas (`pretes`/`tutorial`), program (`rpl`/`reguler`), ID resource dan pemilik akun/penugasan. Scope tidak boleh dibentuk dari POST pengguna tanpa verifikasi record database.
3. Unknown, kosong dan `Semua` tidak boleh diam-diam menjadi Reguler. Endpoint scoped menolak resource tanpa kepemilikan yang terbukti.
4. Semua baca/tulis/bulk/export memeriksa scope yang sama. Admin dapat memilih modul, tetapi ID milik modul lain tetap ditolak dalam endpoint modul yang sedang dipakai. Mahasiswa harus sesuai program dan pemilik pendaftaran; dosen harus sesuai penugasan resource.
5. Simpan manifest migrasi: `source_table`, `source_id`, `source_program_evidence`, `target_module`, `target_id`, status review dan checksum. Unique source/target yang sesuai mencegah duplikasi saat proses diulang. Manifest final belum dibuat karena snapshot produksi belum diaudit.
6. Riwayat campuran tetap utuh dalam jalur arsip legacy. Jangan menggandakan attempt sehingga terlihat sebagai dua ujian; jangan mengubah snapshot soal, kunci, jawaban, timer atau skor. Bila template paket/sesi dipakai kedua program, buat salinan template untuk **ujian baru** setelah masa ujian lama selesai, dengan ID baru dan pemilik eksplisit.
7. Perubahan program pada akun tidak memindahkan riwayat akademik secara otomatis. Review berdasarkan bukti pendaftaran/periode pada waktu kejadian; program akun saat ini hanya kandidat.

`includes/program-separation/scope.php` sudah menyediakan guard murni untuk empat modul, pemilik mahasiswa, penugasan dosen dan role admin. Guard belum terpasang pada route aktif, sehingga belum menegakkan isolasi produksi. Tidak ada bypass tester dalam fondasi baru; kebutuhan tester harus dirancang dan diuji tersendiri sebelum integrasi.

## Tahapan implementasi dan kriteria selesai

| Tahap | Pekerjaan | Kriteria selesai / status saat paket ini |
|---|---|---|
| 1. Pemetaan | Inventaris kode, dependensi, pemilik data, rancangan manifest dan risiko efek inisialisasi | Audit kode dan alat offline tersedia. Audit data hosting belum dilakukan. |
| 2. Pembatas server | Repository/query scoped, snapshot program, guard per-ID, role dan bulk; migrasi skema additive yang telah dilatih | Guard murni sudah diuji. Integrasi query, DDL dan backfill belum dijalankan. |
| 3. Admin Pretes | Halaman periode/peserta/dosen/hari/kuota/tugas/nilai per program dengan scope tetap | Belum diubah; tidak memasang tautan baru yang masuk ke jalur legacy tanpa pembatas. |
| 4. CBT | Kepemilikan bank/paket/sesi, token, tryout, attempt, resume, pengawasan dan hasil | Ditahan: seluruh jalur CBT aktif tetap pada kode lama. Perlu pengujian isolasi dan latihan peralihan terlebih dahulu. |
| 5. Mahasiswa/dosen | Route per program, pemilihan periode yang benar, hak penugasan, tutorial tujuan, arsip legacy | Belum dialihkan. URL lama harus tetap dapat menyelesaikan ujian yang sudah dimulai. |
| 6. Migrasi riwayat | Latihan pada backup, review konflik, manifest ID, pencocokan nilai/log/sertifikat dan restore | Belum ada migrasi produksi; pemeriksa offline hanya kandidat dan daftar masalah. |
| 7. Aktivasi | Masa tenang, backup terverifikasi, pemeriksaan sesi terakhir, aktifkan per modul, smoke test dan pemantauan | Menunggu ujian selesai dan seluruh kriteria teknis di bawah terpenuhi. Tidak otomatis aktif saat push. |

## Pemeriksa snapshot offline yang sudah tersedia

Jalankan hanya pada berkas contoh/salinan data yang telah dibuat di luar jalur ujian aktif. Tool tidak memiliki koneksi database, tidak memuat konfigurasi aplikasi, tidak mengekspor database, tidak membuat skema dan tidak mempunyai opsi apply.

```powershell
php tools/program-separation-audit.php --help
php tools/program-separation-audit.php --input=docs/examples/program-separation-snapshot.json
```

Contoh adalah data sintetis: dua mahasiswa, satu periode Semua, sesi campuran dan satu attempt belum selesai. Exit code yang diharapkan: **3**, karena ada temuan yang memerlukan review. Contoh bukan diagnosis hosting.

Format input: objek `schema_version: 1`, `tables` berisi seluruh tabel minimum dari `Audit::FIELDS`. Masing-masing tabel list baris dan semua kolom minimum wajib ada; nilai tidak diketahui dikirim `null`. Tabel kosong berbeda dari tabel tidak disertakan. Kolom tambahan ditolak agar password, nama/NIM, token, foto, soal dan jawaban tidak terbawa. ID harus positif dan tidak duplikat per tabel. Input maksimum 32 MiB, hanya path file lokal. PHP 8.1+ diperlukan untuk fondasi ini; runtime ujian lama tidak diubah.

| Tabel snapshot | Kolom yang diizinkan |
|---|---|
| users | id, jenis_program |
| master_pretes | id, periode, target_program, status |
| pretes_registrations | id, user_id, periode |
| pretes_cbt_sessions | id, is_active, package_ids_json; opsional mode, exam_open |
| pretes_cbt_packages | id |
| pretes_cbt_attempts | id, user_id, registration_id, session_id, submitted_at |
| tutorial_registrations | id, user_id |
| tutorial_rpl_registrations | id, user_id |
| tutorial_rpl_members | id, user_id, class_id |
| tutorial_rpl_cbt_sessions | id, is_active |
| tutorial_rpl_cbt_attempts | id, user_id, class_id, session_id, submitted, submitted_at |

Exit 0 berarti format valid dan tidak ada temuan dari pemeriksaan minimum; **bukan izin migrasi**. Exit 2 berarti input/argumen tidak valid; exit 3 berarti laporan memiliki temuan. `cutover_allowed` selalu false, bahkan saat seluruh tabel kosong atau tidak ditemukan masalah. Fingerprint SHA-256 menautkan laporan ke snapshot yang diperiksa, tanpa menjamin snapshot lengkap/terkini. Jangan unggah snapshot asli atau laporan ber-ID mahasiswa ke direktori web/Git; simpan privat di luar document root.

Temuan minimum mencakup program kosong, periode Semua/ambigu, konflik akun-periode, attempt beda pemilik pendaftaran, sesi/paket campuran atau belum diketahui, tutorial beda program, sesi aktif dan attempt belum selesai (termasuk yang tenggatnya lewat). Jangan memanggil auto-submit/cleanup untuk menghilangkan blocker. Sesi aktif dianggap perlu ditinjau walaupun belum ada attempt; status aktif saja tidak membuktikan ada peserta yang sedang mengerjakan.

Audit ini belum membaca isi nilai, items, log, sertifikat, tugas atau seluruh relasi kelas. Laporan tidak pernah menyatakan seluruh relasi akademik telah aman. Relasi tersebut wajib direkonsiliasi pada latihan migrasi lengkap.

## Prosedur peralihan setelah masa ujian

1. Tetapkan jendela peralihan dan hentikan pendaftaran/start ujian baru melalui mekanisme yang telah diuji, tanpa memutus save/submit peserta yang masih mengerjakan.
2. Pastikan tidak ada Pretes **dan Tutorial RPL** yang masih aktif, paused/resume, atau menunggu submit. Lakukan pemeriksaan ulang mendekati peralihan. Snapshot offline lama tidak cukup membuktikan server sekarang tenang. Bila masih ada aktivitas, tunda peralihan.
3. Buat backup lengkap database dan berkas yang direferensikan, catat checksum/waktu, dan buktikan restore pada lingkungan terisolasi. Tidak menulis kredensial atau data mahasiswa dalam log Git.
4. Jalankan migrasi additive yang telah diuji pada replika. Pelihara ID/riwayat legacy. Uji skenario gagal di tengah, transaksi/rollback dan pengulangan idempoten. Perubahan DDL MySQL tidak boleh diasumsikan ikut rollback transaksi DML.
5. Bandingkan jumlah peserta per program/periode, jumlah attempt/item terjawab, nilai/kelulusan, penugasan, bukti tugas dan sertifikat. Uji unduhan dan direct URL lintas program. Tolak aktivasi bila ada konflik yang belum diputuskan.
6. Aktifkan admin/pendaftaran baru per modul terlebih dahulu, kemudian start ujian baru setelah pengujian berhasil. Jangan mengganti aset/namespace sesi di tengah attempt legacy.
7. Rollback aplikasi melalui sakelar routing yang telah disiapkan dan diuji; jangan menurunkan skema atau memulihkan backup lama di atas jawaban baru yang sudah masuk. Bila ada data baru setelah aktivasi, gunakan rekonsiliasi manifest sebelum rollback data. Semua langkah aktivasi/rollback harus tercatat dengan waktu WIB, commit dan operator.

## Matriks uji wajib sebelum aktivasi

- Mahasiswa RPL ditolak pada ID/URL/POST Reguler, dan sebaliknya; admin pada endpoint satu modul tidak boleh mengubah ID modul lain. Dosen hanya mengakses penugasan yang sesuai.
- Dua periode aktif beda program tidak saling mengambil peserta, kuota, dosen atau hari. Duplikasi nama periode tidak menyebabkan salah relasi.
- CBT: start, token, save, resume/interupsi, submit/timeout, soal cadangan, kamera, sesi resmi/tryout dan hasil tetap konsisten; ID campuran ditolak tanpa mengubah attempt.
- Riwayat akun yang berubah program, periode Semua, peserta belum pretes, lulus/tidak lulus, serta riwayat Tutorial RPL dalam tabel legacy direview secara eksplisit.
- Ekspor PDF/XLSX/CSV, absensi, pengumuman, sertifikat dan sinkronisasi nilai mengikuti scope dan tidak menggandakan nilai/sertifikat.
- Migrasi ulang, kegagalan parsial dan rollback diuji pada replika MySQL/MariaDB yang sesuai hosting; pengujian SQLite/unit saja belum membuktikan kesiapan DDL hosting.

## Batas keamanan ujian pada paket ini

Tidak menjalankan perintah ke hosting/VPS, tidak membuka halaman ujian produksi untuk menguji, tidak mengubah token/jadwal/sakelar ujian, tidak menjalankan migrasi ataupun restart layanan. Pemeriksaan regresi berjalan pada fixture lokal. Commit/pull/push hanya membawa berkas baru yang belum terhubung ke runtime dan catatan perubahan; bukan bukti deployment/aktivasi pemisahan produksi.

Verifikasi paket: lint empat file PHP baru dan delapan suite (fondasi scope/audit, CBT Pretes, sesi Pretes, token, interruption/resume, akses ujian, paritas CBT Tutorial RPL, paritas pengawasan Tutorial RPL) lulus. Beberapa suite Pretes lama mencatat `no such table: pretes_assessments` karena fixture minimumnya tidak menyediakan tabel sinkronisasi tersebut; suite tetap lulus. Engine dan suite lama tidak diubah untuk menutupi log ini. Pengujian baru tidak memuat konfigurasi, koneksi database atau helper runtime dan tidak menghasilkan log tersebut. MySQL hosting, restore backup dan sesi peserta nyata belum diuji.

## Review hasil audit pertama dari hosting

Capture hosting 2026-11-25 16:17:32 WIB berhasil membuat backup 32.904.359 byte; pengguna melaporkan fingerprint 43 tabel akademik tidak berubah dan 159 temuan. Ringkasan: satu periode Semua (ID 1), 96 konflik pemilik attempt, tiga attempt belum submit (36,54,119), tiga sesi aktif (3,4,5), satu sesi/paket perlu review (3) dan 54 pendaftaran pada tabel Tutorial Reguler yang program akunnya berbeda. Angka ini berasal dari laporan awal, **bukan pernyataan ada 159 data rusak**.

Auditor awal belum membedakan registration_id negatif untuk tryout (`-(session_id * 1000 + attempt_no)`) dan placeholder tester 999999 dari pendaftaran resmi. Perbaikan mengklasifikasikan jenis attempt tanpa mengubah data. Slot tryout harus 1..999, session_id harus cocok, dan mode jika tersedia harus tryout; ketidakcocokan tetap temuan. Placeholder tester tanpa pendaftaran nyata tetap perlu review, bukan izin melewati pemeriksaan. Jika pendaftaran 999999 benar-benar ada, pemiliknya tetap diperiksa.

Mode/exam_open kini menjadi kolom snapshot opsional. Snapshot lama tetap bisa dibaca, tetapi mode tryout yang tidak terekam diberi status belum terverifikasi. Attempt tryout yang belum submit dan sesi tryout aktif tetap dilaporkan terpisah dari resmi; keduanya tidak dihapus/di-submit untuk membersihkan audit. Periode campuran dan riwayat Tutorial Reguler milik akun yang kini RPL harus ditinjau sebagai data historis, tanpa dipindah/dihapus berdasarkan program akun saat ini saja.

`tools/program-separation-review.php --capture-dir=PRIVATE_CAPTURE --live-sessions` memverifikasi hash SQL/snapshot lalu menyajikan ringkasan jenis attempt dan temuan berdasarkan snapshot asli, serta metadata sesi terkini (ID, mode, is_active, exam_open) dari SELECT dalam transaksi read-only. Metadata live ditampilkan terpisah dan tidak menulis ulang snapshot historis. Tidak memanggil helper/initializer aplikasi dan tidak memerlukan proc_open. Skrip PHP file menghindari peringatan cPanel CLI ketika menerima kode php -r multiline; peringatan stat tersebut bukan bukti kerusakan database.

Backup belum diuji restore; pemisahan produksi tetap belum diaktifkan. Review ini tidak membuktikan seluruh 96 temuan awal adalah tryout/tester—jumlah sebenarnya baru diketahui setelah pengguna menjalankan reviewer pada snapshot hosting.
