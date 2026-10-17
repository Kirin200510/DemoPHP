"""Conservative TD1 cleanup with raw evidence and explicit review states.

This stage validates layout, not authenticity. Check digits are intentionally
not validated here; no characters are invented to make an incomplete row fit.
"""

import re
import unicodedata
from functools import lru_cache
from pathlib import Path
from typing import Any

from mrz_image import load_mrz_image, row_filler_evidence


ROW_PATTERNS = (
    re.compile(r"[ACI][A-Z<][A-Z<]{3}[A-Z0-9<]{9}[0-9<][A-Z0-9<]{15}"),
    re.compile(r"[0-9<]{7}[MF<][0-9<]{7}[A-Z<]{3}[A-Z0-9<]{11}[0-9<]"),
    re.compile(r"[A-Z<]{30}"),
)


def compact_mrz(text: str) -> str:
    return re.sub(r"\s+", "", unicodedata.normalize("NFKC", text).upper())


def trim_padding_overrun(text: str, row_number: int) -> str:
    """Remove only surplus filler-like characters outside TD1 data slots.

    The middle row's final character is the composite check digit. An
    overlong optional-data run precedes it, so keep that digit, including 0.
    Never truncate a row when the removed region contains other data.
    """

    if len(text) <= 30:
        return text
    prefix, surplus = text[:30], text[30:]
    if ROW_PATTERNS[row_number].fullmatch(prefix) and re.fullmatch(r"[<CK0]+", surplus):
        return prefix
    if row_number == 1:
        middle = re.fullmatch(r"([0-9<]{7}[MF<][0-9<]{7}[A-Z<]{3})([<CK0]{12,})([0-9<])", text)
        if middle:
            return middle.group(1) + middle.group(2)[:11] + middle.group(3)
    return text


def repair_visual_fillers(text: str, row_number: int, evidence: list[bool]) -> str | None:
    """Align OCR to 30 observed cells, changing only visually verified fillers.

    Repeated OCR padding may contain insertions or deletions in filler runs.
    Runs of <, C, K and 0 can be collapsed in verified filler cells. A single
    other glyph may be corrected at an internal, image-verified separator,
    but not discarded from trailing padding to force an output to fit.
    Identity and check digits survive unchanged. Equally good different
    outputs are ambiguous and must not be adopted.
    """

    if len(evidence) != 30 or not 20 <= len(text) <= 90:
        return None
    allowed = [
        seen and (row_number == 2 or (row_number == 1 and 18 <= index <= 28) or (row_number == 0 and 27 <= index <= 28))
        for index, seen in enumerate(evidence)
    ]
    trailing_start = 29 if row_number in (0, 1) else 30
    while trailing_start > 0 and allowed[trailing_start - 1]:
        trailing_start -= 1

    @lru_cache(maxsize=None)
    def align(cell: int, offset: int) -> tuple[int, tuple[str, ...]]:
        if cell == 30:
            return (0, ("",)) if offset == len(text) else (10000, ())
        choices = []
        if allowed[cell]:
            if cell < trailing_start and offset < len(text) and text[offset] not in "<CK0":
                choices.append((1, "<", 6))
            for count in range(len(text) - offset + 1):
                if offset + count > len(text):
                    continue
                chunk = text[offset:offset + count]
                if any(character not in "<CK0" for character in chunk):
                    break
                edit_cost = 8 * abs(count - 1) + 2 * sum(character != "<" for character in chunk)
                choices.append((count, "<", edit_cost))
        elif offset < len(text):
            choices.append((1, text[offset], 0))
        best_cost, outputs = 10000, set()
        for count, character, edit_cost in choices:
            tail_cost, tails = align(cell + 1, offset + count)
            cost = tail_cost + edit_cost
            if cost > best_cost or not tails:
                continue
            if cost < best_cost:
                best_cost, outputs = cost, set()
            outputs.update(character + tail for tail in tails)
            outputs = set(sorted(outputs)[:2])
        return best_cost, tuple(sorted(outputs))

    cost, outputs = align(0, 0)
    return outputs[0] if len(outputs) == 1 and cost <= 200 else None


def line_geometry(line: dict, position: int) -> tuple[float, float, float, float]:
    polygon = line.get("polygon")
    if not polygon:
        return 0.0, position * 40.0, 1000.0, 20.0
    xs = [point[0] for point in polygon]
    ys = [point[1] for point in polygon]
    return min(xs), (min(ys) + max(ys)) / 2, max(xs), max(1.0, max(ys) - min(ys))


def group_rows(lines: list[dict]) -> list[dict]:
    """Join disjoint, similarly-sized OCR fragments on the same visual row."""

    items = [(line, line_geometry(line, position)) for position, line in enumerate(lines)]
    groups = []
    for line, (left, center, right, height) in sorted(items, key=lambda item: (item[1][1], item[1][0])):
        target = next((group for group in reversed(groups) if (
            abs(group["center"] - center) <= min(group["height"], height) * 0.55
            and max(group["height"], height) / min(group["height"], height) <= 1.8
            and left >= group["right"] - height * 0.3
        )), None)
        if target is None:
            groups.append({"parts": [line], "left": left, "right": right, "center": center, "height": height})
        else:
            target["parts"].append(line)
            target["right"] = max(target["right"], right)
    return groups


def parse_mrz(lines: list[dict[str, Any]], image_path: Path | None = None) -> dict[str, Any]:
    groups = group_rows(lines)
    headers = []
    header_count = 0
    for index, group in enumerate(groups):
        text = compact_mrz("".join(part["text"] for part in group["parts"]))
        header_count += len(re.findall(r"(?:I|1)D?VNM", text))
        if re.match(r"(?:I|1)D?VNM", text):
            headers.append(index)

    result = {
        "format": "TD1",
        "status": "not_detected",
        "format_valid": False,
        "checksum_validated": False,
        "canonical_text": None,
        "raw_lines": [],
        "normalized_lines": [],
        "candidate_lines": [],
        "source_line_indexes": [],
        "corrections": [],
        "warnings": [],
        "identifier": None,
    }
    if not headers:
        return result
    if len(headers) != 1 or header_count != 1:
        result.update(status="needs_review", warnings=["multiple_mrz_headers"])
        return result

    first = groups[headers[0]]
    image = load_mrz_image(image_path)
    selected = [first]
    for group in groups[headers[0] + 1:]:
        previous = selected[-1]
        if len(selected) == 3:
            break
        if group["center"] - previous["center"] > max(previous["height"], group["height"]) * 2.8:
            break
        overlap = min(first["right"], group["right"]) - max(first["left"], group["left"])
        if overlap <= 0:
            continue
        selected.append(group)

    normalized = []
    for row_number, group in enumerate(selected):
        raw_text = " ".join(part["text"] for part in group["parts"])
        text = compact_mrz(raw_text)
        indexes = [part["index"] for part in group["parts"]]
        result["raw_lines"].append({"text": raw_text, "source_line_indexes": indexes})
        result["source_line_indexes"].extend(indexes)
        if text != raw_text:
            result["corrections"].append({"line": row_number + 1, "operation": "unicode_case_and_spacing", "before": raw_text, "after": text})
        if row_number == 0 and text.startswith("1DVNM"):
            before = text
            text = "I" + text[1:]
            result["corrections"].append({"line": 1, "operation": "document_code_ocr_confusion", "before": before, "after": text})
        before = text
        evidence = row_filler_evidence(image, group["parts"]) if image is not None else None
        visual_text = repair_visual_fillers(text, row_number, evidence) if evidence is not None else None
        if visual_text is not None and visual_text != text:
            text = visual_text
            result["corrections"].append({
                "line": row_number + 1,
                "operation": "image_verified_filler_ocr_confusion",
                "before": before,
                "after": text,
                "filler_positions": [index + 1 for index, seen in enumerate(evidence) if seen],
            })
        before = text
        text = trim_padding_overrun(text, row_number)
        if text != before:
            result["corrections"].append({
                "line": row_number + 1,
                "operation": "remove_surplus_padding",
                "before": before,
                "after": text,
            })
        normalized.append(text)

        candidate = text
        if len(text) != 30:
            result["warnings"].append(f"line_{row_number + 1}_length_{len(text)}_expected_30")
        if row_number == 1 and visual_text is None:
            # Optional data may legitimately contain C/K. Offer a suggestion,
            # never rewrite these characters in the trusted normalized text.
            match = re.fullmatch(r"([0-9<]{7}[MF<][0-9<]{7}[A-Z<]{3})([CK0<]{4,})([0-9<])", candidate)
            if match and any(c in match.group(2) for c in "CK0"):
                filler_length = min(11, len(match.group(2)))
                candidate = match.group(1) + "<" * filler_length + match.group(3)
                result["warnings"].append("line_2_possible_filler_ocr_noise")
        if row_number == 2 and visual_text is None:
            suffix = re.search(r"[CK0<]{4,}$", candidate)
            if suffix:
                padding_start = suffix.start()
                if "<" in suffix.group(0):
                    padding_start += suffix.group(0).index("<")
                padding = candidate[padding_start:]
                if any(c in padding for c in "CK0"):
                    candidate = candidate[:padding_start] + "<" * len(padding)
                    result["warnings"].append("line_3_possible_filler_ocr_noise")
        if row_number == 2 and "<<" not in text:
            result["warnings"].append("line_3_name_separator_not_detected")
        if not ROW_PATTERNS[row_number].fullmatch(text):
            result["warnings"].append(f"line_{row_number + 1}_invalid_structure")
        result["candidate_lines"].append({"line": row_number + 1, "text": candidate, "requires_review": candidate != text})

    if len(normalized) != 3:
        result["warnings"].append("incomplete_mrz")
    result["normalized_lines"] = normalized
    result["format_valid"] = len(normalized) == 3 and not result["warnings"]
    result["status"] = "format_valid" if result["format_valid"] else "needs_review"
    if result["format_valid"]:
        result["canonical_text"] = "\n".join(normalized)

    # Preserve the existing Vietnamese identifier profile. Other parts of an
    # incomplete MRZ are not used to invent identity fields.
    first_text = normalized[0]
    if first_text.startswith("IDVNM") and len(first_text) >= 27 and first_text[5:14].isdigit() and first_text[15:27].isdigit():
        source = selected[0]["parts"]
        confidences = [part["confidence"] for part in source if part.get("confidence") is not None]
        result["identifier"] = {
            "prefix_9": first_text[5:14],
            "document_number": first_text[15:27],
            "sequence": first_text[:27],
            "source_line_indexes": [part["index"] for part in source],
            "confidence": min(confidences) if confidences else None,
        }
    return result
