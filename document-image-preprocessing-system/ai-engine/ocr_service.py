import os
import threading
from abc import ABC, abstractmethod
from pathlib import Path
from shutil import copy2
from tempfile import gettempdir
from typing import Any

import requests

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
_detector: Any | None = None
_detector_lock = threading.Lock()
_ocr_predict_lock = threading.Lock()


class OcrProvider(ABC):
    """Contract chung để thay đổi nguồn OCR mà không ảnh hưởng pipeline."""

    @abstractmethod
    def extract(self, image_path: Path) -> dict[str, Any]:
        """Trả kết quả OCR thô theo schema chuẩn của hệ thống."""


class LocalHybridOcrProvider(OcrProvider):
    """Provider mặc định: PP-OCRv5 detect kết hợp VietOCR recognition."""

    def extract(self, image_path: Path) -> dict[str, Any]:
        return extract_local_raw_ocr(image_path)


class HttpEkcycOcrProvider(OcrProvider):
    """Adapter HTTP cho một dịch vụ eKYC/OCR bên thứ ba.

    Nhà cung cấp ngoài cần trả payload OCR thô theo schema chuẩn gồm
    ``status``, ``engine``, ``line_count``, ``full_text`` và ``lines``. Khi
    tích hợp vendor có schema riêng, chỉ cần tạo adapter kế thừa
    :class:`OcrProvider`; pipeline không cần thay đổi.
    """

    def __init__(self, url: str, api_key: str | None, timeout_seconds: int):
        self.url = url
        self.api_key = api_key
        self.timeout_seconds = timeout_seconds

    def extract(self, image_path: Path) -> dict[str, Any]:
        headers = {"Accept": "application/json"}

        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"

        with image_path.open("rb") as image_file:
            response = requests.post(
                self.url,
                headers=headers,
                files={"file": (image_path.name, image_file)},
                timeout=self.timeout_seconds,
            )

        try:
            response.raise_for_status()
        except requests.RequestException as exception:
            raise RuntimeError("External eKYC OCR request failed.") from exception

        try:
            payload = response.json()
        except ValueError as exception:
            raise RuntimeError("External eKYC OCR returned invalid JSON.") from exception

        if not isinstance(payload, dict):
            raise RuntimeError("External eKYC OCR returned an invalid payload.")

        validate_ocr_result(payload)
        return payload


class VnptEkcycOcrProvider(HttpEkcycOcrProvider):
    """VNPT eKYC adapter through the organization's configured OCR gateway.

    VNPT supplies endpoint and credential details per customer integration. The
    endpoint must return this application's normalized raw-OCR schema, allowing
    the remaining normalization pipeline to stay vendor-independent.
    """

    def extract(self, image_path: Path) -> dict[str, Any]:
        result = super().extract(image_path)
        engine = result.get("engine")

        if isinstance(engine, dict):
            engine.setdefault("provider", "VNPT eKYC")

        return result


class FptAiReaderOcrProvider(OcrProvider):
    """Adapter cho FPT.AI Reader, chuẩn hóa response eKYC về schema nội bộ.

    FPT.AI Reader trả dữ liệu trường theo ``data[0]`` thay vì danh sách dòng
    OCR. Adapter này chủ động tạo các dòng ``nhãn: giá trị`` tương thích với
    bước chuẩn hóa hiện có, để Laravel không phụ thuộc schema của vendor.
    """

    DEFAULT_ID_CARD_URL = "https://api.fpt.ai/vision/idr/vnm/"
    DEFAULT_DRIVER_LICENSE_URL = "https://api.fpt.ai/vision/dlr/vnm"

    def __init__(
        self,
        api_key: str,
        document_type: str,
        timeout_seconds: int,
        url: str | None = None,
    ) -> None:
        self.api_key = api_key
        self.document_type = document_type
        self.timeout_seconds = timeout_seconds
        self.url = url or self.endpoint_for(document_type)

    @classmethod
    def endpoint_for(cls, document_type: str) -> str:
        if document_type == "id_card":
            return cls.DEFAULT_ID_CARD_URL

        if document_type == "driver_license":
            return cls.DEFAULT_DRIVER_LICENSE_URL

        raise RuntimeError(
            "Unsupported FPT_AI_READER_DOCUMENT_TYPE. "
            "Supported values: id_card, driver_license."
        )

    def extract(self, image_path: Path) -> dict[str, Any]:
        headers = {
            "Accept": "application/json",
            "api_key": self.api_key,
        }

        try:
            with image_path.open("rb") as image_file:
                response = requests.post(
                    self.url,
                    headers=headers,
                    files={"image": (image_path.name, image_file)},
                    timeout=self.timeout_seconds,
                )
            response.raise_for_status()
        except (OSError, requests.RequestException) as exception:
            raise RuntimeError("FPT.AI Reader request failed.") from exception

        try:
            payload = response.json()
        except ValueError as exception:
            raise RuntimeError("FPT.AI Reader returned invalid JSON.") from exception

        return self.normalize_payload(payload)

    def normalize_payload(self, payload: Any) -> dict[str, Any]:
        if not isinstance(payload, dict):
            raise RuntimeError("FPT.AI Reader returned an invalid payload.")

        if payload.get("errorCode") != 0:
            vendor_message = str(payload.get("errorMessage") or "Unknown error")
            raise RuntimeError(f"FPT.AI Reader rejected the document: {vendor_message}")

        data = payload.get("data")

        if not isinstance(data, list) or not data or not isinstance(data[0], dict):
            raise RuntimeError("FPT.AI Reader returned no recognized document data.")

        field_labels = self.field_labels()
        recognized_document = data[0]
        lines = []

        for index, (key, label) in enumerate(field_labels.items()):
            value = recognized_document.get(key)

            if not self.is_recognized_value(value):
                continue

            value_text = self.value_text(value)

            lines.append(
                {
                    "index": len(lines),
                    "text": f"{label}: {value_text}",
                    "confidence": self.confidence(
                        recognized_document.get(f"{key}_prob")
                    ),
                    "detection_confidence": None,
                    "polygon": self.synthetic_polygon(index),
                }
            )

        if not lines:
            raise RuntimeError("FPT.AI Reader returned no usable OCR fields.")

        result = {
            "status": "completed",
            "engine": {
                "provider": "FPT.AI Reader",
                "document_type": self.document_type,
                "endpoint": self.url,
                "recognition_language": "vi",
            },
            "line_count": len(lines),
            "full_text": "\n".join(line["text"] for line in lines),
            "lines": lines,
        }
        validate_ocr_result(result)

        return result

    def field_labels(self) -> dict[str, str]:
        if self.document_type == "id_card":
            return {
                "id": "Số/No",
                "name": "Họ và tên/Full name",
                "dob": "Ngày sinh/Date of birth",
                "sex": "Giới tính/Sex",
                "nationality": "Quốc tịch/Nationality",
                "home": "Quê quán/Place of origin",
                "address": "Nơi thường trú/Address",
                "doe": "Có giá trị đến/Date of expiry",
            }

        return {
            "id": "Số/No",
            "name": "Họ và tên/Full name",
            "dob": "Ngày sinh/Date of birth",
            "nation": "Quốc tịch/Nationality",
            "address": "Nơi cư trú/Address",
            "class": "Hạng/Class",
            "doe": "Có giá trị đến/Date of expiry",
        }

    @staticmethod
    def is_recognized_value(value: Any) -> bool:
        return isinstance(value, (str, int, float, list)) and FptAiReaderOcrProvider.value_text(
            value
        ).upper() not in {
            "",
            "N/A",
            "NONE",
            "NULL",
        }

    @staticmethod
    def value_text(value: Any) -> str:
        if isinstance(value, list):
            return ", ".join(str(item) for item in value)

        return str(value).strip()

    @staticmethod
    def confidence(value: Any) -> float | None:
        try:
            return float(value)
        except (TypeError, ValueError):
            return None

    @staticmethod
    def synthetic_polygon(index: int) -> list[list[int]]:
        top = index * 32

        return [[0, top], [1000, top], [1000, top + 24], [0, top + 24]]


def get_ocr_provider(provider_name: str | None = None) -> OcrProvider:
    """Chọn provider theo request hoặc môi trường, mặc định giữ OCR nội bộ."""

    provider_name = (
        provider_name
        or os.environ.get("OCR_PROVIDER", "local_hybrid")
    ).strip().lower()

    if provider_name == "local_hybrid":
        return LocalHybridOcrProvider()

    if provider_name == "fpt_ai_reader":
        api_key = os.environ.get("FPT_AI_READER_API_KEY", "").strip()

        if not api_key:
            raise RuntimeError(
                "FPT_AI_READER_API_KEY is required when OCR_PROVIDER=fpt_ai_reader."
            )

        return FptAiReaderOcrProvider(
            api_key=api_key,
            document_type=os.environ.get(
                "FPT_AI_READER_DOCUMENT_TYPE",
                "id_card",
            ).strip().lower(),
            timeout_seconds=int(
                os.environ.get("FPT_AI_READER_TIMEOUT_SECONDS", "30")
            ),
            url=os.environ.get("FPT_AI_READER_URL", "").strip() or None,
        )

    if provider_name == "external_ekyc":
        url = os.environ.get("EKYC_OCR_URL", "").strip()

        if not url:
            raise RuntimeError(
                "EKYC_OCR_URL is required when OCR_PROVIDER=external_ekyc."
            )

        timeout_seconds = int(os.environ.get("EKYC_OCR_TIMEOUT_SECONDS", "30"))

        return HttpEkcycOcrProvider(
            url=url,
            api_key=os.environ.get("EKYC_OCR_API_KEY"),
            timeout_seconds=timeout_seconds,
        )

    if provider_name == "vnpt_ekyc":
        url = os.environ.get("VNPT_EKYC_OCR_URL", "").strip()

        if not url:
            raise RuntimeError(
                "VNPT_EKYC_OCR_URL is required when OCR_PROVIDER=vnpt_ekyc."
            )

        timeout_seconds = int(os.environ.get("VNPT_EKYC_OCR_TIMEOUT_SECONDS", "30"))

        return VnptEkcycOcrProvider(
            url=url,
            api_key=os.environ.get("VNPT_EKYC_OCR_API_KEY"),
            timeout_seconds=timeout_seconds,
        )

    raise RuntimeError(
        "Unsupported OCR_PROVIDER. Supported values: local_hybrid, "
        "fpt_ai_reader, vnpt_ekyc, external_ekyc."
    )


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


def get_detector() -> Any:
    """Khởi tạo PP-OCRv5 detector một lần để tái sử dụng giữa các request."""

    global _detector

    with _detector_lock:
        if _detector is None:
            _detector = create_model(
                OCR_DETECTOR,
                device="cpu",
                engine="onnxruntime",
            )

    return _detector


def bootstrap_recognizer() -> Predictor:
    """Tải và cache VietOCR pretrained khi service chạy lần đầu."""

    global _recognizer

    VIETOCR_CACHE_DIR.mkdir(parents=True, exist_ok=True)
    config = Cfg.load_config_from_name("vgg_transformer")
    config["device"] = "cpu"
    _recognizer = Predictor(config)

    downloaded_weights = Path(gettempdir()) / Path(config["weights"]).name

    if not downloaded_weights.is_file():
        raise FileNotFoundError(
            f"Không tìm thấy VietOCR weight vừa tải: {downloaded_weights}"
        )

    copy2(downloaded_weights, VIETOCR_WEIGHTS_PATH)
    config["weights"] = str(VIETOCR_WEIGHTS_PATH)
    config.save(VIETOCR_CONFIG_PATH)

    return _recognizer


def detect_text_lines(image_path: Path) -> list[tuple[list[list[int]], float | None]]:
    """Dùng PP-OCRv5 detector đã nạp sẵn để phát hiện từng dòng chữ."""

    detector_results = list(get_detector().predict(str(image_path)))

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


def extract_local_raw_ocr(image_path: Path) -> dict[str, Any]:
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


def validate_ocr_result(payload: dict[str, Any]) -> None:
    """Kiểm tra schema OCR tối thiểu trước khi chuyển sang chuẩn hóa dữ liệu."""

    required_keys = {"status", "engine", "line_count", "full_text", "lines"}
    missing_keys = required_keys.difference(payload)

    if missing_keys:
        raise RuntimeError(
            "OCR provider response is missing required fields: "
            + ", ".join(sorted(missing_keys))
        )

    if not isinstance(payload["lines"], list):
        raise RuntimeError("OCR provider response field 'lines' must be a list.")


def extract_raw_ocr(
    image_path: Path,
    provider_name: str | None = None,
) -> dict[str, Any]:
    """Đọc OCR qua provider được cấu hình và giữ schema output thống nhất."""

    if not image_path.is_file():
        raise FileNotFoundError(f"OCR input was not found: {image_path}")

    result = get_ocr_provider(provider_name).extract(image_path)
    validate_ocr_result(result)

    return result
