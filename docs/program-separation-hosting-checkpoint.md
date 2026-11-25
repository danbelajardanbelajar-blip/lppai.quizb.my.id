# Langkah terminal hosting sebelum migrasi pemisahan program

## Verifikasi hasil restore pada database uji

Pengguna membuat database kosong `quic1934_uji` dan user terpisah `quic1934_zenhkm`, memverifikasi jumlah tabel 0, lalu melaporkan impor SQL selesai setelah checksum SQL cocok. Impor selesai belum membuktikan nilai/jawaban hasil restore sesuai baseline. Jangan mengimpor ulang di atas database uji yang kini berisi data.

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

Password melewati stdin pipe dari builtin shell, tidak melalui argumen proses, Git, environment atau chat. Variabel hanya berada dalam subshell. Verifier menolak nama database/user produksi dan hanya membuka koneksi ke database uji di host yang dikonfigurasi. Tidak mengubah config aplikasi dan tidak membutuhkan proc_open. Script memverifikasi identitas sumber capture, checksum SQL dan baseline, marker dump, lalu membandingkan multiset seluruh nilai kolom dan jumlah baris pada semua tabel akademik dalam transaksi read-only. Report baru dibuat private 0600 di direktori capture; metadata capture lama tidak ditimpa.

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
