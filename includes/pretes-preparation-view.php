<?php
$istighasah = require __DIR__ . '/../data/pretes-istighasah.php';
$preparationComponents = pretesComponents();
$learningVideos = require __DIR__ . '/../data/pretes-learning-videos.php';
$learningReadings = json_decode(file_get_contents(__DIR__ . '/../data/pretes-learning-readings.json'), true, 512, JSON_THROW_ON_ERROR);
?>
<style>
.pretes-preparation{max-width:1040px;margin:0 auto 24px;color:#183a31}
.pretes-preparation-hero{padding:28px;border:1px solid #b9dfd2;border-radius:18px;background:linear-gradient(135deg,#edf9f3,#fff);margin-bottom:20px;box-shadow:0 3px 14px rgba(18,75,56,0.05)}
.pretes-preparation-eyebrow{font-size:12px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#31705a}
.pretes-preparation h2{font-size:clamp(22px,3.5vw,28px);line-height:1.3;margin:10px 0;color:#124b38}
.pretes-preparation p{line-height:1.75;font-size:14px}
.pretes-preparation-status{display:inline-block;padding:6px 14px;border-radius:30px;background:#d7eee4;color:#175a40;font-size:12px;font-weight:700}
.pretes-preparation-materials{margin-bottom:20px}
.pretes-material-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 32px;padding-left:24px;margin:20px 0 0}
.pretes-material-list > li{padding:14px 12px 14px 6px;border-bottom:1px solid #e7ede8;line-height:1.6}
.pretes-material-list > li::marker{color:#31705a;font-weight:700}
.pretes-material-list > li > strong{display:block;font-size:15px;color:#183a31}
.pretes-material-type{display:block;font-size:12px;color:#64786d;margin-top:4px}
.pretes-material-list a{display:inline-block;margin-top:6px;color:#176246;font-size:13px;text-decoration:underline}
.pretes-istighasah{border:1px solid #dfE8df;border-radius:20px;background:#fff;padding:28px}
.pretes-istighasah h3{margin:8px 0;color:#194f3b;font-size:23px}
.pretes-istighasah-intro{color:#596d62;margin-bottom:20px}
.pretes-istighasah-bismillah{font-family:"Traditional Arabic","Noto Naskh Arabic","Amiri",serif;font-size:32px;text-align:center;padding:18px 0;color:#174e39;line-height:2}
.pretes-istighasah-list{list-style:none;padding:0;margin:0;counter-reset:bacaan}
.pretes-istighasah-list li{display:flex;gap:20px;align-items:center;justify-content:space-between;padding:20px 0;border-bottom:1px solid #e7ede8}
.pretes-istighasah-arabic{flex:1;min-width:0;font-family:"Traditional Arabic","Noto Naskh Arabic","Amiri",serif;font-size:clamp(26px,4vw,34px);line-height:2.1;text-align:right;overflow-wrap:break-word}
.pretes-istighasah-count{flex:none;min-width:68px;text-align:center;border-radius:10px;background:#f1f7f2;color:#2e6044;padding:9px 7px;font-size:13px;font-weight:700;white-space:nowrap}
.pretes-istighasah-closing{padding:16px;border-radius:12px;background:#f5f8f2;margin:22px 0 0}
@media(max-width:650px){.pretes-preparation-hero,.pretes-istighasah{padding:18px}.pretes-material-list{grid-template-columns:1fr}.pretes-istighasah-list li{gap:12px;align-items:flex-start}.pretes-istighasah-count{min-width:56px;margin-top:10px;font-size:12px}.pretes-istighasah-bismillah{font-size:27px}}
.pretes-reading{margin-top:12px;border:1px solid #d7e5dd;border-radius:10px;background:#fbfdfb;scroll-margin-top:90px}
.pretes-reading summary{cursor:pointer;padding:12px;color:#176246;font-size:14px;font-weight:700}
.pretes-reading summary:focus-visible{outline:2px solid #176246;outline-offset:3px;border-radius:8px}
.pretes-reading-body{padding:4px 16px 16px}
.pretes-material-list > li:has(.pretes-reading[open]){grid-column:1/-1}
.pretes-quran-verses{font-family:"Traditional Arabic","Noto Naskh Arabic","Amiri",serif;text-align:right}
.pretes-quran-verses .pretes-reading-verse{font-size:clamp(28px,4vw,36px);line-height:2.3;color:#183a31;border-bottom:1px solid #e7ede8;padding:16px 0;margin:0;overflow-wrap:break-word}
</style>
<div class="pretes-preparation">
    <section id="materi-persiapan-pretes" class="pretes-preparation-hero pretes-istighasah pretes-preparation-materials" aria-labelledby="pretesPreparationTitle">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px 12px;margin-bottom:12px;">
            <div class="pretes-preparation-eyebrow"><?= sanitize($activePretes['periode'] ?? 'Pretes LPPAI') ?></div>
            <div class="pretes-preparation-status">Pendaftaran pretes masih aktif</div>
        </div>
        <h2 id="pretesPreparationTitle" style="margin:4px 0 10px;color:#124b38;font-size:clamp(20px,3.5vw,26px);font-weight:800;">
            Materi Persiapan &amp; Praktik Pretes
        </h2>
        <p class="pretes-istighasah-intro" style="margin:0 0 20px;color:#4b6358;font-size:13.5px;line-height:1.65;">
            Persiapkan seluruh 12 komponen materi yang diujikan dalam pretes LPPAI berikut, mencakup ujian tulis CBT, hafalan surah pilihan, dzikir/amaliyah, serta praktik ibadah ubudiyah lengkap dengan bacaan dan video tutorial resmi.
        </p>

        <ol class="pretes-material-list">
            <?php foreach ($preparationComponents as $key => $label): ?>
                <li data-pretes-component="<?= sanitize($key) ?>">
                    <strong><?= sanitize($label) ?></strong>
                    <span class="pretes-material-type"><?= $key === 'ujian_tulis' ? 'CBT / Ujian tulis' : 'Lisan / Praktik' ?></span>
                    <?php if (isset($learningVideos[$key])): ?>
                        <a class="pretes-video-link" href="https://www.youtube.com/watch?v=<?= sanitize($learningVideos[$key]['id']) ?>" target="_blank" rel="noopener noreferrer">▶ <?= sanitize($learningVideos[$key]['title']) ?> — NU Online ↗</a>
                    <?php endif; ?>
                    <?php if ($key === 'istighasah' || isset($learningReadings[$key])): ?>
                        <?php include __DIR__ . '/pretes-learning-reading-view.php'; ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
</div>
