<?php
define('PAGE_TITLE', 'Profil Pengguna — Tampilan Admin');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); exit('Halaman ini hanya untuk melihat profil.'); }
require_once __DIR__ . '/../includes/admin-user-profile.php';
require_once __DIR__ . '/../includes/user-table-avatar.php';
$pdo = getDBConnection();
$profileUser = null;
$profileChoices = [];
if (isset($_GET['id'])) {
    $id = is_string($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if (!$id) { http_response_code(400); exit('ID pengguna tidak valid.'); }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $stmt->execute([$id]);
    $profileUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$profileUser) { http_response_code(404); exit('Pengguna tidak ditemukan.'); }
} elseif (isset($_GET['nama']) && is_string($_GET['nama']) && trim($_GET['nama']) !== '' && mb_strlen($_GET['nama'], 'UTF-8') <= 200) {
    $stmt = $pdo->prepare('SELECT id, nama_lengkap, nim, username, role, program_studi FROM users WHERE LOWER(TRIM(nama_lengkap)) = LOWER(?) ORDER BY role, id');
    $stmt->execute([trim($_GET['nama'])]);
    $profileChoices = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static fn($row) => adminProfileNameKey($row['nama_lengkap']) === adminProfileNameKey($_GET['nama'])));
    if (!$profileChoices) { http_response_code(404); exit('Pengguna tidak ditemukan.'); }
    if (count($profileChoices) === 1) {
        header('Location: ' . BASE_URL . '/admin/profil-pengguna.php?id=' . (int)$profileChoices[0]['id']); exit;
    }
} else { http_response_code(400); exit('Pilih pengguna dari nama pada tabel.'); }
$profileHistory = $profileUser ? adminProfileHistory($pdo, $profileUser) : null;
// Resolve only the viewed account's photo, without reading or modifying the admin session.
$profileIdentity = $profileUser;
if ($profileIdentity && empty($profileIdentity['foto'])) {
    foreach ($profileHistory['pretes'] as $registration) {
        if (!empty($registration['foto'])) { $profileIdentity['foto'] = $registration['foto']; break; }
    }
}
include __DIR__ . '/../includes/header.php';
?>
<style>
.admin-profile-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.admin-profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}.admin-profile-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.admin-profile-record{border-top:1px solid #e2e8f0;padding:16px 0;min-width:0}.admin-profile-record h3{overflow-wrap:anywhere}.admin-profile-record summary{cursor:pointer;color:#166534;font-weight:600;padding:8px 0}.admin-profile-fields dt{font-size:12px;color:#64748b;margin-bottom:5px}.admin-profile-fields dd{margin:0;font-weight:600;overflow-wrap:anywhere}
.admin-profile-identity .users-table-user-icon{width:88px!important;height:88px!important;flex-basis:88px!important}.admin-profile-identity .users-table-user-icon svg{width:44px;height:44px}
.admin-profile-identity .users-table-user-cell{font-size:22px;font-weight:800;gap:18px!important}.admin-profile-table{overflow-x:auto}.admin-profile-table table{min-width:600px;width:100%}
@media(max-width:700px){.admin-profile-grid,.admin-profile-fields{grid-template-columns:minmax(0,1fr)}.admin-profile-identity .users-table-user-cell{font-size:18px}}
.admin-profile-identity .users-table-user-icon{position:relative;user-select:none;-webkit-user-select:none;-webkit-touch-callout:none}
.admin-profile-identity .users-table-user-icon.has-photo{cursor:zoom-in;box-shadow:0 4px 14px rgba(0,0,0,0.12);border:3px solid #ffffff;transition:transform 0.2s ease,box-shadow 0.2s ease}
.admin-profile-identity .users-table-user-icon.has-photo:hover{transform:scale(1.05);box-shadow:0 8px 24px rgba(0,0,0,0.18)}
.admin-profile-identity .users-table-user-icon.has-photo::after{content:"🔍";position:absolute;bottom:2px;right:2px;width:26px;height:26px;background:#0f766e;color:#fff;border-radius:50%;border:2px solid #ffffff;display:flex;align-items:center;justify-content:center;font-size:13px;box-shadow:0 2px 6px rgba(0,0,0,0.25);pointer-events:none}
.admin-profile-identity .users-table-user-photo{pointer-events:none;user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;-webkit-user-drag:none}
.admin-pretes-photo-thumb{display:inline-block;position:relative;cursor:zoom-in;border-radius:6px;overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,0.08);transition:transform 0.2s ease}
.admin-pretes-photo-thumb:hover{transform:scale(1.06)}
.photo-zoom-modal{position:fixed;inset:0;z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(15,23,42,0.92);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);padding:16px;box-sizing:border-box;user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;opacity:0;pointer-events:none;transition:opacity 0.22s ease}
.photo-zoom-modal.active{opacity:1;pointer-events:auto}
.photo-zoom-backdrop{position:absolute;inset:0;background:transparent;cursor:pointer}
.photo-zoom-container{display:flex;flex-direction:column;width:100%;max-width:820px;max-height:92vh;background:#0f172a;border:1px solid rgba(255,255,255,0.12);border-radius:16px;box-shadow:0 25px 60px -15px rgba(0,0,0,0.7);overflow:hidden;position:relative;box-sizing:border-box}
.photo-zoom-header{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:#1e293b;border-bottom:1px solid rgba(255,255,255,0.08);gap:12px;flex-wrap:wrap;color:#f8fafc}
.photo-zoom-title{display:flex;align-items:center;gap:8px;font-weight:700;font-size:14.5px;color:#f1f5f9;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.photo-zoom-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.photo-zoom-btn{background:#334155;color:#f8fafc;border:1px solid #475569;border-radius:8px;padding:6px 12px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:4px;transition:all 0.15s ease;line-height:1.2}
.photo-zoom-btn:hover{background:#475569;border-color:#64748b;color:#ffffff}
.photo-zoom-btn:active{transform:scale(0.96)}
.photo-zoom-btn.photo-zoom-close{background:#dc2626;border-color:#b91c1c;color:#ffffff;margin-left:6px}
.photo-zoom-btn.photo-zoom-close:hover{background:#b91c1c}
.photo-zoom-level{font-size:12.5px;font-weight:800;color:#38bdf8;background:#0f172a;padding:5px 10px;border-radius:6px;border:1px solid #1e293b;min-width:48px;text-align:center}
.photo-zoom-viewport{flex:1;min-height:380px;max-height:68vh;display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;background:radial-gradient(circle,#1e293b 0%,#0f172a 100%);cursor:grab}
.photo-zoom-viewport.grabbing{cursor:grabbing}
.photo-zoom-stage{position:relative;display:inline-flex;align-items:center;justify-content:center;transform-origin:center center;transition:transform 0.12s ease-out;will-change:transform}
.photo-zoom-stage.no-transition{transition:none}
.photo-zoom-stage img{max-width:80vw;max-height:62vh;width:auto;height:auto;object-fit:contain;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.5);user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;-webkit-user-drag:none;pointer-events:none}
.photo-zoom-shield{position:absolute;inset:0;width:100%;height:100%;z-index:10;cursor:grab;background:transparent;user-select:none;-webkit-user-select:none;-webkit-touch-callout:none}
.photo-zoom-viewport.grabbing .photo-zoom-shield{cursor:grabbing}
.photo-zoom-footer{display:flex;align-items:center;justify-content:space-between;padding:10px 18px;background:#0b1120;border-top:1px solid rgba(255,255,255,0.08);font-size:12px;color:#94a3b8;flex-wrap:wrap;gap:8px}
.photo-zoom-protected-tag{font-weight:700;color:#ef4444;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);padding:2px 8px;border-radius:6px;font-size:11px}
@media (max-width:600px){.photo-zoom-header{flex-direction:column;align-items:stretch}.photo-zoom-actions{justify-content:space-between}.photo-zoom-viewport{min-height:280px}}
@media print{.photo-zoom-modal{display:none!important}}
</style>
<div class="admin-profile-head"><div><h2 style="margin:0;">Profil Pengguna</h2><p style="margin:6px 0 0;color:#64748b;">Tampilan khusus admin · Informasi akun dan aktivitas akademik</p></div><a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary" data-no-spa style="width:auto;">Kelola Pengguna</a></div>
<?php if (!$profileUser): ?>
<section class="card"><div class="card-header">Pilih akun yang dimaksud</div><div class="card-body">
    <p>Beberapa akun memiliki nama yang sama. Pilih berdasarkan NIM, username, atau peran.</p>
    <div class="admin-profile-table"><table><thead><tr><th>Nama</th><th>NIM / Username</th><th>Peran</th><th>Program Studi</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($profileChoices as $choice): ?>
    <tr><td><?= adminUserProfileLink($choice['nama_lengkap'], $choice['id']) ?></td><td><?= sanitize($choice['nim'] ?: $choice['username']) ?></td><td><?= sanitize(ucfirst($choice['role'])) ?></td><td><?= sanitize($choice['program_studi'] ?? '-') ?></td><td><a class="btn btn-sm btn-primary" data-no-spa href="<?= BASE_URL ?>/admin/profil-pengguna.php?id=<?= (int)$choice['id'] ?>">Lihat Profil</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div></section>
<?php else: ?>
<section class="card"><div class="card-body admin-profile-identity">
    <?= renderUserTableName($profileIdentity) ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;"><span class="badge badge-info"><?= sanitize(ucfirst($profileUser['role'])) ?></span><span class="badge <?= !empty($profileUser['is_active']) ? 'badge-success' : 'badge-danger' ?>"><?= !empty($profileUser['is_active']) ? 'Akun Aktif' : 'Akun Nonaktif' ?></span></div>
</div></section>
<?php if ($profileHistory['errors']): ?>
<section class="card" role="status"><div class="card-header">Sebagian Informasi Belum Tersedia</div><div class="card-body"><ul>
<?php foreach ($profileHistory['errors'] as $message): ?><li><?= sanitize($message) ?></li><?php endforeach; ?>
</ul></div></section>
<?php endif; ?>
<div class="admin-profile-grid">
<?php foreach ([
    'Informasi Akun & Akademik' => ['id'=>'ID Pengguna','username'=>'Username','nim'=>'NIM / NIDN','jenis_program'=>'Jenis Program','program_studi'=>'Program Studi','fakultas'=>'Fakultas','angkatan'=>'Angkatan'],
    'Kontak & Data Pribadi' => ['email'=>'Email','no_hp'=>'Nomor HP / WhatsApp','tempat_lahir'=>'Tempat Lahir','tanggal_lahir'=>'Tanggal Lahir','created_at'=>'Akun Dibuat','updated_at'=>'Terakhir Diperbarui'],
] as $title => $fields): ?>
<section class="card"><div class="card-header"><?= sanitize($title) ?></div><div class="card-body"><dl class="admin-profile-fields">
    <?php foreach ($fields as $field => $label): ?><div><dt><?= sanitize($label) ?></dt><dd><?= sanitize((string)($profileUser[$field] ?? '') !== '' ? (string)$profileUser[$field] : '-') ?></dd></div><?php endforeach; ?>
</dl></div></section>
<?php endforeach; ?>
</div>
<?php if ($profileUser['role'] === 'mahasiswa'): ?>
<?php include __DIR__ . '/../includes/admin-profile-academic-view.php'; ?>
<?php elseif ($profileUser['role'] === 'dosen'): ?>
<section class="card"><div class="card-header">Kelas Tutorial yang Diampu</div><div class="card-body">
<?php if ($profileHistory['ambiguous_teacher']): ?><p>Ada akun dosen lain dengan nama sama. Jadwal tidak ditampilkan agar tidak tertukar.</p>
<?php elseif (!$profileHistory['teaching']): ?><p>Belum ada kelas tutorial yang tersedia.</p>
<?php else: ?><p style="font-size:12px;color:#64748b;">Seluruh kelas yang diampu.</p><div class="admin-profile-table"><table><thead><tr><th>Kelas</th><th>Semester / Gelombang</th><th>Hari</th><th>Jam</th><th>Ruangan</th></tr></thead><tbody>
<?php foreach ($profileHistory['teaching'] as $row): ?><tr><td><?= sanitize($row['nama_kelas']) ?></td><td><?= sanitize($row['semester'] . ' / ' . $row['gelombang']) ?></td><td><?= sanitize($row['hari'] ?: '-') ?></td><td><?= sanitize($row['jam'] ?: '-') ?></td><td><?= sanitize($row['ruangan'] ?: '-') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>
<?php endif; ?>
<?php endif; ?>
<div id="admin-photo-zoom-modal" class="photo-zoom-modal" style="display:none;" role="dialog" aria-modal="true" aria-label="Perbesar Foto">
    <div class="photo-zoom-backdrop" onclick="closePhotoZoom()"></div>
    <div class="photo-zoom-container">
        <div class="photo-zoom-header">
            <div class="photo-zoom-title">
                <span style="font-size:18px;">🔍</span>
                <span id="photo-zoom-caption"><?= sanitize($profileUser['nama_lengkap'] ?? 'Foto Pengguna') ?></span>
            </div>
            <div class="photo-zoom-actions">
                <button type="button" class="photo-zoom-btn" id="photo-zoom-out" title="Perkecil (-)" aria-label="Perkecil">➖</button>
                <span class="photo-zoom-level" id="photo-zoom-level">100%</span>
                <button type="button" class="photo-zoom-btn" id="photo-zoom-in" title="Perbesar (+)" aria-label="Perbesar">➕</button>
                <button type="button" class="photo-zoom-btn" id="photo-zoom-reset" title="Kembalikan Ukuran (100%)" aria-label="Reset Zoom">🔄 Reset</button>
                <button type="button" class="photo-zoom-btn photo-zoom-close" onclick="closePhotoZoom()" title="Tutup (Esc)" aria-label="Tutup">✕ Tutup</button>
            </div>
        </div>
        <div class="photo-zoom-viewport" id="photo-zoom-viewport">
            <div class="photo-zoom-stage" id="photo-zoom-stage">
                <img id="photo-zoom-img" src="" alt="Foto Pengguna" draggable="false">
                <div class="photo-zoom-shield" id="photo-zoom-shield" title="Foto dilindungi — klik dan geser untuk menggeser, klik dua kali untuk zoom"></div>
            </div>
        </div>
        <div class="photo-zoom-footer">
            <span>💡 Putar scroll mouse atau gunakan tombol untuk zoom. Klik &amp; geser untuk menggeser foto saat diperbesar.</span>
            <span class="photo-zoom-protected-tag">🔒 Klik Kanan Dinonaktifkan</span>
        </div>
    </div>
</div>

<script>
(function() {
    var modal = document.getElementById('admin-photo-zoom-modal');
    var zoomImg = document.getElementById('photo-zoom-img');
    var zoomStage = document.getElementById('photo-zoom-stage');
    var zoomViewport = document.getElementById('photo-zoom-viewport');
    var zoomShield = document.getElementById('photo-zoom-shield');
    var zoomLevel = document.getElementById('photo-zoom-level');
    var caption = document.getElementById('photo-zoom-caption');

    var scale = 1.0;
    var posX = 0;
    var posY = 0;
    var isDragging = false;
    var startX = 0;
    var startY = 0;

    function updateTransform(withTransition) {
        if (!zoomStage) return;
        if (withTransition) {
            zoomStage.classList.remove('no-transition');
        } else {
            zoomStage.classList.add('no-transition');
        }
        zoomStage.style.transform = 'translate(' + posX + 'px, ' + posY + 'px) scale(' + scale + ')';
        if (zoomLevel) zoomLevel.textContent = Math.round(scale * 100) + '%';
        if (scale > 1.0) {
            if (zoomViewport) zoomViewport.style.cursor = 'grab';
            if (zoomShield) zoomShield.style.cursor = 'grab';
        } else {
            if (zoomViewport) zoomViewport.style.cursor = 'default';
            if (zoomShield) zoomShield.style.cursor = 'default';
        }
    }

    function setZoom(newScale, withTransition) {
        scale = Math.min(Math.max(newScale, 0.5), 4.0);
        if (scale <= 1.0) {
            posX = 0;
            posY = 0;
        }
        updateTransform(withTransition !== false);
    }

    function zoomIn() {
        setZoom(scale + 0.25, true);
    }

    function zoomOut() {
        setZoom(scale - 0.25, true);
    }

    function resetZoom() {
        posX = 0;
        posY = 0;
        setZoom(1.0, true);
    }

    window.openPhotoZoom = function(src, name) {
        if (!src || !modal) return;
        if (zoomImg) zoomImg.src = src;
        if (name && caption) {
            caption.textContent = name;
        }
        resetZoom();
        modal.style.display = 'flex';
        void modal.offsetHeight;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    };

    window.closePhotoZoom = function() {
        if (!modal) return;
        modal.classList.remove('active');
        setTimeout(function() {
            modal.style.display = 'none';
            if (zoomImg) zoomImg.src = '';
        }, 220);
        document.body.style.overflow = '';
    };

    var btnIn = document.getElementById('photo-zoom-in');
    var btnOut = document.getElementById('photo-zoom-out');
    var btnReset = document.getElementById('photo-zoom-reset');
    if (btnIn) btnIn.addEventListener('click', zoomIn);
    if (btnOut) btnOut.addEventListener('click', zoomOut);
    if (btnReset) btnReset.addEventListener('click', resetZoom);

    if (zoomViewport) {
        zoomViewport.addEventListener('wheel', function(e) {
            e.preventDefault();
            if (e.deltaY < 0) {
                setZoom(scale + 0.15, true);
            } else {
                setZoom(scale - 0.15, true);
            }
        }, { passive: false });
    }

    if (zoomShield) {
        zoomShield.addEventListener('dblclick', function(e) {
            e.preventDefault();
            if (scale > 1.0) {
                resetZoom();
            } else {
                setZoom(2.0, true);
            }
        });

        zoomShield.addEventListener('mousedown', function(e) {
            if (e.button === 0 && scale > 1.0) {
                isDragging = true;
                startX = e.clientX - posX;
                startY = e.clientY - posY;
                zoomViewport.classList.add('grabbing');
                zoomStage.classList.add('no-transition');
            }
        });

        zoomShield.addEventListener('touchstart', function(e) {
            if (e.touches.length === 1 && scale > 1.0) {
                isDragging = true;
                startX = e.touches[0].clientX - posX;
                startY = e.touches[0].clientY - posY;
                zoomViewport.classList.add('grabbing');
                zoomStage.classList.add('no-transition');
            }
        }, { passive: true });
    }

    window.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        posX = e.clientX - startX;
        posY = e.clientY - startY;
        updateTransform(false);
    });

    window.addEventListener('mouseup', function() {
        if (!isDragging) return;
        isDragging = false;
        if (zoomViewport) zoomViewport.classList.remove('grabbing');
        if (zoomStage) zoomStage.classList.remove('no-transition');
    });

    window.addEventListener('touchmove', function(e) {
        if (!isDragging || e.touches.length !== 1) return;
        posX = e.touches[0].clientX - startX;
        posY = e.touches[0].clientY - startY;
        updateTransform(false);
    }, { passive: true });

    window.addEventListener('touchend', function() {
        if (!isDragging) return;
        isDragging = false;
        if (zoomViewport) zoomViewport.classList.remove('grabbing');
        if (zoomStage) zoomStage.classList.remove('no-transition');
    });

    // Anti Right-Click and Anti-Save protections
    [modal, zoomViewport, zoomStage, zoomShield].forEach(function(el) {
        if (el) {
            el.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            });
            el.addEventListener('dragstart', function(e) {
                e.preventDefault();
                return false;
            });
        }
    });

    window.addEventListener('keydown', function(e) {
        if (!modal || !modal.classList.contains('active')) return;
        if (e.key === 'Escape') {
            closePhotoZoom();
            return;
        }
        if (e.key === '+' || e.key === '=') {
            zoomIn();
            return;
        }
        if (e.key === '-' || e.key === '_') {
            zoomOut();
            return;
        }
        if (e.key === '0') {
            resetZoom();
            return;
        }
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S' || e.key === 'p' || e.key === 'P')) {
            e.preventDefault();
            return false;
        }
    });

    function attachPhotoClickers() {
        var icons = document.querySelectorAll('.admin-profile-identity .users-table-user-icon');
        icons.forEach(function(icon) {
            var img = icon.querySelector('img.users-table-user-photo');
            if (img && img.src) {
                icon.classList.add('has-photo');
                icon.setAttribute('title', '🔍 Klik untuk memperbesar foto');
                icon.addEventListener('click', function(e) {
                    e.preventDefault();
                    var nameCell = icon.closest('.users-table-user-cell');
                    var name = nameCell ? (nameCell.textContent || '').trim() : 'Foto Pengguna';
                    openPhotoZoom(img.src, name);
                });
                icon.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                });
                icon.addEventListener('dragstart', function(e) {
                    e.preventDefault();
                    return false;
                });
            }
        });

        // Also protect and bind any thumbnail photos (e.g. pretes pasfoto)
        document.querySelectorAll('.admin-pretes-photo-thumb').forEach(function(thumb) {
            thumb.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            });
            thumb.addEventListener('dragstart', function(e) {
                e.preventDefault();
                return false;
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachPhotoClickers);
    } else {
        attachPhotoClickers();
    }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
