<?php $rplPolicy = tutorialRplRegistrationPolicy(); ?>
<section class="card" aria-label="Aturan pendaftaran Tutorial RPL" style="margin-top:18px;">
    <div class="card-header">Aturan Pendaftaran Tutorial RPL</div>
    <div class="card-body">
        <ul style="margin:0;padding-left:20px;line-height:1.8;">
            <li>Mahasiswa RPL yang <strong>belum mengikuti pretes</strong> maupun <strong>belum lulus pretes</strong> boleh mendaftar.</li>
            <li>Mahasiswa yang sudah lulus pretes resmi dengan nilai minimal <strong><?= (int)$rplPolicy['minimum_pretes_pass_score'] ?></strong> dibebaskan dari tutorial.</li>
            <li>Jadwal pendaftaran: <strong>Senin, 23 November 2026 pukul 00.00 WIB</strong> sampai <strong>Jumat, 27 November 2026 pukul 23.59.59 WIB</strong>.</li>
            <li>Dua jalur yang ditetapkan: <strong>pendaftaran mandiri oleh mahasiswa</strong> dan <strong>pendaftaran melalui admin</strong>, dengan syarat dan jadwal yang sama.</li>
        </ul>
    </div>
</section>
