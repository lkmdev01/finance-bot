#!/usr/bin/env python3
"""Local, single-concurrency Vexa recording transcriber for InovaFinance."""

from __future__ import annotations

import fcntl
import hashlib
import hmac
import json
import logging
import os
import resource
import shutil
import signal
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

LOG = logging.getLogger("meeting-transcription-worker")
STOP = threading.Event()


class SameOriginRedirectHandler(urllib.request.HTTPRedirectHandler):
    def __init__(self, allowed_netloc: str):
        super().__init__()
        self.allowed_netloc = allowed_netloc

    def redirect_request(self, request, response, code, message, headers, new_url):
        parsed = urllib.parse.urlparse(new_url)
        if parsed.scheme != "https" or parsed.netloc != self.allowed_netloc:
            raise RuntimeError("cross_origin_redirect_rejected")
        return super().redirect_request(request, response, code, message, headers, new_url)


def required_env(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"missing_config_{name.lower()}")
    return value


def read_json(response) -> dict:
    payload = response.read()
    if not payload:
        return {}
    parsed = json.loads(payload.decode("utf-8"))
    if not isinstance(parsed, dict):
        raise RuntimeError("invalid_json_response")
    return parsed


class LaravelClient:
    def __init__(self, base_url: str, secret: str, timeout: int):
        self.base_url = base_url.rstrip("/")
        self.secret = secret.encode()
        self.timeout = timeout
        parsed_base = urllib.parse.urlparse(self.base_url)
        if parsed_base.scheme != "https":
            raise RuntimeError("laravel_endpoint_must_use_https")
        if len(self.secret) < 32:
            raise RuntimeError("worker_secret_too_short")
        self.opener = urllib.request.build_opener(SameOriginRedirectHandler(parsed_base.netloc))

    def post(self, path: str, payload: dict | None = None, lease: str | None = None) -> tuple[int, dict]:
        body = json.dumps(payload or {}, ensure_ascii=False, separators=(",", ":")).encode()
        parsed_base = urllib.parse.urlparse(self.base_url)
        full_url = self.base_url + path
        parsed_target = urllib.parse.urlparse(full_url)
        if parsed_target.scheme != "https" or parsed_target.netloc != parsed_base.netloc:
            raise RuntimeError("laravel_url_origin_mismatch")
        timestamp = str(int(time.time()))
        nonce = uuid.uuid4().hex
        body_hash = hashlib.sha256(body).hexdigest()
        message = "\n".join(["POST", parsed_target.path, timestamp, nonce, body_hash]).encode()
        signature = hmac.new(self.secret, message, hashlib.sha256).hexdigest()
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-Worker-Timestamp": timestamp,
            "X-Worker-Nonce": nonce,
            "X-Worker-Signature": signature,
        }
        if lease:
            headers["X-Meeting-Lease"] = lease
        request = urllib.request.Request(full_url, data=body, headers=headers, method="POST")
        try:
            with self.opener.open(request, timeout=self.timeout) as response:
                return response.status, read_json(response)
        except urllib.error.HTTPError as exc:
            try:
                data = read_json(exc)
            except Exception:
                data = {}
            return exc.code, data
        except Exception as exc:
            raise RuntimeError("laravel_request_failed") from exc


class VexaClient:
    def __init__(self, base_url: str, api_key: str, timeout: int):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key
        self.timeout = timeout
        parsed = urllib.parse.urlparse(self.base_url)
        if parsed.scheme != "https":
            raise RuntimeError("vexa_endpoint_must_use_https")
        self.opener = urllib.request.build_opener(SameOriginRedirectHandler(parsed.netloc))

    def request(self, path: str, dest: Path | None = None) -> dict | None:
        base = urllib.parse.urlparse(self.base_url)
        url = urllib.parse.urljoin(self.base_url + "/", path.lstrip("/"))
        parsed = urllib.parse.urlparse(url)
        if parsed.scheme != "https" or parsed.netloc != base.netloc:
            raise RuntimeError("vexa_url_origin_mismatch")
        request = urllib.request.Request(url, headers={"X-API-Key": self.api_key, "Accept": "application/json"})
        try:
            with self.opener.open(request, timeout=self.timeout) as response:
                if dest is None:
                    return read_json(response)
                total = 0
                with dest.open("xb") as output:
                    os.chmod(dest, 0o600)
                    while True:
                        chunk = response.read(1024 * 1024)
                        if not chunk:
                            break
                        total += len(chunk)
                        if total > int(os.environ.get("VEXA_MAX_AUDIO_BYTES", str(2 * 1024 * 1024 * 1024))):
                            raise RuntimeError("recording_too_large")
                        output.write(chunk)
                return {"bytes": total}
        except urllib.error.HTTPError as exc:
            raise RuntimeError(f"vexa_http_{exc.code}") from None
        except RuntimeError:
            raise
        except Exception as exc:
            raise RuntimeError("vexa_request_failed") from exc

    def find_recording(self, vexa_meeting_id: str) -> dict:
        if not vexa_meeting_id.isdigit():
            raise RuntimeError("missing_vexa_meeting_id")
        query = urllib.parse.urlencode({"limit": 50, "offset": 0, "meeting_id": int(vexa_meeting_id)})
        listing = self.request("/recordings?" + query) or {}
        rows = listing.get("recordings", [])
        matches = [row for row in rows if str(row.get("meeting_id", "")) == vexa_meeting_id
                   and row.get("status") in ("completed", "complete", "ready")]
        if not matches:
            raise RuntimeError("recording_not_ready")
        rec = matches[0]
        recording_id = str(rec.get("id", ""))
        if not recording_id.isdigit():
            raise RuntimeError("invalid_recording_identity")
        return rec

    def download_audio(self, recording: dict, path: Path) -> tuple[str, str]:
        rid = str(recording["id"])
        manifest = self.request(f"/recordings/{rid}/master?type=audio") or {}
        raw_url = manifest.get("raw_url")
        media_id = str(manifest.get("media_file_id") or "")
        if not raw_url:
            media_files = recording.get("media_files", [])
            audio = next((item for item in media_files if item.get("type") == "audio"), None)
            if not audio:
                raise RuntimeError("recording_audio_missing")
            media_id = str(audio.get("id", ""))
            raw_url = f"/recordings/{rid}/media/{media_id}/raw?type=audio"
        parsed_base = urllib.parse.urlparse(self.base_url)
        parsed_raw = urllib.parse.urlparse(urllib.parse.urljoin(self.base_url + "/", raw_url))
        if parsed_raw.netloc != parsed_base.netloc or not parsed_raw.path.startswith(f"/recordings/{rid}/"):
            raise RuntimeError("invalid_recording_media_url")
        self.request(parsed_raw.path + (("?" + parsed_raw.query) if parsed_raw.query else ""), path)
        if not media_id and "/media/" in parsed_raw.path:
            media_id = parsed_raw.path.split("/media/", 1)[1].split("/", 1)[0]
        if not media_id:
            raise RuntimeError("missing_media_file_id")
        return rid, media_id


def transcribe(audio_path: Path, model_name: str,
               cancelled: threading.Event | None = None) -> tuple[list[dict], float, float, int, float]:
    try:
        from faster_whisper import WhisperModel
    except Exception as exc:
        raise RuntimeError("faster_whisper_unavailable") from exc
    model = WhisperModel(model_name, device="cpu", compute_type=os.environ.get("WHISPER_COMPUTE_TYPE", "int8"))
    before_cpu = resource.getrusage(resource.RUSAGE_SELF).ru_utime + resource.getrusage(resource.RUSAGE_SELF).ru_stime
    started = time.monotonic()
    iterator, info = model.transcribe(
        str(audio_path), language="pt", vad_filter=False,
        beam_size=int(os.environ.get("WHISPER_BEAM_SIZE", "5")),
    )
    segments = []
    for item in iterator:
        if STOP.is_set() or (cancelled is not None and cancelled.is_set()):
            raise RuntimeError("worker_stopping")
        text = item.text.strip()
        if text:
            segments.append({"start": float(item.start), "end": float(item.end), "text": text})
    elapsed = time.monotonic() - started
    usage = resource.getrusage(resource.RUSAGE_SELF)
    cpu_seconds = max(0.0, usage.ru_utime + usage.ru_stime - before_cpu)
    peak_mib = usage.ru_maxrss / 1024
    return segments, float(info.duration or 0), elapsed, int(peak_mib), cpu_seconds


def cleanup_stale_temp_dirs() -> int:
    ttl_hours = min(720, max(1, int(os.environ.get("WORKER_TEMP_TTL_HOURS", "24"))))
    cutoff = time.time() - ttl_hours * 3600
    removed = 0
    for path in Path(tempfile.gettempdir()).glob("inovafinance-vexa-*"):
        try:
            stat_result = path.stat()
            if path.is_symlink() or not path.is_dir() or stat_result.st_uid != os.getuid() or stat_result.st_mtime > cutoff:
                continue
            shutil.rmtree(path)
            removed += 1
        except OSError:
            LOG.warning("Could not clean an expired private temp directory")
    return removed


def heartbeat_loop(client: LaravelClient, task_id: int, lease: str, lease_seconds: int,
                   lost: threading.Event, stop: threading.Event):
    interval = max(15, min(3600, lease_seconds) // 3)
    while not STOP.is_set() and not stop.wait(interval):
        status, body = client.post(f"/api/v1/internal/meeting-transcriptions/{task_id}/heartbeat", {}, lease)
        if status != 200 or body.get("status") != "renewed":
            lost.set()
            LOG.warning("Lease renewal failed for task %s; stopping result submission", task_id)
            return


def process_one(laravel: LaravelClient, vexa: VexaClient, task: dict, lease_seconds: int, model_name: str):
    task_id = int(task["task_id"])
    lease = str(task["lease_token"])
    heartbeat_lost = threading.Event()
    heartbeat_stop = threading.Event()
    heartbeat = threading.Thread(target=heartbeat_loop,
    args=(laravel, task_id, lease, lease_seconds, heartbeat_lost, heartbeat_stop), daemon=True)
    heartbeat.start()
    task_timeout = min(43200, max(300, int(os.environ.get("WORKER_TASK_TIMEOUT_SECONDS", "14400"))))
    previous_alarm = signal.getsignal(signal.SIGALRM)

    def timeout_task(_signum, _frame):
        raise RuntimeError("transcription_timeout")

    signal.signal(signal.SIGALRM, timeout_task)
    signal.setitimer(signal.ITIMER_REAL, task_timeout)
    try:
        meeting = task.get("meeting") or {}
        recording = vexa.find_recording(str(meeting.get("vexa_meeting_id") or ""))
        with tempfile.TemporaryDirectory(prefix="inovafinance-vexa-") as temp_dir:
            os.chmod(temp_dir, 0o700)
            audio_path = Path(temp_dir) / "meeting-audio"
            recording_id, media_file_id = vexa.download_audio(recording, audio_path)
            segments, duration, elapsed, peak_mib, cpu_seconds = transcribe(audio_path, model_name, heartbeat_lost)
        if heartbeat_lost.is_set():
            return
        intervals = sorted((max(0.0, item["start"]), max(0.0, item["end"])) for item in segments)
        speech_seconds, interval_start, interval_end = 0.0, None, None
        for start, end in intervals:
            if end <= start:
                continue
            if interval_start is None:
                interval_start, interval_end = start, end
            elif start <= interval_end:
                interval_end = max(interval_end, end)
            else:
                speech_seconds += interval_end - interval_start
                interval_start, interval_end = start, end
        if interval_start is not None:
            speech_seconds += interval_end - interval_start
        payload = {
            "language": "pt", "model": model_name,
            "recording_id": recording_id, "media_file_id": media_file_id,
            "audio_duration_seconds": duration,
            "speech_duration_seconds": speech_seconds,
            "segments": segments,
        }
        code, response = laravel.post(f"/api/v1/internal/meeting-transcriptions/{task_id}/result", payload, lease)
        if code != 200 or response.get("status") not in ("accepted", "needs_review", "duplicate"):
            raise RuntimeError("result_submission_rejected")
        LOG.info("Task %s completed with status %s; audio %.1fs, process %.1fs, CPU %.1fs, peak RSS delta %d MiB",
                 task_id, response.get("status"), duration, elapsed, cpu_seconds, peak_mib)
    except Exception as exc:
        if heartbeat_lost.is_set() or str(exc) == "worker_stopping":
            return
        error_code = str(exc) if str(exc).replace("_", "").isalnum() and len(str(exc)) <= 64 else "transcription_failed"
        laravel.post(f"/api/v1/internal/meeting-transcriptions/{task_id}/fail", {"error_code": error_code}, lease)
        LOG.warning("Task %s failed with code %s", task_id, error_code)
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
        signal.signal(signal.SIGALRM, previous_alarm)
        heartbeat_stop.set()
        heartbeat.join(timeout=2)


def main():
    logging.basicConfig(level=os.environ.get("LOG_LEVEL", "INFO"), format="%(asctime)s %(levelname)s %(message)s")
    base = required_env("LARAVEL_BASE_URL")
    worker_secret = required_env("VEXA_TRANSCRIPTION_WORKER_SECRET")
    vexa_base = required_env("VEXA_BASE_URL")
    vexa_key = required_env("VEXA_API_KEY")
    timeout = int(os.environ.get("WORKER_HTTP_TIMEOUT_SECONDS", "60"))
    idle = int(os.environ.get("WORKER_IDLE_SECONDS", "20"))
    lease_seconds = min(3600, max(120, int(os.environ.get("VEXA_TRANSCRIPTION_LEASE_SECONDS", "600"))))
    model_name = os.environ.get("WHISPER_MODEL", "Systran/faster-whisper-small")
    lock_path = Path(os.environ.get("WORKER_LOCK_FILE", "/run/lock/inovafinance-vexa-transcriber.lock"))
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    lock_file = lock_path.open("w")
    try:
        fcntl.flock(lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        raise RuntimeError("another_worker_process_is_running") from None

    laravel = LaravelClient(base, worker_secret, timeout)
    vexa = VexaClient(vexa_base, vexa_key, timeout)
    signal.signal(signal.SIGTERM, lambda *_: STOP.set())
    signal.signal(signal.SIGINT, lambda *_: STOP.set())
    removed_temp_dirs = cleanup_stale_temp_dirs()
    LOG.info("Local transcription worker started; model=%s; concurrency=1; stale_temp_dirs_removed=%d",
             model_name, removed_temp_dirs)
    while not STOP.is_set():
        try:
            status, payload = laravel.post("/api/v1/internal/meeting-transcriptions/claim", {})
            if status == 204 or payload.get("status") == "empty":
                STOP.wait(idle)
                continue
            if status != 200 or payload.get("status") != "claimed":
                LOG.warning("Task claim failed with HTTP %s", status)
                STOP.wait(idle)
                continue
            process_one(laravel, vexa, payload, lease_seconds, model_name)
        except Exception as exc:
            reason = str(exc) if str(exc).replace("_", "").isalnum() and len(str(exc)) <= 64 else "worker_loop_error"
            LOG.warning("Worker loop failed with code %s", reason)
            STOP.wait(idle)
    LOG.info("Local transcription worker stopped")


if __name__ == "__main__":
    main()
