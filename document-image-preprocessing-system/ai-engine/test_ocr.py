import json
import os
import sys
from pathlib import Path

from ocr_service import extract_raw_ocr


BASE_DIR = Path(__file__).resolve().parent
OUTPUT_DIR = BASE_DIR / "output"


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit(
            "Usage: python test_ocr.py <image-path>"
        )

    input_path = Path(sys.argv[1])

    if not input_path.is_absolute():
        input_path = BASE_DIR / input_path

    result = extract_raw_ocr(
        input_path.resolve(),
    )

    result["input"] = {
        "filename": input_path.name,
        "stage": os.environ.get(
            "OCR_INPUT_STAGE",
            "direct_upload",
        ),
    }

    OUTPUT_DIR.mkdir(
        exist_ok=True,
    )

    result_name = os.environ.get(
        "OCR_RESULT_NAME",
        input_path.stem,
    )

    output_filename = os.environ.get(
        "OCR_RAW_OUTPUT_FILENAME",
        f"ocr_raw_result_{result_name}.json",
    )

    output_path = OUTPUT_DIR / output_filename

    output_path.write_text(
        json.dumps(
            result,
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )

    print(
        f"OCR lines: {result['line_count']}"
    )

    for line in result["lines"]:
        print(
            f"[{line['confidence']:.3f}] {line['text']}"
        )

    print(
        f"Raw OCR result: {output_path}"
    )


if __name__ == "__main__":
    main()
