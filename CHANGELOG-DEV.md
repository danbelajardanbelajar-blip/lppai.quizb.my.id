# Log Perubahan Pengembangan (CHANGELOG-DEV)

Catatan sistematis setiap perubahan kode. Entri terbaru di atas.
Format: waktu (WIB, sampai detik) · file · baris · bagian · perubahan · alasan · verifikasi · commit.

---

## 2026-11-10 16:43:23 +0700 — Perbaiki HTTP 500 saat "Sinkronkan ke Hasil"

**File:** `admin/pretes-rekap-nilai.php`
**Bagian:** handler POST `action=sync_to_results` (blok "Handle Action POST: Sinkronisasi Nilai ke Hasil Pretes")

| Baris (setelah edit) | Perubahan |
|---|---|
| 94–127 | **Baru:** `try {` + deteksi kolom `pretes_results` (`SHOW COLUMNS` / `PRAGMA table_info`). Auto-tambah kolom `nilai` dan `keterangan` jika tidak ada; ubah `status_lulus` ENUM tanpa `'tidak_lulus'` menjadi `VARCHAR(30)`; jadikan `pretes_schedule_id` nullable bila NOT NULL tanpa default. Set flag `$hasKet`, `$hasUpd`. `$pdo->beginTransaction()`. |
| 153–156 | UPDATE dibuat dinamis: `keterangan` / `updated_at` hanya ikut jika kolomnya ada. |
| 158–164 | INSERT dibuat dinamis: dengan / tanpa kolom `keterangan`. |
| 168 | **Baru:** `$pdo->commit()`. |
| 172–177 | **Baru:** `catch (Throwable $e)` → rollback, `error_log('[pretes-rekap-nilai sync] …')`, tampilkan pesan "Sinkronisasi gagal: …" (bukan blank 500). |

**Alasan:** tombol "Sinkronkan ke Hasil" memicu HTTP 500 karena query tulis ke `pretes_results` gagal (skema server berbeda dari yang diasumsikan kode) dan tidak ada penanganan error.
**Dampak:** sinkronisasi mengubah struktur tabel `pretes_results` di server bila kolom/tipe tidak sesuai (lihat baris 94–127).
**Verifikasi:** `php -l` OK; `php tests/pretes-rekap-nilai-test.php` → PASS (151 skenario).
**Catatan terkait:** angka "Lulus Pretes" di `admin/dashboard.php:63` dibaca dari `pretes_results` (hasil sync terakhir, semua periode), sedangkan rekap menghitung live per periode — karena itu bisa berbeda.
