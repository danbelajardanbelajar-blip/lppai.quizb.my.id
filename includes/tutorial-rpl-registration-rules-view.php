<?php $rplPolicy = $rplRegistrationPolicy ?? tutorialRplRegistrationPolicy(); ?>
<section class="card" aria-label="Aturan pendaftaran Tutorial RPL" style="margin-top:18px;">
    <div class="card-header">Aturan Pendaftaran Tutorial RPL</div>
    <div class="card-body">
        <ul style="margin:0;padding-left:20px;line-height:1.8;">
            <li>Mahasiswa RPL yang <strong>belum mengikuti pretes</strong> maupun <strong>belum lulus pretes</strong> boleh mendaftar.</li>
            <li>Mahasiswa yang sudah lulus pretes resmi dengan nilai minimal <strong><?= (int)$rplPolicy['minimum_pretes_pass_score'] ?></strong> dibebaskan dari tutorial.</li>
            <?php if (!array_key_exists('active_slot', $rplPolicy) || (int)$rplPolicy['active_slot'] === 1): ?>
            <li>Jadwal pendaftaran: <strong><?= htmlspecialchars((new DateTimeImmutable($rplPolicy['opens_at']))->format('d/m/Y H:i:s'), ENT_QUOTES, 'UTF-8') ?></strong> sampai <strong><?= htmlspecialchars((new DateTimeImmutable($rplPolicy['closes_at']))->format('d/m/Y H:i:s'), ENT_QUOTES, 'UTF-8') ?> WIB</strong>.</li>
            <?php else: ?>
            <li>Jadwal pendaftaran aktif belum tersedia. Silakan hubungi admin.</li>
            <?php endif; ?>
            <li>Dua jalur yang ditetapkan: <strong>pendaftaran mandiri oleh mahasiswa</strong> dan <strong>pendaftaran melalui admin</strong>, dengan syarat dan jadwal yang sama.</li>
        </ul>
    </div>
</section>
