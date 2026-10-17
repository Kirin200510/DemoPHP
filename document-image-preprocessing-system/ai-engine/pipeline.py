import os
import sys
import subprocess
from pathlib import Path


# =========================================================
# PATH
# =========================================================

BASE_DIR = os.path.dirname(
    os.path.abspath(__file__)
)

OUTPUT_DIR = Path(
    os.environ.get(
        "PIPELINE_OUTPUT_DIR",
        os.path.join(BASE_DIR, "output"),
    )
).resolve()

OUTPUT_DIR.mkdir(parents=True, exist_ok=True)


# =========================================================
# RUN SCRIPT
# =========================================================

def run_script(
    script_name,
    *script_arguments
):

    script_path = os.path.join(
        BASE_DIR,
        script_name
    )

    if not os.path.exists(script_path):
        raise FileNotFoundError(
            f"Không tìm thấy script: {script_name}"
        )

    print()
    print("=" * 60)
    print(f"RUNNING: {script_name}")
    print("=" * 60)

    subprocess.run(
        [
            sys.executable,
            script_path,
            *script_arguments,
        ],
        cwd=BASE_DIR,
        check=True
    )


# =========================================================
# CHECK OUTPUT
# =========================================================

def check_output(filename):

    path = OUTPUT_DIR / filename

    if not os.path.exists(path):
        raise FileNotFoundError(
            f"Không tìm thấy output: {filename}"
        )

    print(
        f"OK -> {filename}"
    )


# =========================================================
# INPUT
# =========================================================

if len(sys.argv) != 2:

    print(
        "Usage:"
    )

    print(
        "python pipeline.py <image>"
    )

    print()

    print(
        "Example:"
    )

    print(
        "python pipeline.py input/cccd_1.jpg"
    )

    sys.exit(1)


input_image = sys.argv[1]

input_path = os.path.join(
    BASE_DIR,
    input_image
)

if not os.path.exists(input_path):

    raise FileNotFoundError(
        f"Không tìm thấy ảnh: {input_image}"
    )

os.environ["PIPELINE_INPUT"] = input_path
os.environ["OCR_RESULT_NAME"] = "current"
os.environ["OCR_INPUT_STAGE"] = "preprocessed_final"
os.environ["OCR_RAW_OUTPUT_FILENAME"] = "ocr_raw_result.json"
os.environ["OCR_STRUCTURED_OUTPUT_FILENAME"] = "ocr_structured_result.json"

# Khi được gọi từ FastAPI, OCR được thực hiện trong process thường trực để
# tái sử dụng các model đã nạp. Chạy trực tiếp pipeline.py vẫn giữ đủ 8 bước.
skip_ocr = os.environ.get("PIPELINE_SKIP_OCR") == "1"

print()
print("=" * 60)
print("DOCUMENT IMAGE PREPROCESSING PIPELINE")
print("=" * 60)

print(
    f"Input: {input_image}"
)


# =========================================================
# STEP 1
# Orientation + Rotate
# =========================================================

run_script(
    "test_orientation_rotate.py"
)

check_output(
    "orientation_corrected.jpg"
)


# =========================================================
# STEP 2
# Document Crop
# =========================================================

run_script(
    "test_document_crop.py"
)

check_output(
    "document_crop.jpg"
)


# =========================================================
# STEP 3
# Deskew
# =========================================================

run_script(
    "test_deskew.py"
)

check_output(
    "document_deskew.jpg"
)


# =========================================================
# STEP 4
# Glare Reduction
# =========================================================

run_script(
    "test_glare.py"
)

check_output(
    "document_deglare.jpg"
)


# =========================================================
# STEP 5
# Deblur
# =========================================================

run_script(
    "test_deblur.py"
)

check_output(
    "document_deblur.jpg"
)


# =========================================================
# STEP 6
# Resize + Enhance
# =========================================================

run_script(
    "test_enhance.py"
)

check_output(
    "document_final.jpg"
)


if skip_ocr:
    print("OCR and structured extraction are handled by the FastAPI process.")
else:
    # =========================================================
    # STEP 7
    # OCR FROM FINAL PREPROCESSED IMAGE
    # =========================================================

    run_script(
        "test_ocr.py",
        str(OUTPUT_DIR / "document_final.jpg")
    )

    check_output(
        "ocr_raw_result.json"
    )

    # =========================================================
    # STEP 8
    # Structured OCR fields + face crop
    # =========================================================

    run_script(
        "test_structured_extraction.py",
        str(OUTPUT_DIR / "ocr_raw_result.json"),
        str(OUTPUT_DIR / "document_final.jpg"),
        "current",
    )

    check_output(
        "ocr_structured_result.json"
    )


# =========================================================
# FINAL
# =========================================================

print()
print("=" * 60)
print("PIPELINE COMPLETED SUCCESSFULLY")
print("=" * 60)

print()
print(
    "Deblurred output:"
)

print(
    OUTPUT_DIR / "document_deblur.jpg"
)

print()
print(
    "Final output:"
)

print(
    OUTPUT_DIR / "document_final.jpg"
)

if not skip_ocr:
    print()
    print(
        "OCR output:"
    )

    print(
        OUTPUT_DIR / "ocr_raw_result.json"
    )

    print()
    print(
        "Structured OCR output:"
    )

    print(
        OUTPUT_DIR / "ocr_structured_result.json"
    )

print()
