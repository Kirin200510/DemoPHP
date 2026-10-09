import json
import os
import sys
from pathlib import Path

from structured_extraction import build_structured_result


BASE_DIR = Path(__file__).resolve().parent
OUTPUT_DIR = BASE_DIR / "output"


def main() -> None:
    if len(sys.argv) != 4:
        raise SystemExit(
            "Usage: python test_structured_extraction.py "
            "<raw-ocr-json> <processed-image> <result-name>"
        )

    raw_path = (BASE_DIR / sys.argv[1]).resolve()
    image_path = (BASE_DIR / sys.argv[2]).resolve()
    result_name = sys.argv[3]
    raw_result = json.loads(raw_path.read_text(encoding="utf-8"))
    structured_result = build_structured_result(
        raw_result,
        image_path,
        result_name,
        OUTPUT_DIR,
    )
    structured_result["raw_ocr_path"] = raw_path.name

    raw_result["face_crop"] = structured_result["face_crop"]
    raw_path.write_text(
        json.dumps(raw_result, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    output_filename = os.environ.get(
        "OCR_STRUCTURED_OUTPUT_FILENAME",
        f"ocr_structured_result_{result_name}.json",
    )
    output_path = OUTPUT_DIR / output_filename
    output_path.write_text(
        json.dumps(structured_result, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    print(f"Structured OCR result: {output_path}")
    print(f"Face crop: {structured_result['face_crop']['status']}")


if __name__ == "__main__":
    main()
