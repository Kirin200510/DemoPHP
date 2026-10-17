"""Independent matching of front/back document OCR results."""

import re
import unicodedata
from datetime import datetime
from typing import Any


CCCD_TYPE = "citizen_identity_card"
FRONT_MARKERS = {"cccd_number", "personal_identification_number", "full_name", "date_of_birth", "sex", "nationality"}
BACK_MARKERS = {
    "mrz_prefix_9",
    "mrz_document_number",
    "place_of_birth",
    "issue_date",
    "expiry_date",
    "personal_identification",
    "date_month_year",
}

PAIR_ERRORS = {
    "ERR_PAIR_MISMATCH": (400, "Mặt trước và mặt sau không thuộc cùng một giấy tờ hoặc có thông tin định danh mâu thuẫn."),
    "ERR_PAIR_SIDE_INVALID": (422, "Cần một ảnh mặt trước và một ảnh mặt sau của cùng giấy tờ."),
    "ERR_PAIR_INSUFFICIENT_EVIDENCE": (422, "Chưa đủ thông tin để đối chiếu hai mặt. Hãy chọn ảnh rõ hơn hoặc yêu cầu nhân viên kiểm tra."),
    "ERR_PAIR_CONFLICTING_EVIDENCE": (422, "Các nguồn dữ liệu định danh mâu thuẫn. Cần kiểm tra trước khi gộp hồ sơ."),
}


def rejected_pair(code: str, reason: str, **details: Any) -> dict[str, Any]:
    http_status, message = PAIR_ERRORS[code]
    return {
        "status": "not_matched" if code == "ERR_PAIR_MISMATCH" else "needs_review",
        "document_type": CCCD_TYPE, "reason": reason, "http_status": http_status,
        "error": {"code": code, "message": message}, **details,
    }


def identity_barcodes(result: dict[str, Any]) -> list[dict[str, Any]]:
    records = (result.get("barcodes") or {}).get("records", [])
    return [record for record in records if (
        (record.get("parsed") or {}).get("status") == "parsed"
        and re.fullmatch(r"[0-9]{12}", (record.get("parsed", {}).get("fields") or {}).get("personal_identification_number", ""))
    )]


def comparable_name(value: str) -> str:
    value = unicodedata.normalize("NFD", value.upper().replace("đ", "d").replace("Đ", "D"))
    return "".join(character for character in value if "A" <= character <= "Z")


def field_value(result: dict[str, Any], field_name: str) -> str | None:
    """Return a non-empty normalized OCR field without exposing raw OCR lines."""

    field = (result.get("fields") or {}).get(field_name, {})

    if field.get("extraction_status") == "not_detected":
        return None

    value = field.get("normalized_value")

    return str(value).strip() if value else None


def detect_cccd_side(result: dict[str, Any]) -> dict[str, Any]:
    """Classify one already-OCR'd result as CCCD front, back, or unknown."""

    document_type = (result.get("document") or {}).get("document_type")
    field_names = {
        field_name
        for field_name, field in (result.get("fields") or {}).items()
        if field.get("extraction_status") != "not_detected"
        and field.get("normalized_value")
    }

    if document_type != CCCD_TYPE:
        return {
            "side": "unknown",
            "document_type": document_type,
            "confidence": "not_cccd",
        }

    front_score = len(field_names.intersection(FRONT_MARKERS))
    back_score = len(field_names.intersection(BACK_MARKERS))
    if any(record.get("format") == "PDF417" for record in (result.get("barcodes") or {}).get("records", [])):
        back_score += 3
    if field_names.intersection({"mrz_document_number", "mrz_prefix_9"}):
        back_score += 3

    if front_score >= 2 and front_score > back_score:
        side = "front"
    elif back_score >= 2 and back_score > front_score:
        side = "back"
    else:
        side = "unknown"

    return {
        "side": side,
        "document_type": document_type,
        "confidence": "high" if side != "unknown" else "needs_review",
        "front_marker_count": front_score,
        "back_marker_count": back_score,
    }


def match_citizen_identity_card_sides(
    first_result: dict[str, Any],
    second_result: dict[str, Any],
) -> dict[str, Any]:
    """Match two independent OCR results before a front/back CCCD merge.

    A pair is accepted only if its detected sides differ and the front number
    matches readable MRZ positions or an explicitly parsed back-side barcode.
    Conflicting or unreadable sources never result in an automatic merge.
    """

    first_side = detect_cccd_side(first_result)
    second_side = detect_cccd_side(second_result)

    if {first_side["side"], second_side["side"]} != {"front", "back"}:
        return rejected_pair(
            "ERR_PAIR_SIDE_INVALID", "could_not_identify_one_front_and_one_back_side",
            sides={"first": first_side, "second": second_side},
        )

    front_result, back_result = (
        (first_result, second_result)
        if first_side["side"] == "front"
        else (second_result, first_result)
    )
    front_number = (
        field_value(front_result, "personal_identification_number")
        or field_value(front_result, "cccd_number")
    )
    back_prefix_9 = field_value(back_result, "mrz_prefix_9")
    back_number = field_value(back_result, "mrz_document_number")
    sides = {"first": first_side, "second": second_side}
    front_identifiers = {
        value for value in (
            field_value(front_result, "personal_identification_number"),
            field_value(front_result, "cccd_number"),
        ) if value
    }
    if len(front_identifiers) > 1:
        return rejected_pair("ERR_PAIR_CONFLICTING_EVIDENCE", "front_identifiers_conflict", sides=sides)
    back_barcodes = identity_barcodes(back_result)
    front_barcodes = identity_barcodes(front_result)
    barcode_records = [*front_barcodes, *back_barcodes]
    front_barcode_numbers = {record["parsed"]["fields"]["personal_identification_number"] for record in front_barcodes}
    back_barcode_numbers = {record["parsed"]["fields"]["personal_identification_number"] for record in back_barcodes}
    if (len(front_barcode_numbers) > 1 or len(back_barcode_numbers) > 1
        or (front_number and front_barcode_numbers and front_number not in front_barcode_numbers)
        or (back_number and back_barcode_numbers and back_number not in back_barcode_numbers)
        or (back_prefix_9 and back_barcode_numbers and any(number[3:] != back_prefix_9 for number in back_barcode_numbers))):
        return rejected_pair("ERR_PAIR_CONFLICTING_EVIDENCE", "identity_sources_conflict", sides=sides)
    if not front_number or not re.fullmatch(r"[0-9]{12}", front_number):
        return rejected_pair("ERR_PAIR_INSUFFICIENT_EVIDENCE", "front_identifier_not_available", sides=sides)

    checks = {}
    mrz = back_result.get("mrz") or {}
    mrz_readable = bool(back_number and back_prefix_9) and (
        not mrz or mrz.get("format_valid") is True
    )
    if mrz_readable:
        if not re.fullmatch(r"[0-9]{12}", back_number) or not re.fullmatch(r"[0-9]{9}", back_prefix_9):
            return rejected_pair("ERR_PAIR_INSUFFICIENT_EVIDENCE", "mrz_identifier_invalid", sides=sides)
        checks.update({
            "mrz_positions_6_14_matches_front_suffix": front_number[3:] == back_prefix_9,
            "mrz_positions_16_27_matches_front_number": front_number == back_number,
        })
    if back_barcodes:
        checks["barcode_identifier_matches_front_number"] = front_number == back_barcodes[0]["parsed"]["fields"]["personal_identification_number"]
    if not checks:
        reason = "barcode_payload_unsupported" if (back_result.get("barcodes") or {}).get("records") else "positioned_mrz_identifier_not_available"
        return rejected_pair("ERR_PAIR_INSUFFICIENT_EVIDENCE", reason, sides=sides)

    if not all(checks.values()):
        return rejected_pair(
            "ERR_PAIR_MISMATCH", "cccd_number_does_not_match_mrz_positions" if mrz_readable else "cccd_number_does_not_match_barcode",
            sides=sides, checks=checks,
        )

    for record in barcode_records:
        values = record["parsed"]["fields"]
        for field_name in ("full_name", "date_of_birth", "sex"):
            front_value = field_value(front_result, field_name)
            barcode_value = values.get(field_name)
            if not front_value or not barcode_value:
                continue
            if field_name == "date_of_birth":
                try:
                    matched = datetime.strptime(front_value, "%d/%m/%Y") == datetime.strptime(barcode_value, "%d/%m/%Y")
                except ValueError:
                    return rejected_pair("ERR_PAIR_INSUFFICIENT_EVIDENCE", "date_of_birth_unreadable", sides=sides, checks=checks)
            elif field_name == "sex":
                sexes = {"nam": "M", "male": "M", "m": "M", "nữ": "F", "nu": "F", "female": "F", "f": "F"}
                matched = sexes.get(front_value.lower()) == sexes.get(barcode_value.lower())
                if sexes.get(front_value.lower()) is None or sexes.get(barcode_value.lower()) is None:
                    return rejected_pair("ERR_PAIR_INSUFFICIENT_EVIDENCE", "sex_unreadable", sides=sides, checks=checks)
            else:
                matched = comparable_name(front_value) == comparable_name(barcode_value)
            key = f"barcode_{field_name}_matches_front"
            checks[key] = checks.get(key, True) and matched

    rows = mrz.get("normalized_lines") or []
    if mrz_readable and len(rows) == 3:
        birth = field_value(front_result, "date_of_birth")
        name = field_value(front_result, "full_name")
        if birth:
            try:
                checks["mrz_date_of_birth_matches_front"] = datetime.strptime(birth, "%d/%m/%Y").strftime("%y%m%d") == rows[1][:6]
            except ValueError:
                return rejected_pair("ERR_PAIR_INSUFFICIENT_EVIDENCE", "date_of_birth_unreadable", sides=sides, checks=checks)
        if name:
            checks["mrz_full_name_matches_front"] = comparable_name(name) == comparable_name(rows[2])
    if not all(checks.values()):
        return rejected_pair("ERR_PAIR_MISMATCH", "identity_details_conflict", sides=sides, checks=checks)

    return {
        "status": "matched",
        "document_type": CCCD_TYPE,
        "reason": "cccd_number_matches_mrz_positions" if mrz_readable else "cccd_number_matches_barcode",
        "matching_method": "mrz_and_barcode" if mrz_readable and back_barcodes else "mrz" if mrz_readable else "barcode",
        "sides": {"first": first_side, "second": second_side},
        "checks": checks,
        "front_result": front_result,
        "back_result": back_result,
    }
