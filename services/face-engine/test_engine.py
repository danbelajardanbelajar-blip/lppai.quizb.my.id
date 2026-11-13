import base64
import io
import os
import unittest

import numpy as np
from PIL import Image
from fastapi.testclient import TestClient

os.environ['FACE_ENGINE_TOKEN'] = 'test-secret-only-not-for-production-1234567890'
from engine import FaceEngine, FaceError, decode_image, motion_passes
from app import app


def image_payload(color='gray'):
    buffer = io.BytesIO()
    Image.new('RGB', (320, 320), color).save(buffer, format='JPEG')
    return base64.b64encode(buffer.getvalue()).decode()


class EngineTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.engine = FaceEngine()

    def test_invalid_images(self):
        for value in ['not base64', '', 'a' * 12_000_001]:
            with self.assertRaises(FaceError):
                decode_image(value)

    def test_small_image(self):
        output = io.BytesIO()
        Image.new('RGB', (1, 1)).save(output, format='PNG')
        with self.assertRaises(FaceError):
            decode_image(base64.b64encode(output.getvalue()).decode())

    def test_no_face_real_model(self):
        result = self.engine.verify(image_payload(), [image_payload()])
        self.assertFalse(result['match'])
        self.assertEqual(result['status'], 'NO_FACE')

    def test_motion_directions(self):
        def face(nose):
            return np.array([0, 0, 160, 200, 40, 50, 100, 50, nose, 90, 50, 130, 90, 130, 1])
        center, left, right = face(70), face(55), face(85)
        self.assertTrue(motion_passes([center, left, center], 'left'))
        self.assertTrue(motion_passes([center, right, center], 'right'))
        self.assertFalse(motion_passes([center, right, center], 'left'))
        self.assertFalse(motion_passes([center, center, center], 'left'))
        self.assertFalse(motion_passes([center, left, left], 'left'))

    def test_wrong_frame_count(self):
        self.assertFalse(self.engine.verify(image_payload(), [], 'left')['match'])

    def test_busy(self):
        with self.engine.lock:
            with self.assertRaises(BlockingIOError):
                self.engine.verify(image_payload(), [image_payload()])

    def test_http_authentication_and_input(self):
        with TestClient(app) as client:
            self.assertTrue(client.get('/health').json()['ready'])
            self.assertEqual(client.post('/verify', json={}).status_code, 401)
            headers = {'Authorization': 'Bearer ' + os.environ['FACE_ENGINE_TOKEN']}
            self.assertEqual(client.post('/verify', headers=headers, json={}).status_code, 400)
            response = client.post('/verify', headers=headers, json={
                'reference': image_payload(), 'frames': [image_payload()]})
            self.assertEqual(response.status_code, 200)
            self.assertFalse(response.json()['match'])
            self.assertIn('processing_ms', response.json())


if __name__ == '__main__':
    unittest.main()
