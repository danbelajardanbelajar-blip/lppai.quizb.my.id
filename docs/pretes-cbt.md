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

## Admin

Menu **Bank Soal & Hasil CBT**, atau `pretes-ujian.php?cbt_bank=1`, hanya untuk admin.
Admin dapat menambah/mengedit pertanyaan, pilihan, kategori, kunci, dan status aktif.
Minimal 25 soal aktif diperlukan untuk memulai paket baru. Bank contoh di source
Git tersedia publik; buat/ubah bank resmi melalui admin dan tinjau materi serta
kuncinya sebelum digunakan untuk penilaian resmi. Daftar admin menampilkan 100
upaya terbaru beserta nilai, jumlah benar, dan waktu mulai WIB.

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

## Pemeriksaan

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
