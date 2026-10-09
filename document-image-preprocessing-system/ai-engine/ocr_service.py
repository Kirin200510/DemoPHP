import gc
import os
import threading
from pathlib import Path
from shutil import copy2
from typing import Any


BASE_DIR = Path(__file__).resolve().parent
MODEL_CACHE_DIR = BASE_DIR / "model-cache"
VIETOCR_CACHE_DIR = MODEL_CACHE_DIR / "vietocr"
VIETOCR_CONFIG_PATH = VIETOCR_CACHE_DIR / "vgg_transformer.yml"
VIETOCR_WEIGHTS_PATH = VIETOCR_CACHE_DIR / "vgg_transformer.pth"

os.environ.setdefault("PADDLE_PDX_CACHE_HOME", str(MODEL_CACHE_DIR))
os.environ.setdefault("PADDLE_PDX_DISABLE_MODEL_SOURCE_CHECK", "True")

import cv2
import numpy as np
from paddlex import create_model
from PIL import Image
from vietocr.tool.config import Cfg
from vietocr.tool.predictor import Predictor


OCR_DETECTOR = "PP-OCRv5_server_det"
OCR_RECOGNIZER = "VietOCR vgg_transformer"

_recognizer: Predictor | None = None
_recognizer_lock = threading.Lock()
_ocr_predict_lock = threading.Lock()


def expand_polygon(points: np.ndarray, scale: float = 1.06) -> np.ndarray:
    """Thêm biên để khi crop không cắt mất dấu tiếng Việt."""

    center = points.mean(axis=0)

    return (points - center) * scale + center


def crop_text_line(image: np.ndarray, polygon: list[list[int]]) -> np.ndarray:
    """Nắn polygon của text detector thành ảnh dòng chữ thẳng."""

    source = expand_polygon(np.asarray(polygon, dtype=np.float32))
    top_width = np.linalg.norm(source[1] - source[0])
    bottom_width = np.linalg.norm(source[2] - source[3])
    left_height = np.linalg.norm(source[3] - source[0])
    right_height = np.linalg.norm(source[2] - source[1])

    width = max(2, int(round(max(top_width, bottom_width))))
    height = max(2, int(round(max(left_height, right_height))))
    destination = np.array(
        [
            [0, 0],
            [width - 1, 0],
            [width - 1, height - 1],
            [0, height - 1],
        ],
        dtype=np.float32,
    )
    transform = cv2.getPerspectiveTransform(source, destination)

    return cv2.warpPerspective(
        image,
        transform,
        (width, height),
        flags=cv2.INTER_CUBIC,
        borderMode=cv2.BORDER_REPLICATE,
    )


def get_recognizer() -> Predictor:
    """Khởi tạo VietOCR một lần để tái sử dụng giữa các request."""

    global _recognizer

    with _recognizer_lock:
        if _recognizer is None:
            if not VIETOCR_CONFIG_PATH.is_file() or not VIETOCR_WEIGHTS_PATH.is_file():
                return bootstrap_recognizer()

            config = Cfg.load_config_from_file(VIETOCR_CONFIG_PATH)
            config["weights"] = str(VIETOCR_WEIGHTS_PATH)
            config["device"] = "cpu"
            _recognizer = Predictor(config)

    return _recognizer


def bootstrap_recognizer() -> Predictor:
    """Tải và cache VietOCR pretrained khi service chạy lần đầu."""

    global _recognizer

    VIETOCR_CACHE_DIR.mkdir(parents=True, exist_ok=True)
    config = Cfg.load_config_from_name("vgg_transformer")
    config["device"] = "cpu"
    _recognizer = Predictor(config)

    downloaded_weights = Path("/tmp") / Path(config["weights"]).name

    if not downloaded_weights.is_file():
        raise FileNotFoundError(
            f"Không tìm thấy VietOCR weight vừa tải: {downloaded_weights}"
        )

    copy2(downloaded_weights, VIETOCR_WEIGHTS_PATH)
    config["weights"] = str(VIETOCR_WEIGHTS_PATH)
    config.save(VIETOCR_CONFIG_PATH)

    return _recognizer


def detect_text_lines(image_path: Path) -> list[tuple[list[list[int]], float | None]]:
    """Chỉ dùng PP-OCRv5 detector và giải phóng nó trước khi nhận dạng."""

    detector = create_model(
        OCR_DETECTOR,
        device="cpu",
        engine="onnxruntime",
    )

    try:
        detector_results = list(detector.predict(str(image_path)))
    finally:
        del detector
        gc.collect()

    if not detector_results:
        raise RuntimeError("PP-OCRv5 did not return a detection result.")

    result = detector_results[0].json.get("res", {})
    polygons = result.get("dt_polys", [])
    scores = result.get("dt_scores", [])
    text_lines = [
        (polygon, float(scores[index]) if index < len(scores) else None)
        for index, polygon in enumerate(polygons)
    ]

    return sorted(
        text_lines,
        key=lambda item: (
            float(np.mean(np.asarray(item[0])[:, 1])),
            float(np.mean(np.asarray(item[0])[:, 0])),
        ),
    )


def extract_raw_ocr(image_path: Path) -> dict[str, Any]:
    """Phát hiện bằng PP-OCRv5 và nhận dạng tiếng Việt bằng VietOCR."""

    if not image_path.is_file():
        raise FileNotFoundError(f"OCR input was not found: {image_path}")

    image = cv2.imread(str(image_path))

    if image is None:
        raise FileNotFoundError(f"Không thể đọc ảnh OCR: {image_path}")

    with _ocr_predict_lock:
        detected_lines = detect_text_lines(image_path)
        recognizer = get_recognizer()
        lines = []

        for index, (polygon, detection_confidence) in enumerate(detected_lines):
            line_image = crop_text_line(image, polygon)
            line_image_rgb = cv2.cvtColor(line_image, cv2.COLOR_BGR2RGB)
            text, recognition_confidence = recognizer.predict(
                Image.fromarray(line_image_rgb),
                return_prob=True,
            )
            lines.append(
                {
                    "index": index,
                    "text": text,
                    "confidence": float(recognition_confidence),
                    "detection_confidence": detection_confidence,
                    "polygon": polygon,
                }
            )

    return {
        "status": "completed",
        "engine": {
            "detector": OCR_DETECTOR,
            "recognizer": OCR_RECOGNIZER,
            "recognition_language": "vi",
            "inference_engine": "onnxruntime + pytorch-cpu",
        },
        "line_count": len(lines),
        "full_text": "\n".join(line["text"] for line in lines),
        "lines": lines,
    }
