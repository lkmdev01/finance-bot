import json
import os
import sys
import tempfile
import time
import types
import unittest
from pathlib import Path
from unittest.mock import patch

import meeting_transcription_worker as worker


class FakeResponse:
    status = 200

    def __init__(self, data):
        self.data = data

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def read(self, _size=-1):
        data, self.data = self.data, b""
        return data


class WorkerTests(unittest.TestCase):
    def test_laravel_request_signs_path_timestamp_nonce_and_body(self):
        captured = {}

        class Opener:
            def open(self, request, timeout):
                captured["request"] = request
                captured["timeout"] = timeout
                return FakeResponse(b'{"status":"empty"}')

        client = worker.LaravelClient("https://app.example", "s" * 40, 10)
        client.opener = Opener()
        status, body = client.post("/api/v1/internal/meeting-transcriptions/claim", {})
        req = captured["request"]
        self.assertEqual(status, 200)
        self.assertEqual(body["status"], "empty")
        self.assertEqual(req.get_method(), "POST")
        self.assertEqual(req.get_header("X-worker-signature").__len__(), 64)
        self.assertEqual(req.get_header("User-agent"), "InovaFinance-MeetingWorker/1.0")
        self.assertEqual(captured["timeout"], 10)

    def test_rejects_non_https_service_urls(self):
        with self.assertRaisesRegex(RuntimeError, "must_use_https"):
            worker.LaravelClient("http://app.example", "s" * 40, 10)
        with self.assertRaisesRegex(RuntimeError, "must_use_https"):
            worker.VexaClient("http://vexa.example", "api-key", 10)

    def test_faster_whisper_receives_complete_file_and_explicit_portuguese_language(self):
        calls = {}

        class Segment:
            start, end, text = 1.125, 3.75, "fala original"

        class Info:
            duration = 22.0

        class Model:
            def __init__(self, name, **kwargs):
                calls["model"] = name
                calls["init"] = kwargs

            def transcribe(self, path, **kwargs):
                calls["path"] = path
                calls["transcribe"] = kwargs
                return iter([Segment()]), Info()

        fake_module = types.SimpleNamespace(WhisperModel=Model)
        with patch.dict(sys.modules, {"faster_whisper": fake_module}), patch.dict(
            os.environ, {"WHISPER_INITIAL_PROMPT": "termos técnicos: API, SLA, conciliação"}
        ):
            segments, duration, _elapsed, _rss, _cpu = worker.transcribe(Path("/tmp/full-audio"), "Systran/faster-whisper-small")

        self.assertEqual(calls["model"], "Systran/faster-whisper-small")
        self.assertEqual(calls["transcribe"]["language"], "pt")
        self.assertFalse(calls["transcribe"]["vad_filter"])
        self.assertEqual(calls["transcribe"]["initial_prompt"], "termos técnicos: API, SLA, conciliação")
        self.assertEqual(calls["path"], "/tmp/full-audio")
        self.assertEqual(duration, 22.0)
        self.assertEqual(segments[0]["start"], 1.125)

    def test_worker_processes_full_audio_and_posts_original_timestamps(self):
        class FakeLaravel:
            def __init__(self):
                self.calls = []

            def post(self, path, payload=None, lease=None):
                self.calls.append((path, payload, lease))
                if path.endswith("/result"):
                    return 200, {"status": "accepted"}
                return 200, {"status": "renewed"}

        class FakeVexa:
            def find_recording(self, meeting_id):
                self.meeting_id = meeting_id
                return {"id": 17}

            def download_audio(self, recording, path):
                path.write_bytes(b"original-audio")
                self.path = path
                return "17", "29"

        laravel, vexa = FakeLaravel(), FakeVexa()
        segments = [{"start": 3.25, "end": 4.75, "text": "conteúdo de teste"}]
        with patch.object(worker, "transcribe", return_value=(segments, 15.0, 2.0, 64, 1.5)):
            worker.process_one(laravel, vexa, {
                "task_id": 5, "lease_token": "lease-secret",
                "meeting": {"vexa_meeting_id": "11"},
            }, 600, "Systran/faster-whisper-small")

        result_call = next(call for call in laravel.calls if call[0].endswith("/result"))
        self.assertEqual(vexa.meeting_id, "11")
        self.assertEqual(result_call[1]["language"], "pt")
        self.assertEqual(result_call[1]["segments"][0]["start"], 3.25)
        self.assertEqual(result_call[1]["segments"][0]["end"], 4.75)
        self.assertFalse(vexa.path.exists(), "temporary audio must be removed after processing")

    def test_vexa_recording_download_uses_official_read_endpoints(self):
        requests = []
        responses = [
            b'{"recordings":[{"id":17,"meeting_id":42,"status":"completed","media_files":[{"id":29,"type":"audio"}]}]}',
            b'{"id":17,"media_file_id":29,"raw_url":"/recordings/17/media/29/raw?type=audio"}',
            b"audio-bytes",
        ]

        class Opener:
            def open(self, request, timeout):
                requests.append(request)
                return FakeResponse(responses.pop(0))

        client = worker.VexaClient("https://vexa.example", "key-not-logged", 10)
        client.opener = Opener()
        recording = client.find_recording("42")
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "copy.webm"
            rid, media_id = client.download_audio(recording, path)
            self.assertEqual(path.read_bytes(), b"audio-bytes")
            self.assertEqual((rid, media_id), ("17", "29"))

        self.assertIn("/recordings?limit=50&offset=0&meeting_id=42", requests[0].full_url)
        self.assertTrue(requests[1].full_url.endswith("/recordings/17/master?type=audio"))
        self.assertTrue(requests[2].full_url.endswith("/recordings/17/media/29/raw?type=audio"))
        self.assertTrue(all(request.get_header("X-api-key") == "key-not-logged" for request in requests))
        self.assertTrue(all(request.get_header("User-agent") == "InovaFinance-MeetingWorker/1.0" for request in requests))

    def test_startup_cleanup_removes_only_stale_private_temp_directories(self):
        with tempfile.TemporaryDirectory() as directory:
            stale = Path(directory) / "inovafinance-vexa-stale"
            recent = Path(directory) / "inovafinance-vexa-recent"
            stale.mkdir()
            recent.mkdir()
            old = time.time() - 48 * 3600
            os.utime(stale, (old, old))
            with patch.object(worker.tempfile, "gettempdir", return_value=directory):
                removed = worker.cleanup_stale_temp_dirs()
            self.assertEqual(removed, 1)
            self.assertFalse(stale.exists())
            self.assertTrue(recent.exists())


if __name__ == "__main__":
    unittest.main()
