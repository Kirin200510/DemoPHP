import os
import sys
import subprocess


# =========================================================
# PATH
# =========================================================

BASE_DIR = os.path.dirname(
    os.path.abspath(__file__)
)

OUTPUT_DIR = os.path.join(
    BASE_DIR,
    "output"
)

os.makedirs(
    OUTPUT_DIR,
    exist_ok=True
)


# =========================================================
# RUN SCRIPT
# =========================================================

def run_script(script_name):

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
        [sys.executable, script_path],
        cwd=BASE_DIR,
        check=True
    )


# =========================================================
# CHECK OUTPUT
# =========================================================

def check_output(filename):

    path = os.path.join(
        BASE_DIR,
        filename
    )

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
    "output/orientation_corrected.jpg"
)


# =========================================================
# STEP 2
# Document Crop
# =========================================================

run_script(
    "test_document_crop.py"
)

check_output(
    "output/document_crop.jpg"
)


# =========================================================
# STEP 3
# Deskew
# =========================================================

run_script(
    "test_deskew.py"
)

check_output(
    "output/document_deskew.jpg"
)


# =========================================================
# STEP 4
# Glare Reduction
# =========================================================

run_script(
    "test_glare.py"
)

check_output(
    "output/document_deglare.jpg"
)


# =========================================================
# STEP 5
# Deblur
# =========================================================

run_script(
    "test_deblur.py"
)

check_output(
    "output/document_deblur.jpg"
)


# =========================================================
# STEP 6
# Resize + Enhance
# =========================================================

run_script(
    "test_enhance.py"
)

check_output(
    "output/document_final.jpg"
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
    os.path.join(
        OUTPUT_DIR,
        "document_deblur.jpg"
    )
)

print()
print(
    "Final output:"
)

print(
    os.path.join(
        OUTPUT_DIR,
        "document_final.jpg"
    )
)

print()
