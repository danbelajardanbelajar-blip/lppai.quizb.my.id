# CBT pretes internal

Halaman `pretes-ujian.php` kini memuat CBT internal setelah verifikasi wajah.
Bagian kredensial dan tautan E-Ujian di halaman tersebut telah dihapus.

## Paket dan hasil

- Bank awal: 40 soal contoh dasar ubudiyah, dengan lima pilihan A–E dan satu kunci.
- Per ujian: 25 soal unik dipilih acak; urutan soal dan pilihan diacak dengan CSPRNG.
- Durasi awal: 30 menit, dihitung server. Muat ulang tidak mengganti paket/deadline.
- Satu upaya per peserta dan ID pendaftaran; memulai ulang melanjutkan upaya yang sama.
- Soal, pilihan, dan kunci disalin ke snapshot ketika mulai. Edit bank tidak mengubah paket berjalan.
- Jawaban dan tanda tinjau disimpan ke server, dengan antrean request berurutan di browser.
- Saat koneksi putus, perubahan belum tersimpan ditahan di memori halaman dan dapat dicoba lagi.
  Menutup halaman dapat menghilangkan perubahan yang belum dikonfirmasi; UI memberi peringatan.
- Nilai = jumlah benar / 25 × 100; salah dan kosong bernilai 0. Penilaian dilakukan server.
- Pengumpulan berulang mengembalikan hasil yang sama. Jawaban tidak bisa diubah setelah dikumpulkan.
- Waktu habis difinalisasi pada request selanjutnya atau saat admin membuka hasil. Jawaban lewat deadline ditolak.
- Kamera dan timer pengawasan dihentikan setelah hasil pengumpulan dikonfirmasi server.
- Pemeriksaan wajah ke VPS dilakukan setiap 30 detik selama paket aktif. Satu request berjalan pada satu waktu; pemeriksaan berikutnya dijadwalkan 30 detik setelah request selesai.
- Timer memakai waktu nyata; saat kembali ke tab yang sempat ditunda browser, satu pemeriksaan yang jatuh tempo dicoba kembali. Browser/OS HP tetap dapat menunda kamera dan request di latar belakang.
- Gangguan jaringan/mesin ditampilkan sebagai pemeriksaan tertunda, bukan hasil pelanggaran. Hasil wajah tidak sesuai memberi peringatan dan log, tanpa penguncian atau pengurangan nilai otomatis.
- Metadata dicatat per pemeriksaan. Foto cocok disimpan maksimal sekali per lima menit (termasuk foto check-in); foto tidak sesuai tetap disimpan untuk audit. Ini bukan pelacakan tatapan mata.
- Hasil CBT disimpan terpisah; nilai praktik dan nilai impor lama tidak ditimpa.

## Admin & Manajemen Paket Soal

Menu **Bank Soal & Hasil CBT**, atau `pretes-ujian.php?cbt_bank=1`, hanya untuk admin.
Admin kini dapat mengelola **Paket Soal** (`tab=packages`), menyusun butir pertanyaan per paket (`tab=form`), mengimpor dari Excel ke paket target (`tab=import`), dan melihat hasil ujian (`tab=results`).

### Arsitektur Paket Soal & Prasyarat Penginputan
- **Wadah Paket Soal:** Sebelum butir soal dimasukkan, sistem mewajibkan minimal 1 Paket Soal telah dibuat. Tab Tambah Soal dan Impor Excel akan memblokir penginputan jika belum ada paket yang terdaftar.
- **Parameter Paket:** Setiap paket memiliki Nama, Kode Unik, Deskripsi, Durasi Ujian (menit), dan Target Soal Acak per Sesi Peserta.
- **Paket Aktif:** Satu paket dapat ditetapkan sebagai paket aktif. Saat peserta menekan mulai ujian CBT di portal mahasiswa, server otomatis mengambil soal aktif dari paket yang sedang aktif dan menggunakan durasi serta kuota soal paket tersebut.
- **Filter & Alokasi:** Pada tab Bank Soal, butir pertanyaan dapat difilter berdasarkan paket soal. Menghapus paket soal akan menghapus seluruh butir soal yang dialokasikan di dalamnya secara berantai.

## Pengatur Sesi Ujian (Exam Sessions)

Tab **Sesi Ujian** (`tab=sessions`) memungkinkan admin mengontrol secara granular parameter pelaksanaan ujian pretes:
1. **Modal In-Place (Tanpa Pindah Halaman):** Seluruh aksi tambah/edit paket soal, sesi ujian, dan input cepat butir soal dilakukan menggunakan modal popup (`modalSessionForm`, `modalPackageForm`, `modalQuickQuestion`) tanpa me-reload atau berpindah halaman.
2. **Pilihan Paket Soal:** Admin dapat memilih paket soal mana saja yang diaktifkan dalam sesi tersebut.
3. **Kadar / Kuota per Kategori:** Admin menentukan jumlah kuota pasti soal untuk setiap kategori materi (misal: Fiqih 10, Al-Qur'an 8, Ubudiyah 7). Mesin CBT menarik butir soal acak sesuai kuota masing-masing kategori secara proporsional.
4. **Kewajiban Kamera:**
   - `wajib`: Mewajibkan verifikasi wajah awal (Skenario A) dan pengawasan proctoring AI berkala (Skenario B).
   - `tidak_aktif`: Membebaskan peserta dari kamera; peserta langsung masuk ruang ujian CBT tanpa webcam.
   - `opsional`: Kamera dianjurkan namun tidak memblokir ujian jika tidak tersedia webcam.
5. **Durasi Ujian:** Durasi ujian dalam menit (`duration_minutes`) yang dihitung ketat di server.
6. **Metode Penilaian:**
   - `standar`: Nilai proporsional skala 100 (`benar / total * 100`).
   - `penalti`: Sistem penalti ala UTBK (+4 benar, -1 salah, 0 kosong) lalu diskalakan ke 0–100.
7. **Jadwal Window Ujian:**
   - Waktu Mulai (`start_time`): Ujian belum bisa dimulai sebelum waktu ini tiba (menampilkan countdown/notifikasi waktu buka).
   - Waktu Selesai (`end_time`): Batas akhir di mana peserta baru tidak dapat lagi memulai ujian setelah waktu ini lewat.
8. **Token Ujian:** Admin dapat mengaktifkan kewajiban token (`token_required`) dan men-generate token acak 6 digit. Peserta wajib menginput token yang valid sebelum CBT dimulai.

### Excel .xlsx

Tab Bank Soal menyediakan pencarian, filter kategori/status, pagination, tautan
unduh template, dan ekspor seluruh bank. Tab Impor Excel mendukung pemilihan file
atau drag-and-drop. Tab Hasil Ujian menyediakan ekspor hasil peserta.

Template: `pretes-ujian.php?cbt_bank=1&download=template` (login admin diperlukan).
Berkas asli tersedia di `downloads/template-bank-soal-cbt.xlsx`, berisi lembar
Bank Soal dan Panduan. Gunakan judul kolom:

`Kode Soal | Kategori | Pertanyaan | Pilihan A | Pilihan B | Pilihan C | Pilihan D | Pilihan E | Kunci | Status`

- Ganti baris contoh. Kode boleh kosong untuk soal baru; kode dari ekspor dipertahankan saat memperbarui.
- Kunci satu huruf A–E. Status Aktif/Nonaktif; kategori, pertanyaan, dan lima pilihan berbeda wajib diisi.
- Maksimal 1.000 soal/file dan 5 MB; ZIP, XML, ukuran hasil ekstraksi, dan tipe sel diperiksa.
- Formula, macro, workbook terenkripsi, entity XML, dan file selain .xlsx ditolak.
- Semua baris divalidasi sebelum transaksi. Satu baris salah membatalkan seluruh impor dengan nomor baris kesalahan.
- Pembaruan soal lama hanya jika opsi Perbarui dipilih dan Kode Soal cocok. Tanpa opsi, soal lama dilewati.
- Pertanyaan duplikat yang sudah ada dilewati; kode/pertanyaan ganda dalam file ditolak.
- Ekspor bank dapat diimpor kembali. Semua teks ditulis sebagai sel teks, bukan formula Excel.
- Ekspor hasil mempertahankan NIM sebagai teks, nilai sebagai angka, dan waktu sebagai tanggal Excel dalam WIB.
- Ekspor maksimal 10.000 baris atau 32 MB XML; ZIP/SimpleXML diperlukan pada PHP, tanpa dependensi Composer baru.
- Impor soal tidak mengubah snapshot paket berjalan atau nilai ujian sebelumnya.

```sh
php tests/pretes-cbt-xlsx-test.php
python tests/pretes-cbt-xlsx-http-test.py
```

## Instalasi

Tarik kode terbaru ke hosting. Tabel `pretes_cbt_questions`, `pretes_cbt_attempts`,
dan `pretes_cbt_items` dibuat otomatis saat pertama kali diakses. Seed bank
dilakukan dalam transaksi hanya ketika tabel pertanyaan masih kosong. Tidak ada
perubahan skema/data pada tabel nilai lama. Database MySQL memerlukan hak CREATE
TABLE untuk instalasi pertama dan menggunakan InnoDB untuk transaksi.

CBT/API/modul JS/bank admin dilayani melalui jalur halaman ujian yang sudah bisa
diakses (`cbt_api`, `cbt_asset`, `cbt_bank`) agar cocok dengan layout hosting yang
menempatkan source fisik di subfolder `/web`. Tidak diperlukan deploy ulang VPS
mesin wajah. Konfigurasi privat mesin wajah yang sudah disalin tetap digunakan.

Semua aksi API memerlukan login, CSRF, kepemilikan pendaftaran, dan sesi
verifikasi wajah versi baru yang belum berakhir. API tidak mengirim kunci ke
peserta dan tidak menerima skor/jam ujian dari browser sebagai dasar penilaian.
Pengawasan kamera bersifat pemeriksaan berkala, bukan jaminan antikecurangan.
Check-in wajah mengambil tiga sampel per posisi (sembilan foto total) selama
hitung mundur. Mesin VPS menerima protokol lama tiga foto dan protokol baru
sembilan foto; pasang versi layanan yang mendukung `pose-burst-v2` sebelum menarik
versi halaman ini ke hosting. Pesan gagal menjelaskan langkah gerakan yang belum
terbaca. Ambang pengenalan identitas tetap sama.
Pratinjau video check-in dan pengawas memakai mode cermin. Petunjuk mengikuti
kanan/kiri tubuh peserta; panah kanan berarti kanan peserta pada pratinjau cermin.
Frame yang dikirim tetap piksel kamera asli, sehingga foto acuan/pencocokan tidak
ikut dibalik. Tantangan raw `left` ditampilkan sebagai kanan peserta, raw `right`
sebagai kiri peserta; model VPS dan nonce tetap memvalidasi arah raw semula.

## Pemeriksaan

### Popup pengawasan dan jeda ujian

Selama upaya CBT aktif, meninggalkan tab/halaman atau jendela kehilangan fokus
memicu dialog layar penuh. Kamera yang terputus serta hasil pemeriksaan berkala
`NO_FACE`/`MISMATCH` juga memicu dialog. Gangguan koneksi/mesin verifikasi bukan
pelanggaran kamera. Pemeriksaan wajah masih setiap 30 detik, bukan pelacakan
tatapan mata atau deteksi langsung setiap gerakan kepala.

Server mencatat jeda pada `pretes_cbt_interruptions` dan audit pada
`pretes_cbt_interruption_events`. Waktu dibekukan sejak server menerima jeda,
termasuk setelah refresh; melanjutkan menambah deadline sebesar durasi jeda server.
Dialog menutup soal, memblokir input dan tidak dapat ditutup dengan Escape.
Tombol Lanjutkan Ujian memerlukan halaman aktif dan pemeriksaan wajah baru untuk
sesi kamera wajib. Jika koneksi putus, dialog tetap menghalangi soal dan menyatakan
bahwa jeda server belum terkonfirmasi; durasi sebelum server menerima jeda belum
dapat dijamin berhenti. Browser yang dimatikan paksa tidak menjamin pengiriman
peristiwa meninggalkan halaman.

Hanya soal yang terbuka dikosongkan dan diacak ulang pilihan A–E-nya. Soal itu
ditukar dengan soal belum dijawab yang dipilih acak, sehingga posisi yang terbuka
menampilkan soal lain. Jika tidak ada soal kosong lain, soal tetap di posisinya
dengan pilihan baru. Jawaban dan tanda soal lain dipertahankan, termasuk antrean
autosave. Kunci jawaban mengikuti pilihan baru di server. Revisi urutan menolak
autosave lama dan event ID membuat pengiriman ulang tidak menghukum dua kali.

Uji khusus: `php tests/pretes-cbt-interruption-test.php` dan
`node tests/pretes-cbt-ui-test.cjs`, ditambah uji HTTP `python tests/pretes-cbt-api-test.py`.

```sh
php tests/pretes-cbt-test.php
python tests/pretes-cbt-api-test.py
node tests/pretes-cbt-ui-test.cjs
php tests/pretes-ujian-route-test.php
node tests/pretes-ujian-camera-test.cjs
```

Tes API menjalankan PHP built-in server dengan auth dan SQLite fixture terisolasi;
tidak memakai akun, sesi, atau database produksi. Uji bank, paket acak, snapshot,
nilai, deadline, pemilik upaya, penolakan CSRF/verifikasi wajah, autosave saat
offline, respons terlambat, dan pengumpulan idempoten tercakup.
