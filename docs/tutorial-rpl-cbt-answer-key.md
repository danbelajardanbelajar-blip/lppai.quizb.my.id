# Pemetaan kunci CBT Tutorial RPL

Bug ditemukan pada dua jalur di `includes/tutorial-rpl-cbt-engine.php`:

- Pembentukan attempt awal mengasumsikan `options_json` selalu objek A–E. Impor dan sebagian seeding lama menyimpan daftar indeks 0–4. Pembacaan opsi dengan huruf pada daftar numerik menghasilkan pilihan kosong.
- Saat interupsi/pertukaran soal cadangan, pengacak membaca `$options[$correctKey]`. Untuk daftar numerik, kunci huruf tidak ditemukan; kode lama kemudian menetapkan kunci ke A. Opsi tetap diacak, sehingga A dapat menunjuk isi jawaban yang salah. Ini dapat memengaruhi skor, bukan hanya tampilan Review.
- Pengacakan lama juga mengaitkan jawaban/kunci berdasarkan kesamaan teks. Dua opsi yang kebetulan memiliki teks sama bisa dianggap sebagai opsi yang sama.

Perbaikan memakai normalisasi daftar 0–4, objek A–E/huruf kecil, dan daftar objek `text` ke peta A–E. Pengacakan memindahkan kunci serta jawaban berdasarkan identitas opsi asal. Kunci hilang/kosong atau format tidak valid ditolak; tidak ada fallback kunci A. Opsi kosong yang tidak dipakai tidak diikutkan ke pengacakan. Simpan/impor bank memvalidasi kunci dan menyimpan format huruf yang konsisten; API lama `options` dan `options_json` tetap didukung.

Pengujian memeriksa **teks jawaban benar**, bukan sekadar menjawab huruf kunci dari database. Uji meliputi attempt awal dari bank numerik, pilihan yang telah dijawab, penggantian soal cadangan ketika interupsi, resume, dan skor akhir berdasarkan jawaban yang dipilih menurut isi teks. Bank tidak valid membatalkan pembuatan attempt tanpa baris parsial.

## Batas untuk data yang sudah terlanjur tersimpan

Patch ini tidak memperbaiki snapshot atau menghitung ulang nilai lama secara otomatis. Screenshot attempt 7 menunjukkan gejala yang sesuai, tetapi kondisi baris hosting belum diperiksa. Jangan menganggap semua jawaban A salah atau mengganti semua kunci menjadi E.

Audit harus membaca `tutorial_rpl_cbt_items` bersama bank soal asal. Bandingkan teks pertanyaan, himpunan opsi, dan **teks opsi yang ditunjuk kunci** pada masing-masing urutan; huruf kunci yang berbeda setelah pengacakan adalah normal. Jika bank telah diedit, dihapus, atau teks opsinya duplikat, pemetaan tidak boleh ditebak. Gunakan sumber bank/backup yang sesuai saat attempt dibuat. Perubahan snapshot dan nilai peserta perlu paket perbaikan terpisah yang dapat ditinjau, dengan daftar peserta/soal terdampak serta jejak audit.
