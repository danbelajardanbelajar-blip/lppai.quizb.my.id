<?php
/**
 * Regression test: tombol & tab Bank Soal CBT (pretes-ujian.php?cbt_bank=1) harus bisa diklik.
 *
 * Akar masalah: assets/js/app.js (dimuat di footer SETELAH pretes-cbt-admin.js) mendeklarasikan
 * function global openModal()/closeModal() (memakai kelas .show), menimpa window.openModal milik
 * CBT sehingga seluruh modal (Buat Paket/Sesi, Tambah/Kelola Soal, Edit) tidak pernah terbuka.
 * Selain itu handler submit global mengunci tombol "Memproses..." meski confirm() dibatalkan.
 */
$root = dirname(__DIR__);
$js   = file_get_contents("$root/assets/js/pretes-cbt-admin.js");
$view = file_get_contents("$root/includes/pretes-cbt-admin-view.php");
$app  = file_get_contents("$root/assets/js/app.js");

$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };

// 1. CBT tidak boleh bergantung pada window.openModal/closeModal (bentrok dengan app.js)
$check(preg_match('/window\.(openModal|closeModal)\s*[=(]/', $js) === 0, 'pretes-cbt-admin.js tidak boleh mendefinisikan/memanggil window.openModal/closeModal');
$check(strpos($js, 'function cbtOpenModal(') !== false && strpos($js, 'function cbtCloseModal(') !== false, 'Helper unik cbtOpenModal/cbtCloseModal harus ada');
foreach (['modalPackageForm', 'modalSessionForm', 'modalPackageQuestions', 'modalQuestionForm'] as $id) {
    $check(strpos($js, "cbtOpenModal('$id')") !== false, "Modal $id harus dibuka lewat cbtOpenModal");
}
$check(preg_match('/\bopenModal\(|\bcloseModal\(/', preg_replace('/cbt(Open|Close)Modal\(/', '', $view)) === 0, 'View tidak boleh memanggil openModal/closeModal global');

// 2. Tombol data-action ditangani delegasi saja (tanpa onclick ganda → fetch/handler dobel)
preg_match_all('/<button[^\n]*data-action="(create-package|create-session|add-question|manage-questions)"[^\n]*/', $view, $m);
$check(count($m[0]) >= 7, 'Minimal 7 tombol data-action harus ada (ditemukan ' . count($m[0]) . ')');
foreach ($m[0] as $line) {
    $check(strpos($line, 'onclick=') === false, 'Tombol data-action tidak boleh punya onclick inline: ' . substr(trim($line), 0, 90));
}
foreach (['create-package', 'create-session', 'add-question', 'manage-questions'] as $act) {
    $check(strpos($js, "[data-action=\"$act\"]") !== false, "Delegasi klik untuk data-action=$act harus ada");
}

// 3. Submit yang dibatalkan (confirm → Batal) tidak boleh mengunci tombol
$check(preg_match("/addEventListener\('submit'[\s\S]{0,400}if \(e\.defaultPrevented\) return;/", $app) === 1, 'app.js harus mengabaikan submit yang sudah dibatalkan (e.defaultPrevented)');

// 4. Modal bisa di-scroll: backdrop dipindah ke <body> (lepas dari .content-area ber-transform)
//    dan <form> di dalam .bank-modal ikut menyusut sehingga .bank-modal-body bisa overflow-y:auto
$css = file_get_contents("$root/assets/css/pretes-cbt-admin.css");
$check(strpos($js, 'document.body.appendChild(el)') !== false, 'Backdrop modal harus dipindah ke <body> agar position:fixed mengacu viewport');
$check(strpos($css, '.bank-modal > form{display:flex;flex-direction:column;flex:1 1 auto;min-height:0') !== false, 'Form di dalam modal harus flex column min-height:0');
$check(preg_match('/\.bank-modal-body\{flex:1 1 auto;min-height:0;overflow-y:auto/', $css) === 1, 'Body modal harus flex:1, min-height:0, overflow-y:auto');

// 5. Tab navigasi berupa tautan biasa ke 4 tab
foreach (['packages', 'sessions', 'import', 'results'] as $tab) {
    $check(strpos($view, "'$tab' =>") !== false, "Tab $tab harus ada di navigasi");
}

if ($failures) {
    echo "FAIL:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "PASS: tombol & tab Bank Soal CBT dapat diklik (modal unik, tanpa handler ganda, submit batal tidak mengunci).\n";
