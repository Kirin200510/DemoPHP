import asyncio
import os
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from fastapi import HTTPException

import app


class ConcurrentJobTest(unittest.IsolatedAsyncioTestCase):
    async def test_batch_overlaps_jobs_and_preserves_order_when_one_image_is_rejected(self) -> None:
        started = []
        concurrent_started = asyncio.Event()

        async def fake_process(file, provider):
            self.assertEqual(provider, "local_hybrid")
            if file.filename == "bad.jpg":
                raise HTTPException(status_code=422, detail={"code": "document_frame_not_detected"})
            started.append(file.filename)
            if len(started) == 2:
                concurrent_started.set()
            await concurrent_started.wait()
            return {"status": "completed", "filename": file.filename}

        files = [SimpleNamespace(filename=name) for name in ("first.jpg", "bad.jpg", "last.jpg")]
        with patch("app.process_uploaded_document", side_effect=fake_process):
            result = await asyncio.wait_for(app.process_document_batch(files, "local_hybrid"), timeout=2)

        self.assertEqual(started, ["first.jpg", "last.jpg"])
        self.assertEqual(result["documents"][0]["filename"], "first.jpg")
        self.assertEqual(result["documents"][1]["http_status"], 422)
        self.assertEqual(result["documents"][2]["filename"], "last.jpg")

    def test_creates_isolated_workspaces(self) -> None:
        first_job_id, first_workspace, first_input, first_output = app.create_job_workspace(
            ".jpg"
        )
        second_job_id, second_workspace, second_input, second_output = app.create_job_workspace(
            ".png"
        )

        try:
            self.assertNotEqual(first_job_id, second_job_id)
            self.assertNotEqual(first_workspace, second_workspace)
            self.assertEqual(first_input.parent, first_workspace)
            self.assertEqual(second_input.parent, second_workspace)
            self.assertEqual(first_output.parent, first_workspace)
            self.assertEqual(second_output.parent, second_workspace)
        finally:
            app.cleanup_workspace(first_workspace)
            app.cleanup_workspace(second_workspace)

    async def test_passes_distinct_output_directories_to_parallel_pipelines(self) -> None:
        with tempfile.TemporaryDirectory() as temporary_directory:
            temporary_path = Path(temporary_directory)
            first_input = temporary_path / "first.jpg"
            second_input = temporary_path / "second.jpg"
            first_output = temporary_path / "first-output"
            second_output = temporary_path / "second-output"
            first_input.touch()
            second_input.touch()
            first_output.mkdir()
            second_output.mkdir()
            seen_output_directories: list[str] = []

            def fake_run(*_args: object, **kwargs: object) -> object:
                environment = kwargs["env"]
                output_directory = environment["PIPELINE_OUTPUT_DIR"]
                seen_output_directories.append(output_directory)
                (Path(output_directory) / "document_final.jpg").write_bytes(b"result")

                class CompletedProcess:
                    returncode = 0
                    stdout = ""
                    stderr = ""

                return CompletedProcess()

            async def fake_to_thread(function: object, *args: object, **kwargs: object) -> object:
                return function(*args, **kwargs)

            with (
                patch("app.asyncio.to_thread", side_effect=fake_to_thread),
                patch("app.subprocess.run", side_effect=fake_run),
            ):
                first_result, second_result = await asyncio.gather(
                    app.run_preprocessing_pipeline(first_input, first_output),
                    app.run_preprocessing_pipeline(second_input, second_output),
                )

            self.assertEqual(first_result, first_output / "document_final.jpg")
            self.assertEqual(second_result, second_output / "document_final.jpg")
            self.assertCountEqual(
                seen_output_directories,
                [str(first_output), str(second_output)],
            )
            self.assertNotEqual(
                os.path.realpath(seen_output_directories[0]),
                os.path.realpath(seen_output_directories[1]),
            )
