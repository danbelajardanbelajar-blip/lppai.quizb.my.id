# Alur kerja pengembangan

- Selesaikan setiap perubahan sebagai satu paket yang dapat ditinjau: implementasi, verifikasi, pencatatan, commit, pull, dan push.
- Sebelum setiap push, jalankan pemeriksaan sintaks file yang berubah, pengujian fungsi yang relevan, serta `git diff --check`. Perbaiki kegagalan yang disebabkan perubahan sebelum push; jelaskan keterbatasan verifikasi dengan jujur.
- Catat setiap paket perubahan di `CHANGELOG-DEV.md`, entri terbaru di atas: ID perubahan, waktu WIB sampai detik, file, nomor baris pada snapshot, bagian/fungsi, perubahan dan alasan, hasil verifikasi, serta parent commit. Gunakan ID entri di pesan commit agar commit akhirnya dapat ditelusuri.
- Gunakan waktu penyimpanan file yang sebenarnya untuk catatan edit; jangan mengarang waktu edit yang tidak tercatat. Jika hanya waktu penyimpanan terakhir yang tersedia, nyatakan secara eksplisit.
- Setelah verifikasi dan commit, lakukan `git pull --rebase` dari upstream branch aktif, kemudian push. Jika pull membawa perubahan atau konflik, periksa hasil gabungan dan ulangi verifikasi yang relevan sebelum push. Jangan force push atau menimpa pekerjaan orang lain.
- Pengguna sudah mengizinkan akses folder dan perintah yang diperlukan untuk pekerjaan ini. Lanjutkan tanpa meminta izin berulang selama masih dalam cakupan tugas dan mengikuti kebijakan akses lingkungan.
- Laporkan ringkas hasil verifikasi, hash commit, status pull/push, dan keterbatasan yang masih ada. Push Git tidak membuktikan deployment hosting berhasil; verifikasi deployment secara terpisah bila tersedia.
