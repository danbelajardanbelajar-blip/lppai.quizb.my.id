# Aturan pendaftaran Tutorial RPL

Status: tahap 3 — periode, dosen/hari/kuota, dan pendaftaran mahasiswa melalui jalur mandiri/admin tersedia.

## Ketentuan pengguna

- Sasaran: akun mahasiswa dengan jenis program RPL.
- Mahasiswa yang belum mengikuti pretes dan yang belum lulus pretes sama-sama boleh mendaftar.
- Peserta yang sudah lulus pretes resmi dengan nilai minimal 80 dibebaskan dari tutorial.
- Jadwal awal yang diminta: Senin, 23 November 2026 pukul 00.00.00 WIB sampai Jumat, 27 November 2026 pukul 23.59.59 WIB. Nilai ini menjadi isian awal formulir; jadwal efektif mengikuti periode aktif yang disimpan admin.
- Jalur mandiri: mahasiswa memilih hari yang tersedia, lalu mendaftarkan akunnya sendiri.
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
- Admin membuat dan mengedit nama periode, gelombang, waktu buka/tutup sampai detik dalam WIB, batas total pendaftar, dan status aktif. Batas total 0 berarti tanpa batas tambahan; kapasitas tetap dibatasi kuota dosen per hari. Kuota kelas terpisah.
- Formulir memiliki baris **Tambah dosen dan hari**: pilih akun dosen yang sudah ada, pilih Senin–Minggu, lalu isi kuota minimal 1 untuk dosen pada hari tersebut (awal 30). Satu dosen boleh memiliki beberapa hari; setiap hari memiliki kuota sendiri. Dosen A Senin 30 + dosen B Senin 20 menghasilkan kapasitas Senin 50. Batas total periode, jika diisi, tetap berlaku.
- Pola ini mengikuti skrip periode lama `admin/pretes-dosen.php` dan `admin/tutorial-kelas.php`: beberapa penguji/tutor per hari. RPL menyimpan ID dosen dan kuota per baris di `tutorial_rpl_period_tutors`, bukan teks nama dipisah delimiter. Akun baru tetap dibuat melalui pengelolaan pengguna.
- Periode aktif wajib memiliki minimal satu dosen/hari valid. Dosen/hari duplikat ditolak. Penyimpanan periode dan seluruh baris dosen satu transaksi dengan versi edit yang sama. Baris yang sudah dipakai tidak boleh dihapus/diganti; kuotanya tidak boleh dikurangi di bawah jumlah tempat terisi. Akun yang bukan dosen tidak menambah kapasitas. Periode dengan akun dosen yang berubah masih dapat dinonaktifkan tanpa menghapus riwayat.
- Tabel khusus `tutorial_rpl_registration_periods` dibuat secara idempoten ketika admin membuka halaman periode, memakai koneksi database aplikasi. Tidak menyalin atau mengubah periode tutorial reguler/pretes.
- Tidak ada periode yang otomatis dibuat atau diaktifkan. Setelah deployment, buka halaman admin, periksa isian awal, centang aktif bila sesuai, lalu simpan. Tanpa periode aktif, halaman mahasiswa tidak menampilkan pendaftaran terbuka. Kegagalan database juga tidak membuka pendaftaran.
- Hanya satu periode aktif (constraint UNIQUE pada slot aktif nullable), termasuk ketika dua admin menyimpan bersamaan. Nonaktifkan periode lama sebelum mengaktifkan periode lain; riwayat disimpan dan tidak ada aksi hapus.
- Semua POST wajib admin dan CSRF valid. Edit memakai nomor versi agar formulir lama tidak menimpa perubahan yang lebih baru. Waktu simpan/ubah dan ID admin dicatat dalam tabel. Waktu database di tabel ini berupa waktu lokal WIB, bukan UTC.
- Halaman informasi mahasiswa `/tutorial-rpl.php` dan kartu aturan admin memakai periode aktif dari database. Pendaftaran khusus RPL memakai `/tutorial-rpl-daftar.php`, terpisah dari formulir tutorial umum.
- `tutorialRplPeriodPolicy()` menggabungkan periode database dengan aturan tetap nilai 80 dan kedua jalur. Handler tahap 3 wajib memuat periode terbaru melalui helper ini, bukan memakai nilai tanggal bawaan fungsi kebijakan tanpa konteks periode. `tutorialRplRegistrationDecision()` menerima konteks periode sebagai argumen keenam.

## Pendaftaran peserta (tahap 3)

- Mandiri: `/tutorial-rpl-daftar.php`, menu **Daftar Tutorial RPL** pada sidebar mahasiswa RPL. Pilih hari, lalu klik **Daftar Tutorial RPL**. Hari penuh tidak bisa dipilih. Menu ini tidak tergantung pernah daftar pretes atau menu tutorial reguler. Mahasiswa hanya dapat mendaftarkan akun sendiri; ID target dari POST tidak dipakai.
- Admin: **Tutorial RPL Online → Pendaftaran Peserta RPL**, URL `/admin/tutorial-rpl-pendaftaran.php`. Pilih periode, cari nama/NIM (minimal dua karakter, maksimal 20 hasil), pilih hari untuk mahasiswa, lalu tekan **Daftarkan**. Riwayat menampilkan 50 pendaftaran terbaru pada periode terpilih, termasuk pilihan hari, jalur, waktu, dan pelaku. Periode lama dapat dibuka untuk membaca riwayat, tetapi pendaftaran baru tetap harus periode aktif dan dalam jadwal.
- Tabel `tutorial_rpl_registrations` dibuat idempoten saat halaman pendaftaran dibuka. Menyimpan period_id, user_id, channel, registered_by, registered_at dalam WIB, serta snapshot bukti kelayakan. Constraint UNIQUE(period_id,user_id) dan pemeriksaan transaksi mencegah duplikasi antarjalur; pengiriman ulang mengembalikan pendaftaran lama tanpa mengubah jalur/pelaku awal.
- Role/program pelaku dan mahasiswa diambil ulang dari database saat simpan. Kedua POST memakai CSRF. Bukti skor/status dari POST diabaikan. Kelulusan, jadwal dan kuota dibaca ulang di transaksi, bukan hanya tampilan GET.
- Kuota dipakai bersama kedua jalur. Periode dikunci dengan SELECT FOR UPDATE pada MySQL dan BEGIN IMMEDIATE pada SQLite sebelum menghitung dan menyisipkan. Batas total 0 tanpa batas tambahan. Edit periode menolak batas total baru di bawah jumlah pendaftar yang sudah ada.
- Setelah memeriksa hari, server mereservasi satu tempat pada dosen di hari tersebut yang masih muat (jumlah terisi paling sedikit, lalu ID baris). Mahasiswa tidak memilih dosen. Reservasi ini menjaga kuota dosen; belum membuat kelas atau keanggotaan. Hari invalid/tidak dikonfigurasi/penuh ditolak server walaupun POST dipalsukan atau kuota berubah setelah halaman dibuka. ID jadwal dosen dari POST tidak dipercaya.
- Migrasi menambahkan `hari_pilihan`, `schedule_id`, `day_selected_by`, dan `day_selected_at` secara idempoten tanpa mengisi hari secara acak untuk data lama. Pendaftar lama yang belum memilih hari dapat memilih hari saat periode aktif dan dibuka, tanpa baris pendaftaran baru atau perubahan audit pendaftaran awal. Waktu/pelaku pemilihan hari dicatat terpisah. Kirim ulang pendaftaran yang sudah memiliki hari tidak memindahkan hari atau memakai tempat tambahan.
- Bukti resmi dibaca tanpa helper yang menelan error: hasil `pretes_results` terbaru berstatus lulus dihormati termasuk status historis tanpa nilai numerik (tidak mengarang nilai 80). Jika status lulus memiliki angka di bawah 80 atau nilai tidak valid, pendaftaran ditahan untuk diperiksa admin.
- Bila belum ada status lulus historis: gunakan CBT resmi yang sudah dikumpulkan (registration_id positif dan pemilik sesuai), atau nilai asesmen `ujian_tulis`. Pada mode CBT saja, gunakan hasil resmi terakhir yang sudah dikumpulkan, sehingga pendaftaran pretes baru yang masih pending tidak menghapus kelulusan sebelumnya. Pada mode komponen lengkap, nilai mengikuti pendaftaran pretes terbaru. Tryout dan CBT belum dikumpulkan tidak membebaskan tutorial. Mode `cbt_only` mengikuti batas 80; mode `cbt_tasks` mengikuti seluruh komponen aktif RPL minimal 80. Pengaturan mode/komponen dibaca langsung tanpa fallback saat query gagal; tabel konfigurasi yang belum pernah dibuat memakai nilai bawaan proyek. JSON/nilai tidak valid dan kegagalan query menghentikan simpan, tidak diubah menjadi "belum pretes".
- Pendaftaran belum otomatis menambah `tutorial_rpl_members` atau menempatkan mahasiswa ke kelas. Tampilan menegaskan pembagian kelas terpisah. Mahasiswa melihat riwayat 10 pendaftaran terakhir miliknya.

## Pekerjaan berikutnya dan deployment

Deploy kode, buka/edit halaman periode untuk menyiapkan tabel, tambahkan dosen beserta hari dan kuotanya, periksa jadwal/batas total lalu aktifkan periode yang sesuai. Periode lama tanpa konfigurasi dosen tidak menyediakan pilihan hari sampai admin melengkapinya. Tidak ada periode yang dibuka otomatis oleh paket kode ini. Lakukan uji dengan database MySQL/akun hosting sebelum pengumuman; verifikasi lokal memakai SQLite dan fixture autentikasi.

Tahap 4 menghubungkan menu/pengumuman dan plotting hanya peserta RPL terdaftar.
Kuota kelas dan keanggotaan dilengkapi pada tahap plotting; paket pendaftaran ini tidak mengubah kelas atau anggota yang sudah ada. Pencatatan pelaku pendaftaran sudah tersedia pada tahap 3.
