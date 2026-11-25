<?php
/**
 * LPPAI Corner - Admin: Kelola Tugas Tutorial RPL (Selain CBT).
 * Berparitas penuh dengan admin/pretes-tugas.php:
 * Tambah, edit, simpan, urutkan, nonaktifkan/aktifkan, hapus, serta pengaturan mode kelulusan Tutorial RPL.
 */
define('PAGE_TITLE', 'Kelola Tugas Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
require_once __DIR__ . '/../includes/tutorial-rpl-tasks.php';

$pdo = getDBConnection();
ensureTutorialRplTables($pdo);
try {
    ensureTutorialRplTasksTables($pdo, (int)(getCurrentUser()['id'] ?? 0) ?: null);
} catch (Throwable $e) {
    error_log('tutorial-rpl-tugas initialization: '.$e->getMessage());
    http_response_code(503);
    include __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">Daftar tugas RPL belum dapat disalin atau dimuat. Data sebelumnya tidak ditimpa. Silakan coba kembali atau hubungi pengelola server.</div>';
    include __DIR__ . '/../includes/footer.php';
    return;
}

$selfUrl = BASE_URL . '/admin/tutorial-rpl-tugas.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$flash = $_SESSION['tutorial_rpl_tugas_flash'] ?? null;
unset($_SESSION['tutorial_rpl_tugas_flash']);
$formError = '';
$formInput = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $id = (int)($_POST['id'] ?? 0);
    try {
        if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) {
            http_response_code(403);
            throw new InvalidArgumentException('Sesi tidak valid, muat ulang halaman lalu coba lagi.');
        }
        $msg = '';
        switch ($action) {
            case 'copy_pretes_rpl_list':
                $count = tutorialRplTaskCopyPretesRpl($pdo, true, (int)(getCurrentUser()['id'] ?? 0) ?: null);
                $msg = $count.' tugas berhasil disalin dari Pretes program RPL. Daftar sebelumnya dicadangkan; mode kelulusan tidak diubah.';
                break;
            case 'save':
                $row = tutorialRplTaskSave($pdo, $_POST, $id ?: null);
                $msg = ($id ? 'Perubahan tugas "' : 'Tugas baru "') . $row['label'] . '" berhasil disimpan.';
                break;
            case 'deactivate':
            case 'activate':
                tutorialRplTaskSetActive($pdo, $id, $action === 'activate');
                $msg = $action === 'activate' ? 'Komponen tugas diaktifkan kembali.' : 'Komponen tugas dinonaktifkan.';
                break;
            case 'up':
            case 'down':
                tutorialRplTaskMove($pdo, $id, $action === 'up' ? -1 : 1);
                $msg = 'Urutan komponen tugas diperbarui.';
                break;
            case 'delete':
                tutorialRplTaskDelete($pdo, $id);
                $msg = 'Komponen tugas dihapus permanen.';
                break;
            case 'set_pass_mode':
                $mode = (string)($_POST['rpl_tutorial_pass_mode'] ?? '');
                if (!isset(TUTORIAL_RPL_PASS_MODES[$mode])) {
                    throw new InvalidArgumentException('Mode kelulusan Tutorial RPL tidak dikenal.');
                }
                tutorialRplSettingSet($pdo, 'rpl_tutorial_pass_mode', $mode);
                $msg = 'Mode kelulusan Tutorial RPL disimpan: ' . TUTORIAL_RPL_PASS_MODES[$mode] . '.';
                break;
            default:
                throw new InvalidArgumentException('Aksi tidak dikenal.');
        }
        $_SESSION['tutorial_rpl_tugas_flash'] = ['type' => 'success', 'text' => $msg];
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
        error_log('tutorial-rpl-tugas: ' . $e->getMessage());
        $flash = ['type' => 'danger', 'text' => 'Terjadi kesalahan saat menyimpan. Silakan coba lagi.'];
    }
}

$rows = tutorialRplTaskRows($pdo, true);
$activeCount = count(array_filter($rows, static fn($r) => (int)$r['is_active'] === 1));
$editId = (int)($_GET['edit'] ?? ($formInput['id'] ?? 0));
$editing = null;
foreach ($rows as $r) {
    if ((int)$r['id'] === $editId) $editing = $r;
}
$form = $formInput ?? ($editing ?? [
    'label' => '',
    'bentuk' => TUTORIAL_RPL_TASK_FORMS[0],
    'kategori' => TUTORIAL_RPL_TASK_CATEGORIES[0],
    'video_url' => '',
    'video_title' => '',
    'reading_title' => '',
    'reading_text' => '',
]);
$isEdit = $editId > 0 && ($editing || $formInput);
$passMode = tutorialRplPassMode($pdo);
$cbtOnlyActive = $passMode === 'cbt_only';
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

include __DIR__ . '/../includes/header.php';
?>
<style>
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
.ptg-mode-options{display:grid;gap:8px}
.ptg-mode-option{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;cursor:pointer;font-size:13.5px;background:#fff}
.ptg-mode-option.checked{border-color:#16a34a;background:#f0fdf4}
.ptg-mode-option input{margin-top:3px;width:18px;height:18px;flex-shrink:0}
</style>

<div class="card" style="margin-bottom:18px;">
    <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
            <h2 style="margin:0 0 4px;font-size:20px;">📋 Kelola Tugas Tutorial RPL (Selain CBT)</h2>
            <p style="margin:0;color:#64748b;font-size:13.5px;line-height:1.6;">
                Komponen <strong>1. Ujian tulis</strong> otomatis dari CBT Tutorial RPL dan tidak diatur di sini. Daftar tugas praktik/hafalan di bawah ini menjadi acuan
                komponen tugas Tutorial RPL Online, panduan materi peserta, dan evaluasi dosen tutor.
            </p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="<?= BASE_URL ?>/admin/tutorial-rpl-kelas.php" class="btn btn-secondary" style="text-decoration:none;">🏫 Kelola Kelompok &amp; Drive</a>
            <a href="<?= BASE_URL ?>/admin/tutorial-rpl-nilai.php" class="btn btn-secondary" style="text-decoration:none;">🎓 Rekap Nilai RPL</a>
        </div>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $e($flash['type']) ?>" id="ptg-flash"><?= $e($flash['text']) ?></div>
<?php endif; ?>

<div class="card" id="ptg-rpl-mode" style="margin-bottom:18px;border-left:4px solid <?= $cbtOnlyActive ? '#16a34a' : '#2563eb' ?>;">
    <div class="card-body">
        <form method="post" action="<?= $e($selfUrl) ?>" class="ptg-mode-form" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= $e(csrfToken()) ?>">
            <input type="hidden" name="action" value="set_pass_mode">
            <div style="font-weight:800;font-size:15px;margin-bottom:4px;">⚖️ Mode Kelulusan Tutorial RPL Online</div>
            <p class="ptg-hint" style="margin:0 0 10px;">Menentukan kebijakan pemenuhan syarat lulus bagi peserta Tutorial RPL Online.</p>
            <div class="ptg-mode-options">
                <?php foreach (TUTORIAL_RPL_PASS_MODES as $mk => $ml): ?>
                    <label class="ptg-mode-option <?= $passMode === $mk ? 'checked' : '' ?>">
                        <input type="radio" name="rpl_tutorial_pass_mode" value="<?= $e($mk) ?>" <?= $passMode === $mk ? 'checked' : '' ?>>
                        <span>
                            <strong><?= $e($ml) ?></strong>
                            <span class="ptg-hint" style="display:block;">
                                <?php if ($mk === 'flexible_3_options'): ?>
                                    Mahasiswa dapat memilih salah satu: lulus CBT minimal 70, ujian tatap muka di depan dosen, atau unggah video rekaman tugas ke Google Drive kelas.
                                <?php elseif ($mk === 'cbt_only'): ?>
                                    Mahasiswa cukup menyelesaikan Ujian Tulis CBT Tutorial RPL sesuai passing grade; daftar tugas di bawah tidak diwajibkan.
                                <?php else: ?>
                                    Mahasiswa wajib lulus Ujian Tulis CBT dan menyelesaikan seluruh komponen tugas praktik aktif di bawah ini.
                                <?php endif; ?>
                            </span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary" id="ptg-rpl-mode-save" style="margin-top:10px;">💾 Simpan Mode Kelulusan</button>
        </form>
    </div>
</div>

<?php if ($cbtOnlyActive): ?>
    <div class="alert alert-info" id="ptg-rpl-cbt-only-note">ℹ️ Mode <strong>Cukup CBT</strong> aktif: komponen tugas di bawah ini tidak diwajibkan bagi mahasiswa, tetapi tetap tersimpan bila mode diubah kembali.</div>
<?php endif; ?>

<div class="ptg-grid">
    <div class="card" id="daftar-tugas">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
            <span style="font-weight:700;">Daftar Komponen Tugas — Tutorial RPL Online</span>
            <form method="post" action="<?= $e($selfUrl) ?>" data-no-spa style="margin:0" onsubmit="return confirm('Salin ulang daftar tugas dari Pretes program RPL? Daftar Tutorial RPL akan diganti, daftar sebelumnya dicadangkan. Mode kelulusan tidak berubah.')">
                <input type="hidden" name="csrf_token" value="<?= $e(csrfToken()) ?>">
                <input type="hidden" name="action" value="copy_pretes_rpl_list">
                <button type="submit" class="btn btn-secondary btn-sm">Salin ulang dari Pretes RPL</button>
            </form>
            <?php if ($cbtOnlyActive): ?>
                <span class="ptg-chip" style="background:#e0f2fe;color:#075985;">Mode cukup CBT: hanya Ujian tulis yang wajib</span>
            <?php else: ?>
                <span class="ptg-chip" style="background:#dcfce7;color:#166534;">Aktif: <?= $activeCount ?> tugas + Ujian tulis CBT</span>
            <?php endif; ?>
        </div>
        <div class="card-body ptg-table-wrap">
            <table class="ptg-table">
                <thead><tr><th>No</th><th>Komponen</th><th>Materi</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td><strong>Ujian tulis (CBT)</strong><div class="ptg-hint">CBT Tutorial RPL · nilai otomatis dari sistem</div></td>
                        <td>—</td>
                        <td><span class="ptg-chip" style="background:#e0e7ff;color:#3730a3;">🔒 Tetap</span></td>
                        <td></td>
                    </tr>
                    <?php $no = 1; $last = count($rows) - 1; foreach ($rows as $i => $r): $active = (int)$r['is_active'] === 1; if ($active) $no++; ?>
                        <tr class="<?= $active ? '' : 'inactive' ?>" data-component-key="<?= $e($r['task_key']) ?>">
                            <td><?= $active ? $no : '–' ?></td>
                            <td>
                                <strong><?= $e($r['label']) ?></strong>
                                <div class="ptg-hint"><?= $e($r['bentuk']) ?> · <?= $e($r['kategori']) ?></div>
                                <div class="ptg-hint" style="font-family:monospace;">kunci: <?= $e($r['task_key']) ?></div>
                            </td>
                            <td style="font-size:12.5px;">
                                <?php if (!empty($r['video_url'])): ?><div>▶ <a href="<?= $e($r['video_url']) ?>" target="_blank" rel="noopener noreferrer">Video</a></div><?php endif; ?>
                                <?php if (!empty($r['reading_text'])): ?><div>📖 Bacaan khusus</div><?php endif; ?>
                                <?php if (empty($r['reading_text']) && pretesComponentHasBuiltinReading($r['task_key'])): ?><div>📖 Bacaan bawaan</div><?php endif; ?>
                                <?php if (empty($r['video_url']) && empty($r['reading_text']) && !pretesComponentHasBuiltinReading($r['task_key'])): ?><span style="color:#94a3b8;">—</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($active): ?><span class="ptg-chip" style="background:#dcfce7;color:#166534;">Aktif</span>
                                <?php else: ?><span class="ptg-chip" style="background:#f1f5f9;color:#64748b;">Nonaktif</span><?php endif; ?>
                            </td>
                            <td>
                                <div class="ptg-actions">
                                    <a class="btn btn-sm btn-warning" href="<?= $e($selfUrl) ?>?edit=<?= (int)$r['id'] ?>#form-tugas" title="Edit">✏️ Edit</a>
                                    <?php foreach (['up' => ['⬆', $i > 0], 'down' => ['⬇', $i < $last]] as $act => [$icon, $enabled]): if (!$enabled) continue; ?>
                                        <form method="POST" data-no-spa><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-secondary" name="action" value="<?= $act ?>" title="<?= $act === 'up' ? 'Naikkan' : 'Turunkan' ?>"><?= $icon ?></button></form>
                                    <?php endforeach; ?>
                                    <form method="POST" data-no-spa><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <?php if ($active): ?>
                                            <button class="btn btn-sm btn-outline-danger" name="action" value="deactivate" onclick="return confirm('Nonaktifkan komponen tugas ini?')">⏸ Nonaktifkan</button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-success" name="action" value="activate">▶ Aktifkan</button>
                                        <?php endif; ?>
                                    </form>
                                    <form method="POST" data-no-spa><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-danger" name="action" value="delete" onclick="return confirm('Hapus permanen komponen tugas ini? Tindakan ini tidak dapat dibatalkan.')">🗑</button>
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
        <div class="card-header" style="font-weight:700;"><?= $isEdit ? '✏️ Edit Komponen Tugas' : '➕ Tambah Komponen Tugas' ?> — Tutorial RPL</div>
        <div class="card-body">
            <?php if ($formError): ?><div class="alert alert-danger"><?= $e($formError) ?></div><?php endif; ?>
            <form method="POST" class="ptg-form" action="<?= $e($selfUrl) ?>" data-no-spa>
                <input type="hidden" name="csrf_token" value="<?= $e(csrfToken()) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= $isEdit ? $editId : 0 ?>">
                <div class="form-group">
                    <label for="ptg-label">Nama komponen / tugas *</label>
                    <input type="text" id="ptg-label" name="label" maxlength="150" required value="<?= $e($form['label'] ?? '') ?>" placeholder="Contoh: Praktik Sholat Jenazah">
                </div>
                <div class="form-group" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div>
                        <label for="ptg-bentuk">Bentuk tugas</label>
                        <select id="ptg-bentuk" name="bentuk">
                            <?php foreach (TUTORIAL_RPL_TASK_FORMS as $f): ?><option value="<?= $e($f) ?>" <?= ($form['bentuk'] ?? '') === $f ? 'selected' : '' ?>><?= $e($f) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="ptg-kategori">Kelompok</label>
                        <select id="ptg-kategori" name="kategori">
                            <?php foreach (TUTORIAL_RPL_TASK_CATEGORIES as $c): ?><option value="<?= $e($c) ?>" <?= ($form['kategori'] ?? '') === $c ? 'selected' : '' ?>><?= $e($c) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="ptg-video">Link video panduan (opsional)</label>
                    <input type="url" id="ptg-video" name="video_url" maxlength="500" value="<?= $e($form['video_url'] ?? '') ?>" placeholder="https://www.youtube.com/watch?v=...">
                </div>
                <div class="form-group">
                    <label for="ptg-video-title">Judul video (opsional)</label>
                    <input type="text" id="ptg-video-title" name="video_title" maxlength="200" value="<?= $e($form['video_title'] ?? '') ?>" placeholder="Contoh: Panduan Praktik Sholat Jenazah">
                </div>
                <div class="form-group">
                    <label for="ptg-reading-title">Judul bacaan / panduan (opsional)</label>
                    <input type="text" id="ptg-reading-title" name="reading_title" maxlength="200" value="<?= $e($form['reading_title'] ?? '') ?>" placeholder="Contoh: Niat dan Doa Sholat Jenazah">
                </div>
                <div class="form-group">
                    <label for="ptg-reading">Teks bacaan / instruksi (opsional)</label>
                    <textarea id="ptg-reading" name="reading_text" maxlength="20000" placeholder="Tulis bacaan Arab, latin, atau instruksi tugas. Baris baru dipertahankan."><?= $e($form['reading_text'] ?? '') ?></textarea>
                    <?php if ($isEdit && $editing && pretesComponentHasBuiltinReading($editing['task_key'])): ?><div class="ptg-hint">Komponen ini punya bacaan bawaan. Bila kolom ini diisi, teks ini yang ditampilkan menggantikan bacaan bawaan.</div><?php endif; ?>
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
