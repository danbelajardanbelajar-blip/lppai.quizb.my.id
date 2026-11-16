<?php
/**
 * LPPAI Corner - Admin: Kelola Tugas Pretes (komponen selain CBT) per program RPL / Reguler.
 * Tambah, edit, simpan, urutkan, nonaktifkan/aktifkan, dan hapus (hanya yang belum bernilai).
 * Data: tabel pretes_components (includes/pretes-components.php).
 */
define('PAGE_TITLE', 'Kelola Tugas Pretes');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/pretes-helper.php';

$pdo = getDBConnection();
pretesComponentsPdo($pdo);
ensurePretesFeatures($pdo); // tabel nilai (pretes_assessments) dipakai untuk cek "sudah bernilai" sebelum hapus
ensurePretesComponentTable($pdo);

$program = strtolower((string)($_GET['program'] ?? $_POST['program'] ?? 'reguler'));
if (!isset(PRETES_COMPONENT_PROGRAMS[$program])) $program = 'reguler';
$selfUrl = BASE_URL . '/admin/pretes-tugas.php?program=' . rawurlencode($program);

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$flash = $_SESSION['pretes_tugas_flash'] ?? null;
unset($_SESSION['pretes_tugas_flash']);
$formError = '';
$formInput = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) throw new InvalidArgumentException('Sesi tidak valid, muat ulang halaman lalu coba lagi.');
        $msg = '';
        switch ($action) {
            case 'save':
                $row = pretesComponentSave($pdo, $program, $_POST, $id ?: null);
                $msg = ($id ? 'Perubahan komponen "' : 'Komponen baru "') . $row['label'] . '" berhasil disimpan.';
                break;
            case 'deactivate':
            case 'activate':
                pretesComponentSetActive($pdo, $program, $id, $action === 'activate');
                $msg = $action === 'activate' ? 'Komponen diaktifkan kembali.' : 'Komponen dinonaktifkan. Nilai yang sudah tersimpan tetap aman.';
                break;
            case 'up':
            case 'down':
                pretesComponentMove($pdo, $program, $id, $action === 'up' ? -1 : 1);
                $msg = 'Urutan komponen diperbarui.';
                break;
            case 'delete':
                pretesComponentDelete($pdo, $program, $id);
                $msg = 'Komponen dihapus permanen.';
                break;
            default:
                throw new InvalidArgumentException('Aksi tidak dikenal.');
        }
        $_SESSION['pretes_tugas_flash'] = ['type' => 'success', 'text' => $msg];
        header('Location: ' . $selfUrl . '#daftar-tugas');
        exit;
    } catch (InvalidArgumentException $e) {
        if ($action === 'save') {
            $formError = $e->getMessage();
            $formInput = $_POST;
        } else {
            $flash = ['type' => 'danger', 'text' => $e->getMessage()];
        }
    } catch (Throwable $e) {
        error_log('pretes-tugas: ' . $e->getMessage());
        $flash = ['type' => 'danger', 'text' => 'Terjadi kesalahan saat menyimpan. Silakan coba lagi.'];
    }
}

$rows = pretesComponentRows($pdo, $program, true);
$activeCount = count(array_filter($rows, static fn($r) => (int)$r['is_active'] === 1));
$editId = (int)($_GET['edit'] ?? ($formInput['id'] ?? 0));
$editing = null;
foreach ($rows as $r) if ((int)$r['id'] === $editId) $editing = $r;
$form = $formInput ?? ($editing ?? ['label' => '', 'bentuk' => 'Lisan / Praktik', 'kategori' => PRETES_COMPONENT_CATEGORIES[0],
    'video_url' => '', 'video_title' => '', 'reading_title' => '', 'reading_text' => '']);
$isEdit = $editId > 0 && ($editing || $formInput);
$programLabel = PRETES_COMPONENT_PROGRAMS[$program];
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

include __DIR__ . '/../includes/header.php';
?>
<style>
.ptg-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.ptg-tab{padding:9px 18px;border-radius:999px;border:1.5px solid #cbd5e1;background:#fff;color:#334155;font-weight:700;text-decoration:none;font-size:14px}
.ptg-tab.active{background:#1e40af;border-color:#1e40af;color:#fff}
.ptg-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:20px;align-items:start}
@media(max-width:960px){.ptg-grid{grid-template-columns:1fr}}
.ptg-table{width:100%;border-collapse:collapse;font-size:13.5px}
.ptg-table th{background:#f8fafc;text-align:left;padding:10px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#475569;border-bottom:2px solid #e2e8f0}
.ptg-table td{padding:10px;border-bottom:1px solid #f1f5f9;vertical-align:top}
.ptg-table tr.inactive td{opacity:.55;background:#fafafa}
.ptg-actions{display:flex;gap:4px;flex-wrap:wrap}
.ptg-actions form{margin:0}
.ptg-actions .btn{padding:4px 9px;font-size:12px;line-height:1.4}
.ptg-chip{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}
.ptg-form .form-group{margin-bottom:12px}
.ptg-form label{display:block;font-weight:700;font-size:13px;margin-bottom:4px;color:#1e293b}
.ptg-form input[type=text],.ptg-form input[type=url],.ptg-form select,.ptg-form textarea{width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:14px;font-family:inherit}
.ptg-form textarea{min-height:120px;resize:vertical}
.ptg-hint{font-size:12px;color:#64748b;margin-top:3px}
.ptg-table-wrap{overflow-x:auto}
</style>

<div class="card" style="margin-bottom:18px;">
    <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
            <h2 style="margin:0 0 4px;font-size:20px;">📋 Kelola Tugas Pretes (Selain CBT)</h2>
            <p style="margin:0;color:#64748b;font-size:13.5px;line-height:1.6;">
                Komponen <strong>1. Ujian tulis</strong> otomatis dari CBT dan tidak diatur di sini. Komponen aktif di bawah ini dipakai pada materi peserta,
                input nilai dosen, Hasil Pretes, dan rekap nilai sesuai program mahasiswa. Lulus bila seluruh komponen aktif bernilai minimal 80.
            </p>
        </div>
        <a href="<?= BASE_URL ?>/admin/pretes-rekap-nilai.php" class="btn btn-secondary" style="text-decoration:none;">🎓 Rekap Nilai</a>
    </div>
</div>

<nav class="ptg-tabs" aria-label="Pilih program">
    <?php foreach (PRETES_COMPONENT_PROGRAMS as $pk => $pl): ?>
        <a class="ptg-tab <?= $pk === $program ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/pretes-tugas.php?program=<?= $pk ?>" data-program-tab="<?= $pk ?>">🎓 Program <?= $e($pl) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($flash): ?>
    <div class="alert alert-<?= $e($flash['type']) ?>" id="ptg-flash"><?= $e($flash['text']) ?></div>
<?php endif; ?>

<div class="ptg-grid">
    <div class="card" id="daftar-tugas">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
            <span style="font-weight:700;">Daftar Komponen — Program <?= $e($programLabel) ?></span>
            <span class="ptg-chip" style="background:#dcfce7;color:#166534;">Aktif: <?= $activeCount ?> + Ujian tulis = <?= $activeCount + 1 ?> komponen wajib</span>
        </div>
        <div class="card-body ptg-table-wrap">
            <table class="ptg-table">
                <thead><tr><th>No</th><th>Komponen</th><th>Materi</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td><strong>Ujian tulis</strong><div class="ptg-hint">CBT / Ujian tulis · nilai otomatis dari CBT</div></td>
                        <td>—</td>
                        <td><span class="ptg-chip" style="background:#e0e7ff;color:#3730a3;">🔒 Tetap</span></td>
                        <td></td>
                    </tr>
                    <?php $no = 1; $last = count($rows) - 1; foreach ($rows as $i => $r): $active = (int)$r['is_active'] === 1; if ($active) $no++; ?>
                        <tr class="<?= $active ? '' : 'inactive' ?>" data-component-key="<?= $e($r['comp_key']) ?>">
                            <td><?= $active ? $no : '–' ?></td>
                            <td>
                                <strong><?= $e($r['label']) ?></strong>
                                <div class="ptg-hint"><?= $e($r['bentuk']) ?> · <?= $e($r['kategori']) ?></div>
                                <div class="ptg-hint" style="font-family:monospace;">kunci: <?= $e($r['comp_key']) ?></div>
                            </td>
                            <td style="font-size:12.5px;">
                                <?php if (!empty($r['video_url'])): ?><div>▶ <a href="<?= $e($r['video_url']) ?>" target="_blank" rel="noopener noreferrer">Video</a></div><?php endif; ?>
                                <?php if (!empty($r['reading_text'])): ?><div>📖 Bacaan khusus</div>
                                <?php elseif (pretesComponentHasBuiltinReading($r['comp_key'])): ?><div>📖 Bacaan bawaan</div><?php endif; ?>
                                <?php if (empty($r['video_url']) && empty($r['reading_text']) && !pretesComponentHasBuiltinReading($r['comp_key'])): ?><span style="color:#94a3b8;">—</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($active): ?><span class="ptg-chip" style="background:#dcfce7;color:#166534;">Aktif</span>
                                <?php else: ?><span class="ptg-chip" style="background:#f1f5f9;color:#64748b;">Nonaktif</span><?php endif; ?>
                            </td>
                            <td>
                                <div class="ptg-actions">
                                    <a class="btn btn-sm btn-warning" href="<?= $e($selfUrl) ?>&amp;edit=<?= (int)$r['id'] ?>#form-tugas" title="Edit">✏️ Edit</a>
                                    <?php foreach (['up' => ['⬆', $i > 0], 'down' => ['⬇', $i < $last]] as $act => [$icon, $enabled]): if (!$enabled) continue; ?>
                                        <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="program" value="<?= $e($program) ?>">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-secondary" name="action" value="<?= $act ?>" title="<?= $act === 'up' ? 'Naikkan' : 'Turunkan' ?>"><?= $icon ?></button></form>
                                    <?php endforeach; ?>
                                    <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="program" value="<?= $e($program) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <?php if ($active): ?>
                                            <button class="btn btn-sm btn-outline-danger" name="action" value="deactivate" onclick="return confirm('Nonaktifkan komponen ini untuk program <?= $e($programLabel) ?>? Nilai yang sudah tersimpan tetap aman.')">⏸ Nonaktifkan</button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-success" name="action" value="activate">▶ Aktifkan</button>
                                        <?php endif; ?>
                                    </form>
                                    <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="program" value="<?= $e($program) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-danger" name="action" value="delete" onclick="return confirm('Hapus permanen komponen ini? Hanya bisa bila belum ada nilai mahasiswa. Tindakan ini tidak dapat dibatalkan.')">🗑</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="form-tugas">
        <div class="card-header" style="font-weight:700;"><?= $isEdit ? '✏️ Edit Komponen' : '➕ Tambah Komponen' ?> — Program <?= $e($programLabel) ?></div>
        <div class="card-body">
            <?php if ($formError): ?><div class="alert alert-danger"><?= $e($formError) ?></div><?php endif; ?>
            <form method="POST" class="ptg-form" action="<?= $e($selfUrl) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="program" value="<?= $e($program) ?>">
                <input type="hidden" name="id" value="<?= $isEdit ? $editId : 0 ?>">
                <div class="form-group">
                    <label for="ptg-label">Nama komponen / tugas *</label>
                    <input type="text" id="ptg-label" name="label" maxlength="150" required value="<?= $e($form['label'] ?? '') ?>" placeholder="Contoh: Praktik adzan dan iqamah">
                </div>
                <div class="form-group" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div>
                        <label for="ptg-bentuk">Bentuk ujian</label>
                        <select id="ptg-bentuk" name="bentuk">
                            <?php foreach (PRETES_COMPONENT_FORMS as $f): ?><option value="<?= $e($f) ?>" <?= ($form['bentuk'] ?? '') === $f ? 'selected' : '' ?>><?= $e($f) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="ptg-kategori">Kelompok</label>
                        <select id="ptg-kategori" name="kategori">
                            <?php foreach (PRETES_COMPONENT_CATEGORIES as $c): ?><option value="<?= $e($c) ?>" <?= ($form['kategori'] ?? '') === $c ? 'selected' : '' ?>><?= $e($c) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="ptg-video">Link video panduan (opsional)</label>
                    <input type="url" id="ptg-video" name="video_url" maxlength="500" value="<?= $e($form['video_url'] ?? '') ?>" placeholder="https://www.youtube.com/watch?v=...">
                </div>
                <div class="form-group">
                    <label for="ptg-video-title">Judul video (opsional)</label>
                    <input type="text" id="ptg-video-title" name="video_title" maxlength="200" value="<?= $e($form['video_title'] ?? '') ?>" placeholder="Contoh: Praktik wudlu lengkap — NU Online">
                </div>
                <div class="form-group">
                    <label for="ptg-reading-title">Judul bacaan (opsional)</label>
                    <input type="text" id="ptg-reading-title" name="reading_title" maxlength="200" value="<?= $e($form['reading_title'] ?? '') ?>" placeholder="Contoh: Doa setelah wudlu">
                </div>
                <div class="form-group">
                    <label for="ptg-reading">Teks bacaan / instruksi (opsional)</label>
                    <textarea id="ptg-reading" name="reading_text" maxlength="20000" placeholder="Tulis bacaan Arab, latin, atau instruksi tugas. Baris baru dipertahankan."><?= $e($form['reading_text'] ?? '') ?></textarea>
                    <?php if ($isEdit && $editing && pretesComponentHasBuiltinReading($editing['comp_key'])): ?>
                        <div class="ptg-hint">Komponen ini punya bacaan bawaan. Bila kolom ini diisi, teks ini yang ditampilkan menggantikan bacaan bawaan.</div>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary" id="ptg-submit">💾 <?= $isEdit ? 'Simpan Perubahan' : 'Tambah Komponen' ?></button>
                    <?php if ($isEdit): ?><a href="<?= $e($selfUrl) ?>#form-tugas" class="btn btn-secondary" style="text-decoration:none;">Batal</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
