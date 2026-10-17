"""Verify admin selections are immediately visible to metrics and OCR requests."""

import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import AsyncMock, patch

from fastapi.testclient import TestClient

import app


class ProviderMetricsTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        self.workspace = tempfile.TemporaryDirectory()
        self.state_path = Path(self.workspace.name) / "ocr-provider.json"
        self.environment = patch.dict(os.environ, {
            "OCR_PROVIDER": "local_hybrid",
            "AI_OCR_PROVIDER_STATE_PATH": str(self.state_path),
        })
        self.environment.start()

    def tearDown(self) -> None:
        self.environment.stop()
        self.workspace.cleanup()

    def test_both_metric_routes_follow_selection_without_restart(self) -> None:
        client = TestClient(app.app)
        paths = client.get("/openapi.json").json()["paths"]
        self.assertIn("/metric", paths)
        self.assertIn("/metrics", paths)
        for provider in ("fpt_ai_reader", "vnpt_ekyc", "external_ekyc", "local_hybrid"):
            self.state_path.write_text('{"provider": "' + provider + '"}')
            for route in ("/metric", "/metrics"):
                response = client.get(route)
                self.assertEqual(response.status_code, 200)
                self.assertIn("no-store", response.headers["Cache-Control"])
                metrics = response.json()["ocr"]
                self.assertEqual(metrics["provider"], provider)
                self.assertEqual(
                    metrics["local_worker_processes"],
                    app.LOCAL_OCR_WORKERS if provider == "local_hybrid" else 0,
                )

    def test_missing_invalid_or_corrupt_state_uses_environment(self) -> None:
        self.assertEqual(app.selected_ocr_provider(), "local_hybrid")
        for content in ('{"provider":"unknown"}', '{', '[]'):
            self.state_path.write_text(content)
            self.assertEqual(app.selected_ocr_provider(), "local_hybrid")

    def test_batch_receives_the_provider_header_sent_by_laravel(self) -> None:
        client = TestClient(app.app)
        with patch("app.process_uploaded_document", new_callable=AsyncMock) as process:
            process.return_value = {"status": "completed"}
            response = client.post(
                "/process-batch",
                headers={"X-OCR-Provider": "fpt_ai_reader"},
                files=[("files", ("first.jpg", b"image")), ("files", ("second.jpg", b"image"))],
            )
        self.assertEqual(response.status_code, 200)
        self.assertEqual(process.await_count, 2)
        self.assertTrue(all(call.args[1] == "fpt_ai_reader" for call in process.await_args_list))

    async def test_ocr_uses_the_same_provider_as_metrics_and_honors_request_override(self) -> None:
        self.state_path.write_text('{"provider":"vnpt_ekyc"}')
        with patch("app.asyncio.to_thread", new_callable=AsyncMock) as extract:
            await app.extract_ocr_concurrently(Path("unused.jpg"))
            self.assertEqual(extract.call_args.args[2], app.processing_metrics()["ocr"]["provider"])
            await app.extract_ocr_concurrently(Path("unused.jpg"), "fpt_ai_reader")
            self.assertEqual(extract.call_args.args[2], "fpt_ai_reader")


if __name__ == "__main__":
    unittest.main()
