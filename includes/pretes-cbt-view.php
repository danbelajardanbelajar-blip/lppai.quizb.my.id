<link rel="stylesheet" href="<?= BASE_URL ?>/pretes-ujian.php?cbt_style=exam&amp;v=<?= filemtime(__DIR__ . '/../assets/css/pretes-cbt-exam.css') ?>">
<section class="cbt-shell" id="pretesCbt" aria-label="CBT Pretes Ubudiyah">
    <div class="cbt-heading">
        <div><div class="cbt-eyebrow">LPPAI / Ruang Asesmen</div><h3>CBT Pretes Ubudiyah</h3><p>25 soal acak · Pilihan A–E · Jawaban tersimpan otomatis</p></div>
        <div class="cbt-time-box"><small>Sisa waktu ujian</small><div class="cbt-timer" id="cbtTimer">30:00</div></div>
    </div>
    <div class="cbt-completion"><b>Terjawab <span id="cbtAnsweredCount">0/25</span></b><div id="cbtCompletion" class="cbt-progress-track" role="progressbar" aria-label="Soal terjawab" aria-valuemin="0" aria-valuemax="25" aria-valuenow="0"><span id="cbtCompletionFill"></span></div></div>
    <div class="cbt-body">
        <div id="cbtMessage" class="cbt-message" role="status" aria-live="polite">Memuat status ujian…</div>
        <div id="cbtIntro">
            <h4 style="margin-top:0">Ujian berlangsung di halaman ini</h4>
            <div class="cbt-intro-facts"><span>25 soal pilihan ganda</span><span>Durasi 30 menit</span><span>Satu kali pengumpulan</span></div>
            <p>Anda mendapat 25 soal dari bank soal dengan urutan soal dan pilihan jawaban yang diacak. Durasi 30 menit dimulai setelah tombol Mulai Ujian ditekan. Waktu tetap berjalan jika halaman ditutup.</p>
            <p>Gunakan nomor soal untuk berpindah dan tandai soal yang ingin diperiksa kembali. Setelah dikumpulkan, jawaban tidak dapat diubah. Kamera pengawas tetap aktif selama ujian.</p>
            <button type="button" id="cbtStart" class="btn btn-success" disabled>Mulai Ujian · 25 Soal</button>
        </div>
        <div id="cbtWorkspace" class="cbt-layout" style="display:none">
            <div>
                <div id="cbtPosition" style="font-size:13px;color:#64748b;font-weight:700"></div>
                <div id="cbtQuestion" class="cbt-question"></div>
                <fieldset id="cbtOptions" style="border:0;padding:0;margin:0"><legend class="sr-only" style="font-size:13px">Pilih satu jawaban</legend></fieldset>
                <label class="cbt-review-label"><input type="checkbox" id="cbtFlag"> Tandai untuk diperiksa kembali</label>
                <div class="cbt-actions">
                    <button type="button" class="btn btn-secondary" id="cbtPrevious">← Sebelumnya</button>
                    <button type="button" class="btn btn-primary" id="cbtNext">Berikutnya →</button>
                    <button type="button" class="btn btn-secondary" id="cbtClear">Kosongkan jawaban</button>
                </div>
            </div>
            <aside class="cbt-sidebar">
                <strong>Navigasi Soal</strong>
                <div id="cbtNumbers" class="cbt-nav" aria-label="Nomor soal"></div>
                <p id="cbtProgress" style="font-size:13px;color:#475569"></p>
                <p style="font-size:12px;color:#64748b">Hijau: dijawab · Kuning: ditandai · Bingkai: soal saat ini</p>
                <button type="button" id="cbtSubmit" class="btn btn-success" style="width:100%">Kumpulkan Ujian</button>
                <button type="button" id="cbtRetry" class="btn btn-secondary" style="display:none;width:100%;margin-top:8px">Coba Simpan Lagi</button>
            </aside>
        </div>
        <div id="cbtResult" class="cbt-result" style="display:none" aria-live="polite"></div>
    </div>
</section>
