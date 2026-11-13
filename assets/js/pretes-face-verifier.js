/**
 * LPPAI Corner - Pretes Face Verifier & Live Camera Handler
 * Modul deteksi dan verifikasi wajah otomatis untuk pendaftaran pretes resmi.
 * Didukung oleh Pico Machine Learning Face Detection (Decision Tree Classifier)
 * dan Multi-Layer Heuristic Facial Landmark & Texture Analyzer.
 */
(function(window) {
    'use strict';

    // ── 1. Embedded Pico Face Detection Engine ────────────────────────────────
    var pico = {};

    pico.unpack_cascade = function(bytes) {
        var dview = new DataView(new ArrayBuffer(4));
        var p = 8;
        dview.setUint8(0, bytes[p+0]); dview.setUint8(1, bytes[p+1]); dview.setUint8(2, bytes[p+2]); dview.setUint8(3, bytes[p+3]);
        var tdepth = dview.getInt32(0, true);
        p = p + 4;
        dview.setUint8(0, bytes[p+0]); dview.setUint8(1, bytes[p+1]); dview.setUint8(2, bytes[p+2]); dview.setUint8(3, bytes[p+3]);
        var ntrees = dview.getInt32(0, true);
        p = p + 4;
        var tcodes_ls = [];
        var tpreds_ls = [];
        var thresh_ls = [];
        for (var t = 0; t < ntrees; ++t) {
            Array.prototype.push.apply(tcodes_ls, [0, 0, 0, 0]);
            Array.prototype.push.apply(tcodes_ls, bytes.slice(p, p + 4 * Math.pow(2, tdepth) - 4));
            p = p + 4 * Math.pow(2, tdepth) - 4;
            for (var i = 0; i < Math.pow(2, tdepth); ++i) {
                dview.setUint8(0, bytes[p+0]); dview.setUint8(1, bytes[p+1]); dview.setUint8(2, bytes[p+2]); dview.setUint8(3, bytes[p+3]);
                tpreds_ls.push(dview.getFloat32(0, true));
                p = p + 4;
            }
            dview.setUint8(0, bytes[p+0]); dview.setUint8(1, bytes[p+1]); dview.setUint8(2, bytes[p+2]); dview.setUint8(3, bytes[p+3]);
            thresh_ls.push(dview.getFloat32(0, true));
            p = p + 4;
        }
        var tcodes = new Int8Array(tcodes_ls);
        var tpreds = new Float32Array(tpreds_ls);
        var thresh = new Float32Array(thresh_ls);

        return function classify_region(r, c, s, pixels, ldim) {
            r = 256 * r;
            c = 256 * c;
            var root = 0;
            var o = 0.0;
            var pow2tdepth = Math.pow(2, tdepth) >> 0;

            for (var i = 0; i < ntrees; ++i) {
                var idx = 1;
                for (var j = 0; j < tdepth; ++j) {
                    idx = 2 * idx + (pixels[((r + tcodes[root + 4 * idx + 0] * s) >> 8) * ldim + ((c + tcodes[root + 4 * idx + 1] * s) >> 8)] <= pixels[((r + tcodes[root + 4 * idx + 2] * s) >> 8) * ldim + ((c + tcodes[root + 4 * idx + 3] * s) >> 8)]);
                }
                o = o + tpreds[pow2tdepth * i + idx - pow2tdepth];
                if (o <= thresh[i]) return -1;
                root += 4 * pow2tdepth;
            }
            return o - thresh[ntrees - 1];
        };
    };

    pico.run_cascade = function(image, classify_region, params) {
        var pixels = image.pixels;
        var nrows = image.nrows;
        var ncols = image.ncols;
        var ldim = image.ldim;
        var shiftfactor = params.shiftfactor;
        var minsize = params.minsize;
        var maxsize = params.maxsize;
        var scalefactor = params.scalefactor;
        var scale = minsize;
        var detections = [];

        while (scale <= maxsize) {
            var step = Math.max(shiftfactor * scale, 1) >> 0;
            var offset = (scale / 2 + 1) >> 0;
            for (var r = offset; r <= nrows - offset; r += step) {
                for (var c = offset; c <= ncols - offset; c += step) {
                    var q = classify_region(r, c, scale, pixels, ldim);
                    if (q > 0.0) {
                        detections.push([r, c, scale, q]);
                    }
                }
            }
            scale = scale * scalefactor;
        }
        return detections;
    };

    pico.cluster_detections = function(dets, iouthreshold) {
        dets = dets.sort(function(a, b) { return b[3] - a[3]; });
        function calculate_iou(det1, det2) {
            var r1 = det1[0], c1 = det1[1], s1 = det1[2];
            var r2 = det2[0], c2 = det2[1], s2 = det2[2];
            var overr = Math.max(0, Math.min(r1 + s1 / 2, r2 + s2/2) - Math.max(r1 - s1 / 2, r2 - s2 / 2));
            var overc = Math.max(0, Math.min(c1 + s1 / 2, c2 + s2/2) - Math.max(c1 - s1 / 2, c2 - s2 / 2));
            return (overr * overc) / (s1 * s1 + s2 * s2 - overr * overc);
        }
        var assignments = new Array(dets.length).fill(0);
        var clusters = [];
        for (var i = 0; i < dets.length; ++i) {
            if (assignments[i] === 0) {
                var r = 0.0, c = 0.0, s = 0.0, q = 0.0, n = 0;
                for (var j = i; j < dets.length; ++j) {
                    if (calculate_iou(dets[i], dets[j]) > iouthreshold) {
                        assignments[j] = 1;
                        r += dets[j][0];
                        c += dets[j][1];
                        s += dets[j][2];
                        q += dets[j][3];
                        n += 1;
                    }
                }
                clusters.push([r / n, c / n, s / n, q]);
            }
        }
        return clusters;
    };

    // ── 2. PretesFaceVerifier Object ──────────────────────────────────────────
    var PretesFaceVerifier = {
        stream: null,
        facingMode: 'user',
        cascadeClassifier: null,
        isCascadeLoading: false,

        /**
         * Inisialisasi otomatis model facefinder cascade binary
         */
        initCascade: function() {
            var self = this;
            if (self.cascadeClassifier || self.isCascadeLoading) return;
            self.isCascadeLoading = true;

            var scriptEl = document.currentScript || document.querySelector('script[src*="pretes-face-verifier"]');
            var basePath = '';
            if (scriptEl && scriptEl.src) {
                var cleanSrc = scriptEl.src.split('?')[0];
                basePath = cleanSrc.substring(0, cleanSrc.lastIndexOf('/'));
            }
            var cascadeUrl = (basePath || '') + '/facefinder.bin';
            if (!basePath && typeof window.BASE_URL !== 'undefined') {
                cascadeUrl = window.BASE_URL + '/assets/js/facefinder.bin';
            }

            if (typeof fetch === 'function') {
                fetch(cascadeUrl)
                    .then(function(res) {
                        if (!res.ok) throw new Error("HTTP " + res.status);
                        return res.arrayBuffer();
                    })
                    .then(function(buffer) {
                        var bytes = new Int8Array(buffer);
                        self.cascadeClassifier = pico.unpack_cascade(bytes);
                        self.isCascadeLoading = false;
                    })
                    .catch(function(err) {
                        self.isCascadeLoading = false;
                        console.warn("[PretesFaceVerifier] Pico cascade load warning:", err);
                    });
            }
        },

        /**
         * Tunggu hingga classifier siap dengan batas toleransi timeout
         */
        ensureClassifierReady: function(timeoutMs) {
            var self = this;
            return new Promise(function(resolve) {
                if (self.cascadeClassifier) {
                    resolve(self.cascadeClassifier);
                    return;
                }
                var start = Date.now();
                var timer = setInterval(function() {
                    if (self.cascadeClassifier) {
                        clearInterval(timer);
                        resolve(self.cascadeClassifier);
                    } else if (Date.now() - start > (timeoutMs || 800)) {
                        clearInterval(timer);
                        resolve(null);
                    }
                }, 40);
            });
        },

        /**
         * Mengakses kamera perangkat mahasiswa
         */
        startCamera: function(videoEl, onStarted, onError) {
            var self = this;
            if (self.stream) {
                self.stopCamera();
            }

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                if (onError) onError(new Error("Perangkat atau peramban Anda tidak mendukung akses kamera langsung. Silakan gunakan opsi Unggah File Foto."));
                return;
            }

            var constraints = {
                audio: false,
                video: {
                    facingMode: self.facingMode,
                    width: { ideal: 640 },
                    height: { ideal: 480 }
                }
            };

            navigator.mediaDevices.getUserMedia(constraints)
                .then(function(mediaStream) {
                    self.stream = mediaStream;
                    videoEl.srcObject = mediaStream;
                    videoEl.setAttribute('playsinline', 'true');
                    videoEl.play();
                    if (onStarted) onStarted(mediaStream);
                })
                .catch(function(err) {
                    if (self.facingMode === 'user') {
                        navigator.mediaDevices.getUserMedia({ audio: false, video: true })
                            .then(function(mediaStream) {
                                self.stream = mediaStream;
                                videoEl.srcObject = mediaStream;
                                videoEl.play();
                                if (onStarted) onStarted(mediaStream);
                            })
                            .catch(function(err2) {
                                if (onError) onError(err2);
                            });
                    } else {
                        if (onError) onError(err);
                    }
                });
        },

        /**
         * Menghentikan video stream kamera
         */
        stopCamera: function() {
            if (this.stream) {
                try {
                    this.stream.getTracks().forEach(function(track) {
                        track.stop();
                    });
                } catch(e) {}
                this.stream = null;
            }
        },

        /**
         * Berganti kamera depan / belakang
         */
        toggleFacingMode: function(videoEl, onStarted, onError) {
            this.facingMode = (this.facingMode === 'user') ? 'environment' : 'user';
            this.startCamera(videoEl, onStarted, onError);
        },

        /**
         * Mengambil snapshot dari video ke canvas dengan aspek rasio 3:4 portrait pasfoto
         */
        captureSnapshot: function(videoEl) {
            var vw = videoEl.videoWidth || 640;
            var vh = videoEl.videoHeight || 480;

            var targetRatio = 3 / 4;
            var cropW = vw;
            var cropH = vh;
            var startX = 0;
            var startY = 0;

            if (vw / vh > targetRatio) {
                cropW = Math.round(vh * targetRatio);
                startX = Math.round((vw - cropW) / 2);
            } else {
                cropH = Math.round(vw / targetRatio);
                startY = Math.round((vh - cropH) / 2);
            }

            var canvas = document.createElement('canvas');
            canvas.width = 480;
            canvas.height = 640;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(videoEl, startX, startY, cropW, cropH, 0, 0, canvas.width, canvas.height);
            return canvas;
        },

        /**
         * Verifikasi Keterbukaan Mata (Eye Openness & Anti-Blink Detector)
         * Memeriksa kontras pupil/iris dan sklera mata untuk memastikan mata tidak terpejam/berkedip.
         */
        checkEyeOpenness: function(pixels, width, height, r, c, s) {
            var eyeY = Math.round(r + 0.29 * s);
            var pupilDist = Math.round(0.14 * s);
            var leftEyeX = Math.round(c - pupilDist);
            var rightEyeX = Math.round(c + pupilDist);

            var boxRadX = Math.max(3, Math.round(s * 0.08));
            var boxRadY = Math.max(3, Math.round(s * 0.05));

            var skinSum = 0, skinCount = 0;
            var cheekY1 = Math.min(height - 1, Math.round(r + 0.65 * s));
            var cheekY2 = Math.min(height - 1, Math.round(r + 0.85 * s));
            var cheekX1 = Math.max(0, Math.round(c - 0.20 * s));
            var cheekX2 = Math.min(width - 1, Math.round(c + 0.20 * s));
            for (var y = cheekY1; y <= cheekY2; y++) {
                for (var x = cheekX1; x <= cheekX2; x++) {
                    skinSum += pixels[y * width + x];
                    skinCount++;
                }
            }
            var skinLum = skinCount > 0 ? (skinSum / skinCount) : 150;
            var pupilThreshold = Math.max(30, skinLum - 25);

            function inspectEye(centerX, centerY) {
                var minVal = 255, maxVal = 0, darkCount = 0, totalCount = 0;
                for (var iy = Math.max(0, centerY - boxRadY); iy <= Math.min(height - 1, centerY + boxRadY); iy++) {
                    for (var ix = Math.max(0, centerX - boxRadX); ix <= Math.min(width - 1, centerX + boxRadX); ix++) {
                        var val = pixels[iy * width + ix];
                        if (val < minVal) minVal = val;
                        if (val > maxVal) maxVal = val;
                        totalCount++;
                        if (val <= pupilThreshold) {
                            darkCount++;
                        }
                    }
                }
                var contrast = maxVal - minVal;
                var darkRatio = totalCount > 0 ? (darkCount / totalCount) : 0;
                var isOpen = (contrast >= 28 && (darkRatio >= 0.05 || minVal <= skinLum - 30));
                return {
                    contrast: contrast,
                    darkRatio: darkRatio,
                    isOpen: isOpen
                };
            }

            var left = inspectEye(leftEyeX, eyeY);
            var right = inspectEye(rightEyeX, eyeY);

            var eyesOpen = (left.isOpen && right.isOpen) || 
                           ((left.isOpen || right.isOpen) && (left.contrast + right.contrast >= 65));

            return {
                isOpen: eyesOpen,
                leftEye: left,
                rightEye: right
            };
        },

        /**
         * Engine utama verifikasi wajah:
         * Menggunakan Pico ML Facefinder sebagai verifikator utama,
         * native FaceDetector sebagai sekunder, dan structural texture check sebagai fallback.
         */
        verifyFace: function(canvasOrImage, mode) {
            var self = this;
            mode = mode || 'upload';

            return new Promise(function(resolve) {
                try {
                    var origW = canvasOrImage.width || canvasOrImage.naturalWidth || 480;
                    var origH = canvasOrImage.height || canvasOrImage.naturalHeight || 640;

                    // Buat canvas sampling 240x320 untuk analisis real-time
                    var sampleW = 240;
                    var sampleH = 320;
                    var sampleCanvas = document.createElement('canvas');
                    sampleCanvas.width = sampleW;
                    sampleCanvas.height = sampleH;
                    var sCtx = sampleCanvas.getContext('2d');
                    sCtx.drawImage(canvasOrImage, 0, 0, sampleW, sampleH);

                    var imgData;
                    try {
                        imgData = sCtx.getImageData(0, 0, sampleW, sampleH);
                    } catch(e) {
                        resolve(self.analyzeCanvasFaceFallback(canvasOrImage, origW, origH, mode));
                        return;
                    }

                    var pixels = imgData.data;

                    // 1. Evaluasi Pencahayaan (Luminansi) Terhadap Kamera Gelap / Silau
                    var totalLum = 0;
                    var gray = new Uint8Array(sampleW * sampleH);
                    for (var r = 0; r < sampleH; ++r) {
                        for (var c = 0; c < sampleW; ++c) {
                            var pIdx = (r * sampleW + c) * 4;
                            var lum = Math.round(0.299 * pixels[pIdx] + 0.587 * pixels[pIdx + 1] + 0.114 * pixels[pIdx + 2]);
                            gray[r * sampleW + c] = lum;
                            totalLum += lum;
                        }
                    }
                    var avgLum = totalLum / (sampleW * sampleH);

                    if (avgLum < 18) {
                        resolve({
                            valid: false,
                            faces: [],
                            reason: "Pencahayaan foto terlalu gelap (kamera tertutup atau ruangan minim cahaya). Pastikan pencahayaan cukup terang."
                        });
                        return;
                    }
                    if (avgLum > 252) {
                        resolve({
                            valid: false,
                            faces: [],
                            reason: "Pencahayaan foto terlalu silau (overexposed). Harap kurangi cahaya langsung ke arah lensa kamera."
                        });
                        return;
                    }

                    // 2. Jalankan Pico ML Facefinder Classifier
                    self.ensureClassifierReady(750).then(function(classifier) {
                        if (classifier) {
                            var image = {
                                pixels: gray,
                                nrows: sampleH,
                                ncols: sampleW,
                                ldim: sampleW
                            };
                            var params = {
                                shiftfactor: 0.1,
                                minsize: 20,
                                maxsize: 240,
                                scalefactor: 1.1
                            };
                            var rawDets = pico.run_cascade(image, classifier, params);
                            var dets = pico.cluster_detections(rawDets, 0.2);
                            // Standar confidence threshold pico: > 5.0
                            var confidentFaces = dets.filter(function(d) { return d[3] > 5.0; });

                            if (confidentFaces.length === 0) {
                                resolve({
                                    valid: false,
                                    faces: [],
                                    reason: "Wajah tidak terdeteksi pada foto. Pastikan Anda berada tepat di depan kamera dan seluruh bagian wajah terlihat jelas."
                                });
                                return;
                            }

                            if (confidentFaces.length > 1) {
                                resolve({
                                    valid: false,
                                    faces: confidentFaces,
                                    reason: "Terdeteksi lebih dari satu wajah (" + confidentFaces.length + " orang). Pasfoto resmi wajib foto perorangan (sendiri)."
                                });
                                return;
                            }

                            // 1 Wajah Terdeteksi Valid
                            var best = confidentFaces[0];
                            var br = best[0], bc = best[1], bs = best[2], bScore = best[3];
                            var scaleX = origW / sampleW;
                            var scaleY = origH / sampleH;

                            var detectedBox = {
                                x: Math.round(Math.max(0, (bc - bs * 0.55) * scaleX)),
                                y: Math.round(Math.max(0, (br - bs * 0.65) * scaleY)),
                                width: Math.round(bs * 1.1 * scaleX),
                                height: Math.round(bs * 1.3 * scaleY)
                            };

                            // Evaluasi proporsi dan posisi
                            var centerX = (detectedBox.x + detectedBox.width / 2) / origW;
                            var widthRatio = detectedBox.width / origW;

                            if (mode === 'upload') {
                                if (centerX < 0.12 || centerX > 0.88) {
                                    resolve({
                                        valid: false,
                                        faces: [detectedBox],
                                        reason: "Posisi wajah terlalu ke tepi. Posisikan wajah tepat di tengah bidang pasfoto."
                                    });
                                    return;
                                }
                                if (widthRatio < 0.12) {
                                    resolve({
                                        valid: false,
                                        faces: [detectedBox],
                                        reason: "Ukuran wajah terlalu kecil pada foto. Harap unggah pasfoto dari jarak dada ke atas."
                                    });
                                    return;
                                }
                            }

                            // Validasi Keterbukaan Mata (Anti-Blink Check)
                            var eyeCheck = self.checkEyeOpenness(gray, sampleW, sampleH, br, bc, bs);
                            if (!eyeCheck.isOpen) {
                                resolve({
                                    valid: false,
                                    faces: [detectedBox],
                                    reason: mode === 'camera'
                                        ? "Mata Anda terpejam/tertutup saat pengambilan foto. Harap buka kedua mata dan menghadap lurus ke kamera."
                                        : "Mata terpejam/tertutup pada pasfoto. Harap gunakan pasfoto dengan kedua mata terbuka jelas menghadap lurus ke kamera."
                                });
                                return;
                            }

                            var successMsg = mode === 'camera'
                                ? "Foto kamera berhasil diverifikasi! Wajah tampak jelas dan proporsional di dalam panduan."
                                : "Pasfoto resmi valid! Seluruh bagian wajah terdeteksi jelas dan proporsional.";

                            resolve({
                                valid: true,
                                faces: [detectedBox],
                                box: detectedBox,
                                score: bScore,
                                message: successMsg
                            });
                            return;
                        }

                        // 3. Jika Classifier Belum Siap: Coba Native Browser FaceDetector
                        if ('FaceDetector' in window) {
                            try {
                                var detector = new window.FaceDetector({ fastMode: true, maxDetectedFaces: 5 });
                                detector.detect(canvasOrImage)
                                    .then(function(faces) {
                                        if (!faces || faces.length === 0) {
                                            resolve({
                                                valid: false,
                                                faces: [],
                                                reason: "Wajah tidak terdeteksi pada foto. Pastikan Anda berada tepat di depan kamera dan seluruh bagian wajah terlihat jelas."
                                            });
                                            return;
                                        }
                                        if (faces.length > 1) {
                                            resolve({
                                                valid: false,
                                                faces: faces,
                                                reason: "Terdeteksi lebih dari satu wajah (" + faces.length + " orang). Pasfoto resmi wajib foto perorangan (sendiri)."
                                            });
                                            return;
                                        }
                                        var b = faces[0].boundingBox;
                                        var fBox = { x: b.x, y: b.y, width: b.width, height: b.height };
                                        resolve({
                                            valid: true,
                                            faces: [fBox],
                                            box: fBox,
                                            message: "Foto resmi valid! Seluruh bagian wajah terdeteksi jelas dan proporsional."
                                        });
                                    })
                                    .catch(function() {
                                        resolve(self.analyzeCanvasFaceFallback(canvasOrImage, origW, origH, mode));
                                    });
                                return;
                            } catch(e) {}
                        }

                        // 4. Fallback Analisis Struktural & Tekstur
                        resolve(self.analyzeCanvasFaceFallback(canvasOrImage, origW, origH, mode));
                    });
                } catch(outerErr) {
                    resolve(self.analyzeCanvasFaceFallback(canvasOrImage, origW, origH, mode));
                }
            });
        },

        /**
         * Fallback Heuristik: Analisis Warna Kulit + Struktur Tekstur Wajah
         * Secara ketat menolak dinding polos, lantai, langit-langit, atau ruangan kosong tanpa wajah.
         */
        analyzeCanvasFaceFallback: function(canvasOrImg, origW, origH, mode) {
            mode = mode || 'upload';
            var sampleW = 240;
            var sampleH = 320;
            var canvas = document.createElement('canvas');
            canvas.width = sampleW;
            canvas.height = sampleH;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(canvasOrImg, 0, 0, sampleW, sampleH);

            var imgData;
            try {
                imgData = ctx.getImageData(0, 0, sampleW, sampleH);
            } catch(e) {
                return {
                    valid: false,
                    faces: [],
                    reason: "Wajah tidak dapat diverifikasi dari perangkat. Silakan coba kembali."
                };
            }

            var pixels = imgData.data;
            var totalPixels = sampleW * sampleH;
            var skinPixelCount = 0;
            var colCounts = new Uint16Array(sampleW);
            var rowCounts = new Uint16Array(sampleH);

            var lumArray = new Uint8Array(totalPixels);
            var sumLum = 0, sumSqLum = 0;

            for (var y = 0; y < sampleH; y++) {
                for (var x = 0; x < sampleW; x++) {
                    var idx = (y * sampleW + x) * 4;
                    var r = pixels[idx], g = pixels[idx + 1], b = pixels[idx + 2];
                    var yVal = 0.299 * r + 0.587 * g + 0.114 * b;
                    var cb = 128 - 0.168736 * r - 0.331264 * g + 0.5 * b;
                    var cr = 128 + 0.5 * r - 0.418688 * g - 0.081312 * b;

                    lumArray[y * sampleW + x] = yVal;
                    sumLum += yVal;
                    sumSqLum += yVal * yVal;

                    // Model warna kulit manusia
                    var isSkinYCbCr = (cb >= 70 && cb <= 140 && cr >= 125 && cr <= 185 && yVal >= 35 && yVal <= 245);
                    var isSkinRGB = (r > 55 && g > 35 && b > 20 && r >= g && r >= b && (r - b >= 8));

                    if (isSkinYCbCr || isSkinRGB) {
                        skinPixelCount++;
                        colCounts[x]++;
                        rowCounts[y]++;
                    }
                }
            }

            // Validasi 1: Persentase piksel kulit manusia
            var skinCoverage = skinPixelCount / totalPixels;
            if (skinCoverage < 0.04) {
                return {
                    valid: false,
                    faces: [],
                    reason: "Wajah tidak terdeteksi pada foto. Pastikan Anda berada tepat di depan kamera dan seluruh bagian wajah terlihat jelas."
                };
            }
            if (skinCoverage > 0.85) {
                return {
                    valid: false,
                    faces: [],
                    reason: "Foto terlalu dekat atau blur ekstrem. Harap ambil foto dari jarak dada ke atas."
                };
            }

            // Validasi 2: Variansi Tekstur & Kontras (Menolak Dinding Polos / Langit-langit / Meja)
            var meanLum = sumLum / totalPixels;
            var variance = (sumSqLum / totalPixels) - (meanLum * meanLum);
            var stdDev = Math.sqrt(Math.max(0, variance));

            // Dinding polos memiliki variansi rendah (< 18), wajah manusia memiliki variansi tinggi karena mata, alis, bibir, rambut
            if (stdDev < 18) {
                return {
                    valid: false,
                    faces: [],
                    reason: "Wajah tidak terdeteksi (bidang foto terlalu polos atau tidak tampak fitur wajah). Pastikan seluruh wajah menghadap kamera."
                };
            }

            // Validasi 3: Filter Densitas Klaster Kepala
            var maxCol = 0, maxRow = 0;
            for (var cx = 0; cx < sampleW; cx++) if (colCounts[cx] > maxCol) maxCol = colCounts[cx];
            for (var ry = 0; ry < sampleH; ry++) if (rowCounts[ry] > maxRow) maxRow = rowCounts[ry];

            var thX = Math.max(4, Math.round(maxCol * 0.15));
            var thY = Math.max(4, Math.round(maxRow * 0.15));

            var fMinX = 0; while (fMinX < sampleW && colCounts[fMinX] < thX) fMinX++;
            var fMaxX = sampleW - 1; while (fMaxX > 0 && colCounts[fMaxX] < thX) fMaxX--;
            var fMinY = 0; while (fMinY < sampleH && rowCounts[fMinY] < thY) fMinY++;
            var fMaxY = sampleH - 1; while (fMaxY > 0 && rowCounts[fMaxY] < thY) fMaxY--;

            if (fMinX >= fMaxX || (fMaxX - fMinX) < 25 || fMinY >= fMaxY || (fMaxY - fMinY) < 30) {
                return {
                    valid: false,
                    faces: [],
                    reason: "Bentuk wajah tidak teridentifikasi dengan jelas. Harap posisikan kepala di tengah frame."
                };
            }

            // Validasi 4: Uji Kontras Area Mata vs Dahi (Haar Feature: Mata lebih gelap dari dahi/pipi)
            var midX = Math.round((fMinX + fMaxX) / 2);
            var headH = fMaxY - fMinY;
            var eyeY = Math.round(fMinY + headH * 0.40);
            var foreheadY = Math.round(fMinY + headH * 0.20);
            var cheekY = Math.round(fMinY + headH * 0.65);

            var lumForehead = lumArray[Math.min(sampleH - 1, foreheadY) * sampleW + midX];
            var lumEye = lumArray[Math.min(sampleH - 1, eyeY) * sampleW + midX];
            var lumCheek = lumArray[Math.min(sampleH - 1, cheekY) * sampleW + midX];

            // Pada dinding polos atau langit-langit, perbedaan luminansi vertikal hampir 0
            if (Math.abs(lumForehead - lumEye) < 1 && Math.abs(lumCheek - lumEye) < 1) {
                return {
                    valid: false,
                    faces: [],
                    reason: "Fitur wajah (mata, hidung, bibir) tidak teridentifikasi. Pastikan seluruh bagian wajah terlihat jelas."
                };
            }

            var scaleX = origW / sampleW;
            var scaleY = origH / sampleH;
            var detectedBox = {
                x: Math.round(fMinX * scaleX),
                y: Math.round(fMinY * scaleY),
                width: Math.round((fMaxX - fMinX) * scaleX),
                height: Math.round((fMaxY - fMinY) * scaleY)
            };

            // Validasi Keterbukaan Mata (Fallback Eye Check)
            var eyeCheckFallback = this.checkEyeOpenness(lumArray, sampleW, sampleH, fMinY + headH * 0.15, midX, headH);
            if (!eyeCheckFallback.isOpen) {
                return {
                    valid: false,
                    faces: [detectedBox],
                    reason: mode === 'camera'
                        ? "Mata Anda terpejam/tertutup saat pengambilan foto. Harap buka kedua mata dan menghadap lurus ke kamera."
                        : "Mata terpejam/tertutup pada pasfoto. Harap gunakan pasfoto dengan kedua mata terbuka jelas menghadap lurus ke kamera."
                };
            }

            return {
                valid: true,
                faces: [detectedBox],
                box: detectedBox,
                message: mode === 'camera'
                    ? "Foto kamera berhasil diverifikasi! Wajah tampak jelas dan proporsional di dalam panduan."
                    : "Pasfoto resmi valid! Seluruh bagian wajah terdeteksi jelas dan proporsional."
            };
        }
    };

    // Otomatis muat cascade model saat script ini dieksekusi
    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                PretesFaceVerifier.initCascade();
            });
        } else {
            PretesFaceVerifier.initCascade();
        }
    }

    window.PretesFaceVerifier = PretesFaceVerifier;

})(typeof window !== 'undefined' ? window : this);
