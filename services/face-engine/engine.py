"""YuNet detection + SFace embeddings. No images or embeddings are persisted."""
import base64
import hashlib
import io
import os
import threading
from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageOps


class FaceError(ValueError):
    pass


def decode_image(payload: str):
    if not isinstance(payload, str) or len(payload) > 12_000_000:
        raise FaceError('Ukuran gambar tidak valid.')
    if payload.startswith('data:image/jpeg;base64,'):
        payload = payload.split(',', 1)[1]
    try:
        raw = base64.b64decode(payload, validate=True)
        with Image.open(io.BytesIO(raw)) as source:
            if source.width * source.height > 16_000_000 or min(source.size) < 112:
                raise FaceError('Resolusi foto tidak sesuai. Gunakan foto wajah yang jelas.')
            image = ImageOps.exif_transpose(source).convert('RGB')
            image.thumbnail((960, 960))
            return cv2.cvtColor(np.array(image), cv2.COLOR_RGB2BGR)
    except FaceError:
        raise
    except Exception as error:
        raise FaceError('Gambar tidak dapat dibaca.') from error


def motion_passes(faces, direction):
    """Basic randomized head-turn challenge; NOT certified presentation attack detection."""
    def nose_ratio(face):
        eye_mid = (face[4] + face[6]) / 2
        eye_distance = abs(face[4] - face[6])
        if eye_distance < 12:
            raise FaceError('Wajah terlalu kecil. Dekatkan wajah ke kamera.')
        return float((face[8] - eye_mid) / eye_distance)

    neutral, turn, returned = [nose_ratio(face) for face in faces]
    delta = turn - neutral
    correct_direction = delta < -0.12 if direction == 'left' else delta > 0.12
    return correct_direction and abs(returned - neutral) < 0.10


class FaceEngine:
    def __init__(self, model_dir=None, threshold=None):
        model_dir = Path(model_dir or Path(__file__).parent / 'models')
        self.threshold = float(threshold if threshold is not None else os.getenv('FACE_MATCH_THRESHOLD', '0.45'))
        if not 0.363 <= self.threshold <= 0.9:
            raise ValueError('FACE_MATCH_THRESHOLD must be between 0.363 and 0.9')
        self.detector = cv2.FaceDetectorYN.create(str(model_dir / 'yunet.onnx'), '', (320, 320), 0.9, 0.3, 5000)
        self.recognizer = cv2.FaceRecognizerSF.create(str(model_dir / 'sface.onnx'), '')
        self.lock = threading.Lock()

    def describe(self, image):
        height, width = image.shape[:2]
        self.detector.setInputSize((width, height))
        _, faces = self.detector.detect(image)
        if faces is None or len(faces) != 1:
            raise FaceError('Harus ada tepat satu wajah pada setiap foto, termasuk pasfoto pendaftaran.')
        face = faces[0]
        if min(face[2:4]) < 70:
            raise FaceError('Wajah terlalu kecil. Dekatkan wajah ke kamera atau perbarui pasfoto.')
        x, y, w, h = [int(value) for value in face[:4]]
        crop = image[max(0, y):min(height, y + h), max(0, x):min(width, x + w)]
        if crop.size == 0:
            raise FaceError('Wajah berada di luar bingkai.')
        gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
        if gray.mean() < 35 or gray.mean() > 225 or cv2.Laplacian(gray, cv2.CV_64F).var() < 20:
            raise FaceError('Foto terlalu gelap, terang, atau buram. Perbaiki pencahayaan dan tahan kepala.')
        aligned = self.recognizer.alignCrop(image, face)
        return face, self.recognizer.feature(aligned).copy()

    def verify(self, reference, frames, direction=None):
        result = dict(engine='opencv-sface-v1', match=False, similarity=0.0,
                      status='ERROR', reason='', motion_passed=False)
        if direction not in (None, 'left', 'right') or len(frames) != (3 if direction else 1):
            return dict(result, reason='Data tantangan tidak valid.')
        if not self.lock.acquire(blocking=False):
            raise BlockingIOError('Engine busy')
        try:
            _, ref_embedding = self.describe(decode_image(reference))
            images = [decode_image(frame) for frame in frames]
            if direction and len({hashlib.sha256(image.tobytes()).digest() for image in images}) != 3:
                raise FaceError('Foto tantangan berulang. Ikuti gerakan dari kamera secara langsung.')
            descriptions = [self.describe(image) for image in images]
            scores = [float(self.recognizer.match(ref_embedding, embedding, cv2.FaceRecognizerSF_FR_COSINE))
                      for _, embedding in descriptions]
            same_person = all(score >= self.threshold for score in scores)
            # Require all challenge frames to contain the same person as well as the reference.
            consistent = all(float(self.recognizer.match(descriptions[0][1], embedding,
                              cv2.FaceRecognizerSF_FR_COSINE)) >= self.threshold
                             for _, embedding in descriptions[1:])
            motion = motion_passes([face for face, _ in descriptions], direction) if direction else False
            score = round(min(scores) * 100, 1)
            if not same_person or not consistent:
                return dict(result, status='MISMATCH', similarity=score,
                            reason='Ciri wajah belum sesuai pasfoto pendaftaran. Hadapkan wajah dengan pencahayaan jelas.')
            if direction and not motion:
                return dict(result, status='MOTION_REQUIRED', similarity=score,
                            reason='Gerakan belum sesuai. Gerakkan kepala mengikuti panah, lalu kembali menghadap lurus.')
            return dict(result, match=True, status='MATCH', similarity=score, motion_passed=motion,
                        reason='Ciri wajah sesuai pasfoto pendaftaran. Skor adalah indeks kemiripan, bukan persentase kepastian identitas.')
        except FaceError as error:
            return dict(result, status='NO_FACE', reason=str(error))
        finally:
            self.lock.release()
