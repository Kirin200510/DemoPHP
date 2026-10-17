import asyncio
import base64
import json
import os
import shutil
import subprocess
import sys
import time
import uuid
from collections import deque
from concurrent.futures import ProcessPoolExecutor
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Any, AsyncIterator, Awaitable, Callable

from fastapi import FastAPI, File, Header, HTTPException, Request, UploadFile
from fastapi.responses import FileResponse, JSONResponse, Response
from PIL import Image, UnidentifiedImageError
from starlette.background import BackgroundTask

from image_quality import assess_image_quality
from document_pairing import match_citizen_identity_card_sides, rejected_pair
from pydantic import BaseModel, ConfigDict, Field
from ocr_service import extract_raw_ocr
from structured_extraction import (
    build_citizen_identity_card_pair_result,
    build_structured_result,
)


BASE_DIR = Path(__file__).resolve().parent
TEMP_DIR = BASE_DIR / "tmp"
TEMP_DIR.mkdir(parents=True, exist_ok=True)

ALLOWED_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp"}
MAX_UPLOAD_SIZE_BYTES = 10 * 1024 * 1024
PIPELINE_TIMEOUT_SECONDS = 300


def positive_environment_integer(name: str, default: int, maximum: int) -> int:
    """Read a positive bounded integer from the process environment."""

    try:
        value = int(os.environ.get(name, str(default)))
    except ValueError:
        value = default

    return min(max(value, 1), maximum)


# Each job receives its own input and output directory.  This is essential:
# pipeline stages write fixed filenames such as document_final.jpg.
MAX_CONCURRENT_JOBS = positive_environment_integer(
    "AI_MAX_CONCURRENT_JOBS",
    default=2,
    maximum=4,
)
LOCAL_OCR_WORKERS = positive_environment_integer(
    "AI_LOCAL_OCR_WORKERS",
    default=MAX_CONCURRENT_JOBS,
    maximum=MAX_CONCURRENT_JOBS,
)
processing_semaphore = asyncio.Semaphore(MAX_CONCURRENT_JOBS)
local_ocr_executor = ProcessPoolExecutor(max_workers=LOCAL_OCR_WORKERS)
processing_durations_seconds: deque[float] = deque(maxlen=100)
processing_success_count = 0
processing_failure_count = 0
active_jobs = 0


@asynccontextmanager
async def application_lifespan(_: FastAPI) -> AsyncIterator[None]:
    """Release child OCR workers when the API server stops."""

    try:
        yield
    finally:
        local_ocr_executor.shutdown(wait=False, cancel_futures=True)


app = FastAPI(
    title="Document Image AI Engine",
    version="1.1.0",
    lifespan=application_lifespan,
)


@app.middleware("http")
async def disable_metrics_cache(
    request: Request,
    call_next: Callable[[Request], Awaitable[Response]],
) -> Response:
    response = await call_next(request)
    if request.url.path in {"/metric", "/metrics"}:
        response.headers["Cache-Control"] = "no-store, no-cache, must-revalidate"
    return response


def create_job_workspace(extension: str) -> tuple[str, Path, Path, Path]:
    """Create an isolated, short-lived workspace for one uploaded image."""

    job_id = uuid.uuid4().hex
    workspace = (TEMP_DIR / job_id).resolve()
    output_dir = workspace / "output"
    workspace.mkdir(parents=True, exist_ok=False)
    output_dir.mkdir(parents=True, exist_ok=False)

    return job_id, workspace, workspace / f"input{extension}", output_dir


def cleanup_workspace(workspace: Path) -> None:
    """Remove one validated temporary workspace after its response is complete."""

    try:
        resolved_workspace = workspace.resolve()
        resolved_temp_dir = TEMP_DIR.resolve()

        if resolved_workspace.parent == resolved_temp_dir:
            shutil.rmtree(resolved_workspace, ignore_errors=True)
    except OSError as exception:
        print(f"Workspace cleanup warning: {exception}")


def validate_uploaded_image(input_path: Path) -> None:
    """Validate file size, image encoding and early image-quality rejection."""

    try:
        file_size = input_path.stat().st_size
    except OSError as exception:
        raise HTTPException(
            status_code=400,
            detail="Could not read uploaded image file.",
        ) from exception

    if file_size > MAX_UPLOAD_SIZE_BYTES:
        raise HTTPException(
            status_code=422,
            detail="Image file size must not exceed 10 MB.",
        )

    try:
        with Image.open(input_path) as image:
            image.verify()
    except (UnidentifiedImageError, OSError, ValueError) as exception:
        raise HTTPException(
            status_code=422,
            detail="Uploaded file is not a valid image.",
        ) from exception

    quality_result = assess_image_quality(input_path)

    if not quality_result.accepted:
        raise HTTPException(
            status_code=422,
            detail={
                "code": quality_result.code,
                "message": quality_result.detail,
                "metrics": {
                    "blur_score": round(quality_result.blur_score, 2),
                    "contrast_score": round(quality_result.contrast_score, 2),
                    "bright_ratio": round(quality_result.bright_ratio, 4),
                    "document_frame_score": round(
                        quality_result.document_frame_score,
                        4,
                    ),
                },
            },
        )


async def save_upload(file: UploadFile, input_path: Path) -> None:
    """Persist one received upload in its job workspace."""

    with input_path.open("wb") as buffer:
        shutil.copyfileobj(file.file, buffer)

    await file.close()


@asynccontextmanager
async def processing_slot() -> AsyncIterator[None]:
    """Limit total CPU/GPU intensive processing jobs and collect safe metrics."""

    global active_jobs, processing_failure_count, processing_success_count

    async with processing_semaphore:
        active_jobs += 1
        started_at = time.perf_counter()

        try:
            yield
        except Exception:
            processing_failure_count += 1
            raise
        else:
            processing_success_count += 1
        finally:
            processing_durations_seconds.append(time.perf_counter() - started_at)
            active_jobs -= 1


async def run_preprocessing_pipeline(input_path: Path, output_dir: Path) -> Path:
    """Run crop, perspective correction, deskew and enhancement for one job."""

    environment = os.environ.copy()
    environment["PIPELINE_INPUT"] = str(input_path)
    environment["PIPELINE_OUTPUT_DIR"] = str(output_dir)
    environment["PIPELINE_SKIP_OCR"] = "1"

    command = [
        sys.executable,
        str(BASE_DIR / "pipeline.py"),
        str(input_path),
    ]

    try:
        process = await asyncio.to_thread(
            subprocess.run,
            command,
            cwd=str(BASE_DIR),
            env=environment,
            capture_output=True,
            text=True,
            timeout=PIPELINE_TIMEOUT_SECONDS,
        )
    except subprocess.TimeoutExpired as exception:
        raise HTTPException(
            status_code=504,
            detail=(
                "AI preprocessing timed out after "
                f"{PIPELINE_TIMEOUT_SECONDS} seconds."
            ),
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
        raise HTTPException(status_code=500, detail=error_message[-4000:])

    final_output = output_dir / "document_final.jpg"

    if not final_output.is_file():
        raise HTTPException(
            status_code=500,
            detail="Pipeline completed but document_final.jpg is missing.",
        )

    return final_output


def selected_ocr_provider() -> str:
    """Read the admin selection shared with Laravel on every request.

    The private state file is replaced atomically by Laravel, survives API
    restarts and is visible to all Uvicorn workers on the same filesystem.
    Separate deployments can mount it at AI_OCR_PROVIDER_STATE_PATH.
    """

    state_path = Path(os.environ.get(
        "AI_OCR_PROVIDER_STATE_PATH",
        str(BASE_DIR.parent / "storage/app/private/ocr-provider.json"),
    ))
    try:
        state = json.loads(state_path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        state = {}

    provider = state.get("provider") if isinstance(state, dict) else None
    if provider in {"local_hybrid", "fpt_ai_reader", "vnpt_ekyc", "external_ekyc"}:
        return provider

    return os.environ.get("OCR_PROVIDER", "local_hybrid").strip().lower()


def uses_local_ocr(provider_name: str | None = None) -> bool:
    return (provider_name or selected_ocr_provider()).strip().lower() == "local_hybrid"


async def extract_ocr_concurrently(
    image_path: Path,
    provider_name: str | None = None,
) -> dict:
    """Use independent model processes for local OCR, threads for remote OCR."""

    provider_name = provider_name or selected_ocr_provider()
    if uses_local_ocr(provider_name):
        loop = asyncio.get_running_loop()
        return await loop.run_in_executor(
            local_ocr_executor,
            extract_raw_ocr,
            image_path,
            provider_name,
        )

    return await asyncio.to_thread(extract_raw_ocr, image_path, provider_name)


async def run_full_pipeline(
    input_path: Path,
    output_dir: Path,
    provider_name: str | None = None,
) -> tuple[bytes, dict, dict, bytes | None]:
    """Preprocess, OCR and normalize a single document in its own workspace."""

    async with processing_slot():
        final_output = await run_preprocessing_pipeline(input_path, output_dir)

        try:
            raw_ocr = await extract_ocr_concurrently(final_output, provider_name)
            raw_ocr["input"] = {
                "filename": input_path.name,
                "stage": "preprocessed_final",
            }
            structured_ocr = await asyncio.to_thread(
                build_structured_result,
                raw_ocr,
                final_output,
                "current",
                output_dir,
                barcode_image_path=input_path,
            )
        except (OSError, RuntimeError, ValueError) as exception:
            raise HTTPException(
                status_code=500,
                detail="OCR processing failed after image preprocessing.",
            ) from exception

        raw_ocr["face_crop"] = structured_ocr.get("face_crop", {})
        raw_ocr["workspace_output"] = None
        structured_ocr["raw_ocr_path"] = None

        face_crop_image = None
        face_crop = structured_ocr.get("face_crop", {})

        if face_crop.get("status") == "detected":
            faces_dir = (output_dir / "faces").resolve()
            face_path = (output_dir / str(face_crop.get("path", ""))).resolve()

            if face_path.is_relative_to(faces_dir) and face_path.is_file():
                face_crop_image = face_path.read_bytes()

        return (
            final_output.read_bytes(),
            raw_ocr,
            structured_ocr,
            face_crop_image,
        )


@app.get("/health")
def health_check() -> dict:
    return {"status": "ok", "service": "document-image-ai-engine"}


@app.get("/metric")
@app.get("/metrics")
def processing_metrics() -> dict:
    """Return current metrics and the OCR provider saved by the admin UI.

    After saving another vendor, click Execute again in Swagger to fetch
    its current provider. A previous response is a snapshot, not a live feed.
    """

    provider = selected_ocr_provider()
    completed_requests = processing_success_count + processing_failure_count
    average_duration_seconds = (
        sum(processing_durations_seconds) / len(processing_durations_seconds)
        if processing_durations_seconds
        else None
    )

    return {
        "processing": {
            "max_concurrent_jobs": MAX_CONCURRENT_JOBS,
            "active_jobs": active_jobs,
            "available_slots": MAX_CONCURRENT_JOBS - active_jobs,
            "lock_active": active_jobs >= MAX_CONCURRENT_JOBS,
            "success_count": processing_success_count,
            "failure_count": processing_failure_count,
            "failure_ratio": (
                processing_failure_count / completed_requests
                if completed_requests
                else 0.0
            ),
            "duration_sample_size": len(processing_durations_seconds),
            "average_duration_seconds": average_duration_seconds,
        },
        "ocr": {
            "models_reused_in_process": True,
            "provider": provider,
            "local_worker_processes": LOCAL_OCR_WORKERS if uses_local_ocr(provider) else 0,
        },
    }


def validate_extension(file: UploadFile) -> str:
    extension = Path(file.filename or "").suffix.lower()

    if extension not in ALLOWED_EXTENSIONS:
        raise HTTPException(
            status_code=400,
            detail="Unsupported image format. Allowed: jpg, jpeg, png, webp.",
        )

    return extension


async def process_uploaded_document(
    file: UploadFile,
    ocr_provider: str | None = None,
) -> dict:
    """Preprocess, OCR and normalize one uploaded image in isolation."""

    ocr_provider = ocr_provider or selected_ocr_provider()
    extension = validate_extension(file)
    _, workspace, input_path, output_dir = create_job_workspace(extension)

    try:
        await save_upload(file, input_path)
        validate_uploaded_image(input_path)
        processed_image, raw_ocr, structured_ocr, face_crop_image = await run_full_pipeline(
            input_path,
            output_dir,
            ocr_provider,
        )

        return {
            "status": "completed",
            "processed_image": {
                "filename": "document_final.jpg",
                "media_type": "image/jpeg",
                "base64": base64.b64encode(processed_image).decode("ascii"),
            },
            "ocr": {"raw": raw_ocr, "structured": structured_ocr},
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
        cleanup_workspace(workspace)


@app.post("/process")
async def process_document(
    file: UploadFile = File(...),
    ocr_provider: str | None = Header(default=None, alias="X-OCR-Provider"),
) -> dict:
    """Preprocess, OCR and normalize one independently submitted image."""

    return await process_uploaded_document(file, ocr_provider)


@app.post("/process-batch")
async def process_document_batch(
    files: list[UploadFile] = File(...),
    ocr_provider: str | None = Header(default=None, alias="X-OCR-Provider"),
) -> dict:
    """Process each submitted image independently and concurrently.

    This endpoint does not infer any relationship between files. Side matching
    is intentionally a separate explicit call to ``/document-sides/match``.
    """

    if not files:
        raise HTTPException(status_code=422, detail="At least one image is required.")

    provider = ocr_provider or selected_ocr_provider()

    async def process_batch_item(file: UploadFile) -> dict:
        try:
            return await process_uploaded_document(file, provider)
        except HTTPException as exception:
            return {
                "status": "failed",
                "http_status": exception.status_code,
                "detail": exception.detail,
            }
        except Exception:
            return {
                "status": "failed",
                "http_status": 500,
                "detail": "Document processing and OCR failed.",
            }

    results = await asyncio.gather(
        *(process_batch_item(file) for file in files),
    )

    return {
        "status": "completed",
        "document_count": len(results),
        "documents": results,
    }


@app.post("/ocr")
async def ocr(
    file: UploadFile = File(...),
    ocr_provider: str | None = Header(default=None, alias="X-OCR-Provider"),
) -> dict:
    """OCR a document image without image preprocessing."""

    extension = validate_extension(file)
    _, workspace, input_path, _ = create_job_workspace(extension)

    try:
        await save_upload(file, input_path)
        validate_uploaded_image(input_path)

        async with processing_slot():
            return await extract_ocr_concurrently(input_path, ocr_provider)
    except HTTPException:
        raise
    except Exception as exception:
        print(f"OCR error: {exception!r}")
        raise HTTPException(status_code=500, detail="OCR processing failed.") from exception
    finally:
        cleanup_workspace(workspace)


class ParsedBarcodeInput(BaseModel):
    model_config = ConfigDict(extra="allow")
    status: str = "unsupported"
    fields: dict[str, str] = Field(default_factory=dict)


class BarcodeRecordInput(BaseModel):
    model_config = ConfigDict(extra="allow")
    format: str
    parsed: ParsedBarcodeInput = Field(default_factory=ParsedBarcodeInput)


class BarcodeInput(BaseModel):
    model_config = ConfigDict(extra="allow")
    records: list[BarcodeRecordInput] = Field(default_factory=list)


class MrzInput(BaseModel):
    model_config = ConfigDict(extra="allow")
    normalized_lines: list[str] = Field(default_factory=list)
    format_valid: bool = False


class StructuredDocumentInput(BaseModel):
    model_config = ConfigDict(extra="allow")
    document: dict[str, Any] | None = None
    fields: dict[str, dict[str, Any]] = Field(default_factory=dict)
    barcodes: BarcodeInput = Field(default_factory=BarcodeInput)
    mrz: MrzInput = Field(default_factory=MrzInput)


@app.post("/document-sides/match", responses={400: {"description": "Identity mismatch"}, 422: {"description": "Invalid sides or insufficient evidence"}})
def match_document_sides(results: list[StructuredDocumentInput]) -> Any:
    """Match exactly two independently produced structured OCR results.

    OCR remains a per-image service. This endpoint is an explicit, separate
    operation and never assumes that images submitted together are two sides
    of the same document.
    """

    if len(results) != 2:
        return JSONResponse(
            status_code=422,
            content=rejected_pair("ERR_PAIR_SIDE_INVALID", "exactly_two_results_required"),
        )

    results = [result.model_dump(exclude_unset=True) for result in results]
    match_result = match_citizen_identity_card_sides(results[0], results[1])
    if match_result["status"] != "matched":
        return JSONResponse(status_code=match_result["http_status"], content=match_result)

    if match_result["status"] == "matched":
        front_result = match_result["front_result"]
        back_result = match_result["back_result"]
        match_result["front_input_index"] = 0 if front_result is results[0] else 1
        match_result["back_input_index"] = 0 if back_result is results[0] else 1
        paired_normalized_ocr = build_citizen_identity_card_pair_result(
            front_result,
            back_result,
            "front",
            "back",
        )
        paired_normalized_ocr["pairing"]["match_reason"] = match_result["reason"]

    match_result.pop("front_result", None)
    match_result.pop("back_result", None)
    match_result["paired_normalized_ocr"] = paired_normalized_ocr

    return match_result


@app.post("/preprocess")
async def preprocess(file: UploadFile = File(...)) -> FileResponse:
    """Preprocess one image and stream its final image response."""

    extension = validate_extension(file)
    job_id, workspace, input_path, output_dir = create_job_workspace(extension)

    try:
        await save_upload(file, input_path)
        validate_uploaded_image(input_path)

        async with processing_slot():
            final_output = await run_preprocessing_pipeline(input_path, output_dir)

        return FileResponse(
            path=str(final_output),
            media_type="image/jpeg",
            filename=f"{job_id}.jpg",
            background=BackgroundTask(cleanup_workspace, workspace),
        )
    except HTTPException:
        cleanup_workspace(workspace)
        raise
    except Exception as exception:
        cleanup_workspace(workspace)
        print(f"Preprocess error: {exception!r}")
        raise HTTPException(status_code=500, detail="AI preprocessing failed.") from exception
