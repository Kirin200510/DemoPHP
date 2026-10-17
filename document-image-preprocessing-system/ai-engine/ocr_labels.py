"""Shared, bounded label recognition for every supported document field.

Only label spans are canonicalized. Names, addresses and identifiers outside
those spans remain untouched; raw OCR is always retained by the caller.
"""

import copy
import re
import unicodedata
from dataclasses import dataclass
from difflib import SequenceMatcher
from functools import lru_cache


FIELD_LABELS = {
    "personal_identification_number": ("so dinh danh ca nhan", "personal identification number"),
    "full_name": ("ho ten", "ho va ten", "ho chu dem va ten khai sinh", "full name"),
    "date_of_birth": ("ngay sinh", "ngay thang nam sinh", "date of birth", "date of binh"),
    "sex": ("gioi tinh", "sex"),
    "nationality": ("quoc tich", "nationality"),
    "place_of_origin": ("que quan", "place of origin"),
    "personal_identification": ("dac diem nhan dang", "personal identification"),
    "date_month_year": ("ngay thang nam", "date month year"),
    "residence": ("noi thuong tru", "noi cu tru", "noi cu", "place of residence", "plate of residence"),
    "place_of_birth": ("noi dang ky khai sinh", "place of birth"),
    "issue_date": ("ngay thang nam cap", "date of issue"),
    "document_number": ("so no", "so i no", "so ino", "so cccd", "id no"),
    "license_class": ("hang class", "class"),
    "expiry_date": ("co gia tri den", "ngay thang nam het han", "date of expiry", "expiry date", "expires", "dale of expity"),
    "owner_name": ("ten chu xe", "owner s full name", "owners full name"),
    "vehicle_address": ("dia chi", "address"),
    "brand": ("nhan hieu", "brand"),
    "model_code": ("so loai", "model code"),
    "engine_number": ("so may", "engine no", "engine number"),
    "chassis_number": ("so khung", "chassis no", "chassis number"),
    "paint_color": ("mau son", "color"),
    "operating_scope": ("hoat dong trong pham vi", "operating scope"),
    "registration_plate": ("bien so dang ky", "no plate", "n plate", "number plate"),
    "seating_capacity": ("so cho ngoi", "seating capacity", "number of seats"),
    "engine_power": ("cong suat", "power"),
    "vehicle_type": ("loai xe", "vehicle type", "loai xe type"),
    "engine_displacement": ("dung tich", "displacement", "cylinder capacity"),
}

CANONICAL_FIELD_LABELS = {
    "personal_identification_number": "Số định danh cá nhân/Personal identification number",
    "full_name": "Họ và tên/Full name",
    "date_of_birth": "Ngày, tháng, năm sinh/Date of birth",
    "sex": "Giới tính/Sex",
    "nationality": "Quốc tịch/Nationality",
    "place_of_origin": "Quê quán/Place of origin",
    "personal_identification": "Đặc điểm nhân dạng/Personal identification",
    "date_month_year": "Ngày, tháng, năm/Date, month, year",
    "residence": "Nơi cư trú/Place of residence",
    "place_of_birth": "Nơi đăng ký khai sinh/Place of birth",
    "issue_date": "Ngày, tháng, năm cấp/Date of issue",
    "document_number": "Số/No",
    "license_class": "Hạng/Class",
    "expiry_date": "Ngày, tháng, năm hết hạn/Date of expiry",
    "owner_name": "Tên chủ xe/Owner's full name",
    "vehicle_address": "Địa chỉ/Address",
    "brand": "Nhãn hiệu/Brand",
    "model_code": "Số loại/Model code",
    "engine_number": "Số máy/Engine number",
    "chassis_number": "Số khung/Chassis number",
    "paint_color": "Màu sơn/Color",
    "operating_scope": "Hoạt động trong phạm vi/Operating scope",
    "registration_plate": "Biển số đăng ký/Number plate",
    "seating_capacity": "Số chỗ ngồi/Seating capacity",
    "engine_power": "Công suất/Power",
    "vehicle_type": "Loại xe/Vehicle type",
    "engine_displacement": "Dung tích/Cylinder capacity",
}

# Observed OCR artifacts, applied to all fields only at a label boundary.
LABEL_NOISE_SUFFIXES = ("sentine",)


def label_key(text: str) -> str:
    text = unicodedata.normalize("NFD", text.lower().replace("đ", "d"))
    return re.sub(r"[^a-z0-9]", "", "".join(c for c in text if unicodedata.category(c) != "Mn"))


@dataclass(frozen=True)
class LabelMatch:
    field: str
    start: int
    end: int
    score: float


@lru_cache(maxsize=8192)
def find_label_matches(text: str) -> tuple[LabelMatch, ...]:
    """Resolve competing labels together, preferring exact and longer labels.

    In particular, the generic date label must not win over a birth/issue/
    expiry label and the personal-identification label must not win over
    personal-identification-number. Ambiguous fuzzy candidates are omitted.
    """

    tokens = list(re.finditer(r"[^\W_]+", text, flags=re.UNICODE))
    candidates = []
    for field, aliases in FIELD_LABELS.items():
        for alias in aliases:
            expected = label_key(alias)
            for start in range(len(tokens)):
                for end in range(start + 1, min(len(tokens), start + len(alias.split()) + 2) + 1):
                    span = text[tokens[start].start():tokens[end - 1].end()]
                    # A colon separates a label from its value; never span it.
                    if ":" in span:
                        continue
                    has_value_separator = bool(re.match(r"\s*:", text[tokens[end - 1].end():]))
                    prefix = text[:tokens[start].start()].rstrip()
                    suffix = text[tokens[end - 1].end():].lstrip()
                    has_bilingual_partner = any(
                        label_key(partner) != expected
                        and len(label_key(partner)) >= 5
                        and (
                            (prefix.endswith(("/", "|")) and label_key(prefix).endswith(label_key(partner)))
                            or (suffix.startswith(("/", "|")) and label_key(suffix).startswith(label_key(partner)))
                        )
                        for partner in aliases
                    )
                    if ":" in prefix and not has_value_separator and not has_bilingual_partner:
                        continue
                    if (
                        len(expected) < 8
                        and start > 0
                        and not has_value_separator
                        and not has_bilingual_partner
                        and not text[:tokens[start].start()].rstrip().endswith(("/", "|"))
                    ):
                        continue
                    actual = label_key(span)
                    digit_count = sum(character.isdigit() for character in actual)
                    if digit_count:
                        if digit_count / len(actual) > 0.2:
                            continue
                        actual = actual.translate(str.maketrans({"0": "o", "1": "i", "5": "s"}))
                    if not actual or min(len(actual), len(expected)) / max(len(actual), len(expected)) < 0.80:
                        continue
                    score = 1.0 if actual == expected else SequenceMatcher(None, expected, actual).ratio()
                    threshold = 0.88 if len(expected) >= 12 else 0.92
                    if score < 1.0 and (len(expected) < 8 or score < threshold):
                        continue
                    candidates.append(LabelMatch(field, tokens[start].start(), tokens[end - 1].end(), score))

    selected = []
    for match in sorted(set(candidates), key=lambda item: (-item.score, -(item.end - item.start), item.start)):
        if any(match.start < item.end and item.start < match.end for item in selected):
            continue
        if match.score < 1.0 and any(
            other.field != match.field
            and other.start == match.start
            and other.end == match.end
            and abs(other.score - match.score) < 0.04
            for other in candidates
        ):
            continue
        selected.append(match)
    return tuple(sorted(selected, key=lambda item: item.start))


def clean_ocr_lines(lines: list[dict]) -> list[dict]:
    """Return a working copy with canonical label spans and traceable edits."""

    cleaned = []
    for line in lines:
        item = copy.deepcopy(line)
        raw_text = item.get("raw_text", item["text"])
        matches = []
        for match in find_label_matches(raw_text):
            suffix = re.match(
                r"\s+(?:" + "|".join(map(re.escape, LABEL_NOISE_SUFFIXES)) + r")(?=\s*(?::|$))",
                raw_text[match.end:],
                flags=re.IGNORECASE,
            )
            if suffix:
                match = LabelMatch(match.field, match.start, match.end + suffix.end(), match.score)
            if (
                matches
                and matches[-1].field == match.field
                and (
                    re.fullmatch(r"[\s/|]*", raw_text[matches[-1].end:match.start])
                    or is_noisy_bilingual_gap(raw_text, matches[-1], match)
                )
            ):
                previous = matches.pop()
                match = LabelMatch(match.field, previous.start, match.end, min(previous.score, match.score))
            matches.append(match)
        corrections = []
        text = raw_text
        for match in reversed(matches):
            canonical = CANONICAL_FIELD_LABELS[match.field]
            text = text[:match.start] + canonical + text[match.end:]
            corrections.append({
                "field": match.field,
                "raw_label": raw_text[match.start:match.end],
                "canonical_label": canonical,
                "score": match.score,
                "source_span": [match.start, match.end],
            })
        item["raw_text"] = raw_text
        item["text"] = text
        item["label_matches"] = list(reversed(corrections))
        cleaned.append(item)
    return cleaned


def is_noisy_bilingual_gap(text: str, previous: LabelMatch, following: LabelMatch) -> bool:
    """Extend a label to its bilingual delimiter, not into its value."""

    gap = text[previous.end:following.start]
    if not re.fullmatch(r"(?:\s+[^\W\d_]{1,4}){1,2}\s*[/|]\s*", gap):
        return False
    left_label = re.split(r"[/|]", text[previous.start:following.start])[0]
    return any(
        SequenceMatcher(None, label_key(left_label), label_key(alias)).ratio() >= 0.8
        for alias in FIELD_LABELS[previous.field]
    )
