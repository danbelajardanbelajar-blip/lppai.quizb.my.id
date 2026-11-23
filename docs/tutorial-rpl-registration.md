# Aturan pendaftaran Tutorial RPL

Status: tahap 2 — kebijakan bersama dan pengelolaan periode admin tersedia.

## Ketentuan pengguna

- Sasaran: akun mahasiswa dengan jenis program RPL.
- Mahasiswa yang belum mengikuti pretes dan yang belum lulus pretes sama-sama boleh mendaftar.
- Peserta yang sudah lulus pretes resmi dengan nilai minimal 80 dibebaskan dari tutorial.
- Jadwal awal yang diminta: Senin, 23 November 2026 pukul 00.00.00 WIB sampai Jumat, 27 November 2026 pukul 23.59.59 WIB. Nilai ini menjadi isian awal formulir; jadwal efektif mengikuti periode aktif yang disimpan admin.
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

## Pengelolaan periode (tahap 2)

- Menu admin: **Tutorial RPL Online → Periode Pendaftaran RPL**, URL `/admin/tutorial-rpl-periode.php`.
- Admin membuat dan mengedit nama periode, gelombang, waktu buka/tutup sampai detik dalam WIB, kuota pendaftar, dan status aktif. Kuota 0 berarti tanpa batas; kuota ini terpisah dari kuota kelas.
- Tabel khusus `tutorial_rpl_registration_periods` dibuat secara idempoten ketika admin membuka halaman periode, memakai koneksi database aplikasi. Tidak menyalin atau mengubah periode tutorial reguler/pretes.
- Tidak ada periode yang otomatis dibuat atau diaktifkan. Setelah deployment, buka halaman admin, periksa isian awal, centang aktif bila sesuai, lalu simpan. Tanpa periode aktif, halaman mahasiswa tidak menampilkan pendaftaran terbuka. Kegagalan database juga tidak membuka pendaftaran.
- Hanya satu periode aktif (constraint UNIQUE pada slot aktif nullable), termasuk ketika dua admin menyimpan bersamaan. Nonaktifkan periode lama sebelum mengaktifkan periode lain; riwayat disimpan dan tidak ada aksi hapus.
- Semua POST wajib admin dan CSRF valid. Edit memakai nomor versi agar formulir lama tidak menimpa perubahan yang lebih baru. Waktu simpan/ubah dan ID admin dicatat dalam tabel. Waktu database di tabel ini berupa waktu lokal WIB, bukan UTC.
- Halaman informasi mahasiswa `/tutorial-rpl.php` dan kartu aturan admin memakai periode aktif dari database. Tautan ke formulir tutorial umum dihapus dari halaman RPL karena formulir itu belum melayani pendaftaran khusus RPL.
- `tutorialRplPeriodPolicy()` menggabungkan periode database dengan aturan tetap nilai 80 dan kedua jalur. Handler tahap 3 wajib memuat periode terbaru melalui helper ini, bukan memakai nilai tanggal bawaan fungsi kebijakan tanpa konteks periode. `tutorialRplRegistrationDecision()` menerima konteks periode sebagai argumen keenam.

## Batas tahap 2 dan pekerjaan berikutnya

Tahap ini belum membuat tabel pendaftaran mahasiswa, formulir mandiri/admin
untuk mendaftarkan peserta, atau menghubungkan hasil daftar ke
`tutorial_rpl_members`. Kuota periode baru disimpan; penegakannya saat menerima
pendaftaran harus dibuat pada tahap 3 dengan transaksi dan pemeriksaan kuota.
Aktivasi periode belum berarti formulir pendaftaran sudah tersedia.

Tahap 3 membuat kedua formulir dan penyimpanan pendaftaran dengan validasi ini,
CSRF, transaksi, pemeriksaan data terbaru, serta pencegahan duplikasi. Bukti
kelulusan harus diambil dari sumber resmi aplikasi, termasuk status kelulusan
historis; konflik status dan nilai perlu ditangani sebelum menerima pendaftaran.
Tahap 4 menghubungkan menu/pengumuman dan plotting hanya peserta RPL terdaftar.
Kuota kelas, keanggotaan, dan pencatatan pelaku pendaftaran dilengkapi pada tahap
penyimpanan; aturan ini tidak mengubah kelas atau data peserta yang sudah ada.
