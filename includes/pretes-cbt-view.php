<style>
.cbt-shell{border:1px solid #cbd5e1;border-radius:16px;overflow:hidden;background:#fff;margin-bottom:24px}
.cbt-heading{padding:18px 22px;background:#0f766e;color:#fff;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
.cbt-heading h3{margin:0;color:#fff;font-size:18px}.cbt-heading p{margin:5px 0 0;color:#ccfbf1;font-size:13px}
.cbt-timer{font-variant-numeric:tabular-nums;font-size:24px;font-weight:800;white-space:nowrap}
.cbt-body{padding:22px}.cbt-layout{display:grid;grid-template-columns:minmax(0,1fr) 210px;gap:24px}
.cbt-question{font-size:17px;line-height:1.7;white-space:pre-wrap;margin:12px 0 20px;color:#0f172a}
.cbt-option{display:flex;align-items:flex-start;gap:12px;padding:13px 16px;margin-bottom:10px;border:1.5px solid #cbd5e1;border-radius:10px;cursor:pointer;line-height:1.5}
.cbt-option:has(input:checked){border-color:#0f766e;background:#f0fdfa}.cbt-option input{margin-top:5px;accent-color:#0f766e}
.cbt-nav{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin:12px 0}.cbt-nav button{min-height:36px;border:1px solid #cbd5e1;border-radius:6px;background:#f8fafc;color:#334155;cursor:pointer;font-weight:700}
.cbt-nav button.answered{background:#dcfce7;color:#166534;border-color:#86efac}.cbt-nav button.flagged{background:#fef3c7;color:#92400e;border-color:#fbbf24}.cbt-nav button.current{outline:3px solid #0f766e;outline-offset:1px}
.cbt-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:20px}.cbt-message{padding:12px 16px;border-radius:8px;background:#eff6ff;color:#1e40af;margin-bottom:14px;font-size:13px;white-space:pre-wrap}
.cbt-message.error{background:#fef2f2;color:#991b1b}.cbt-result{text-align:center;padding:24px}.cbt-result strong{display:block;font-size:48px;color:#0f766e}
@media(max-width:720px){.cbt-layout{grid-template-columns:1fr}.cbt-body{padding:16px}.cbt-nav{grid-template-columns:repeat(10,1fr)}.cbt-nav button{min-width:0;font-size:11px}}
@media(max-width:430px){.cbt-nav{grid-template-columns:repeat(5,1fr)}}
</style>
<section class="cbt-shell" id="pretesCbt" aria-label="CBT Pretes Ubudiyah">
    <div class="cbt-heading">
        <div><h3>CBT Pretes Ubudiyah</h3><p>25 soal acak · Pilihan A–E · Jawaban tersimpan otomatis</p></div>
        <div><div style="font-size:11px">SISA WAKTU</div><div class="cbt-timer" id="cbtTimer">30:00</div></div>
    </div>
    <div class="cbt-body">
        <div id="cbtMessage" class="cbt-message" role="status" aria-live="polite">Memuat status ujian…</div>
        <div id="cbtIntro">
            <h4 style="margin-top:0">Ujian berlangsung di halaman ini</h4>
            <p>Anda mendapat 25 soal dari bank soal dengan urutan soal dan pilihan jawaban yang diacak. Durasi 30 menit dimulai setelah tombol Mulai Ujian ditekan. Waktu tetap berjalan jika halaman ditutup.</p>
            <p>Gunakan nomor soal untuk berpindah dan tandai soal yang ingin diperiksa kembali. Setelah dikumpulkan, jawaban tidak dapat diubah. Kamera pengawas tetap aktif selama ujian.</p>
            <button type="button" id="cbtStart" class="btn btn-success" disabled>Mulai Ujian · 25 Soal</button>
        </div>
        <div id="cbtWorkspace" class="cbt-layout" style="display:none">
            <div>
                <div id="cbtPosition" style="font-size:13px;color:#64748b;font-weight:700"></div>
                <div id="cbtQuestion" class="cbt-question"></div>
                <fieldset id="cbtOptions" style="border:0;padding:0;margin:0"><legend class="sr-only" style="font-size:13px">Pilih satu jawaban</legend></fieldset>
                <label style="display:flex;gap:8px;align-items:center;margin-top:14px"><input type="checkbox" id="cbtFlag"> Tandai untuk diperiksa kembali</label>
                <div class="cbt-actions">
                    <button type="button" class="btn btn-secondary" id="cbtPrevious">← Sebelumnya</button>
                    <button type="button" class="btn btn-primary" id="cbtNext">Berikutnya →</button>
                    <button type="button" class="btn btn-secondary" id="cbtClear">Kosongkan jawaban</button>
                </div>
            </div>
            <aside>
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
