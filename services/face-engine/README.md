# Mesin verifikasi wajah pretes

Server PHP membaca pasfoto dari `uploads/`, memvalidasi kepemilikan pendaftaran,
dan menerbitkan nonce tantangan yang berlaku 90 detik. Browser mengirim tiga foto
(lurus, menoleh mengikuti panah acak, lurus kembali). PHP mengonsumsi nonce secara
atomik, lalu mengirim foto ke layanan ini. Browser tidak mengirim keputusan cocok
atau embedding yang dipercaya server.

YuNet mendeteksi tepat satu wajah dan landmark. SFace menyelaraskan wajah dan
menghasilkan embedding; semua foto harus cocok dengan pasfoto serta satu sama
lain. Pemeriksaan kualitas menolak wajah kecil, foto buram, dan pencahayaan ekstrem.
Pemeriksaan gerakan menghitung perubahan posisi hidung relatif terhadap mata.
Ini tantangan gerakan dasar, **bukan PAD/liveness bersertifikasi**, dan belum
menjamin penolakan video replay, deepfake, atau kamera virtual. Untuk ujian dengan
risiko tinggi, gunakan pengawas atau penyedia PAD yang dievaluasi secara terpisah.

## VPS (Python 3.11+ atau Docker)

Jalankan dari folder layanan, bukan web root PHP:

```sh
python3 -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
python download_models.py
export FACE_ENGINE_TOKEN="$(python -c 'import secrets; print(secrets.token_hex(32))')"
# Simpan token dalam environment file yang hanya dapat dibaca pemilik layanan.
uvicorn app:app --host 127.0.0.1 --port 8000 --no-access-log --limit-concurrency 8
```

Atau build Docker dari folder ini dan jalankan image dengan environment file:

```sh
docker build -t lppai-face-engine .
docker run -d --name lppai-face-engine --restart unless-stopped \
  --env-file /etc/lppai-face-engine.env -p 127.0.0.1:8000:8000 lppai-face-engine
```

Layanan harus berada di belakang reverse proxy HTTPS. Atur batas body 14 MB,
timeout proxy 20 detik, dan batasi akses `/verify` ke IP hosting PHP jika tersedia.
Jangan mengekspos port 8000 ke internet. Terapkan pembatasan request pada reverse
proxy; layanan menolak request tanpa bearer token dan menolak antrean inferensi
dengan status 503 ketika mesin sibuk. Tambahkan worker hanya sesuai kapasitas RAM/CPU.

Contoh lokasi Nginx di dalam server block TLS yang sudah memiliki sertifikat:

```nginx
location / {
    client_max_body_size 14m;
    proxy_read_timeout 20s;
    proxy_pass http://127.0.0.1:8000;
    proxy_set_header Host $host;
}
```

`GET /health` mengembalikan `ready: true` hanya setelah kedua model berhasil dimuat.
Model diunduh dari commit OpenCV Zoo yang tetap dan SHA256-nya diperiksa. Lisensi
YuNet (MIT) dan SFace (Apache 2.0) tersedia di `licenses/`.

## Hosting PHP

Aktifkan ekstensi cURL dan set environment PHP (misalnya melalui konfigurasi pool
PHP-FPM/cPanel) dengan token yang sama seperti di VPS:

```text
PRETES_FACE_ENGINE_URL=https://face.example.com
PRETES_FACE_ENGINE_TOKEN=<token-rahasia-yang-sama-dengan-VPS>
```

Jangan menaruh token di JavaScript, Git, atau URL. `.env` tidak dimuat otomatis oleh
aplikasi ini. Pastikan environment benar-benar diteruskan ke PHP oleh pengelola hosting.
PHP menunggu maksimal 15 detik ke VPS, browser maksimal 20 detik per request.
Tanpa konfigurasi, saat model gagal, atau saat VPS tidak merespons, verifikasi
gagal dan akses ujian tetap terkunci. Tidak ada fallback pembandingan piksel/GD.
Tabel nonce dibuat pada penggunaan pertama; akun DB memerlukan hak CREATE TABLE
untuk instalasi pertama. Setelah itu tidak ada DDL per verifikasi.

Bypass tester dinonaktifkan secara default. Jangan set `PRETES_ALLOW_TEST_BYPASS=1`
di produksi. Sesi lolos dari mesin lama tidak diterima; peserta melakukan check-in
ulang. Sesi versi baru berlaku empat jam.

## Pengujian dan kalibrasi

```sh
pip install httpx
python -m unittest test_engine.py
```

Dari root proyek:

```sh
php tests/pretes-face-engine-test.php
php tests/pretes-proctoring-scenarios-test.php
node tests/pretes-ujian-camera-test.cjs
```

Default cosine threshold 0.45, lebih ketat dari contoh LFW OpenCV 0.363.
`FACE_MATCH_THRESHOLD` dapat diatur antara 0.363 dan 0.9 pada layanan VPS.
Skor di UI adalah cosine similarity × 100, **bukan peluang identitas benar**.
Threshold dan ambang gerakan harus dikalibrasi dengan sampel peserta berizin,
perangkat nyata, dan pencahayaan bervariasi sebelum penggunaan ujian. Uji foto
orang berbeda, lebih dari satu wajah, foto cetak/layar, video replay, kondisi
model mati, token salah, nonce kedaluwarsa, dan pemutusan koneksi VPS.

Layanan tidak menyimpan gambar atau embedding; snapshot audit tetap disimpan oleh
aplikasi PHP sesuai alur sebelumnya. Tidak ada gambar dikirim ke penyedia AI luar.

## Batas integrasi E-Ujian

Portal soal saat ini adalah URL eksternal yang bisa dibuka langsung. Verifikasi
di situs PHP hanya mengendalikan tampilan tombol di situs ini; untuk mewajibkan
verifikasi sebelum akses soal, integrasikan token/SSO atau aturan akses pada
penyedia E-Ujian. Menyembunyikan URL tidak mengamankan portal eksternal.

Rujukan:
- https://docs.opencv.org/4.x/d0/dd4/tutorial_dnn_face.html
- https://github.com/opencv/opencv_zoo/tree/48b10fa505aeb51fbd127fc60a5c660040e823b7
