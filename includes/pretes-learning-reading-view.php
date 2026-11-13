<?php $readingId = $key === 'istighasah' ? 'bacaan-istighasah' : 'bacaan-' . str_replace('_', '-', $key); ?>
<details id="<?= sanitize($readingId) ?>" class="pretes-reading">
    <summary>Lihat bacaan <?= sanitize($key === 'istighasah' ? 'istighasah' : $learningReadings[$key]['title']) ?></summary>
    <div class="pretes-reading-body">
        <?php if ($key === 'istighasah'): ?>
            <p>Bacaan dan jumlah pengulangan mengikuti gambar panduan yang diberikan LPPAI.</p>
            <div class="pretes-istighasah-bismillah" lang="ar" dir="rtl">بِسْمِ اللّٰهِ الرَّحْمٰنِ الرَّحِيمِ</div>
            <ol class="pretes-istighasah-list" aria-label="Urutan bacaan istighasah">
                <?php foreach ($istighasah as $i => $reading): ?>
                    <li><span class="pretes-istighasah-count" aria-label="Bacaan <?= $i + 1 ?>, dibaca <?= $reading['count'] ?> kali"><?= $reading['count'] ?> kali</span><span class="pretes-istighasah-arabic" lang="ar" dir="rtl"><?= sanitize($reading['text']) ?></span></li>
                <?php endforeach; ?>
            </ol>
            <p class="pretes-istighasah-closing">Setelah rangkaian bacaan istighasah, lanjutkan dengan membaca <strong>Surat Yasin</strong>, sesuai petunjuk pada gambar panduan.</p>
        <?php else: ?>
            <?php if (count($learningReadings[$key]['verses']) > 1): ?>
                <p><?= count($learningReadings[$key]['verses']) ?> ayat · Latih bacaan secara berurutan.</p>
                <div class="pretes-istighasah-bismillah" lang="ar" dir="rtl">بِسْمِ اللّٰهِ الرَّحْمٰنِ الرَّحِيمِ</div>
            <?php endif; ?>
            <div class="pretes-quran-verses" lang="ar" dir="rtl">
                <?php foreach ($learningReadings[$key]['verses'] as $verse): ?>
                    <p class="pretes-reading-verse"><?= sanitize($verse) ?></p>
                <?php endforeach; ?>
            </div>
            <a href="<?= sanitize($learningReadings[$key]['source']) ?>" target="_blank" rel="noopener noreferrer">Sumber bacaan, latin, dan terjemah: Quran NU Online ↗</a>
        <?php endif; ?>
    </div>
</details>
