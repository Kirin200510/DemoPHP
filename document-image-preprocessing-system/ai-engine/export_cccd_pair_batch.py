"""Export normalized OCR data for multiple independent CCCD front/back pairs."""

import copy
import json
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

from document_pairing import match_citizen_identity_card_sides
from export_structured_ocr_batch import BASE_DIR, run_pipeline
from structured_extraction import build_citizen_identity_card_pair_result


def sanitize_result(result: dict) -> dict:
    """Remove temporary crop paths that no longer exist after export."""

    sanitized = copy.deepcopy(result)
    face_crop = sanitized.get("face_crop")

    if isinstance(face_crop, dict):
        face_crop.pop("path", None)
        face_crop["persisted"] = False

    return sanitized


def process_pair(front_path: Path, back_path: Path) -> dict:
    """OCR two sides independently, then merge only after a valid match."""

    with tempfile.TemporaryDirectory(prefix="cccd-pair-", dir=BASE_DIR / "tmp") as directory:
        workspace = Path(directory)
        front_result = run_pipeline(front_path, workspace)
        back_result = run_pipeline(back_path, workspace)

    match_result = match_citizen_identity_card_sides(front_result, back_result)
    paired_normalized_ocr = None

    if match_result["status"] == "matched":
        paired_normalized_ocr = build_citizen_identity_card_pair_result(
            match_result["front_result"],
            match_result["back_result"],
            front_path.name,
            back_path.name,
        )
        paired_normalized_ocr["pairing"]["match_reason"] = match_result["reason"]

    match_result.pop("front_result", None)
    match_result.pop("back_result", None)

    return {
        "source_images": {
            "submitted_front": front_path.name,
            "submitted_back": back_path.name,
        },
        "side_matching": match_result,
        **({"paired_normalized_ocr": paired_normalized_ocr} if paired_normalized_ocr is not None else {}),
        "independent_structured_ocr": {
            "front": sanitize_result(front_result),
            "back": sanitize_result(back_result),
        },
    }


def main() -> None:
    if len(sys.argv) != 6:
        raise SystemExit(
            "Usage: python export_cccd_pair_batch.py "
            "<front-1> <back-1> <front-2> <back-2> <output-json>"
        )

    paths = [Path(argument).resolve() for argument in sys.argv[1:]]
    image_paths = paths[:4]
    output_path = paths[4]
    missing = [path for path in image_paths if not path.is_file()]

    if missing:
        raise FileNotFoundError(
            "Input image was not found: "+", ".join(str(path) for path in missing)
        )

    (BASE_DIR / "tmp").mkdir(parents=True, exist_ok=True)
    result = {
        "status": "completed",
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "ocr_provider": "local_hybrid",
        "processing_mode": "independent_ocr_with_pairwise_cccd_matching",
        "pairs": [
            process_pair(image_paths[0], image_paths[1]),
            process_pair(image_paths[2], image_paths[3]),
        ],
    }
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(
        json.dumps(result, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    print(f"Normalized pair OCR result: {output_path}")


if __name__ == "__main__":
    main()
