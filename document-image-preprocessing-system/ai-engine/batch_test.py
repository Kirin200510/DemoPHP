import shutil
import subprocess
import sys
from pathlib import Path


BASE_DIR = Path(__file__).resolve().parent
INPUT_DIR = BASE_DIR / "input"
OUTPUT_DIR = BASE_DIR / "output"
RESULT_DIR = BASE_DIR / "result"
DETECTION_RESULT_DIR = RESULT_DIR / "detection"
CROP_RESULT_DIR = RESULT_DIR / "crop"

SUPPORTED_EXTENSIONS = {
    ".jpg",
    ".jpeg",
    ".png",
    ".webp"
}


def natural_sort_key(path):
    name = path.stem

    if name.isdigit():
        return 0, int(name), path.suffix.lower()

    return 1, name.lower(), path.suffix.lower()


def result_name(image_path, suffix):
    return f"{image_path.stem}_{suffix}.jpg"


def main():
    image_paths = sorted(
        [
            path
            for path in INPUT_DIR.iterdir()
            if (
                path.is_file()
                and path.suffix.lower() in SUPPORTED_EXTENSIONS
            )
        ],
        key=natural_sort_key
    )

    if not image_paths:
        raise FileNotFoundError(
            f"Không tìm thấy ảnh kiểm thử trong: {INPUT_DIR}"
        )

    for directory in [
        RESULT_DIR,
        DETECTION_RESULT_DIR,
        CROP_RESULT_DIR
    ]:
        directory.mkdir(
            parents=True,
            exist_ok=True
        )

    failures = []

    for index, image_path in enumerate(
        image_paths,
        start=1
    ):
        print(
            f"[{index}/{len(image_paths)}] "
            f"Processing {image_path.name}...",
            flush=True
        )

        process = subprocess.run(
            [
                sys.executable,
                str(BASE_DIR / "pipeline.py"),
                str(image_path)
            ],
            cwd=BASE_DIR,
            capture_output=True,
            text=True,
            check=False
        )

        if process.returncode != 0:
            failures.append(
                image_path.name
            )

            print(
                process.stderr
                or process.stdout
                or "Unknown pipeline error.",
                flush=True
            )

            continue

        outputs = [
            (
                OUTPUT_DIR / "document_final.jpg",
                RESULT_DIR
                / result_name(image_path, "final")
            ),
            (
                OUTPUT_DIR / "document_detection.jpg",
                DETECTION_RESULT_DIR
                / result_name(image_path, "detection")
            ),
            (
                OUTPUT_DIR / "document_crop.jpg",
                CROP_RESULT_DIR
                / result_name(image_path, "crop")
            )
        ]

        for source_path, destination_path in outputs:
            if not source_path.exists():
                raise FileNotFoundError(
                    f"Pipeline không tạo output: {source_path}"
                )

            shutil.copy2(
                source_path,
                destination_path
            )

        print(
            f"OK -> {result_name(image_path, 'final')}",
            flush=True
        )

    print()
    print(
        f"Completed: {len(image_paths) - len(failures)}"
        f"/{len(image_paths)}"
    )

    print(
        f"Result directory: {RESULT_DIR}"
    )

    if failures:
        raise RuntimeError(
            "Các ảnh xử lý thất bại: "
            + ", ".join(failures)
        )


if __name__ == "__main__":
    main()
