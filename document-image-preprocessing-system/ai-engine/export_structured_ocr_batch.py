"""Run full local OCR pipelines and export normalized results into one JSON file."""

import json
import os
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


BASE_DIR = Path(__file__).resolve().parent
DEFAULT_INPUTS = ["input/4.jpg", "input/5.jpg", "input/18.jpg"]
DEFAULT_OUTPUT = BASE_DIR / "output" / "ocr_structured_results_4_5_18.json"


def run_pipeline(input_path: Path, temporary_directory: Path) -> dict[str, Any]:
    """Run all preprocessing and local OCR stages in one isolated workspace."""

    output_directory = temporary_directory / input_path.stem
    output_directory.mkdir(parents=True, exist_ok=True)
    environment = os.environ.copy()
    environment["OCR_PROVIDER"] = "local_hybrid"
    environment["PIPELINE_OUTPUT_DIR"] = str(output_directory)
    environment.pop("PIPELINE_SKIP_OCR", None)

    process = subprocess.run(
        [sys.executable, str(BASE_DIR / "pipeline.py"), str(input_path)],
        cwd=BASE_DIR,
        env=environment,
        capture_output=True,
        text=True,
        timeout=600,
        check=False,
    )

    if process.returncode != 0:
        message = process.stderr.strip() or process.stdout.strip() or "Unknown pipeline error."
        raise RuntimeError(f"{input_path.name}: {message[-4000:]}")

    structured_path = output_directory / "ocr_structured_result.json"

    if not structured_path.is_file():
        raise FileNotFoundError(f"Missing normalized OCR result for {input_path.name}.")

    return json.loads(structured_path.read_text(encoding="utf-8"))


def main() -> None:
    arguments = sys.argv[1:]
    output_path = DEFAULT_OUTPUT

    if "--output" in arguments:
        output_option_index = arguments.index("--output")

        if output_option_index == len(arguments) - 1:
            raise ValueError("The --output option requires a JSON filename.")

        output_path = Path(arguments[output_option_index + 1]).resolve()
        del arguments[output_option_index : output_option_index + 2]

    input_paths = [
        (BASE_DIR / relative_path).resolve()
        for relative_path in (arguments or DEFAULT_INPUTS)
    ]

    missing_paths = [path for path in input_paths if not path.is_file()]

    if missing_paths:
        raise FileNotFoundError(
            "Input image was not found: " + ", ".join(str(path) for path in missing_paths)
        )

    temporary_parent = BASE_DIR / "tmp"
    temporary_parent.mkdir(parents=True, exist_ok=True)
    documents = []

    with tempfile.TemporaryDirectory(prefix="batch-ocr-", dir=temporary_parent) as directory:
        temporary_directory = Path(directory)

        for input_path in input_paths:
            print(f"Processing {input_path.name}...", flush=True)
            documents.append(
                {
                    "source_filename": input_path.name,
                    "structured_ocr": run_pipeline(input_path, temporary_directory),
                }
            )

    result = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "ocr_provider": "local_hybrid",
        "document_count": len(documents),
        "documents": documents,
    }
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(
        json.dumps(result, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    print(f"Normalized OCR results: {output_path}")


if __name__ == "__main__":
    main()
