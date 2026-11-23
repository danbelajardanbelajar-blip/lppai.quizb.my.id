# Aturan pendaftaran Tutorial RPL

Status: tahap 1 — kebijakan disepakati dan fungsi keputusan bersama tersedia.

## Ketentuan pengguna

- Sasaran: akun mahasiswa dengan jenis program RPL.
- Mahasiswa yang belum mengikuti pretes dan yang belum lulus pretes sama-sama boleh mendaftar.
- Peserta yang sudah lulus pretes resmi dengan nilai minimal 80 dibebaskan dari tutorial.
- Buka: Senin, 23 November 2026 pukul 00.00.00 WIB.
- Tutup: Jumat, 27 November 2026 pukul 23.59.59 WIB. Zona waktu Asia/Jakarta.
- Jalur mandiri: mahasiswa mendaftarkan akunnya sendiri.
- Jalur admin: admin mendaftarkan mahasiswa RPL yang dipilih. Syarat dan batas waktu sama; belum ada pengecualian pendaftaran di luar jadwal.
- Periode pretes yang masih aktif bukan alasan untuk menolak mahasiswa yang belum dinyatakan lulus.
- Nilai tryout dan pengerjaan yang belum dikumpulkan bukan bukti kelulusan pretes resmi.

## Sumber aturan

`includes/tutorial-rpl-registration-policy.php` menyediakan konfigurasi periode,
fungsi status waktu, dan fungsi keputusan kedua jalur. Keputusan menggunakan data
akun serta bukti hasil pretes resmi yang dibaca server. Nilai tidak boleh dipercaya
dari POST mahasiswa. Hasil resmi kosong/tidak valid harus ditinjau; kegagalan query
database tidak boleh dianggap sebagai mahasiswa belum pretes.

## Batas tahap 1 dan pekerjaan berikutnya

Tahap ini belum membuat tabel pendaftaran, formulir mandiri/admin baru, atau
menghubungkan hasil daftar ke `tutorial_rpl_members`. Halaman informasi RPL
dan halaman admin menampilkan aturan yang sama. Tanggal informasi memakai
fungsi waktu bersama, tetapi formulir tutorial umum yang sudah ada tetap memiliki
aturan lama dan belum menjadi pintu pendaftaran RPL yang selesai.

Tahap 2 menghubungkan periode, kuota, serta status buka/tutup ke pengaturan admin.
Tahap 3 membuat kedua formulir dan penyimpanan pendaftaran dengan validasi ini,
CSRF, transaksi, pemeriksaan data terbaru, serta pencegahan duplikasi. Bukti
kelulusan harus diambil dari sumber resmi aplikasi, termasuk status kelulusan
historis; konflik status dan nilai perlu ditangani sebelum menerima pendaftaran.
Tahap 4 menghubungkan menu/pengumuman dan plotting hanya peserta RPL terdaftar.
Kuota kelas, keanggotaan, dan pencatatan pelaku pendaftaran dilengkapi pada tahap
penyimpanan; aturan ini tidak mengubah kelas atau data peserta yang sudah ada.
