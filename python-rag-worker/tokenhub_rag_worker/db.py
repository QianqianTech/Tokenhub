from __future__ import annotations

from contextlib import contextmanager
from typing import Any, Iterator
import uuid

import mysql.connector

from .config import Config


class Database:
    def __init__(self, config: Config):
        self.config = config

    @contextmanager
    def connect(self) -> Iterator[Any]:
        connection = mysql.connector.connect(
            host=self.config.db_host,
            port=self.config.db_port,
            database=self.config.db_database,
            user=self.config.db_username,
            password=self.config.db_password,
            autocommit=False,
        )
        try:
            yield connection
            connection.commit()
        except Exception:
            connection.rollback()
            raise
        finally:
            connection.close()

    def ping(self) -> None:
        with self.connect() as connection:
            cursor = connection.cursor()
            cursor.execute("SELECT 1")
            cursor.fetchall()

    def claim_next_job(self) -> dict[str, Any] | None:
        with self.connect() as connection:
            cursor = connection.cursor(dictionary=True)
            cursor.execute(
                """
                UPDATE rag_embedding_jobs
                SET status = 'retrying', locked_at = NULL, lock_token = NULL, next_run_at = NULL
                WHERE execution_backend = 'python_local'
                  AND status = 'running'
                  AND (locked_at IS NULL OR TIMESTAMPDIFF(SECOND, locked_at, NOW()) > %s)
                """,
                (self.config.job_lease_seconds,),
            )
            cursor.execute(
                """
                SELECT j.*,
                       vc.collection_name,
                       vc.dimension,
                       vc.config_json,
                       d.dataset_id AS external_dataset_id,
                       d.name AS dataset_name,
                       k.kb_id AS external_kb_id
                FROM rag_embedding_jobs j
                LEFT JOIN rag_vector_configs vc ON vc.id = j.vector_config_id
                LEFT JOIN rag_vector_datasets d ON d.id = j.dataset_id
                LEFT JOIN rag_knowledge_bases k ON k.id = j.kb_id
                WHERE j.status IN ('queued','retrying')
                  AND j.execution_backend = 'python_local'
                  AND (j.next_run_at IS NULL OR j.next_run_at <= NOW())
                ORDER BY j.id
                LIMIT 1
                FOR UPDATE SKIP LOCKED
                """
            )
            job = cursor.fetchone()
            if not job:
                return None

            lock_token = uuid.uuid4().hex
            cursor.execute(
                """
                UPDATE rag_embedding_jobs
                SET status = 'running', attempts = attempts + 1, locked_at = NOW(), lock_token = %s, progress_percent = 5
                WHERE id = %s
                """,
                (lock_token, job["id"]),
            )
            claimed = dict(job)
            claimed["lock_token"] = lock_token
            claimed["attempts"] = int(claimed.get("attempts") or 0) + 1
            return claimed

    def mark_job_succeeded(self, job_id: int, lock_token: str) -> None:
        with self.connect() as connection:
            cursor = connection.cursor()
            cursor.execute(
                """
                UPDATE rag_embedding_jobs
                SET status = 'succeeded',
                    processed_items = total_items,
                    progress_percent = 100,
                    error_code = NULL,
                    error_message = NULL,
                    locked_at = NULL,
                    lock_token = NULL,
                    next_run_at = NULL
                WHERE id = %s AND lock_token = %s
                """,
                (job_id, lock_token),
            )

    def mark_job_failed(self, job_id: int, lock_token: str, error_code: str, error_message: str) -> None:
        with self.connect() as connection:
            cursor = connection.cursor()
            cursor.execute(
                """
                UPDATE rag_embedding_jobs
                SET status = IF(attempts >= max_attempts, 'failed', 'retrying'),
                    error_code = %s,
                    error_message = %s,
                    next_run_at = DATE_ADD(NOW(), INTERVAL LEAST(900, attempts * 30) SECOND),
                    locked_at = NULL,
                    lock_token = NULL
                WHERE id = %s AND lock_token = %s
                """,
                (error_code, error_message[:500], job_id, lock_token),
            )

    def refresh_upload_status(self, upload_id: int | None) -> None:
        if not upload_id:
            return

        with self.connect() as connection:
            cursor = connection.cursor(dictionary=True)
            cursor.execute("SELECT * FROM rag_uploads WHERE id = %s", (upload_id,))
            upload = cursor.fetchone()
            if not upload:
                return

            cursor.execute(
                """
                SELECT
                    COUNT(*) AS total_jobs,
                    SUM(status = 'succeeded') AS succeeded_jobs,
                    SUM(status IN ('failed','cancelled')) AS failed_jobs,
                    SUM(status IN ('queued','running','retrying')) AS active_jobs
                FROM rag_embedding_jobs
                WHERE upload_id = %s AND job_type <> 'parse'
                """,
                (upload_id,),
            )
            summary = cursor.fetchone() or {}
            total = int(summary.get("total_jobs") or 0)
            if total <= 0:
                return

            succeeded = int(summary.get("succeeded_jobs") or 0)
            failed = int(summary.get("failed_jobs") or 0)
            active = int(summary.get("active_jobs") or 0)
            if succeeded == total:
                vectorize_status = "indexed"
                status = "indexed"
                stage = "indexed"
                progress = 100
            elif active > 0:
                vectorize_status = "processing"
                status = "vectorizing"
                stage = "vectorizing"
                progress = max(45, min(95, int((succeeded / total) * 100)))
            elif succeeded > 0 and failed > 0:
                vectorize_status = "partial_failed"
                status = "partial_failed"
                stage = "partial_failed"
                progress = 80
            else:
                vectorize_status = "failed"
                status = "failed"
                stage = "failed"
                progress = 80

            cursor.execute(
                """
                UPDATE rag_uploads
                SET vectorize_status = %s,
                    status = %s,
                    processed_items = %s,
                    progress_percent = %s,
                    progress = %s,
                    current_stage = %s,
                    stage = %s
                WHERE id = %s
                """,
                (vectorize_status, status, succeeded, progress, progress, stage, stage, upload_id),
            )

            dataset_id = upload.get("dataset_id")
            if dataset_id:
                if vectorize_status == "indexed":
                    cursor.execute(
                        """
                        UPDATE rag_vector_datasets
                        SET chunk_count = (SELECT COUNT(*) FROM rag_chunks WHERE kb_id = %s AND lifecycle_status = 'active'),
                            vector_count = (SELECT COUNT(*) FROM rag_vector_records WHERE dataset_id = %s AND index_status = 'indexed'),
                            last_indexed_at = NOW()
                        WHERE id = %s
                        """,
                        (upload.get("kb_id"), dataset_id, dataset_id),
                    )
                else:
                    cursor.execute(
                        """
                        UPDATE rag_vector_datasets
                        SET chunk_count = (SELECT COUNT(*) FROM rag_chunks WHERE kb_id = %s AND lifecycle_status = 'active'),
                            vector_count = (SELECT COUNT(*) FROM rag_vector_records WHERE dataset_id = %s AND index_status = 'indexed')
                        WHERE id = %s
                        """,
                        (upload.get("kb_id"), dataset_id, dataset_id),
                    )

    def row(self, sql: str, params: tuple[Any, ...]) -> dict[str, Any] | None:
        with self.connect() as connection:
            cursor = connection.cursor(dictionary=True)
            cursor.execute(sql, params)
            item = cursor.fetchone()
            return dict(item) if item else None

    def execute(self, sql: str, params: tuple[Any, ...]) -> None:
        with self.connect() as connection:
            cursor = connection.cursor()
            cursor.execute(sql, params)
