<?php $istighasah = require __DIR__ . '/../data/pretes-istighasah.php'; ?>
<style>
.pretes-preparation{max-width:1040px;margin:0 auto 24px;color:#183a31}
.pretes-preparation-hero{padding:30px;border:1px solid #b9dfd2;border-radius:20px;background:linear-gradient(135deg,#edf9f3,#fff);margin-bottom:20px}
.pretes-preparation-eyebrow{font-size:12px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#31705a}
.pretes-preparation h2{font-size:clamp(23px,4vw,32px);line-height:1.3;margin:12px 0;color:#124b38}
.pretes-preparation p{line-height:1.8;font-size:14px}
.pretes-preparation-status{display:inline-block;padding:7px 12px;border-radius:30px;background:#d7eee4;color:#175a40;font-size:12px;font-weight:700;margin-bottom:12px}
.pretes-preparation-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:24px 0}
.pretes-preparation-step{padding:18px;border:1px solid #deebe5;border-radius:14px;background:#fff}
.pretes-preparation-step strong{display:block;margin:8px 0;font-size:15px}
.pretes-preparation-step p{margin:0;color:#52675f;font-size:13px}
.pretes-preparation-actions{display:flex;flex-wrap:wrap;gap:10px}
.pretes-istighasah{border:1px solid #dfE8df;border-radius:20px;background:#fff;padding:28px}
.pretes-istighasah h3{margin:8px 0;color:#194f3b;font-size:23px}
.pretes-istighasah-intro{color:#596d62;margin-bottom:20px}
.pretes-istighasah-bismillah{font-family:"Traditional Arabic","Noto Naskh Arabic","Amiri",serif;font-size:32px;text-align:center;padding:18px 0;color:#174e39;line-height:2}
.pretes-istighasah-list{list-style:none;padding:0;margin:0;counter-reset:bacaan}
.pretes-istighasah-list li{display:flex;gap:20px;align-items:center;justify-content:space-between;padding:20px 0;border-bottom:1px solid #e7ede8}
.pretes-istighasah-arabic{flex:1;min-width:0;font-family:"Traditional Arabic","Noto Naskh Arabic","Amiri",serif;font-size:clamp(26px,4vw,34px);line-height:2.1;text-align:right;overflow-wrap:break-word}
.pretes-istighasah-count{flex:none;min-width:68px;text-align:center;border-radius:10px;background:#f1f7f2;color:#2e6044;padding:9px 7px;font-size:13px;font-weight:700;white-space:nowrap}
.pretes-istighasah-closing{padding:16px;border-radius:12px;background:#f5f8f2;margin:22px 0 0}
@media(max-width:650px){.pretes-preparation-hero,.pretes-istighasah{padding:20px}.pretes-preparation-grid{grid-template-columns:1fr}.pretes-istighasah-list li{gap:12px;align-items:flex-start}.pretes-istighasah-count{min-width:56px;margin-top:10px;font-size:12px}.pretes-istighasah-bismillah{font-size:27px}}
</style>
<div class="pretes-preparation">
    <section class="pretes-preparation-hero" aria-labelledby="pretesPreparationTitle">
        <div class="pretes-preparation-status">Pendaftaran pretes masih aktif</div>
        <div class="pretes-preparation-eyebrow"><?= sanitize($activePretes['periode']) ?></div>
        <h2 id="pretesPreparationTitle"><?= $registeredForActivePretes ? 'Menunggu pembagian kelas dan penguji' : 'Persiapkan diri untuk mengikuti pretes' ?></h2>
        <?php if ($registeredForActivePretes): ?>
            <p>Pendaftaran Anda untuk periode ini sudah tercatat. Silakan menunggu pengumuman <strong>pembagian kelas, jadwal, dan penguji pretes</strong>. Selama menunggu, gunakan waktu untuk mempersiapkan materi dan praktik pretes.</p>
        <?php else: ?>
            <p>Pendaftaran pretes periode ini masih dibuka. Jika Anda belum mendaftar pada periode ini, silakan menyelesaikan pendaftaran terlebih dahulu. Setelah terdaftar, pantau pengumuman pembagian kelas, jadwal, dan penguji.</p>
        <?php endif; ?>
        <p><strong>Hasil kelulusan periode ini belum ditampilkan.</strong> Status pendaftaran aktif tidak berarti Anda telah lulus atau tidak lulus pretes.</p>
        <div class="pretes-preparation-grid">
            <div class="pretes-preparation-step"><span class="pretes-preparation-eyebrow">01 / Persiapan</span><strong>Pelajari kembali materi pretes</strong><p>Ulangi bacaan Al-Qur’an, doa, serta materi dan praktik ibadah yang akan diujikan.</p></div>
            <div class="pretes-preparation-step"><span class="pretes-preparation-eyebrow">02 / Informasi</span><strong>Pantau pembagian peserta</strong><p>Periksa menu Peserta &amp; Jadwal Pretes secara berkala untuk melihat informasi yang diterbitkan pengelola.</p></div>
            <div class="pretes-preparation-step"><span class="pretes-preparation-eyebrow">03 / Amaliah</span><strong>Baca istighasah</strong><p>Gunakan bacaan di bawah untuk mempersiapkan diri, memohon pertolongan dan kemudahan kepada Allah.</p></div>
        </div>
        <div class="pretes-preparation-actions">
            <?php if (!$registeredForActivePretes): ?><a class="btn btn-success" href="<?= BASE_URL ?>/pretes-daftar.php">Daftar Pretes</a><?php endif; ?>
            <a class="btn btn-primary" href="<?= BASE_URL ?>/pretes-peserta.php">Lihat Peserta &amp; Jadwal Pretes</a>
            <a class="btn btn-secondary" href="#bacaan-istighasah">Baca Istighasah</a>
        </div>
    </section>
    <section id="bacaan-istighasah" class="pretes-istighasah" aria-labelledby="pretesIstighasahTitle">
        <div class="pretes-preparation-eyebrow">Bacaan persiapan pretes</div>
        <h3 id="pretesIstighasahTitle">Istighasah</h3>
        <p class="pretes-istighasah-intro">Bacaan dan jumlah pengulangan berikut mengikuti gambar panduan yang diberikan LPPAI.</p>
        <div class="pretes-istighasah-bismillah" lang="ar" dir="rtl">بِسْمِ اللّٰهِ الرَّحْمٰنِ الرَّحِيمِ</div>
        <ol class="pretes-istighasah-list" aria-label="Urutan bacaan istighasah">
            <?php foreach ($istighasah as $i => $reading): ?>
                <li><span class="pretes-istighasah-count" aria-label="Bacaan <?= $i + 1 ?>, dibaca <?= $reading['count'] ?> kali"><?= $reading['count'] ?> kali</span><span class="pretes-istighasah-arabic" lang="ar" dir="rtl"><?= sanitize($reading['text']) ?></span></li>
            <?php endforeach; ?>
        </ol>
        <p class="pretes-istighasah-closing">Setelah rangkaian bacaan istighasah, lanjutkan dengan membaca <strong>Surat Yasin</strong>, sesuai petunjuk pada gambar panduan.</p>
    </section>
</div>
