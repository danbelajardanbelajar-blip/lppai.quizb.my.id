<link rel="stylesheet" href="<?= $escape(BASE_URL) ?>/assets/css/pretes-program.css?v=<?= filemtime(__DIR__.'/../assets/css/pretes-program.css') ?>">
<div class="pp-app">
    <section class="pp-hero">
        <div><span class="pp-eyebrow">ADMINISTRASI PRETES</span><h2>Pretes <?= $escape($programLabel) ?></h2><p>Periode, peserta, tugas, dan hasil ujian dalam satu ruang program.</p></div>
        <div class="pp-actions"><a class="btn btn-secondary" data-no-spa href="<?= $escape(BASE_URL) ?>/admin/dashboard.php">← Dashboard</a><a class="btn btn-primary" data-no-spa href="<?= $escape(BASE_URL) ?>/admin/pretes-<?= $escape($program) ?>-tugas.php">Kelola tugas <?= $escape($programLabel) ?></a></div>
    </section>
    <div class="pp-stats"><article><span>Pendaftaran peserta</span><strong><?= $counts['participants'] ?></strong></article><article><span>Pengerjaan CBT</span><strong><?= $counts['results'] ?></strong></article><article><span>Komponen tugas aktif</span><strong><?= $counts['tasks'] ?></strong></article></div>
    <p class="pp-note">Riwayat dikelompokkan menurut program akun mahasiswa saat ini. Periode campuran lama tetap ditandai sebagai arsip bersama. Melihat dan mengekspor data di sini tidak mengubah jawaban atau nilai.</p>
    <nav class="pp-tabs" aria-label="Bagian Pretes <?= $escape($programLabel) ?>">
        <?php foreach(['periods'=>'Periode','participants'=>'Peserta','results'=>'Hasil CBT','tasks'=>'Tugas']as$key=>$label): ?>
            <a data-no-spa class="<?= $key===$tab?'active':'' ?>" <?= $key===$tab?'aria-current="page"':'' ?> href="<?= $escape($url(['tab'=>$key])) ?>"><?= $escape($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <section class="card pp-card">
        <header class="card-header"><strong><?= $escape(['periods'=>'Periode Pretes','participants'=>'Peserta Pretes','results'=>'Hasil CBT','tasks'=>'Daftar Tugas'][$tab]) ?> <?= $escape($programLabel) ?></strong>
        <?php if($listing): ?><a class="btn btn-secondary btn-sm" data-no-spa href="<?= $escape($url(['tab'=>$tab,'q'=>$search,'kind'=>$kind,'download'=>'csv']+($periodId?['period'=>$periodId]:[]))) ?>">↓ Ekspor CSV</a><?php endif; ?></header>
        <?php if($listing): ?>
            <form method="get" class="pp-filters" action="<?= $escape($base) ?>" data-no-spa>
                <input type="hidden" name="tab" value="<?= $escape($tab) ?>">
                <label>Cari mahasiswa<input type="search" name="q" maxlength="200" placeholder="Nama atau NIM" value="<?= $escape($search) ?>"></label>
                <label>Periode<select name="period"><option value="">Semua periode</option><?php foreach($periods as$period): ?><option value="<?= (int)$period['id'] ?>" <?= $periodId===(int)$period['id']?'selected':'' ?>><?= $escape($period['periode']) ?></option><?php endforeach; ?></select></label>
                <?php if($tab==='results'): ?><label>Jenis ujian<select name="kind"><?php foreach(['all'=>'Resmi & tryout','resmi'=>'Resmi','tryout'=>'Tryout']as$key=>$label): ?><option value="<?= $key ?>" <?= $kind===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <button class="btn btn-primary" type="submit">Tampilkan</button><a data-no-spa class="pp-reset" href="<?= $escape($url(['tab'=>$tab])) ?>">Reset</a>
            </form>
            <?php if ($tab==='results' && $periodId!==null): ?><p class="pp-note" style="padding:0 20px">Pilih <strong>Semua periode</strong> untuk melihat tryout yang tidak memiliki periode pendaftaran.</p><?php endif; ?>
        <?php endif; ?>
        <div class="pp-table-wrap"><table class="pp-table">
        <?php if($tab==='periods'): ?>
            <thead><tr><th>Periode</th><th>Tahun ajaran</th><th>Program</th><th>Mulai</th><th>Status</th><th>Data peserta</th></tr></thead><tbody>
            <?php foreach($periods as$period):$mixed=strtolower(trim((string)$period['target_program']))==='semua'; ?><tr><td><strong><?= $escape($period['periode']) ?></strong></td><td><?= $escape($period['tahun_ajaran']) ?></td><td><span class="pp-badge <?= $mixed?'archive':'' ?>"><?= $mixed?'Campuran lama':$escape($programLabel) ?></span></td><td><?= $escape($period['tanggal_mulai']?:'—') ?></td><td><?= $escape($period['status']) ?></td><td><a data-no-spa href="<?= $escape($url(['tab'=>'participants','period'=>(int)$period['id']])) ?>">Lihat peserta <?= $escape($programLabel) ?> →</a></td></tr><?php endforeach; ?>
            </tbody>
        <?php elseif($tab==='participants'): ?>
            <thead><tr><th>NIM & nama</th><th>Program studi</th><th>Periode</th><th>Hari / jam</th><th>Penguji / ruangan</th><th>Status</th></tr></thead><tbody>
            <?php foreach($rows as$row): ?><tr data-participant="<?= (int)$row['id'] ?>"><td><span class="pp-muted"><?= $escape($row['nim']) ?></span><br><strong><?= $escape($row['nama_lengkap']) ?></strong></td><td><?= $escape($row['program_studi']) ?></td><td><?= $escape($row['periode']) ?></td><td><?= $escape($row['hari_pilihan']?:'—') ?><br><span class="pp-muted"><?= $escape($row['jam']) ?></span></td><td><?= $escape($row['dosen_penguji']?:'Belum ditetapkan') ?><br><span class="pp-muted"><?= $escape($row['ruangan']) ?></span></td><td><?= $escape($row['status']) ?></td></tr><?php endforeach; ?>
            </tbody>
        <?php elseif($tab==='results'): ?>
            <thead><tr><th>NIM & nama</th><th>Sesi</th><th>Jenis</th><th>Status pengerjaan</th><th>Nilai tersimpan</th><th>Review</th></tr></thead><tbody>
            <?php foreach($rows as$row): ?><tr data-attempt="<?= (int)$row['id'] ?>"><td><span class="pp-muted"><?= $escape($row['nim']) ?></span><br><strong><?= $escape($row['nama_lengkap']) ?></strong></td><td><?= $escape($row['session_title']?:'Sesi lama') ?></td><td><span class="pp-badge"><?= (int)$row['registration_id']<0?'Tryout':'Resmi' ?></span></td><td><?= $row['submitted_at']!==null?'Sudah dikumpulkan':'Belum dikumpulkan' ?></td><td><strong><?= $row['score']!==null?$escape($row['score']):'—' ?></strong></td><td><a class="btn btn-secondary btn-sm" data-no-spa href="<?= $escape($url(['tab'=>'results','kind'=>$kind,'review'=>(int)$row['id']])) ?>">Review</a></td></tr><?php endforeach; ?>
            </tbody>
        <?php else: ?>
            <thead><tr><th>Tugas</th><th>Bentuk</th><th>Kategori</th><th>Status</th></tr></thead><tbody>
            <?php foreach($tasks as$task): ?><tr><td><strong><?= $escape($task['label']) ?></strong></td><td><?= $escape($task['bentuk']) ?></td><td><?= $escape($task['kategori']) ?></td><td><span class="pp-badge <?= (int)$task['is_active']===1?'':'archive' ?>"><?= (int)$task['is_active']===1?'Aktif':'Nonaktif' ?></span></td></tr><?php endforeach; ?>
            </tbody>
        <?php endif; ?>
        </table></div>
        <?php if(($tab==='periods'&&!$periods)||($listing&&!$rows)||($tab==='tasks'&&!$tasks)): ?><p class="pp-empty">Belum ada data yang sesuai untuk Pretes <?= $escape($programLabel) ?>.</p><?php endif; ?>
        <?php if($listing):$pages=max(1,(int)ceil($listing['count']/25));$nav=['tab'=>$tab,'q'=>$search,'kind'=>$kind]+($periodId?['period'=>$periodId]:[]); ?>
            <footer class="pp-pagination"><span><?= $listing['count'] ?> data · Halaman <?= $page ?> dari <?= $pages ?></span><div><?php if($page>1): ?><a data-no-spa href="<?= $escape($url($nav+['page'=>$page-1])) ?>">← Sebelumnya</a><?php endif; ?><?php if($page<$pages): ?><a data-no-spa href="<?= $escape($url($nav+['page'=>$page+1])) ?>">Berikutnya →</a><?php endif; ?></div></footer>
        <?php endif; ?>
    </section>
</div>
