"""Local barcode decoding and explicit, conservative identity payload profiles.

Decoding is not document authentication or decryption. Unknown/binary layouts
are preserved for review rather than searched for arbitrary 12-digit strings.
"""

import base64
import json
import re
import unicodedata
from datetime import datetime
from pathlib import Path

import cv2
import zxingcpp


IDENTITY_KEYS = {
    "personalidentificationnumber", "cccdnumber", "documentnumber",
    "socccd", "sodinhdanhcanhan",
}
FIELD_KEYS = {
    "fullname": "full_name", "hovaten": "full_name",
    "dateofbirth": "date_of_birth", "ngaysinh": "date_of_birth",
    "sex": "sex", "gioitinh": "sex",
    "residence": "residence", "noicutru": "residence",
    "issuedate": "issue_date", "ngaycap": "issue_date",
}


def key_name(value: str) -> str:
    normalized = unicodedata.normalize("NFD", value.lower().replace("đ", "d"))
    return re.sub(r"[^a-z0-9]", "", normalized)


def normalized_date(value: str) -> str | None:
    for pattern in ("%d/%m/%Y", "%Y-%m-%d", "%d%m%Y"):
        try:
            return datetime.strptime(value, pattern).strftime("%d/%m/%Y")
        except ValueError:
            continue
    return None


def unique_json_fields(pairs: list[tuple[str, object]]) -> dict:
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Duplicate barcode payload key")
        result[key] = value
    return result


def _read_barcodes(image):
    return zxingcpp.read_barcodes(
        image, try_rotate=True, try_downscale=True, try_invert=True
    )


def _read_barcode_region(image):
    """Retry low-resolution cards with their common top-edge PDF417 region enlarged."""
    height, width = image.shape[:2]
    if height < 2 or width < 2:
        return []

    region = image[: max(1, int(height * 0.30)), :]
    scale = min(3.0, 2400 / width)
    if scale <= 1.0:
        return []

    enlarged = cv2.resize(
        region, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC
    )
    return _read_barcodes(enlarged)


def parse_identity_payload(raw: bytes, barcode_format: str) -> dict:
    unknown = {"status": "unsupported", "profile": None, "fields": {}}
    if len(raw) > 8192:
        return unknown
    try:
        text = raw.decode("utf-8").strip()
    except UnicodeDecodeError:
        return unknown
    fields = {}
    profile = None
    if re.fullmatch(r"[0-9]{12}", text):
        fields = {"personal_identification_number": text}
        profile = "identity_number_only"
    elif text.startswith("{"):
        try:
            data = json.loads(text, object_pairs_hook=unique_json_fields)
        except (ValueError, RecursionError):
            return unknown
        if not isinstance(data, dict):
            return unknown
        for key, value in data.items():
            name = key_name(key)
            target = "personal_identification_number" if name in IDENTITY_KEYS else FIELD_KEYS.get(name)
            if target:
                if not isinstance(value, str):
                    return unknown
                value = value.strip()
                if target in fields and fields[target] != value:
                    return unknown
                fields[target] = value
        profile = "identity_keyed_json"
    elif barcode_format == "QRCode":
        parts = text.split("|")
        if len(parts) != 7 or not re.fullmatch(r"[0-9]{12}", parts[0]):
            return unknown
        if parts[1] and not re.fullmatch(r"[0-9]{9}|[0-9]{12}", parts[1]):
            return unknown
        if not parts[2].strip() or parts[4].strip().lower() not in {"nam", "nữ", "nu", "male", "female"}:
            return unknown
        if not normalized_date(parts[3]) or not normalized_date(parts[6]):
            return unknown
        fields = dict(zip(
            ("personal_identification_number", "previous_id_number", "full_name", "date_of_birth", "sex", "residence", "issue_date"),
            parts,
        ))
        profile = "vn_identity_qr_pipe_7"
    if not re.fullmatch(r"[0-9]{12}", fields.get("personal_identification_number", "")):
        return unknown
    for field in ("date_of_birth", "issue_date"):
        if field in fields:
            date = normalized_date(fields[field])
            if date is None:
                return unknown
            fields[field] = date
    if "sex" in fields:
        sexes = {"nam": "Nam", "male": "Nam", "m": "Nam", "nữ": "Nữ", "nu": "Nữ", "female": "Nữ", "f": "Nữ"}
        sex = sexes.get(fields["sex"].lower())
        if sex is None:
            return unknown
        fields["sex"] = sex
    if "full_name" in fields and (not fields["full_name"] or any(
        not (character.isalpha() or character.isspace() or character in "-'") for character in fields["full_name"]
    )):
        return unknown
    return {"status": "parsed", "profile": profile, "fields": fields}


def decode_document_barcodes(image_path: Path, original_path: Path | None = None) -> dict:
    records = []
    seen = set()
    warnings = []
    paths = [("processed", image_path)]
    if original_path is not None and original_path != image_path:
        paths.append(("original", original_path))
    for stage, path in paths:
        image = cv2.imread(str(path)) if path.is_file() else None
        if image is None:
            continue
        try:
            decoded = _read_barcodes(image)
        except (ValueError, RuntimeError):
            warnings.append("barcode_decoder_failed")
            continue

        if not any(barcode.valid for barcode in decoded):
            try:
                decoded = _read_barcode_region(image)
            except (ValueError, RuntimeError):
                if "barcode_decoder_failed" not in warnings:
                    warnings.append("barcode_decoder_failed")
                continue

        for barcode in decoded[:16]:
            if not barcode.valid:
                continue
            raw = bytes(barcode.bytes)
            barcode_format = barcode.format.name
            if (barcode_format, raw) in seen:
                continue
            seen.add((barcode_format, raw))
            records.append({
                "format": barcode_format,
                "source_stage": stage,
                "content_type": barcode.content_type.name,
                "byte_length": len(raw),
                "symbology_identifier": barcode.symbology_identifier,
                "raw_bytes_base64": base64.b64encode(raw).decode("ascii"),
                "raw_text": barcode.text,
                "parsed": parse_identity_payload(raw, barcode_format),
            })
    return {
        "decoder": "zxing-cpp", "status": "decoded" if records else "not_detected",
        "records": records, "warnings": warnings,
    }
