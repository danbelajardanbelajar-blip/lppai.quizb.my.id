# Langkah terminal hosting sebelum migrasi pemisahan program

Pengguna sudah menyatakan sesi ujian ditutup dan dinonaktifkan serta mengizinkan peralihan dilanjutkan. Akses SSH hosting menolak autentikasi dari terminal agen; pengguna menjelaskan shared hosting membatasi akses dari IP lain. Karena itu backup/audit hosting harus dijalankan pengguna dari terminal cPanel. Jangan membuka akses database/SSH ke publik atau mengirim password demi melewati batas ini.

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
php tools/program-separation-capture.php --output-dir="$HOME/lppai-private-backups"
```

Tidak ada flag apply/migrate pada perintah ini. Output backup ditolak jika berada di dalam folder aplikasi atau direktori `public_html`, `htdocs`, `www`; parent output harus 0700 pada Linux. Folder per-run dibuat 0700 dan berkas 0600. Pastikan kapasitas disk tersedia untuk backup penuh database. CLI membutuhkan PHP 8.1+, PDO MySQL, proc_open dan mysqldump yang tersedia melalui PATH hosting; script berhenti bila fasilitas tidak tersedia. Tabel non-InnoDB menyebabkan penolakan agar konsistensi backup tidak diasumsikan.

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

Backup SQL mencakup seluruh database aplikasi, termasuk akun dan data autentikasi; file opsi mysqldump sementara berisi kredensial, diberi 0600 dan dihapus setelah proses selesai/dalam handler kegagalan. Password tidak dimasukkan ke argumen command line atau dicetak. Jangan membagikan direktori ini melalui File Manager public link.

Sidik data mengikutkan semua kolom setiap tabel akademik dan menghitung duplikasi, null, binary serta jawaban/nilai persis; urutan fisik baris tidak memengaruhi hash. Snapshot minimum tidak membawa kolom password, token, nilai individual atau jawaban. Perubahan data akademik selama capture menyebabkan gagal, bukan laporan aman palsu. Sidik akademik bukan checksum semua tabel login/log sistem; backup SQL tetap mencakup seluruh database.

## Jika gagal

- `CAPTURE INCOMPLETE` berarti backup/capture belum dapat dianggap lengkap. Tidak ada migrasi yang dijalankan. Simpan berkas parsial tetap private; jangan membagikan dump atau konfigurasi database.
- Jika `mysqldump` tidak tersedia/privilege routine-event kurang, sampaikan pesan error ringkas; perintah alternatif harus disesuaikan dengan fasilitas hosting. Jangan membuat script backup publik melalui URL.
- Jika ditemukan perubahan data saat capture, jangan menutup paksa atau menghapus attempt untuk memperbaiki checksum. Tinjau aktivitas terlebih dahulu lalu ulangi capture.
- Jika ada attempt belum submit, audit mempertahankannya. Sesi yang sudah dinonaktifkan tidak membuktikan semua attempt telah selesai. Kelanjutan migrasi harus menjaga atau meninjau attempt tersebut, tanpa mengubah nilainya.

## Batas lanjut migrasi

Setelah hasil tersedia: audit konflik program dan periode campuran berdasarkan bukti, siapkan manifest ID, latih migrasi pada salinan dan restore backup pada database uji terisolasi. Bandingkan nilai/jawaban sebelum-sesudah. Baru kemudian perubahan skema/repository/halaman per program dapat diaktifkan dengan rencana rollback yang telah diuji. Jangan memulihkan database penuh di atas transaksi produksi baru atau menganggap backup SQL yang belum dilatih restore sebagai backup terverifikasi.

Tidak ada migrasi/aktivasi pemisahan produksi dalam paket checkpoint ini. Pekerjaan eksternal yang diperlukan diserahkan dalam bentuk perintah terminal karena batas akses shared hosting; belum ada klaim integritas database hosting sebelum hasil perintah diterima.
