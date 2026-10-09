import re
import unicodedata
from difflib import SequenceMatcher
from pathlib import Path
from typing import Any

import cv2
import numpy as np


BASE_DIR = Path(__file__).resolve().parent
FACE_DETECTOR_MODEL = (
    BASE_DIR
    / "model-cache"
    / "face-detection"
    / "face_detection_yunet_2026may.onnx"
)

FIELD_LABELS = {
    "full_name": ("ho ten", "ho va ten", "full name"),
    "date_of_birth": ("ngay sinh", "date of birth"),
    "sex": ("gioi tinh", "sex"),
    "nationality": ("quoc tich", "nationality"),
    "place_of_origin": ("que quan", "place of origin"),
    "residence": (
        "noi thuong tru",
        "noi thuong tru/",
        "noi cu tru",
        "place of residence",
    ),
    "document_number": (
        "so/no",
        "so no",
        "so i no",
        "so i/no",
        "so ino",
        "so cccd",
        "id no",
    ),
    "license_class": ("hang/class", "hang class", "class:"),
    "expiry_date": ("co gia tri den", "expires", "expiry date"),
    "owner_name": (
        "ten chu xe",
        "owner's full name",
        "owner s full name",
        "owners full name",
    ),
    "vehicle_address": ("dia chi", "address"),
    "brand": ("nhan hieu", "brand"),
    "model_code": ("so loai", "model code"),
    "engine_number": ("so may", "engine no", "engine number"),
    "chassis_number": ("so khung", "chassis no", "chassis number"),
    "paint_color": ("mau son", "color"),
    "operating_scope": ("hoat dong trong pham vi", "operating scope"),
    "registration_plate": (
        "bien so dang ky",
        "no plate",
        "n° plate",
        "number plate",
    ),
    "seating_capacity": ("so cho ngoi", "seating capacity", "number of seats"),
    "engine_power": ("cong suat", "power"),
    "vehicle_type": ("loai xe", "vehicle type"),
    "engine_displacement": ("dung tich", "displacement", "cylinder capacity"),
}
VERIFICATION_KEYWORDS = {
    "issuer": ("bo giao thong", "so giao thong", "cong an", "bo gtvt"),
    "signer_title": ("giam doc", "pho giam doc", "truong phong"),
    "verification": ("chung thuc", "xac nhan", "nguoi ky", "chu ky", "con dau"),
}
DOCUMENT_TITLE_PATTERNS = {
    "driver_license": ("giay phep lai", "driver's license", "drivers license"),
    "citizen_identity_card": ("can cuoc cong dan", "citizen identity"),
    "vehicle_registration": (
        "giay chung nhan dang ky xe",
        "dang ky xe",
        "vehicle registration",
        "certificate of registration",
    ),
}
DOCUMENT_TYPE_LABELS = {
    "driver_license": "Giấy phép lái xe / Driver's License",
    "citizen_identity_card": "Căn cước công dân / Citizen Identity Card",
    "vehicle_registration": "Giấy chứng nhận đăng ký xe / Vehicle Registration",
}
NON_FIELD_ANNOTATIONS = (
    "mau minh hoa",
    "khong co gia tri",
    "specimen",
    "sample document",
)
RESIDENCE_STOP_KEYWORDS = (
    "ngay",
    "date",
    "thang",
    "month",
    "year",
)
OWNER_NAME_PATTERNS = (
    r"tên\s+chủ\s+xe",
    r"ten\s+chu\s+xe",
    r"owner[\s']*s?\s+full\s+name",
)


def normalize_text(text: str) -> str:
    """Chuẩn hóa Unicode và khoảng trắng nhưng không tự đoán nội dung OCR."""

    return re.sub(r"\s+", " ", unicodedata.normalize("NFC", text)).strip()


def searchable_text(text: str) -> str:
    """Tạo khóa không dấu để nhận diện nhãn dù OCR mất dấu tiếng Việt."""

    normalized = unicodedata.normalize("NFD", normalize_text(text).lower())
    without_diacritics = "".join(
        character
        for character in normalized
        if unicodedata.category(character) != "Mn"
    )

    return re.sub(
        r"[^a-z0-9]+",
        " ",
        without_diacritics.replace("đ", "d"),
    ).strip()


def is_non_field_annotation(text: str) -> bool:
    """Loại watermark/chú thích mẫu khỏi ứng viên giá trị có cấu trúc."""

    text_key = searchable_text(text)

    return any(annotation in text_key for annotation in NON_FIELD_ANNOTATIONS)


def line_center(line: dict[str, Any]) -> tuple[float, float]:
    """Trả tâm polygon để ghép nhãn và giá trị nằm cùng hàng."""

    polygon = np.asarray(line["polygon"], dtype=np.float32)

    return float(polygon[:, 0].mean()), float(polygon[:, 1].mean())


def line_bounds(line: dict[str, Any]) -> tuple[float, float, float, float]:
    """Trả bounding box polygon để ghép các dòng theo bố cục OCR."""

    polygon = np.asarray(line["polygon"], dtype=np.float32)

    return (
        float(polygon[:, 0].min()),
        float(polygon[:, 1].min()),
        float(polygon[:, 0].max()),
        float(polygon[:, 1].max()),
    )


def median_line_height(lines: list[dict[str, Any]]) -> float:
    """Tạo ngưỡng khoảng cách thích nghi với kích thước ảnh/cỡ chữ."""

    heights = [
        max(1.0, bottom - top)
        for line in lines
        for _, top, _, bottom in [line_bounds(line)]
    ]

    return float(np.median(heights)) if heights else 20.0


def is_field_label_line(text: str) -> bool:
    """Cho biết dòng có nhãn field khác, là điểm dừng khi ghép địa chỉ."""

    text_key = searchable_text(text)

    return any(
        searchable_text(label) in text_key
        for labels in FIELD_LABELS.values()
        for label in labels
    )


def has_field_label(text: str, labels: tuple[str, ...]) -> bool:
    """So khớp nhãn sau khi bỏ dấu và chuẩn hóa các ký tự phân cách."""

    text_key = searchable_text(text)

    for label in labels:
        label_key = searchable_text(label)

        if label_key in text_key:
            return True

        label_word_count = len(label_key.split())

        if len(label_key) < 7 or label_word_count < 2:
            continue

        text_words = text_key.split()
        similarity_threshold = 0.86 if len(label_key) >= 10 else 0.85

        for start in range(len(text_words)):
            for length in range(
                max(1, label_word_count - 1),
                min(len(text_words) - start, label_word_count + 1) + 1,
            ):
                candidate = " ".join(text_words[start : start + length])

                if SequenceMatcher(None, label_key, candidate).ratio() >= similarity_threshold:
                    return True

    return False


def is_residence_stop_line(text: str) -> bool:
    """Không ghép phần xác thực/chữ ký hoặc tiêu đề vào địa chỉ."""

    text_key = searchable_text(text)

    return (
        is_field_label_line(text)
        or any(
            keyword in text_key
            for keywords in VERIFICATION_KEYWORDS.values()
            for keyword in keywords
        )
        or any(
            pattern in text_key
            for patterns in DOCUMENT_TITLE_PATTERNS.values()
            for pattern in patterns
        )
        or any(keyword in text_key for keyword in RESIDENCE_STOP_KEYWORDS)
    )


def same_row_value_line(
    lines: list[dict[str, Any]],
    label_position: int,
) -> dict[str, Any] | None:
    """Tìm vùng text gần nhất ở bên phải của nhãn trên cùng một hàng."""

    label_x, label_y = line_center(lines[label_position])
    candidates = []

    for position, candidate in enumerate(lines):
        if position == label_position:
            continue

        if (
            is_non_field_annotation(candidate["text"])
            or is_field_label_line(candidate["text"])
        ):
            continue

        candidate_x, candidate_y = line_center(candidate)

        if candidate_x <= label_x + 10 or abs(candidate_y - label_y) > 10:
            continue

        candidates.append((candidate_x - label_x, candidate))

    return min(candidates, default=(None, None), key=lambda item: item[0])[1]


def nearby_number_value(
    lines: list[dict[str, Any]],
    label_position: int,
) -> tuple[str, list[int]] | None:
    """Tìm số định danh ở cạnh nhãn khi OCR tách nhãn và số thành hai vùng."""

    label_x, label_y = line_center(lines[label_position])
    candidates = []

    for position, candidate in enumerate(lines):
        if position == label_position or is_non_field_annotation(candidate["text"]):
            continue

        number_match = re.search(r"\d{6,}", normalize_text(candidate["text"]))

        if number_match is None:
            continue

        candidate_x, candidate_y = line_center(candidate)
        vertical_distance = abs(candidate_y - label_y)

        if vertical_distance > 85:
            continue

        candidates.append(
            (
                vertical_distance,
                abs(candidate_x - label_x),
                number_match.group(0),
                candidate,
            )
        )

    if not candidates:
        return None

    _, _, number_value, number_line = min(candidates)

    return number_value, [lines[label_position]["index"], number_line["index"]]


def nearby_registration_plate_value(
    lines: list[dict[str, Any]],
    label_position: int,
) -> tuple[str, list[int]] | None:
    """Tìm biển số ở vùng ngay dưới nhãn khi OCR tách thành dòng chữ lớn."""

    label_x, label_y = line_center(lines[label_position])
    _, _, _, label_bottom = line_bounds(lines[label_position])
    line_height = median_line_height(lines)
    candidates = []

    for candidate in lines:
        if candidate["index"] == lines[label_position]["index"]:
            continue

        candidate_text = normalize_text(candidate["text"])
        plate_match = re.search(
            r"(?P<plate>\d{2}[A-ZĐ][A-Z0-9]{0,2}[-\s]?\d{3}[.\s]?\d{2})(?:\s*\([A-Z]\)|[A-Z])?(?![A-Z0-9])",
            candidate_text,
            flags=re.IGNORECASE,
        )

        if plate_match is None:
            continue

        candidate_x, candidate_y = line_center(candidate)
        vertical_distance = candidate_y - label_y

        _, candidate_top, _, _ = line_bounds(candidate)

        if (
            candidate_top < label_bottom - line_height * 0.25
            or vertical_distance > max(260.0, line_height * 12)
        ):
            continue

        candidates.append(
            (
                vertical_distance,
                abs(candidate_x - label_x),
                plate_match.group("plate"),
                candidate,
            )
        )

    if not candidates:
        return None

    _, _, plate_value, plate_line = min(candidates)

    return plate_value, [lines[label_position]["index"], plate_line["index"]]


def sanitize_owner_name(value: str) -> str:
    """Giữ ký tự hợp lệ trong tên người, loại nhiễu OCR như ``);``."""

    return normalize_text(
        "".join(
            character
            for character in value
            if character.isalpha() or character.isspace() or character in "-'"
        )
    )


def sanitize_address(value: str) -> str:
    """Chỉ bỏ ký tự nhiễu ở rìa địa chỉ, không tự thay đổi nội dung OCR."""

    value = normalize_text(value)
    value = re.sub(r"[);\]}]+$", "", value)

    return normalize_text(value)


def is_name_like(value: str) -> bool:
    """Kiểm tra bảo thủ để tránh gán mã số hoặc nhãn thành tên người."""

    words = value.split()

    return 2 <= len(words) <= 5 and all(
        all(character.isalpha() or character in "-'" for character in word)
        for word in words
    )


def owner_name_above_address(
    lines: list[dict[str, Any]],
) -> tuple[str, list[int]] | None:
    """Fallback bố cục: tên chủ xe thường nằm ngay trên nhãn địa chỉ."""

    address_position = next(
        (
            position
            for position, line in enumerate(lines)
            if has_field_label(line["text"], FIELD_LABELS["vehicle_address"])
        ),
        None,
    )

    if address_position is None:
        return None

    address_line = lines[address_position]
    address_left, address_top, _, _ = line_bounds(address_line)
    line_height = median_line_height(lines)
    candidates = []

    for candidate in lines:
        if candidate["index"] == address_line["index"]:
            continue

        if is_non_field_annotation(candidate["text"]) or is_field_label_line(candidate["text"]):
            continue

        candidate_left, _, _, candidate_bottom = line_bounds(candidate)
        value = sanitize_owner_name(candidate["text"])

        if (
            candidate_bottom > address_top + line_height * 0.25
            or address_top - candidate_bottom > max(140.0, line_height * 5)
            or candidate_left < address_left - line_height * 2
            or not is_name_like(value)
        ):
            continue

        candidates.append((address_top - candidate_bottom, abs(candidate_left - address_left), value, candidate))

    if not candidates:
        return None

    _, _, owner_name, owner_line = min(candidates)

    return owner_name, [owner_line["index"]]


def owner_name_value(
    lines: list[dict[str, Any]],
    label_position: int,
) -> tuple[str, list[int]]:
    """Lấy tên chủ xe từ cùng dòng hoặc dòng giá trị ngay bên dưới nhãn."""

    label_line = lines[label_position]
    inline_value = sanitize_owner_name(
        inline_value_after_last_label(
            normalize_text(label_line["text"]),
            OWNER_NAME_PATTERNS,
        )
    )

    if len(inline_value.split()) >= 2:
        return inline_value, [label_line["index"]]

    label_left, label_top, _, label_bottom = line_bounds(label_line)
    line_height = median_line_height(lines)
    candidates = []

    for candidate in lines:
        if candidate["index"] == label_line["index"]:
            continue

        if is_non_field_annotation(candidate["text"]) or is_field_label_line(candidate["text"]):
            continue

        candidate_left, candidate_top, _, _ = line_bounds(candidate)
        value = sanitize_owner_name(candidate["text"])

        if (
            candidate_top <= label_top + line_height * 0.30
            or candidate_top - label_bottom > max(110.0, line_height * 4)
            or candidate_left < label_left - line_height * 2
            or len(value.split()) < 2
        ):
            continue

        candidates.append(
            (
                max(candidate_top - label_bottom, 0.0),
                abs(candidate_left - label_left),
                value,
                candidate,
            )
        )

    if not candidates:
        address_fallback = owner_name_above_address(lines)

        if address_fallback is not None:
            owner_name, source_indexes = address_fallback

            return owner_name, [label_line["index"], *source_indexes]

        return "", [label_line["index"]]

    _, _, owner_name, owner_line = min(candidates)

    return owner_name, [label_line["index"], owner_line["index"]]


def inline_value_after_last_label(
    text: str,
    patterns: tuple[str, ...],
) -> str:
    """Lấy phần giá trị sau nhãn cuối cùng khi nhiều field nằm chung một dòng."""

    matches = [
        match
        for pattern in patterns
        for match in re.finditer(pattern, text, flags=re.IGNORECASE)
    ]

    if not matches:
        return ""

    label_end = max(matches, key=lambda match: match.start()).end()
    value = text[label_end:]
    value = re.sub(r"^[\s:;/|I]+", "", value)

    return normalize_text(value)


def residence_layout_lines(
    lines: list[dict[str, Any]],
    label_position: int,
    existing_indexes: list[int],
) -> list[dict[str, Any]]:
    """Ghép nhiều hàng địa chỉ theo vùng polygon và thứ tự đọc.

    OCR có thể tách địa chỉ thành nhiều polygon tùy độ nghiêng, độ phân giải
    và lóa. Hàm bắt đầu từ nhãn/giá trị đã nhận diện, sau đó chỉ nối hàng bên
    dưới còn ở cùng vùng ngang. Khoảng cách được tính theo chiều cao chữ trung
    vị nên không phụ thuộc cố định vào độ phân giải ảnh.
    """

    line_height = median_line_height(lines)
    initial_indexes = set(existing_indexes)
    selected_indexes = set(initial_indexes)
    selected_indexes.add(lines[label_position]["index"])
    selected_lines = [
        line
        for line in lines
        if line["index"] in selected_indexes
    ]
    label_left, _, label_right, _ = line_bounds(lines[label_position])
    _, _, _, last_bottom = max(
        (line_bounds(line) for line in selected_lines),
        key=lambda bounds: bounds[3],
    )
    corridor_left = min(line_bounds(line)[0] for line in selected_lines)
    corridor_right = max(line_bounds(line)[2] for line in selected_lines)
    max_gap = max(line_height * 3.25, 24.0)

    while True:
        nearby_lines = []

        for candidate in lines:
            if candidate["index"] in selected_indexes:
                continue

            candidate_text = normalize_text(candidate["text"])
            left, top, right, bottom = line_bounds(candidate)

            if top < last_bottom - line_height * 0.35 or top - last_bottom > max_gap:
                continue

            nearby_lines.append((top, left, candidate, candidate_text))

        if not nearby_lines:
            break

        nearby_lines.sort(key=lambda item: (item[0], item[1]))
        next_top = nearby_lines[0][0]
        same_row_tolerance = max(line_height * 0.65, 8.0)
        next_row = [
            (candidate, candidate_text)
            for top, _, candidate, candidate_text in nearby_lines
            if abs(top - next_top) <= same_row_tolerance
        ]

        # Không được bỏ qua một hàng có tín hiệu dừng để "nhảy" xuống hàng
        # thấp hơn: đó thường là ngày cấp, chữ ký hoặc field kế tiếp.
        stop_line_tolerance = max(line_height * 0.75, 12.0)
        has_stop_in_address_region = any(
            (
                not candidate_text
                or is_non_field_annotation(candidate_text)
                or is_residence_stop_line(candidate_text)
                or re.search(r"\b\d{1,2}[/-]\d{1,2}[/-]\d{4}\b", candidate_text)
            )
            and line_bounds(candidate)[2] >= corridor_left - stop_line_tolerance
            for candidate, candidate_text in next_row
        )

        if has_stop_in_address_region:
            break

        row_candidates = []

        for candidate, _ in next_row:
            left, _, right, _ = line_bounds(candidate)

            # Dòng địa chỉ tiếp theo phải còn trong cột của giá trị. Với dòng
            # đầu tiên chưa có value cùng hàng, cho phép bắt đầu ngay sau nhãn.
            horizontal_gap = max(corridor_left - right, left - corridor_right, 0.0)
            max_horizontal_gap = max(line_height * 2.5, (corridor_right - corridor_left) * 0.40)

            if (
                right < label_left - line_height
                or left > max(corridor_right, label_right) + max_horizontal_gap
                or horizontal_gap > max_horizontal_gap
            ):
                continue

            row_candidates.append(candidate)

        if not row_candidates:
            break

        selected_indexes.update(candidate["index"] for candidate in row_candidates)
        selected_lines.extend(row_candidates)
        row_bounds = [line_bounds(candidate) for candidate in row_candidates]
        corridor_left = min(corridor_left, *(bounds[0] for bounds in row_bounds))
        corridor_right = max(corridor_right, *(bounds[2] for bounds in row_bounds))
        last_bottom = max(bounds[3] for bounds in row_bounds)

    return [
        line
        for line in lines
        if line["index"] in selected_indexes
        and line["index"] not in initial_indexes
    ]


def multiline_address_value(
    lines: list[dict[str, Any]],
    label_position: int,
    label_patterns: tuple[str, ...],
) -> tuple[str, list[int]]:
    """Trích xuất và ghép một trường địa chỉ nhiều dòng để truy vết."""

    label_line = lines[label_position]
    label_text = normalize_text(label_line["text"])
    value = inline_value_after_last_label(
        label_text,
        label_patterns,
    )
    source_indexes = [label_line["index"]]

    if not value:
        same_row_value = same_row_value_line(lines, label_position)

        if same_row_value is not None and not is_residence_stop_line(same_row_value["text"]):
            value = normalize_text(same_row_value["text"])
            source_indexes.append(same_row_value["index"])

    continuation_lines = residence_layout_lines(
        lines,
        label_position,
        source_indexes,
    )
    continuation_lines.sort(
        key=lambda line: (line_center(line)[1], line_center(line)[0]),
    )
    continuation_values = [normalize_text(line["text"]) for line in continuation_lines]
    source_indexes.extend(line["index"] for line in continuation_lines)

    return normalize_text(" ".join([value, *continuation_values])), source_indexes


def line_value(lines: list[dict[str, Any]], index: int, field_name: str) -> tuple[str, list[int]]:
    """Lấy giá trị cùng dòng hoặc các dòng kế tiếp gần nhất với nhãn."""

    current_text = normalize_text(lines[index]["text"])

    if field_name == "license_class":
        class_match = re.search(r"\b[A-Z]\d{1,2}\b", current_text)

        if class_match:
            return class_match.group(0), [lines[index]["index"]]

    if field_name in {"document_number", "cccd_number"}:
        number_match = re.search(r"\d{6,}", current_text)

        if number_match:
            return number_match.group(0), [lines[index]["index"]]

        nearby_number = nearby_number_value(lines, index)

        if nearby_number is not None:
            return nearby_number

    if field_name == "registration_plate":
        plate_value = nearby_registration_plate_value(lines, index)

        if plate_value is not None:
            return plate_value

        return "", [lines[index]["index"]]

    if field_name == "owner_name":
        return owner_name_value(lines, index)

    if field_name == "nationality":
        nationality = inline_value_after_last_label(
            current_text,
            (r"quốc\s*tịch", r"quoc\s*tich", r"nationality"),
        )

        if nationality:
            return nationality, [lines[index]["index"]]

    if field_name in {"residence", "vehicle_address"}:
        address_patterns = (
            (
                r"nơi\s+thường\s+trú",
                r"noi\s+thuong\s+tru",
                r"nơi\s+cư\s+trú",
                r"noi\s+cu\s+tru",
                r"place\s+of\s+residence",
                r"address",
            )
            if field_name == "residence"
            else (r"địa\s+chỉ", r"dia\s+chi", r"address")
        )
        residence, source_indexes = multiline_address_value(
            lines,
            index,
            address_patterns,
        )

        if residence:
            return residence, source_indexes

    if field_name == "sex":
        sex_match = re.search(
            r"(?:gioi tinh|sex)\s*[/|:]?\s*(nam|nu|male|female)\b",
            searchable_text(current_text),
        )

        if sex_match:
            normalized_sex = {
                "nam": "Nam",
                "nu": "Nữ",
                "male": "Nam",
                "female": "Nữ",
            }[sex_match.group(1)]

            return normalized_sex, [lines[index]["index"]]

    if field_name in {"date_of_birth", "expiry_date"}:
        date_match = re.search(r"\b\d{1,2}[/-]\d{1,2}[/-]\d{4}\b", current_text)

        if date_match:
            return date_match.group(0), [lines[index]["index"]]

        label_x, label_y = line_center(lines[index])
        date_candidates = []

        for candidate in lines:
            if is_non_field_annotation(candidate["text"]):
                continue

            candidate_x, candidate_y = line_center(candidate)
            vertical_distance = candidate_y - label_y

            if vertical_distance < -10 or vertical_distance > 90 or candidate_x <= label_x:
                continue

            candidate_match = re.search(
                r"\b\d{1,2}[/-]\d{1,2}[/-]\d{4}\b",
                normalize_text(candidate["text"]),
            )

            if candidate_match:
                date_candidates.append(
                    (
                        abs(vertical_distance),
                        candidate_x - label_x,
                        candidate_match.group(0),
                        candidate,
                    )
                )

        if date_candidates:
            _, _, date_value, date_line = min(date_candidates)

            return date_value, [lines[index]["index"], date_line["index"]]

    if ":" in current_text:
        value = normalize_text(current_text.split(":", maxsplit=1)[1])

        if value:
            return value, [lines[index]["index"]]

    row_value = same_row_value_line(lines, index)

    if row_value is not None:
        values = [normalize_text(row_value["text"])]
        source_indexes = [lines[index]["index"], row_value["index"]]

        if field_name in {"residence", "vehicle_address"}:
            _, row_value_y = line_center(row_value)
            continuation = min(
                (
                    candidate
                    for candidate in lines
                    if candidate["index"] not in source_indexes
                    and 0 < line_center(candidate)[1] - row_value_y <= 28
                    and line_center(candidate)[0] > line_center(lines[index])[0]
                ),
                default=None,
                key=lambda candidate: line_center(candidate)[1],
            )

            if continuation is not None:
                values.append(normalize_text(continuation["text"]))
                source_indexes.append(continuation["index"])

        return normalize_text(" ".join(values)), source_indexes

    if field_name in {"residence", "vehicle_address"}:
        label_x, label_y = line_center(lines[index])
        address_candidates = []

        for candidate in lines:
            candidate_key = searchable_text(candidate["text"])
            candidate_x, candidate_y = line_center(candidate)
            vertical_distance = candidate_y - label_y

            if (
                vertical_distance <= 0
                or vertical_distance > 90
                or candidate_x < label_x - 80
                or is_non_field_annotation(candidate["text"])
            ):
                continue

            if any(
                searchable_text(label) in candidate_key
                for labels in FIELD_LABELS.values()
                for label in labels
            ):
                continue

            address_candidates.append(
                (
                    vertical_distance,
                    abs(candidate_x - label_x),
                    candidate,
                )
            )

        if address_candidates:
            _, _, address_line = min(address_candidates)

            return normalize_text(address_line["text"]), [
                lines[index]["index"],
                address_line["index"],
            ]

    collected_lines = []
    source_indexes = [lines[index]["index"]]
    maximum_lines = 2 if field_name in {"residence", "vehicle_address"} else 1

    for candidate in lines[index + 1 : index + 1 + maximum_lines]:
        candidate_key = searchable_text(candidate["text"])

        if is_non_field_annotation(candidate["text"]):
            continue

        if any(
            searchable_text(label) in candidate_key
            for labels in FIELD_LABELS.values()
            for label in labels
        ):
            break

        if any(
            keyword in candidate_key
            for keywords in VERIFICATION_KEYWORDS.values()
            for keyword in keywords
        ):
            break

        collected_lines.append(normalize_text(candidate["text"]))
        source_indexes.append(candidate["index"])

    return normalize_text(" ".join(collected_lines)), source_indexes


def format_value(field_name: str, value: str) -> str:
    """Áp dụng format an toàn, không sửa dữ liệu khi không chắc chắn."""

    value = normalize_text(value)

    if field_name in {"date_of_birth", "expiry_date"}:
        match = re.search(r"\b\d{1,2}[/-]\d{1,2}[/-]\d{4}\b", value)

        if match:
            return match.group(0).replace("-", "/")

    if field_name in {"document_number", "cccd_number"}:
        digits = "".join(re.findall(r"\d", value))

        if len(digits) >= 6:
            return digits

    if field_name in {"full_name", "owner_name"} and value.isupper():
        return value.title()

    if field_name == "vehicle_address":
        return sanitize_address(value)

    return value


def field_name_for_document(
    field_name: str,
    document: dict[str, Any] | None,
) -> str:
    """Đặt tên số định danh đúng theo loại giấy tờ đã nhận diện."""

    if (
        field_name == "document_number"
        and document is not None
        and document.get("document_type") == "citizen_identity_card"
    ):
        return "cccd_number"

    return field_name


def extract_fields(
    lines: list[dict[str, Any]],
    document: dict[str, Any] | None,
) -> dict[str, Any]:
    """Trích xuất các field phổ biến theo nhãn và loại giấy tờ."""

    fields = {}

    for field_name, labels in FIELD_LABELS.items():
        for position, line in enumerate(lines):
            if not has_field_label(line["text"], labels):
                continue

            if (
                field_name == "full_name"
                and has_field_label(line["text"], FIELD_LABELS["owner_name"])
            ):
                continue

            normalized_field_name = field_name_for_document(field_name, document)
            raw_value, source_indexes = line_value(
                lines,
                position,
                normalized_field_name,
            )

            if not raw_value:
                continue

            fields[normalized_field_name] = {
                "label": normalize_text(line["text"]),
                "raw_value": raw_value,
                "normalized_value": format_value(normalized_field_name, raw_value),
                "confidence": line.get("confidence"),
                "source_line_indexes": source_indexes,
            }
            break

    vehicle_markers = {
        "brand",
        "model_code",
        "engine_number",
        "chassis_number",
        "registration_plate",
    }

    if "owner_name" not in fields and len(vehicle_markers.intersection(fields)) >= 3:
        owner_fallback = owner_name_above_address(lines)

        if owner_fallback is not None:
            owner_name, source_indexes = owner_fallback
            source_line = next(
                (
                    line
                    for line in lines
                    if line["index"] == source_indexes[0]
                ),
                None,
            )

            fields["owner_name"] = {
                "label": "Tên chủ xe (fallback bố cục)",
                "raw_value": owner_name,
                "normalized_value": format_value("owner_name", owner_name),
                "confidence": source_line.get("confidence") if source_line else None,
                "source_line_indexes": source_indexes,
            }

    return fields


def extract_document_title(lines: list[dict[str, Any]]) -> dict[str, Any] | None:
    """Nhận diện tiêu đề loại giấy tờ nhưng vẫn giữ nguyên text OCR nguồn."""

    for line in lines:
        line_key = searchable_text(line["text"])

        for document_type, patterns in DOCUMENT_TITLE_PATTERNS.items():
            if any(pattern in line_key for pattern in patterns):
                return {
                    "document_type": document_type,
                    "raw_value": normalize_text(line["text"]),
                    "normalized_value": DOCUMENT_TYPE_LABELS[document_type],
                    "confidence": line.get("confidence"),
                    "source_line_indexes": [line["index"]],
                }

    return None


def infer_document_from_fields(fields: dict[str, Any]) -> dict[str, Any] | None:
    """Suy ra loại giấy tờ từ nhãn đặc trưng khi tiêu đề bị OCR bỏ sót.

    Ảnh chụp thẻ nhựa dễ bị lóa ngay vùng tiêu đề, trong khi nhãn ``Hạng/Class``
    ở phần thân thẻ vẫn còn rõ. Đây chỉ là fallback phân loại giao diện, không
    tự tạo hay thay đổi giá trị OCR của bất kỳ trường nào.
    """

    vehicle_markers = {
        "owner_name",
        "brand",
        "model_code",
        "engine_number",
        "chassis_number",
        "registration_plate",
    }
    matched_vehicle_markers = vehicle_markers.intersection(fields)

    if len(matched_vehicle_markers) >= 3:
        source_fields = [
            fields[field_name]
            for field_name in matched_vehicle_markers
        ]

        return {
            "document_type": "vehicle_registration",
            "raw_value": "Nhận diện từ nhãn trường đăng ký xe",
            "normalized_value": DOCUMENT_TYPE_LABELS["vehicle_registration"],
            "confidence": min(
                field["confidence"]
                for field in source_fields
                if field["confidence"] is not None
            )
            if any(field["confidence"] is not None for field in source_fields)
            else None,
            "source_line_indexes": sorted(
                {
                    source_index
                    for field in source_fields
                    for source_index in field["source_line_indexes"]
                }
            ),
            "detection_method": "field_label_fallback",
        }

    license_class = fields.get("license_class")

    if license_class is not None:
        return {
            "document_type": "driver_license",
            "raw_value": license_class["label"],
            "normalized_value": "Giấy phép lái xe / Driver's License",
            "confidence": license_class["confidence"],
            "source_line_indexes": license_class["source_line_indexes"],
            "detection_method": "field_label_fallback",
        }

    cccd_markers = {
        "document_number",
        "place_of_origin",
        "sex",
    }

    if len(cccd_markers.intersection(fields)) >= 2:
        source_fields = [
            fields[field_name]
            for field_name in cccd_markers
            if field_name in fields
        ]

        return {
            "document_type": "citizen_identity_card",
            "raw_value": "Nhận diện từ nhãn trường CCCD",
            "normalized_value": "Căn cước công dân / Citizen Identity Card",
            "confidence": min(
                field["confidence"]
                for field in source_fields
                if field["confidence"] is not None
            )
            if any(field["confidence"] is not None for field in source_fields)
            else None,
            "source_line_indexes": sorted(
                {
                    source_index
                    for field in source_fields
                    for source_index in field["source_line_indexes"]
                }
            ),
            "detection_method": "field_label_fallback",
        }

    return None


def extract_verification(lines: list[dict[str, Any]], fields: dict[str, Any]) -> dict[str, Any]:
    """Lọc riêng cơ quan, chức danh và ứng viên người ký/xác thực."""

    matched_lines = []
    signer_title = None
    issuer = []
    field_source_indexes = {
        source_index
        for field in fields.values()
        for source_index in field["source_line_indexes"]
    }

    for line in lines:
        line_key = searchable_text(line["text"])
        categories = [
            category
            for category, keywords in VERIFICATION_KEYWORDS.items()
            if any(keyword in line_key for keyword in keywords)
        ]

        if not categories:
            continue

        matched_lines.append(
            {
                "index": line["index"],
                "text": normalize_text(line["text"]),
                "confidence": line.get("confidence"),
                "categories": categories,
            }
        )

        if "issuer" in categories:
            issuer.append(normalize_text(line["text"]))

        if "signer_title" in categories and signer_title is None:
            signer_title = normalize_text(line["text"])

    signer_name_candidates = [
        {
            "index": line["index"],
            "text": normalize_text(line["text"]).title(),
            "confidence": line.get("confidence"),
        }
        for line in lines
        if line["index"] not in field_source_indexes
        and not is_non_field_annotation(line["text"])
        and re.fullmatch(r"[A-ZÀ-ỴĐ ]{6,}", normalize_text(line["text"]))
        and len(normalize_text(line["text"]).split()) in range(2, 5)
        and not any(
            keyword in searchable_text(line["text"])
            for keyword in (
                "bo ",
                "gtvt",
                "giam doc",
                "truong phong",
                "giao thong",
                "van tai",
            )
        )
    ]

    return {
        "issuer": issuer or None,
        "signer_title": signer_title,
        "signer_name_candidates": signer_name_candidates,
        "matched_lines": matched_lines,
    }


def detect_and_crop_face(image_path: Path, result_name: str, output_dir: Path) -> dict[str, Any]:
    """Detect một khuôn mặt lớn nhất và lưu crop riêng tư dưới output/faces."""

    image = cv2.imread(str(image_path))

    if image is None:
        raise FileNotFoundError(f"Không thể đọc ảnh để detect khuôn mặt: {image_path}")

    if not FACE_DETECTOR_MODEL.is_file():
        return {
            "status": "model_not_available",
            "detection_model": "YuNet",
        }

    detector = cv2.FaceDetectorYN_create(
        str(FACE_DETECTOR_MODEL),
        "",
        (image.shape[1], image.shape[0]),
        0.55,
        0.3,
        5000,
    )
    _, faces = detector.detect(image)

    if faces is None or len(faces) == 0:
        return {
            "status": "not_detected",
            "detection_model": "YuNet",
        }

    face = max(faces, key=lambda candidate: float(candidate[2] * candidate[3] * candidate[-1]))
    x, y, width, height = [int(round(value)) for value in face[:4]]
    padding_x = int(round(width * 0.15))
    padding_y = int(round(height * 0.15))
    left = max(0, x - padding_x)
    top = max(0, y - padding_y)
    right = min(image.shape[1], x + width + padding_x)
    bottom = min(image.shape[0], y + height + padding_y)
    crop = image[top:bottom, left:right]

    faces_dir = output_dir / "faces"
    faces_dir.mkdir(parents=True, exist_ok=True)
    crop_path = faces_dir / f"face_{result_name}.jpg"

    if not cv2.imwrite(str(crop_path), crop, [cv2.IMWRITE_JPEG_QUALITY, 95]):
        raise RuntimeError(f"Không thể lưu face crop: {crop_path}")

    return {
        "status": "detected",
        "detection_model": "YuNet",
        "path": str(crop_path.relative_to(output_dir)),
        "bbox": [x, y, width, height],
        "crop_bbox": [left, top, right - left, bottom - top],
        "confidence": float(face[-1]),
    }


def build_structured_result(
    raw_result: dict[str, Any],
    image_path: Path,
    result_name: str,
    output_dir: Path,
) -> dict[str, Any]:
    """Tạo dữ liệu field/verification và metadata face crop từ raw OCR."""

    lines = raw_result.get("lines", [])
    document = extract_document_title(lines)
    fields = extract_fields(lines, document)

    if document is None:
        document = infer_document_from_fields(fields)

        if document is not None and document["document_type"] == "citizen_identity_card":
            fields = extract_fields(lines, document)

    verification = extract_verification(lines, fields)
    face_crop = detect_and_crop_face(image_path, result_name, output_dir)
    consumed_indexes = {
        source_index
        for field in fields.values()
        for source_index in field["source_line_indexes"]
    }
    consumed_indexes.update(
        line["index"] for line in verification["matched_lines"]
    )

    if document is not None:
        consumed_indexes.update(document["source_line_indexes"])

    unmapped_raw_lines = [
        {
            "index": line["index"],
            "text": normalize_text(line["text"]),
            "confidence": line.get("confidence"),
            "polygon": line["polygon"],
        }
        for line in lines
        if line["index"] not in consumed_indexes
    ]

    return {
        "status": "completed",
        "input": raw_result.get("input", {}),
        "ocr_engine": raw_result.get("engine", {}),
        "document": document,
        "fields": fields,
        "verification": verification,
        "face_crop": face_crop,
        "unmapped_raw_lines": unmapped_raw_lines,
        "raw_ocr_path": f"ocr_raw_result_{result_name}.json",
    }
