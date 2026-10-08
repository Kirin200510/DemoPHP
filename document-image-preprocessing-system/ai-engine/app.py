import asyncio
import os
import shutil
import subprocess
import sys
import uuid
from pathlib import Path

from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.responses import FileResponse
from starlette.background import BackgroundTask


# =========================================================
# PATH
# =========================================================

BASE_DIR = Path(__file__).resolve().parent

OUTPUT_DIR = BASE_DIR / "output"
TEMP_DIR = BASE_DIR / "tmp"

OUTPUT_DIR.mkdir(
    exist_ok=True
)

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
