import asyncio
import base64
import json
import os
import shutil
import subprocess
import sys
import uuid
from pathlib import Path

from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.responses import FileResponse
from starlette.background import BackgroundTask

from ocr_service import extract_raw_ocr


# =========================================================
# PATH
# =========================================================

BASE_DIR = Path(__file__).resolve().parent

OUTPUT_DIR = BASE_DIR / "output"
TEMP_DIR = BASE_DIR / "tmp"

OUTPUT_DIR.mkdir(
    exist_ok=True
)

FINAL_OUTPUT = OUTPUT_DIR / "document_final.jpg"
RAW_OCR_OUTPUT = OUTPUT_DIR / "ocr_raw_result.json"
STRUCTURED_OCR_OUTPUT = OUTPUT_DIR / "ocr_structured_result.json"

TEMP_DIR.mkdir(
    exist_ok=True
)


# =========================================================
# FASTAPI
# =========================================================

app = FastAPI(
    title="Document Image AI Engine",
    version="1.0.0"
)


# =========================================================
# PROCESSING LOCK
# =========================================================
#
# pipeline.py đang sử dụng các output cố định:
#
# output/orientation_corrected.jpg
# output/document_crop.jpg
# output/document_deskew.jpg
# output/document_deglare.jpg
# output/document_deblur.jpg
# output/document_final.jpg
#
# Vì vậy tại thời điểm hiện tại chỉ cho phép
# một pipeline chạy tại một thời điểm.
# =========================================================

processing_lock = asyncio.Lock()


# =========================================================
# ALLOWED IMAGE EXTENSIONS
# =========================================================

ALLOWED_EXTENSIONS = {
    ".jpg",
    ".jpeg",
    ".png",
    ".webp"
}


# =========================================================
# HEALTH CHECK
# =========================================================

@app.get("/health")
def health_check():

    return {
        "status": "ok",
        "service": "document-image-ai-engine"
    }


# =========================================================
# CLEANUP
# =========================================================

def cleanup_files(
    input_path: Path,
    result_path: Path
):
    """
    Xóa các file tạm sau khi response đã được gửi.
    """

    try:

        if input_path.exists():
            input_path.unlink()

        if result_path.exists():
            result_path.unlink()

    except Exception as e:

        print(
            f"Cleanup warning: {e}"
        )


# =========================================================
# FULL PIPELINE
# =========================================================

async def run_full_pipeline(
    input_path: Path,
) -> tuple[bytes, dict, dict, bytes | None]:
    """Chạy tiền xử lý + OCR và đọc toàn bộ output trong cùng một lock."""

    environment = os.environ.copy()
    environment["PIPELINE_INPUT"] = str(input_path)

    command = [
        sys.executable,
        str(BASE_DIR / "pipeline.py"),
        str(input_path),
    ]

    async with processing_lock:
        try:
            process = await asyncio.to_thread(
                subprocess.run,
                command,
                cwd=str(BASE_DIR),
                env=environment,
                capture_output=True,
                text=True,
                timeout=300,
            )
        except subprocess.TimeoutExpired as exception:
            raise HTTPException(
                status_code=504,
                detail="AI processing timed out after 300 seconds.",
            ) from exception

        if process.stdout:
            print(process.stdout)

        if process.stderr:
            print(process.stderr)

        if process.returncode != 0:
            error_message = (
                process.stderr.strip()
                or process.stdout.strip()
                or "Unknown pipeline error."
            )

            raise HTTPException(
                status_code=500,
                detail=error_message[-4000:],
            )

        required_outputs = (
            FINAL_OUTPUT,
            RAW_OCR_OUTPUT,
            STRUCTURED_OCR_OUTPUT,
        )

        missing_outputs = [
            output.name
            for output in required_outputs
            if not output.is_file()
        ]

        if missing_outputs:
            raise HTTPException(
                status_code=500,
                detail=(
                    "Pipeline completed but outputs are missing: "
                    + ", ".join(missing_outputs)
                ),
            )

        try:
            raw_ocr = json.loads(
                RAW_OCR_OUTPUT.read_text(encoding="utf-8")
            )
            structured_ocr = json.loads(
                STRUCTURED_OCR_OUTPUT.read_text(encoding="utf-8")
            )
        except json.JSONDecodeError as exception:
            raise HTTPException(
                status_code=500,
                detail="Pipeline returned invalid OCR JSON.",
            ) from exception

        processed_image = FINAL_OUTPUT.read_bytes()
        face_crop = structured_ocr.get("face_crop", {})
        face_crop_image = None

        if face_crop.get("status") == "detected":
            relative_face_path = face_crop.get("path")
            faces_dir = (OUTPUT_DIR / "faces").resolve()
            face_path = (OUTPUT_DIR / str(relative_face_path)).resolve()

            if (
                face_path.is_relative_to(faces_dir)
                and face_path.is_file()
            ):
                face_crop_image = face_path.read_bytes()

    return processed_image, raw_ocr, structured_ocr, face_crop_image


# =========================================================
# PROCESS + OCR
# =========================================================

@app.post("/process")
async def process_document(
    file: UploadFile = File(...),
):
    """Tiền xử lý ảnh, OCR và trả ảnh cùng dữ liệu JSON chuẩn hóa."""

    original_name = file.filename or ""
    extension = Path(original_name).suffix.lower()

    if extension not in ALLOWED_EXTENSIONS:
        raise HTTPException(
            status_code=400,
            detail=(
                "Unsupported image format. "
                "Allowed: jpg, jpeg, png, webp."
            ),
        )

    input_path = TEMP_DIR / f"{uuid.uuid4().hex}{extension}"

    try:
        with input_path.open("wb") as buffer:
            shutil.copyfileobj(file.file, buffer)

        await file.close()

        processed_image, raw_ocr, structured_ocr, face_crop_image = await run_full_pipeline(
            input_path
        )

        return {
            "status": "completed",
            "processed_image": {
                "filename": "document_final.jpg",
                "media_type": "image/jpeg",
                "base64": base64.b64encode(processed_image).decode("ascii"),
            },
            "ocr": {
                "raw": raw_ocr,
                "structured": structured_ocr,
            },
            "face_crop": {
                "media_type": "image/jpeg",
                "base64": base64.b64encode(face_crop_image).decode("ascii"),
            }
            if face_crop_image is not None
            else None,
        }
    except HTTPException:
        raise
    except Exception as exception:
        print(f"Process and OCR error: {exception!r}")

        raise HTTPException(
            status_code=500,
            detail="Document processing and OCR failed.",
        ) from exception
    finally:
        if input_path.exists():
            input_path.unlink()


# =========================================================
# OCR
# =========================================================

@app.post("/ocr")
async def ocr(
    file: UploadFile = File(...)
):
    """Đọc toàn bộ text từ một ảnh và trả JSON raw OCR."""

    original_name = file.filename or ""
    extension = Path(
        original_name
    ).suffix.lower()

    if extension not in ALLOWED_EXTENSIONS:
        raise HTTPException(
            status_code=400,
            detail=(
                "Unsupported image format. "
                "Allowed: jpg, jpeg, png, webp."
            ),
        )

    input_path = TEMP_DIR / f"{uuid.uuid4().hex}{extension}"

    try:
        with input_path.open("wb") as buffer:
            shutil.copyfileobj(
                file.file,
                buffer,
            )

        await file.close()

        return await asyncio.to_thread(
            extract_raw_ocr,
            input_path,
        )

    except HTTPException:
        raise

    except Exception as e:
        print(
            f"OCR error: {e!r}"
        )

        raise HTTPException(
            status_code=500,
            detail="OCR processing failed.",
        ) from e

    finally:
        if input_path.exists():
            input_path.unlink()


# =========================================================
# PREPROCESS
# =========================================================

@app.post("/preprocess")
async def preprocess(
    file: UploadFile = File(...)
):

    # =====================================================
    # 1. VALIDATE FILE
    # =====================================================

    original_name = file.filename or ""

    extension = Path(
        original_name
    ).suffix.lower()

    if extension not in ALLOWED_EXTENSIONS:

        raise HTTPException(
            status_code=400,
            detail=(
                "Unsupported image format. "
                "Allowed: jpg, jpeg, png, webp."
            )
        )


    # =====================================================
    # 2. CREATE UNIQUE JOB ID
    # =====================================================

    job_id = uuid.uuid4().hex

    input_path = (
        TEMP_DIR
        / f"{job_id}{extension}"
    )

    result_path = (
        TEMP_DIR
        / f"{job_id}_result.jpg"
    )


    try:

        # =================================================
        # 3. SAVE UPLOADED IMAGE
        # =================================================

        with input_path.open("wb") as buffer:

            shutil.copyfileobj(
                file.file,
                buffer
            )

        await file.close()


        print()
        print("=" * 60)
        print("NEW PREPROCESSING JOB")
        print("=" * 60)

        print(
            f"Job ID : {job_id}"
        )

        print(
            f"Input  : {input_path}"
        )


        # =================================================
        # 4. RUN PIPELINE
        # =================================================

        async with processing_lock:

            print()
            print(
                "Running AI pipeline..."
            )


            # ---------------------------------------------
            # Environment variables
            # ---------------------------------------------

            environment = os.environ.copy()

            environment[
                "PIPELINE_INPUT"
            ] = str(input_path)


            # ---------------------------------------------
            # Pipeline command
            # ---------------------------------------------

            command = [
                sys.executable,
                str(
                    BASE_DIR / "pipeline.py"
                ),
                str(input_path)
            ]


            print(
                "Command:",
                " ".join(command)
            )


            # ---------------------------------------------
            # Run pipeline
            # ---------------------------------------------

            try:

                process = await asyncio.to_thread(
                    subprocess.run,
                    command,
                    cwd=str(BASE_DIR),
                    env=environment,
                    capture_output=True,
                    text=True,
                    timeout=300
                )

            except subprocess.TimeoutExpired:

                print()
                print(
                    "===== PIPELINE TIMEOUT ====="
                )

                raise HTTPException(
                    status_code=504,
                    detail=(
                        "AI preprocessing "
                        "timed out after 300 seconds."
                    )
                )


            # ---------------------------------------------
            # Print pipeline output
            # ---------------------------------------------

            if process.stdout:

                print()
                print(
                    "===== PIPELINE STDOUT ====="
                )

                print(
                    process.stdout
                )


            if process.stderr:

                print()
                print(
                    "===== PIPELINE STDERR ====="
                )

                print(
                    process.stderr
                )


            # =================================================
            # 5. CHECK PIPELINE STATUS
            # =================================================

            if process.returncode != 0:

                # Lấy lỗi thật
                error_message = (
                    process.stderr.strip()
                    or process.stdout.strip()
                    or "Unknown pipeline error."
                )

                print()
                print(
                    "===== PIPELINE ERROR ====="
                )

                print(
                    error_message
                )

                print(
                    "=========================="
                )

                raise HTTPException(
                    status_code=500,
                    detail=error_message[-4000:]
                )


            # =================================================
            # 6. CHECK FINAL OUTPUT
            # =================================================

            final_output = (
                OUTPUT_DIR
                / "document_final.jpg"
            )


            if not final_output.exists():

                raise HTTPException(
                    status_code=500,
                    detail=(
                        "Pipeline completed successfully "
                        "but document_final.jpg "
                        "was not found."
                    )
                )


            # =================================================
            # 7. COPY FINAL RESULT
            # =================================================

            shutil.copy2(
                final_output,
                result_path
            )


            print()
            print(
                "===== AI PROCESSING SUCCESS ====="
            )

            print(
                f"Result: {result_path}"
            )

            print(
                "================================="
            )


        # =====================================================
        # 8. RETURN IMAGE
        # =====================================================

        return FileResponse(
            path=str(result_path),
            media_type="image/jpeg",
            filename=f"{job_id}.jpg",
            background=BackgroundTask(
                cleanup_files,
                input_path,
                result_path
            )
        )


    # =====================================================
    # HTTP EXCEPTION
    # =====================================================

    except HTTPException:
        raise


    # =====================================================
    # UNEXPECTED ERROR
    # =====================================================

    except Exception as e:

        print()
        print(
            "===== UNEXPECTED ERROR ====="
        )

        print(
            repr(e)
        )

        print(
            "============================"
        )

        # Cleanup nếu có lỗi
        try:

            if input_path.exists():
                input_path.unlink()

            if result_path.exists():
                result_path.unlink()

        except Exception:
            pass


        raise HTTPException(
            status_code=500,
            detail=str(e)
        )
