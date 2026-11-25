# Langkah terminal hosting sebelum migrasi pemisahan program

## Bukti latihan lulus dan perintah tanpa mengetik password ulang

Pengguna melaporkan test_schema_rehearsal_passed pada quic1934_uji, mode resume_metadata_only, zero ALTER, seluruh nilai kolom lama pada 78 tabel identik. Sebanyak 547 pendaftaran ditautkan ke periode unik, 77 attempt resmi diberi snapshot program berdasarkan periode, satu periode target tunggal dan satu periode campuran/unknown; paket/sesi belum diberi owner. Report schema-rehearsal-20261125-181114-90b77f87-after.json. Produksi tidak dihubungi, cutover_allowed tetap false.

Pengguna meminta tidak mengetik password berulang. Karena akun database uji sama dengan DB_USER pada hosting, ketiga CLI verify-restore, inspect-rehearsal dan rehearse-schema mendukung flag eksplisit --use-config-test-credentials. Flag ini membaca DB_PASS yang sudah ada pada config hosting, hanya jika target persis quic1934_uji, target berbeda dari DB_NAME produksi (case-insensitive), serta username cocok persis DB_USER. Tidak membuka koneksi produksi. Tidak membuat salinan password, menyimpannya di Git/file baru/environment/argumen atau mencetaknya. Password dari chat tidak disalin ke kode/dokumen. Mode stdin lama tetap tersedia tanpa flag.

Pemeriksaan berikut hanya membaca proyeksi kolom lama dan metadata setelah latihan, sekaligus membuktikan mode kredensial hosting dapat dipakai tanpa prompt:

```bash
cd "$HOME/public_html/lppai.quizb.my.id/web" &&
git pull --ff-only &&
php tools/program-separation-inspect-rehearsal.php \
  --capture-dir="$HOME/lppai-private-backups/lppai-20261125-161732-97da6915" \
  --database=quic1934_uji \
  --username=quic1934_zenhkm \
  --use-config-test-credentials
```

Jangan ulangi rehearsal yang sudah lulus atau verifier restore lama setelah schema bertambah. Tahap berikut masih memerlukan penetapan owner/template paket-sesi per program dan integrasi endpoint. Kredensial yang tersedia tidak mengatasi pembatasan SSH hosting dari IP luar; tindakan eksternal tetap dijalankan pengguna melalui terminal cPanel.

## Lanjutkan metadata dari enam kolom kosong yang sudah terverifikasi

Inspeksi pengguna setelah rollback membuktikan seluruh kolom lama pada 78 tabel tetap sama dengan preimage, tidak ada tabel hilang/bertambah, keenam kolom tambahan ada dan seluruh isinya NULL. Jurnal sumber schema-rehearsal-20261125-175633-56d7ef73-before.json. Hosting mengonfirmasi master_pretes.updated_at memiliki ON UPDATE. Perilaku lama yang mengubah timestamp saat UPDATE metadata telah direproduksi pada MariaDB 11.4.9 lokal; transaksi rollback memulihkan seluruh nilai. Perbaikan mengisi kolom metadata baru sambil menetapkan setiap kolom ON UPDATE pada tabel yang ditulis ke nilainya sendiri, sehingga tidak mengganti waktu historis (referensi: https://dev.mysql.com/doc/refman/8.4/en/timestamp-initialization.html).

Mode resume di bawah tidak menjalankan ALTER, impor atau DROP. Ia membutuhkan report restore lulus dan jurnal sumber yang cocok backup/database, proyeksi akademik jurnal sama dengan baseline backup, seluruh nilai lama saat ini sama, daftar/urutan kolom persis, enam kolom baru bertipe/nullable/default sesuai serta masih NULL, dan semua tabel InnoDB. Jika salah satu tidak cocok, berhenti tanpa mencoba memperbaiki nilai. Pemeriksaan proyeksi lama tetap dijalankan dalam transaksi pengisian; perubahan kolom lama lain tetap menyebabkan rollback. Keberhasilan/ketidakberhasilan resume tidak mengaktifkan pemisahan runtime produksi.

```bash
cd "$HOME/public_html/lppai.quizb.my.id/web" &&
git pull --ff-only &&
(
  set +x
  umask 077
  IFS= read -r -s -p "Password database uji: " lppai_test_password || exit 1
  printf '\n'
  printf '%s' "$lppai_test_password" | php tools/program-separation-rehearse-schema.php \
    --capture-dir="$HOME/lppai-private-backups/lppai-20261125-161732-97da6915" \
    --restore-report="$HOME/lppai-private-backups/lppai-20261125-161732-97da6915/restore-verification-20261125-174226-b6d56ecc.json" \
    --resume-from="$HOME/lppai-private-backups/lppai-20261125-161732-97da6915/schema-rehearsal-20261125-175633-56d7ef73-before.json" \
    --database=quic1934_uji \
    --username=quic1934_zenhkm
)
```

Hasil yang diharapkan test_schema_rehearsal_passed, mode=resume_metadata_only, resumed_existing_columns=true, alter_statements_executed=0, all_original_column_values_unchanged=true, original_tables_verified=78, cutover_allowed=false. Jurnal asli tidak ditimpa; jurnal sebelum/sesudah baru dibuat private. Jangan menjalankan ulang setelah sukses; metadata yang sudah diisi ditolak. Bila gagal, kirim error sebelum mengambil langkah lain. Jika laporan akhir gagal ditulis setelah commit, pengisian metadata mungkin sudah tersimpan; jangan menganggap seluruh operasi rollback. Jalankan inspector untuk memastikan kondisi.

## Inspeksi baca saja setelah latihan membatalkan DML

Pengguna mengirim REHEARSAL INCOMPLETE: Original data changed during rehearsal; metadata updates rolled back, added columns may remain. Jangan mengulang ALTER/impor atau menghapus kolom. Produksi tidak dihubungi; DDL pada database uji dapat sudah committed. Penyebab belum terbukti: salah satu kemungkinan adalah kolom ON UPDATE yang otomatis berubah saat metadata diisi. Definisi master_pretes di admin/pretes-jadwal.php memiliki updated_at TIMESTAMP ... ON UPDATE CURRENT_TIMESTAMP; keberadaan efek pada tabel hosting harus dibuktikan, bukan diasumsikan.

Inspektor berikut hanya SELECT/SHOW dalam transaksi read-only, tidak ALTER/UPDATE, tidak memperbaiki hash/nilai dan tidak menulis jurnal baru. Ia memeriksa semua jurnal preimage yang sesuai capture (tidak memilih diam-diam jika ada beberapa), membandingkan seluruh nilai kolom lama yang tersimpan, daftar/urutan tabel, keberadaan enam kolom tambahan, jumlah baris metadata baru non-NULL dan daftar kolom yang memiliki automatic ON UPDATE. Output hanya nama tabel/kolom, jumlah/hash/status; tidak ada nilai, jawaban atau identitas individual.

```bash
cd "$HOME/public_html/lppai.quizb.my.id/web" &&
git pull --ff-only &&
(
  set +x
  umask 077
  IFS= read -r -s -p "Password database uji: " lppai_test_password || exit 1
  printf '\n'
  printf '%s' "$lppai_test_password" | php tools/program-separation-inspect-rehearsal.php \
    --capture-dir="$HOME/lppai-private-backups/lppai-20261125-161732-97da6915" \
    --database=quic1934_uji \
    --username=quic1934_zenhkm
)
```

all_original_column_values_unchanged=true berarti proyeksi semua kolom lama saat inspeksi cocok dengan preimage, meski schema sudah bertambah; ini bukan bukti latihan berhasil. different_tables menunjukkan perbedaan yang masih ada, sedangkan automatic_update_columns hanya kandidat penyebab, bukan pembuktian kolom tersebut berubah dalam transaksi yang sudah rollback. Kirim hasil inspeksi terlebih dahulu sebelum membuat perintah recovery. Tidak ada penghapusan/restore/retry otomatis dan cutover tetap false.

## Latihan skema additive setelah restore lulus

Bukti hosting terbaru: restore_academic_verification_passed, 78 tabel total, 43 tabel akademik cocok, different_tables=[], report restore-verification-20261125-174226-b6d56ecc.json. Langkah berikut hanya mengubah salinan quic1934_uji. CLI rehearsal tidak memiliki mode produksi/override dan menolak jika nama database uji sama dengan konfigurasi aplikasi. Password user yang sama dapat dipakai, tetapi koneksi dibuat eksplisit ke database uji. Semua tabel harus InnoDB; baseline akademik diperiksa ulang sebelum ALTER.

```bash
cd "$HOME/public_html/lppai.quizb.my.id/web" &&
git pull --ff-only &&
(
  set +x
  umask 077
  IFS= read -r -s -p "Password database uji: " lppai_test_password || exit 1
  printf '\n'
  printf '%s' "$lppai_test_password" | php tools/program-separation-rehearse-schema.php \
    --capture-dir="$HOME/lppai-private-backups/lppai-20261125-161732-97da6915" \
    --restore-report="$HOME/lppai-private-backups/lppai-20261125-161732-97da6915/restore-verification-20261125-174226-b6d56ecc.json" \
    --database=quic1934_uji \
    --username=quic1934_zenhkm
)
```

Enam kolom baru: master_pretes.program_scope; pretes_registrations.periode_id dan program_snapshot; pretes_cbt_sessions.program_scope; pretes_cbt_packages.program_scope; pretes_cbt_attempts.program_snapshot. Semua nullable, tidak rename/drop kolom/tabel dan tidak menambah FK yang bisa menghapus riwayat. Pendaftaran diberi periode_id hanya bila label cocok tepat satu periode berdasarkan collation database. Program pendaftaran/attempt resmi hanya diisi dari target periode RPL/Reguler yang eksplisit, dengan pemilik pendaftaran valid. Program akun saat ini bukan dasar backfill. Periode Semua/unknown, label ambigu, tryout dan konflik pemilik tetap tanpa program; sesi/paket tetap belum diberi pemilik. Tidak menyimpulkan pemisahan total dari latihan ini.

Sebelum DDL, jurnal private menyimpan daftar kolom asli dan fingerprint seluruh tabel, termasuk akun dan data nonakademik (hanya hash/jumlah, bukan isi pribadi). Setelah metadata baru diisi dalam transaksi, proyeksi semua kolom asli harus identik. Efek trigger ke nilai/data lama membatalkan DML. MySQL ALTER melakukan implicit commit: jika gagal, kolom yang sudah ditambahkan dapat tetap ada. Tool tidak pernah mengklaim rollback DDL, menghapus kolom otomatis atau memulihkan database penuh. Bila gagal, kirim error ringkas; jangan impor/ulang/drop sendiri. Tool menolak schema yang telah dimodifikasi atau input berubah.

Hasil yang diharapkan test_schema_rehearsal_passed, added_columns=6, original_tables_verified=78, all_original_column_values_unchanged=true, cutover_allowed=false. Ini hanya latihan fondasi skema, belum migrasi produksi, pengisian kepemilikan paket/sesi, atau aktivasi endpoint per program. Verifier restore lama membandingkan seluruh kolom dengan backup sebelum ALTER; sesudah latihan, jangan menggunakannya untuk menilai proyeksi kolom lama karena enam kolom baru memang tidak ada dalam baseline. Gunakan jurnal/proyeksi rehearsal, bukan menghapus kolom agar verifier lama lulus.

## Koreksi impor MariaDB yang selesai tetapi database uji tetap kosong

Keluaran hosting setelah verifier dc48d48: koneksi ke quic1934_uji berhasil, tetapi academic_tables=0 dan restored_total_tables=0. Ini belum bukti kerusakan nilai; tidak ada data restore yang dapat dibandingkan. Perintah impor sebelumnya dari agen memakai --one-database bersama -D. Pada kode klien MariaDB, opsi -o mengatur one_database=skip_updates=1; argumen database positional di akhir memulihkan skip_updates=0, sedangkan -D hanya mengatur current_db. Karena itu kombinasi lama dapat melewati query tanpa exit gagal. Referensi primer: https://github.com/MariaDB/server/blob/11.4/client/mysql.cc (case 'o', opsi database/-D, penanganan argc==1, dan skip_updates di com_go). Versi klien hosting belum dicatat, sehingga kecocokan ini merupakan diagnosis berdasarkan kode serta hasil 0 tabel, bukan reproduksi langsung pada hosting dari agen.

Jangan menghapus database atau mengubah dump. Ulangi hanya bila pemeriksaan di bawah memastikan database uji masih kosong. Host dibaca dari konfigurasi yang juga dipakai verifier; tidak memakai password/config produksi untuk login, dan nama database produksi ditolak. SHA256 harus cocok dengan backup yang sudah diidentifikasi. Password uji diminta dua kali oleh klien. Nama database pada impor harus menjadi argumen terakhir, bukan -D. Opsi --one-database merupakan filter terbatas berdasarkan USE, bukan pembatas privilege pengganti akun database.

```bash
cd "$HOME/public_html/lppai.quizb.my.id/web" &&
(
  set +x
  set -o pipefail
  backup="$HOME/lppai-private-backups/lppai-20261125-161732-97da6915/database.sql"
  lppai_test_host=$(php -r '
    require "config/database.php";
    if (strcasecmp(DB_NAME,"quic1934_uji")===0) {fwrite(STDERR,"STOP: tujuan adalah database produksi.\n");exit(1);}
    $expected="c30a4a8a54a574c5b63e3670b193537b7997621cc12f45c251f004b91af8e0ac";
    if (!is_file($argv[1]) || !hash_equals($expected,hash_file("sha256",$argv[1]))) {fwrite(STDERR,"STOP: checksum backup berbeda.\n");exit(1);}
    echo DB_HOST;
  ' "$backup") || exit 1

  jumlah=$(/usr/bin/mariadb --no-defaults --host="$lppai_test_host" \
    -u quic1934_zenhkm -p -D quic1934_uji -Nse \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();") || exit 1
  [ "$jumlah" = "0" ] || { echo "STOP: database uji tidak kosong; jangan impor ulang."; exit 1; }

  hasil=$({
    cat "$backup" &&
    printf '\nSELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();\n'
  } | /usr/bin/mariadb --no-defaults --binary-mode --one-database \
      --host="$lppai_test_host" -u quic1934_zenhkm -p -N -s quic1934_uji) || exit 1
  case "$hasil" in ''|*[!0-9]*) echo "STOP: hasil jumlah tabel tidak valid."; exit 1;; esac
  [ "$hasil" -gt 0 ] || { echo "STOP: hasil impor masih 0 tabel."; exit 1; }
  printf 'Impor menghasilkan %s tabel. Lanjutkan verifier fingerprint.\n' "$hasil"
)
```

Setelah jumlah tabel lebih dari 0, jalankan verifier pada bagian berikut. Hanya kecocokan fingerprint akademik yang dapat membuktikan isi tabel akademik hasil restore sesuai backup historis. Jangan menyatakan latihan restore lulus hanya karena proses impor exit 0 atau tabel sudah ada. Perintah ini tidak mengubah konfigurasi website atau memigrasi pemisahan produksi.

## Verifikasi hasil restore pada database uji

Pengguna membuat database kosong `quic1934_uji` dan memakai user `quic1934_zenhkm`, memverifikasi jumlah tabel 0, lalu melaporkan impor SQL selesai setelah checksum SQL cocok. Keluaran verifier pertama menunjukkan username tersebut sama dengan konfigurasi produksi; database tetap berbeda. Pemeriksaan SELECT-only mengizinkan username bersama, tetapi tetap menolak database produksi. Impor selesai belum membuktikan nilai/jawaban hasil restore sesuai baseline. Jangan mengimpor ulang di atas database uji yang kini berisi data.

Jalankan dari terminal cPanel, bukan melalui URL:

```bash
cd "$HOME/public_html/lppai.quizb.my.id/web" &&
git pull --ff-only &&
(
  set +x
  umask 077
  IFS= read -r -s -p "Password database uji: " lppai_test_password || exit 1
  printf '\n'
  printf '%s' "$lppai_test_password" | php tools/program-separation-verify-restore.php \
    --capture-dir="$HOME/lppai-private-backups/lppai-20261125-161732-97da6915" \
    --database=quic1934_uji \
    --username=quic1934_zenhkm
)
```

Password melewati stdin pipe dari builtin shell, tidak melalui argumen proses, Git, environment atau chat. Variabel hanya berada dalam subshell. Verifier menolak nama database produksi (juga perbedaan huruf besar/kecil), hanya membuka koneksi ke database uji di host yang dikonfigurasi, lalu memeriksa SELECT DATABASE(). Username yang sama dapat dipakai jika memiliki akses ke database uji; izin ini khusus verifier baca saja, bukan izin migrasi/impor ke produksi. Tidak mengubah config aplikasi dan tidak membutuhkan proc_open. Script memverifikasi identitas sumber capture, checksum SQL dan baseline, marker dump, lalu membandingkan multiset seluruh nilai kolom dan jumlah baris pada semua tabel akademik dalam transaksi read-only. Report baru dibuat private 0600 di direktori capture; metadata capture lama tidak ditimpa.

Hasil lulus adalah `restore_academic_verification_passed`, `academic_data_matches_backup: true`, `different_tables: []`. Angka dan checksum merujuk isi backup historis, bukan database produksi saat ini. `restore_tested: true` berarti latihan restore berhasil untuk data akademik; tipe kolom/index/constraint dan data nonakademik belum dibandingkan. `cutover_allowed` tetap false: ini belum latihan migrasi/pemetaan periode campuran. Keluaran gagal tidak boleh dianggap lolos. Jangan menghapus atau memperbaiki nilai agar hash cocok. Kirim ringkasan JSON saja, bukan password atau SQL.

Pengguna sudah menyatakan sesi ujian ditutup dan dinonaktifkan serta mengizinkan peralihan dilanjutkan. Akses SSH hosting menolak autentikasi dari terminal agen; pengguna menjelaskan shared hosting membatasi akses dari IP lain. Karena itu backup/audit hosting harus dijalankan pengguna dari terminal cPanel. Jangan membuka akses database/SSH ke publik atau mengirim password demi melewati batas ini.

## Pembaruan untuk shared hosting tanpa proc_open

Hosting telah melaporkan `Call to undefined function proc_open()`. Jangan mengubah disable_functions atau membuka akses server. Gunakan runner `.sh` di bawah: fase PHP `--prepare` hanya membaca database dan menyiapkan berkas private; mysqldump dijalankan shell terminal; fase PHP `--finish` membandingkan checksum lalu menghasilkan ringkasan. Kedua fase PHP telah diuji dengan proc_open/proc_close dinonaktifkan. Perintah PHP langsung lama sekarang berhenti sebelum koneksi/file dibuat dan mengarahkan ke runner shell.

Kegagalan percobaan lama tidak menjalankan migrasi; folder parsial tetap private dan tidak perlu dihapus. Percobaan baru memakai folder unik, tidak menimpa backup lama. Hasil dump yang gagal tidak diberi marker selesai dan ditolak saat verifikasi.

## Paket perlindungan yang sudah disiapkan

- `ensurePretesCbtTables()` tidak lagi otomatis memanggil impor Paket 2 atau normalisasi/aktivasi sesi default. Sesi yang dimatikan admin tidak diaktifkan kembali oleh pembacaan biasa; fungsi maintenance yang dapat menghapus attempt tidak lagi terpanggil otomatis. Operasi CLI maintenance lama tetap eksplisit dan **tidak perlu dijalankan** untuk audit ini.
- Regresi diuji lintas proses PHP agar static cache tidak menyembunyikan efek pembukaan halaman berikutnya. Fixture mempertahankan sesi nonaktif, konfigurasi paket, snapshot soal, kunci, jawaban belum submit dan nilai ujian selesai. Versi parent menunjukkan mutasi pada fixture yang sama; versi perbaikan mempertahankan seluruh baris.
- `tools/program-separation-capture.php` hanya CLI dan tidak menjalankan migrasi. Script memuat konstanta database dari config, bukan auth/initializer. Ia membuat backup SQL penuh memakai mysqldump, snapshot audit minimum, dan sidik data akademik sebelum/sesudah capture. Tidak pernah memanggil cleanup, auto-submit, restart, atau aktivasi sesi.

## Jalankan dari folder proyek di terminal cPanel

Pastikan folder terminal adalah checkout aplikasi LPPAI yang benar dan memakai branch main. Bila folder belum diketahui, `pwd` serta `git rev-parse --show-toplevel` hanya membaca lokasi; jangan menjalankan perintah di proyek lain.

```bash
git status --short
git branch --show-current
```

Jika ada perubahan lokal hosting, simpan/review dahulu; jangan memakai reset/clean/force. Setelah checkout benar dan bersih:

```bash
git pull --ff-only &&
(umask 077; mkdir -p "$HOME/lppai-private-backups") &&
chmod 700 "$HOME/lppai-private-backups" &&
sh tools/program-separation-capture.sh --output-dir="$HOME/lppai-private-backups"
```

Tidak ada flag apply/migrate pada perintah ini. Output backup ditolak jika berada di dalam folder aplikasi atau direktori `public_html`, `htdocs`, `www`; parent output harus 0700 pada Linux. Folder per-run dibuat 0700 dan berkas 0600. Pastikan kapasitas disk tersedia untuk backup penuh database. CLI membutuhkan PHP 8.1+, PDO MySQL, shell sh dan mysqldump yang tersedia melalui PATH terminal; proc_open PHP tidak diperlukan. PHP CLI alternatif dapat dipilih lewat PHP_BIN. Script berhenti bila fasilitas tidak tersedia. Tabel non-InnoDB menyebabkan penolakan agar konsistensi backup tidak diasumsikan.

## Hasil yang dibutuhkan untuk langkah berikutnya

Keluaran yang berhasil berstatus `backup_created_no_migration` serta `academic_data_unchanged_during_capture: true`. Kirim **keluaran ringkasan itu** terlebih dahulu. Ia tidak berisi password, token, nama/NIM, soal, jawaban, foto atau nilai individual. `restore_tested: false` dan `cutover_allowed: false` tetap ditulis; hasil ini belum mengizinkan migrasi.

Folder private berisi:

| Berkas | Isi / penggunaan |
|---|---|
| database.sql | Backup database penuh dengan skema, data, routine, event dan trigger; sensitif, jangan dikirim ke chat/Git/web |
| integrity-before.json, integrity-after.json | Jumlah dan checksum multiset setiap tabel akademik (`pretes_*`, `tutorial_*`, master periode, tabel nilai/sertifikat sesuai prefix) |
| snapshot.json | Proyeksi ID/program/relasi minimum untuk audit; tetap simpan privat |
| schema-gaps.json | Tabel/kolom yang berbeda dari minimum audit; tidak ada ALTER otomatis |
| audit.json | Kandidat program dan masalah campuran/konflik/sesi aktif/attempt belum selesai; bukan keputusan migrasi |
| summary.json | Ringkasan hasil dan checksum backup, boleh dikirim sebagai status tanpa isi data |
| capture-state.json | Identitas database berupa hash dan checksum berkas persiapan; menolak verifikasi pada database lain/berkas yang berubah |
| dump.sh | Perintah mysqldump private dengan argumen dikutip; tidak menampilkan password |
| dump-complete | Marker hanya dibuat setelah mysqldump exit 0 dan berkas parsial berhasil dipromosikan |
| dump-error.txt | Stderr mysqldump tetap private; jangan mengirim dump SQL/config untuk diagnosis |

Backup SQL mencakup seluruh database aplikasi, termasuk akun dan data autentikasi; file mysql-client.cnf sementara berisi kredensial dan diberi 0600. Trap shell menghapusnya saat dump selesai/gagal/interupsi; verifikasi menolak hasil jika kredensial belum dibersihkan. Jika terminal berhenti tepat setelah prepare sebelum shell dimulai, file tetap private di folder 0700 dan tidak boleh dibagikan. Password tidak dimasukkan ke argumen command line atau dicetak. Jangan membagikan direktori ini melalui File Manager public link.

Sidik data mengikutkan semua kolom setiap tabel akademik dan menghitung duplikasi, null, binary serta jawaban/nilai persis; urutan fisik baris tidak memengaruhi hash. Snapshot minimum tidak membawa kolom password, token, nilai individual atau jawaban. Perubahan data akademik selama capture menyebabkan gagal, bukan laporan aman palsu. Sidik akademik bukan checksum semua tabel login/log sistem; backup SQL tetap mencakup seluruh database.

## Jika gagal

- `CAPTURE INCOMPLETE` berarti backup/capture belum dapat dianggap lengkap. Tidak ada migrasi yang dijalankan. Simpan berkas parsial tetap private; jangan membagikan dump atau konfigurasi database.
- Jika `mysqldump` tidak tersedia/privilege routine-event kurang, sampaikan pesan error ringkas; perintah alternatif harus disesuaikan dengan fasilitas hosting. Jangan membuat script backup publik melalui URL.
- Jika ditemukan perubahan data saat capture, jangan menutup paksa atau menghapus attempt untuk memperbaiki checksum. Tinjau aktivitas terlebih dahulu lalu ulangi capture.
- Jika ada attempt belum submit, audit mempertahankannya. Sesi yang sudah dinonaktifkan tidak membuktikan semua attempt telah selesai. Kelanjutan migrasi harus menjaga atau meninjau attempt tersebut, tanpa mengubah nilainya.

## Batas lanjut migrasi

Setelah hasil tersedia: audit konflik program dan periode campuran berdasarkan bukti, siapkan manifest ID, latih migrasi pada salinan dan restore backup pada database uji terisolasi. Bandingkan nilai/jawaban sebelum-sesudah. Baru kemudian perubahan skema/repository/halaman per program dapat diaktifkan dengan rencana rollback yang telah diuji. Jangan memulihkan database penuh di atas transaksi produksi baru atau menganggap backup SQL yang belum dilatih restore sebagai backup terverifikasi.

Tidak ada migrasi/aktivasi pemisahan produksi dalam paket checkpoint ini. Pekerjaan eksternal yang diperlukan diserahkan dalam bentuk perintah terminal karena batas akses shared hosting; belum ada klaim integritas database hosting sebelum hasil perintah diterima.

## Hasil uji kompatibilitas lokal

Uji integrasi menjalankan runner shell asli dengan PHP fixture yang menonaktifkan proc_open/proc_close, serta klien mysqldump sintetis. Diuji sukses/gagal/ulang, quoting path dan database dengan kutip, pembersihan file kredensial, perbedaan database, perubahan snapshot/nilai dan larangan klaim restore/migrasi. Ini menguji alur runner, bukan backup MySQL hosting nyata. Setelah perbaikan dipull, keluaran terminal hosting tetap diperlukan untuk memastikan mysqldump/privilege tersedia.

## Tutup gerbang Pretes yang ternyata masih aktif

Review pengguna setelah commit 1a0b3e2 mengonfirmasi 96 attempt tryout dan 77 attempt resmi pada snapshot lama; tiga pending semuanya tryout (36,54,119). Metadata live menunjukkan sesi 3 mode tryout, sesi 4 dan 5 mode resmi, ketiganya is_active=1 dan exam_open=1. Pernyataan sesi sudah ditutup sebelumnya tidak membuktikan seluruh sesi Pretes tertutup; metadata ini menunjukkan gerbang Pretes yang masih terbuka, bukan bukti ada peserta sedang mengerjakan saat itu.

Untuk menutup hanya gerbang sesi yang telah diidentifikasi, jalankan dari checkout aplikasi:

```bash
git pull --ff-only &&
php tools/program-separation-close-gates.php \
  --capture-dir="$HOME/lppai-private-backups/lppai-20261125-161732-97da6915" \
  --close-pretes=3,4,5
```

Ini operasi tulis terbatas: hanya is_active dan exam_open pada sesi Pretes 3,4,5 dibuat 0. Tidak memanggil engine/cleanup, tidak menutup paksa attempt, tidak mengumpulkan tryout, tidak mengubah token/paket/jadwal/nilai, dan tidak mengubah sesi Tutorial RPL. Tool memverifikasi checksum SQL/snapshot serta identitas database dari capture private sebelum menulis. Sesi yang tidak dipilih tetap utuh. ID tidak valid/hilang membatalkan operasi, bukan diabaikan.

Dalam transaksi InnoDB, tool mengunci baris sesi, menyimpan preimage flag dan fingerprint ke berkas private, lalu membandingkan seluruh tabel database lain (termasuk akun, nilai dan jawaban) dan seluruh kolom sesi sesudah UPDATE. Perubahan tak diharapkan pada nilai/data lain atau field sesi menyebabkan rollback. Snapshot dalam transaksi hanya membuktikan operasi ini tidak mengubah data terlindungi; ini bukan jaminan tidak ada koneksi lain yang menulis sesudah commit. Side-effect trigger nilai pada fixture diuji dan menyebabkan rollback seluruh perubahan termasuk flag.

Keluaran `selected_pretes_gates_closed_no_migration` memuat status sesi terkini, audit baru dari SELECT-only, jumlah riwayat tutorial berdasarkan program akun saat ini, serta lokasi log gate-close-*.json yang menyimpan preimage dan hasil. Snapshot/backup lama tidak ditimpa. Jika log akhir gagal sesudah commit, tool menyatakan perubahan gerbang sudah committed dan meminta pemeriksaan ulang, bukan mengklaim rollback. Jalankan ulang aman untuk sesi yang sudah tertutup.

Kirim ringkasan keluaran saja; tidak perlu mengirim backup SQL, token, nilai individual atau log private. Tiga tryout pending dan 54 riwayat Tutorial Reguler tetap dipertahankan untuk review, bukan dihapus/dipindah supaya audit bersih. Backup belum dilatih restore dan pemisahan program belum diaktifkan; penutupan gerbang ini merupakan prasyarat, bukan pelaksanaan migrasi data.
