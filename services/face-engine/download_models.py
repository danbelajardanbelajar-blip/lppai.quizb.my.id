"""Download pinned official OpenCV Zoo models and verify their published LFS SHA256."""
import hashlib
import urllib.request
from pathlib import Path

COMMIT = '48b10fa505aeb51fbd127fc60a5c660040e823b7'
MODELS = {
    'yunet.onnx': ('face_detection_yunet/face_detection_yunet_2023mar.onnx',
                   '8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4'),
    'sface.onnx': ('face_recognition_sface/face_recognition_sface_2021dec.onnx',
                   '0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79'),
}


def download():
    directory = Path(__file__).parent / 'models'
    directory.mkdir(exist_ok=True)
    for name, (source, checksum) in MODELS.items():
        target = directory / name
        if target.exists() and hashlib.sha256(target.read_bytes()).hexdigest() == checksum:
            continue
        url = f'https://media.githubusercontent.com/media/opencv/opencv_zoo/{COMMIT}/models/{source}'
        with urllib.request.urlopen(url, timeout=60) as response:
            data = response.read(50_000_000)
        if hashlib.sha256(data).hexdigest() != checksum:
            raise RuntimeError(f'Checksum mismatch: {name}')
        target.write_bytes(data)
        print(f'Verified {name}')


if __name__ == '__main__':
    download()
