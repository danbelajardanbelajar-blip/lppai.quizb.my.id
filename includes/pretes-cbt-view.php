<link rel="stylesheet" href="<?= BASE_URL ?>/pretes-ujian.php?cbt_style=exam&amp;v=<?= filemtime(__DIR__ . '/../assets/css/pretes-cbt-exam.css') ?>">
<section class="cbt-shell" id="pretesCbt" aria-label="CBT Pretes Ubudiyah">
    <div class="cbt-heading">
        <div><div class="cbt-eyebrow">LPPAI / Ruang Asesmen</div><h3>CBT Pretes Ubudiyah</h3><p>Soal acak terstandar · Pilihan A–E · Jawaban tersimpan otomatis</p></div>
        <div class="cbt-time-box"><small>Sisa waktu ujian</small><div class="cbt-timer" id="cbtTimer">--:--</div></div>
    </div>
    <div class="cbt-completion"><b>Terjawab <span id="cbtAnsweredCount">0/0</span></b><div id="cbtCompletion" class="cbt-progress-track" role="progressbar" aria-label="Soal terjawab" aria-valuemin="0" aria-valuemax="25" aria-valuenow="0"><span id="cbtCompletionFill"></span></div></div>
    <div class="cbt-body">
        <div id="cbtMessage" class="cbt-message" role="status" aria-live="polite">Memuat status ujian…</div>
        <div id="cbtIntro">
            <h4 style="margin-top:0">Ujian berlangsung di halaman ini</h4>
            <div class="cbt-intro-facts"><span>Soal pilihan ganda A–E</span><span>Durasi terhitung server</span><span>Satu kali pengumpulan</span></div>
            <p>Anda mendapat soal dan pilihan jawaban acak. Saat meninggalkan halaman atau pemeriksaan kamera tidak sesuai, popup menutup soal dan waktu dijeda setelah dikonfirmasi server. Soal yang sedang terbuka dikosongkan, dipindahkan ke posisi acak di antara soal yang belum dijawab, dan pilihannya diacak ulang. Jawaban soal lain tetap tersimpan.</p>
            <p>Gunakan nomor soal untuk berpindah dan tandai soal yang ingin diperiksa kembali. Setelah dikumpulkan, jawaban tidak dapat diubah.</p>
            <div id="cbtTokenBox" style="display:none;margin:16px auto;background:#fefce8;border:1.5px solid #fde047;border-radius:12px;padding:14px;max-width:380px;text-align:left">
                <label for="cbtInputToken" style="display:block;font-size:12px;font-weight:700;color:#854d0e;margin-bottom:6px">🔑 Masukkan Token Ujian *</label>
                <input type="text" id="cbtInputToken" maxlength="32" placeholder="TOKEN" style="font-family:monospace;font-size:16px;font-weight:800;letter-spacing:2px;text-align:center;text-transform:uppercase;padding:8px 12px;border:1.5px solid #ca8a04;border-radius:8px;width:100%;box-sizing:border-box;background:#fff">
                <span style="font-size:11px;color:#a16207;display:block;margin-top:5px">Mintalah token ujian kepada proktor atau pengawas di ruangan.</span>
            </div>
            <button type="button" id="cbtStart" class="btn btn-success" disabled>Mulai Ujian</button>
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
<dialog id="cbtInterruption" class="cbt-interruption" aria-labelledby="cbtInterruptionTitle" aria-describedby="cbtInterruptionReason cbtInterruptionAction cbtInterruptionStatus">
    <div class="cbt-interruption-card">
        <h2 id="cbtInterruptionTitle">Ujian dijeda</h2>
        <p id="cbtInterruptionReason"></p>
        <p id="cbtInterruptionAction"></p>
        <p id="cbtInterruptionStatus" role="status" aria-live="polite"></p>
        <button type="button" id="cbtResume" class="btn btn-success">Lanjutkan Ujian</button>
    </div>
</dialog>
