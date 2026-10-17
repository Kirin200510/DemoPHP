"""Run a local full pipeline for the front and back of one CCCD card."""

import json
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

from export_structured_ocr_batch import BASE_DIR, run_pipeline
from document_pairing import match_citizen_identity_card_sides
from structured_extraction import build_citizen_identity_card_pair_result


DEFAULT_OUTPUT = BASE_DIR / "output" / "ocr_structured_result_cccd_front_back.json"


def remove_transient_face_crop_path(result: dict) -> dict:
    """Keep an exported OCR result truthful after its temporary workspace is deleted."""

    face_crop = result.get("face_crop")

    if isinstance(face_crop, dict):
        face_crop.pop("path", None)
        face_crop["persisted"] = False

    return result


def main() -> None:
    if len(sys.argv) not in {3, 4}:
        raise SystemExit(
            "Usage: python export_cccd_pair_ocr.py <front-image> <back-image> [output-json]"
        )

    front_path = Path(sys.argv[1]).resolve()
    back_path = Path(sys.argv[2]).resolve()
    output_path = Path(sys.argv[3]).resolve() if len(sys.argv) == 4 else DEFAULT_OUTPUT

    missing_paths = [path for path in (front_path, back_path) if not path.is_file()]

    if missing_paths:
        raise FileNotFoundError(
            "Input image was not found: " + ", ".join(str(path) for path in missing_paths)
        )

    temporary_parent = BASE_DIR / "tmp"
    temporary_parent.mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory(prefix="cccd-pair-", dir=temporary_parent) as directory:
        temporary_directory = Path(directory)
        print("Processing CCCD front side...", flush=True)
        front_result = run_pipeline(front_path, temporary_directory)
        print("Processing CCCD back side...", flush=True)
        back_result = run_pipeline(back_path, temporary_directory)

    match_result = match_citizen_identity_card_sides(front_result, back_result)
    paired_normalized_ocr = None

    if match_result["status"] == "matched":
        matched_front_filename = (
            front_path.name
            if match_result["front_result"] is front_result
            else back_path.name
        )
        matched_back_filename = (
            back_path.name
            if match_result["back_result"] is back_result
            else front_path.name
        )
        paired_normalized_ocr = build_citizen_identity_card_pair_result(
            match_result["front_result"],
            match_result["back_result"],
            matched_front_filename,
            matched_back_filename,
        )
        paired_normalized_ocr["pairing"]["match_reason"] = match_result["reason"]

    match_result.pop("front_result", None)
    match_result.pop("back_result", None)
    result = {
        "status": "completed",
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "ocr_provider": "local_hybrid",
        "processing_mode": "independent_ocr_with_optional_front_back_match",
        "documents": [
            {
                "source_filename": front_path.name,
                "structured_ocr": remove_transient_face_crop_path(front_result),
            },
            {
                "source_filename": back_path.name,
                "structured_ocr": remove_transient_face_crop_path(back_result),
            },
        ],
        "side_matching": match_result,
        **({"paired_normalized_ocr": paired_normalized_ocr} if paired_normalized_ocr is not None else {}),
    }

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(
        json.dumps(result, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    print(f"Independent OCR and side-match result: {output_path}")


if __name__ == "__main__":
    main()
