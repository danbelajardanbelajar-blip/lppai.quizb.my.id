<?php
// $rplTasks hanya berisi tugas aktif dari helper Tutorial RPL, bukan tabel Pretes.
$learningReadings = json_decode(file_get_contents(__DIR__ . '/../data/pretes-learning-readings.json'), true) ?: [];
$istighasah = require __DIR__ . '/../data/pretes-istighasah.php';
?>
<style>
.rpl-task-panel{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:18px 20px;min-width:0}
.rpl-task-panel h4{margin:0 0 12px;font-size:15px;color:#1e293b}
.rpl-task-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:12px}
.rpl-task-item{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;min-width:0;overflow-wrap:anywhere;font-size:13px;color:#334155}
.rpl-task-item h5{margin:0 0 6px;font-size:14px;color:#1e293b}
.rpl-task-meta{font-size:12px;color:#64748b;margin-bottom:8px;line-height:1.6}
.rpl-task-item a{color:#166534;text-decoration:underline;line-height:1.7}
.rpl-task-item details{margin-top:10px;border-top:1px solid #e2e8f0;padding-top:10px}
.rpl-task-item summary{cursor:pointer;color:#166534;font-weight:700;line-height:1.6}
.rpl-task-item summary:focus-visible{outline:2px solid #166534;outline-offset:4px}
.rpl-task-item:has(details[open]){grid-column:1/-1}
.rpl-task-text{white-space:pre-wrap;unicode-bidi:plaintext;line-height:1.9;margin-top:12px;font-size:16px}
.rpl-task-item .pretes-reading-body{padding-top:12px;line-height:1.8}
.rpl-task-item .pretes-quran-verses,.rpl-task-item .pretes-istighasah-arabic,.rpl-task-item .pretes-istighasah-bismillah{font-family:"Traditional Arabic","Noto Naskh Arabic",serif;font-size:clamp(25px,4vw,32px);line-height:2.2}
.rpl-task-item .pretes-istighasah-bismillah{text-align:center}
.rpl-task-item .pretes-istighasah-list{list-style:none;padding:0}
.rpl-task-item .pretes-istighasah-list li{display:flex;align-items:baseline;gap:14px;padding:12px 0;border-bottom:1px solid #e2e8f0}
.rpl-task-item .pretes-istighasah-count{flex:none;font-size:12px;color:#64748b}
.rpl-task-item .pretes-istighasah-arabic{min-width:0;flex:1;text-align:right}
.rpl-task-note{margin:12px 0 0;font-size:12.5px;color:#64748b;line-height:1.6}
@media(max-width:480px){.rpl-task-panel{padding:16px}.rpl-task-grid{grid-template-columns:minmax(0,1fr)}}
</style>
<section class="rpl-task-panel" id="rpl-rincian-tugas" aria-labelledby="rpl-task-heading">
    <h4 id="rpl-task-heading">📌 Rincian Tugas Ujian Praktik Ibadah (Opsi B &amp; C)<?= !$rplTasksUnavailable ? ' · ' . count($rplTasks) . ' tugas' : '' ?></h4>
    <?php if ($rplTasksUnavailable): ?>
        <p role="status">Daftar tugas belum dapat dimuat. Silakan coba kembali atau hubungi admin LPPAI.</p>
    <?php elseif (!$rplTasks): ?>
        <p>Belum ada tugas praktik aktif. Silakan menunggu informasi dari dosen tutor.</p>
    <?php else: ?>
        <div class="rpl-task-grid">
            <?php foreach ($rplTasks as $i => $task): $key = (string)$task['task_key']; ?>
                <article class="rpl-task-item" data-rpl-task="<?= $escape($key) ?>">
                    <h5><?= $i + 1 ?>. <?= $escape($task['label']) ?></h5>
                    <div class="rpl-task-meta"><?= $escape($task['bentuk']) ?> · <?= $escape($task['kategori']) ?></div>
                    <?php $videoUrl = (string)($task['video_url'] ?? ''); ?>
                    <?php if (filter_var($videoUrl, FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($videoUrl, PHP_URL_SCHEME)), ['https','http'], true)): ?>
                        <a href="<?= $escape($videoUrl) ?>" target="_blank" rel="noopener noreferrer">▶ <?= $escape($task['video_title'] ?: 'Tonton video panduan') ?> ↗</a>
                    <?php endif; ?>
                    <?php if (!empty($task['reading_text'])): ?>
                        <details>
                            <summary><?= $escape($task['reading_title'] ?: 'Lihat bacaan / instruksi tugas') ?></summary>
                            <div class="rpl-task-text"><?= $escape($task['reading_text']) ?></div>
                        </details>
                    <?php elseif ($key === 'istighasah' || isset($learningReadings[$key])): ?>
                        <?php include __DIR__ . '/pretes-learning-reading-view.php'; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="rpl-task-note"><em>Catatan Tambahan:</em> Jika memilih <strong>Opsi B</strong>, ujian dilakukan langsung secara tatap muka di depan dosen tutor. Jika memilih <strong>Opsi C</strong>, mahasiswa merekam video tugas praktik yang tercantum di atas dan mengunggahnya ke Google Drive kelompok bimbingan.</p>
    <?php endif; ?>
</section>
