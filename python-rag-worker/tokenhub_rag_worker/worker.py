from __future__ import annotations

import time

import redis

from . import logging_json as log
from .config import Config
from .db import Database
from .errors import error_code_for
from .tasks import TaskProcessor


class Worker:
    def __init__(self) -> None:
        self.config = Config()
        self.database = Database(self.config)
        self.redis = redis.Redis(host=self.config.redis_host, port=self.config.redis_port, password=self.config.redis_password or None, socket_timeout=2)
        self.processor = TaskProcessor(self.config, self.database)

    def healthcheck(self) -> None:
        self.database.ping()
        self.redis.ping()

    def run_forever(self) -> None:
        log.info("rag worker started", batch_size=self.config.batch_size, poll_interval_seconds=self.config.poll_interval_seconds)
        while True:
            self.record_heartbeat()
            processed = 0
            for _ in range(max(1, self.config.batch_size)):
                try:
                    job = self.database.claim_next_job()
                except Exception as exc:  # database down: log, back off, retry
                    log.error("rag job claim failed", error_code=error_code_for({}, exc), error=str(exc)[:500])
                    break
                if not job:
                    break
                processed += 1
                self.run_job(job)
                self.record_heartbeat()
            if processed == 0:
                time.sleep(self.config.poll_interval_seconds)

    def record_heartbeat(self) -> None:
        try:
            self.redis.set("operations:worker:rag-worker", str(int(time.time())), ex=30)
        except redis.RedisError:
            pass

    def run_job(self, job: dict) -> None:
        # BIZ-008: lines of one job carry its id as request_id (and the id of
        # the request that queued it when the job row has one).
        request_id = str(job.get("request_id") or job.get("job_id") or f"ragjob_{job.get('id')}")
        with log.correlation(request_id, str(job.get("trace_id") or "") or None):
            started = time.monotonic()
            context = {
                "job_id": job.get("job_id"),
                "job_type": job.get("job_type"),
                "upload_id": job.get("upload_id"),
                "attempt": job.get("attempts"),
            }
            try:
                self.processor.process(job)
                self.database.mark_job_succeeded(int(job["id"]), str(job["lock_token"]))
                log.info("rag job succeeded", latency_ms=int((time.monotonic() - started) * 1000), **context)
            except Exception as exc:
                code = error_code_for(job, exc)
                log.error(
                    "rag job failed",
                    error_code=code,
                    exception=type(exc).__name__,
                    error=str(exc)[:500],
                    latency_ms=int((time.monotonic() - started) * 1000),
                    **context,
                )
                try:
                    self.database.mark_job_failed(int(job["id"]), str(job["lock_token"]), code, str(exc))
                except Exception as mark_exc:
                    log.error("rag job failure could not be recorded", error=str(mark_exc)[:500], **context)
            finally:
                try:
                    self.database.refresh_upload_status(job.get("upload_id"))
                except Exception as refresh_exc:
                    log.warning("rag upload status refresh failed", error=str(refresh_exc)[:500], **context)
