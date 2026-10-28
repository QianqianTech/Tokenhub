from __future__ import annotations

import time

import redis

from .config import Config
from .db import Database
from .tasks import (
    RAG_IMAGE_EMBEDDING_FAILED,
    RAG_MODEL_NOT_CONFIGURED,
    RAG_MULTIMODAL_EMBEDDING_FAILED,
    TaskProcessor,
)


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
        while True:
            self.record_heartbeat()
            processed = 0
            for _ in range(max(1, self.config.batch_size)):
                job = self.database.claim_next_job()
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
        try:
            self.processor.process(job)
            self.database.mark_job_succeeded(int(job["id"]), str(job["lock_token"]))
        except Exception as exc:
            self.database.mark_job_failed(int(job["id"]), str(job["lock_token"]), error_code_for(job), str(exc))
        finally:
            self.database.refresh_upload_status(job.get("upload_id"))


def error_code_for(job: dict) -> str:
    job_type = str(job.get("job_type") or "")
    if job_type == "image_embedding":
        return RAG_IMAGE_EMBEDDING_FAILED
    if job_type == "multimodal_embedding":
        return RAG_MULTIMODAL_EMBEDDING_FAILED
    return RAG_MODEL_NOT_CONFIGURED
