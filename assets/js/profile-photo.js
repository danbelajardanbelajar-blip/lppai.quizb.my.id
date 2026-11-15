(function () {
    'use strict';
    const form = document.getElementById('formUploadPhoto');
    if (!form) return;
    const file = document.getElementById('foto_profil');
    const data = document.getElementById('profilePhotoData');
    const submit = document.getElementById('profilePhotoSubmit');
    const status = document.getElementById('profilePhotoStatus');
    const video = document.getElementById('profilePhotoVideo');
    const capture = document.getElementById('profilePhotoCapture');
    const close = document.getElementById('profilePhotoStop');
    let revision = 0, cameraRevision = 0;
    file.required = false; // Camera captures are sent through foto_data instead.
    const notice = (text, error = false) => {
        status.textContent = text;
        status.style.color = error ? '#b91c1c' : '#475569';
    };
    function clearSelection() {
        revision++;
        data.value = '';
        submit.disabled = true;
        return revision;
    }
    function stopCamera() {
        cameraRevision++;
        if (window.PretesFaceVerifier) window.PretesFaceVerifier.stopCamera();
        video.hidden = capture.hidden = close.hidden = true;
    }
    async function processPhoto(image, mode, version) {
        try {
            const width = image.naturalWidth || image.videoWidth || image.width;
            const height = image.naturalHeight || image.videoHeight || image.height;
            if (width < 240 || height < 320 || width * height > 12000000) throw new Error('Gunakan foto minimal 240 × 320 piksel dan maksimal 12 megapiksel.');
            const canvas = document.createElement('canvas');
            canvas.width = 480; canvas.height = 640;
            const cropWidth = Math.min(width, Math.floor(height * 3 / 4));
            const cropHeight = Math.min(height, Math.floor(width * 4 / 3));
            const context = canvas.getContext('2d');
            context.fillStyle = '#fff'; context.fillRect(0, 0, 480, 640);
            context.drawImage(image, (width - cropWidth) / 2, (height - cropHeight) / 2, cropWidth, cropHeight, 0, 0, 480, 640);
            if (!window.PretesFaceVerifier) throw new Error('Pemeriksa wajah belum termuat. Muat ulang halaman.');
            notice('Memeriksa wajah dan kualitas foto…');
            const result = await window.PretesFaceVerifier.verifyFace(canvas, mode);
            if (version !== revision) return;
            if (!result.valid) throw new Error(result.reason || 'Wajah belum jelas. Gunakan foto menghadap depan dengan kedua mata terbuka.');
            data.value = canvas.toDataURL('image/jpeg', 0.9);
            const preview = document.getElementById('profileAvatarPreview');
            const img = new Image();
            img.src = data.value; img.alt = 'Pratinjau foto acuan ujian';
            img.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';
            preview.replaceChildren(img);
            submit.disabled = false;
            stopCamera();
            notice('Foto lolos pemeriksaan awal. Klik Unggah Foto untuk pemeriksaan akhir dan penyimpanan sebagai acuan ujian.');
        } catch (error) {
            if (version !== revision) return;
            data.value = ''; submit.disabled = true;
            notice(error.message || 'Foto tidak dapat diproses. Pilih ulang foto.', true);
        }
    }
    file.addEventListener('change', function () {
        stopCamera();
        const version = clearSelection();
        const selected = file.files[0];
        if (!selected) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(selected.type) || selected.size > 5 * 1024 * 1024) {
            notice('Gunakan JPG, PNG, atau WEBP maksimal 5 MB.', true); return;
        }
        const url = URL.createObjectURL(selected);
        const image = new Image();
        image.onload = () => { URL.revokeObjectURL(url); if (version === revision) processPhoto(image, 'upload', version); };
        image.onerror = () => { URL.revokeObjectURL(url); if (version === revision) notice('File gambar tidak dapat dibaca.', true); };
        image.src = url;
    });
    document.getElementById('profilePhotoCamera').addEventListener('click', function () {
        clearSelection(); file.value = '';
        if (!window.PretesFaceVerifier) { notice('Pemeriksa kamera belum termuat. Muat ulang halaman.', true); return; }
        const camera = ++cameraRevision;
        notice('Meminta izin kamera…');
        window.PretesFaceVerifier.startCamera(video, function () {
            if (camera !== cameraRevision) { window.PretesFaceVerifier.stopCamera(); return; }
            video.hidden = capture.hidden = close.hidden = false;
            notice('Hadapkan wajah lurus ke kamera, buka kedua mata, lalu klik Ambil Foto.');
        }, function () { if (camera === cameraRevision) notice('Kamera tidak dapat dibuka. Izinkan kamera atau gunakan unggah file.', true); });
    });
    capture.addEventListener('click', function () { processPhoto(video, 'camera', clearSelection()); });
    close.addEventListener('click', stopCamera);
    form.addEventListener('submit', function (event) {
        if (!data.value || submit.disabled) { event.preventDefault(); notice('Foto harus lolos pemeriksaan terlebih dahulu.', true); return; }
        submit.disabled = true;
        notice('Sedang memeriksa foto di server. Jangan tutup halaman…');
    });
    window.addEventListener('pagehide', stopCamera);
})();
