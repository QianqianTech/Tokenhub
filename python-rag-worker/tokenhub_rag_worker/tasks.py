from __future__ import annotations

import json
import math
import os
import re
import uuid
import zlib
from typing import Any
from urllib.parse import quote

import requests
from PIL import Image

from .config import Config
from .db import Database
from .documents import OcrUnavailable, image_ocr_engine, pdf_to_markdown
from .redaction import normalize_types, redact

RAG_MODEL_NOT_CONFIGURED = "RAG_MODEL_NOT_CONFIGURED"
RAG_IMAGE_EMBEDDING_FAILED = "RAG_IMAGE_EMBEDDING_FAILED"
RAG_MULTIMODAL_EMBEDDING_FAILED = "RAG_MULTIMODAL_EMBEDDING_FAILED"
RAG_OCR_UNAVAILABLE = "RAG_OCR_UNAVAILABLE"
RAG_DOCUMENT_EMPTY = "RAG_DOCUMENT_EMPTY"
CHUNKER_VERSION = "p2-v1"


class TaskProcessor:
    def __init__(self, config: Config, database: Database):
        self.config = config
        self.database = database

    def process(self, job: dict[str, Any]) -> None:
        job_type = str(job.get("job_type") or "")
        if job_type == "text_embedding":
            self.process_text_embedding(job)
        elif job_type == "image_embedding":
            self.process_image_embedding(job)
        elif job_type == "multimodal_embedding":
            self.process_multimodal_embedding(job)
        elif job_type == "ocr":
            self.process_ocr(job)
        elif job_type == "caption":
            self.process_caption(job)
        elif job_type == "parse":
            self.process_parse(job)
        else:
            raise RuntimeError(f"unsupported RAG job type: {job_type}")

    def process_text_embedding(self, job: dict[str, Any]) -> None:
        chunk = self.load_chunk(job)
        dimension = 3 if job.get("dimension") is None else int(job["dimension"])
        vector = self.local_embedding(str(chunk.get("content") or ""), dimension)
        self.write_vector(job, chunk, vector, "text")

    def process_image_embedding(self, job: dict[str, Any]) -> None:
        chunk = self.load_chunk(job)
        asset = self.load_image_asset(chunk)
        text = image_text(asset, chunk)
        dimension = 3 if job.get("dimension") is None else int(job["dimension"])
        vector = self.local_embedding(text, dimension)
        self.write_vector(job, chunk, vector, "image")

    def process_multimodal_embedding(self, job: dict[str, Any]) -> None:
        chunk = self.load_chunk(job)
        asset = self.load_image_asset(chunk)
        text = str(chunk.get("content") or "") + "\n" + image_text(asset, chunk)
        dimension = 3 if job.get("dimension") is None else int(job["dimension"])
        vector = self.local_embedding(text, dimension)
        self.write_vector(job, chunk, vector, "multimodal")

    def process_ocr(self, job: dict[str, Any]) -> None:
        # Real OCR only. The former placeholder wrote "image WxH" into ocr_text, which then
        # polluted lexical and vector indexes (RAG-009).
        chunk = self.load_chunk(job)
        asset = self.load_image_asset(chunk)
        engine = image_ocr_engine()
        if engine is None:
            raise RuntimeError(RAG_OCR_UNAVAILABLE + ": install rapidocr_onnxruntime in the RAG worker image")
        path = storage_path(self.config.storage_root, str(asset.get("storage_path") or ""))
        if not os.path.isfile(path):
            raise RuntimeError("image file not found: " + path)
        text = engine(path).strip()
        self.database.execute(
            "UPDATE rag_image_assets SET ocr_text = %s, updated_at = NOW() WHERE id = %s",
            (text, asset["id"]),
        )

    def process_caption(self, job: dict[str, Any]) -> None:
        # Image descriptions come from a vision model through TokenHub (RagAiImageAnnotationService);
        # the worker has no model and no longer invents a caption from the existing text.
        raise RuntimeError("caption jobs are handled by the PHP vision annotation service")

    def process_parse(self, job: dict[str, Any]) -> None:
        upload = self.database.row("SELECT * FROM rag_uploads WHERE id = %s", (job.get("upload_id"),))
        if not upload:
            raise RuntimeError("rag_uploads row not found")
        path = storage_path(self.config.storage_root, str(upload.get("storage_path") or ""))
        if not os.path.isfile(path):
            raise RuntimeError("upload file not found: " + path)
        metadata = json.loads(upload.get("metadata") or "{}") if isinstance(upload.get("metadata"), str) else (upload.get("metadata") or {})
        try:
            parsed = pdf_to_markdown(path)
        except OcrUnavailable as exc:
            self.fail_upload(upload, RAG_OCR_UNAVAILABLE, str(exc))
            raise RuntimeError(RAG_OCR_UNAVAILABLE + ": " + str(exc)) from exc
        body = re.sub(r"<!--\s*page:\d+\s*-->", "", parsed["markdown"]).strip()
        if not body:
            self.fail_upload(upload, RAG_DOCUMENT_EMPTY, "No text could be extracted from the document")
            raise RuntimeError(RAG_DOCUMENT_EMPTY)
        filename = str(metadata.get("original_filename") or upload.get("original_filename") or "document")
        title = os.path.splitext(os.path.basename(filename))[0] or "document"
        redaction_types = normalize_types(metadata.get("redaction_types"))
        markdown, redaction_counts = redact(parsed["markdown"], redaction_types)
        title, title_counts = redact(title, redaction_types)
        for kind, count in title_counts.items():
            redaction_counts[kind] = redaction_counts.get(kind, 0) + count
        item_metadata = {
            "document_format": "pdf",
            "parser": "python:pdf" + ("+ocr" if parsed["ocr_pages"] else ""),
            "pages": parsed["pages"],
            "ocr_pages": parsed["ocr_pages"],
            "parse_warnings": parsed["warnings"],
            "chunker": CHUNKER_VERSION,
            "document_key": metadata.get("document_key"),
            "chunking_profile": metadata.get("chunking_profile"),
        }
        if redaction_types:
            item_metadata["redaction"] = {"types": redaction_types, "counts": redaction_counts}
        self.database.execute(
            """
            INSERT INTO rag_upload_preview_items (preview_item_id, upload_id, kb_id, dataset_id, item_type, title, content, validation_status, validation_errors, metadata)
            VALUES (%s, %s, %s, %s, 'text', %s, %s, %s, %s, %s)
            """,
            (
                "pvi_" + uuid.uuid4().hex[:24],
                upload["id"],
                upload["kb_id"],
                upload.get("dataset_id"),
                title[:250],
                markdown,
                "warning" if parsed["warnings"] else "valid",
                json.dumps(parsed["warnings"], ensure_ascii=False),
                json.dumps(item_metadata, ensure_ascii=False),
            ),
        )
        waiting = "waiting_review" if str(upload.get("review_status") or "") == "pending" else "waiting_confirm"
        self.database.execute(
            """
            UPDATE rag_uploads
            SET preview_status = 'ready', status = %s, total_items = 1, text_items = 1, image_items = 0,
                current_stage = %s, stage = %s, progress_percent = 30, progress = 30
            WHERE id = %s AND preview_status = 'building'
            """,
            (waiting, waiting, waiting, upload["id"]),
        )

    def fail_upload(self, upload: dict[str, Any], code: str, message: str) -> None:
        self.database.execute(
            "UPDATE rag_uploads SET preview_status = 'failed', status = 'failed', error_code = %s, error_message = %s, current_stage = 'failed', stage = 'failed' WHERE id = %s",
            (code, message[:500], upload["id"]),
        )

    def load_chunk(self, job: dict[str, Any]) -> dict[str, Any]:
        chunk = self.database.row("SELECT * FROM rag_chunks WHERE id = %s", (job.get("chunk_id"),))
        if not chunk:
            raise RuntimeError("rag_chunks row not found")
        return chunk

    def load_image_asset(self, chunk: dict[str, Any]) -> dict[str, Any]:
        image_id = chunk.get("image_asset_id")
        if not image_id:
            raise RuntimeError("rag_image_assets row not configured for image job")
        asset = self.database.row("SELECT * FROM rag_image_assets WHERE id = %s", (image_id,))
        if not asset:
            raise RuntimeError("rag_image_assets row not found")
        return asset

    def write_vector(self, job: dict[str, Any], chunk: dict[str, Any], vector: list[float], modality: str) -> None:
        collection = self.collection_name(job, modality)
        vector_id = f"vec_{modality}_{chunk['chunk_id']}"
        qdrant_point_id = qdrant_id(vector_id)
        payload = vector_payload(job, chunk, vector_id, modality)
        self.upsert_qdrant(collection, qdrant_point_id, vector, payload)
        self.database.execute(
            """
            INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, image_id, vector_config_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'indexed', 'indexed', NOW())
            ON DUPLICATE KEY UPDATE collection_name = VALUES(collection_name), vector_config_id = VALUES(vector_config_id), vector_json = VALUES(vector_json), content_hash = VALUES(content_hash), index_status = 'indexed', status = 'indexed', indexed_at = NOW(), deleted_at = NULL
            """,
            (
                vector_id,
                collection,
                chunk.get("kb_id"),
                job.get("dataset_id"),
                chunk.get("id"),
                chunk.get("image_asset_id"),
                job.get("vector_config_id"),
                modality,
                len(vector),
                json.dumps(vector),
                chunk.get("content_hash"),
            ),
        )

    def local_embedding(self, text: str, dimension: int) -> list[float]:
        if not self.config.enable_local_fallback:
            raise RuntimeError(RAG_MODEL_NOT_CONFIGURED)
        return deterministic_embedding(text, dimension)

    def collection_name(self, job: dict[str, Any], modality: str) -> str:
        configured = str(job.get("collection_name") or "").strip()
        if configured:
            return configured
        dataset_id = sanitize_identifier(str(job.get("external_dataset_id") or "unknown"), "unknown")
        return f"{self.config.qdrant_collection_prefix}_{dataset_id}_{modality}"

    def upsert_qdrant(self, collection: str, vector_id: str, vector: list[float], payload: dict[str, Any]) -> None:
        if not self.config.qdrant_url:
            if self.config.enable_local_fallback:
                return
            raise RuntimeError(RAG_MODEL_NOT_CONFIGURED)
        self.ensure_qdrant_collection(collection, len(vector))
        encoded_collection = quote(collection, safe="")
        response = requests.put(
            f"{self.config.qdrant_url}/collections/{encoded_collection}/points?wait=true",
            headers=self.config.qdrant_headers,
            json={"points": [{"id": vector_id, "vector": vector, "payload": payload}]},
            timeout=10,
        )
        if response.status_code >= 400:
            raise RuntimeError(response.text[:500])

    def ensure_qdrant_collection(self, collection: str, dimension: int) -> None:
        encoded_collection = quote(collection, safe="")
        exists = requests.get(
            f"{self.config.qdrant_url}/collections/{encoded_collection}",
            headers=self.config.qdrant_headers,
            timeout=5,
        )
        if exists.status_code >= 200 and exists.status_code < 300:
            return
        if exists.status_code != 404:
            raise RuntimeError(exists.text[:500])
        created = requests.put(
            f"{self.config.qdrant_url}/collections/{encoded_collection}",
            headers=self.config.qdrant_headers,
            json={"vectors": {"size": max(1, dimension), "distance": "Cosine"}},
            timeout=10,
        )
        if created.status_code >= 400:
            raise RuntimeError(created.text[:500])


def deterministic_embedding(text: str, dimension: int = 3) -> list[float]:
    vector = [0.0 for _ in range(max(1, dimension))]
    tokens = [token for token in text.lower().split() if token] or list(text)
    for token in tokens:
        vector[zlib.crc32(token.encode("utf-8")) % len(vector)] += 1.0
    norm = math.sqrt(sum(value * value for value in vector))
    return [value / norm for value in vector] if norm else vector


def qdrant_id(vector_id: str) -> str:
    return str(uuid.uuid5(uuid.NAMESPACE_URL, vector_id))


def sanitize_identifier(value: str, fallback: str) -> str:
    return re.sub(r"[^A-Za-z0-9_]+", "_", value) or fallback


def image_text(asset: dict[str, Any], chunk: dict[str, Any]) -> str:
    parts = [
        asset.get("purpose"),
        asset.get("short_description"),
        asset.get("detailed_description"),
        asset.get("ocr_text"),
        chunk.get("content"),
    ]
    return "\n".join(str(part) for part in parts if part)


def vector_payload(job: dict[str, Any], chunk: dict[str, Any], vector_id: str, modality: str) -> dict[str, Any]:
    """Same payload keys as PHP RagVectorContractService::forChunk + RagEmbeddingJobService (RAG-036)."""
    metadata = chunk.get("metadata")
    if isinstance(metadata, str):
        try:
            metadata = json.loads(metadata or "{}")
        except ValueError:
            metadata = {}
    metadata = metadata if isinstance(metadata, dict) else {}
    return {
        "vector_id": vector_id,
        "kb_id": job.get("external_kb_id"),
        "dataset_id": job.get("external_dataset_id"),
        "dataset_name": job.get("dataset_name") or "",
        "chunk_id": chunk.get("chunk_id"),
        "document_key": metadata.get("document_key") or "",
        "title": chunk.get("title") or "",
        "content": chunk.get("content") or "",
        "modality": modality,
        "image_id": job.get("external_image_id"),
        "source_uri": metadata.get("source_uri") or "",
        "human_annotation": metadata.get("human_annotation") or {},
        "ai_annotation": metadata.get("ai_annotation") or {},
    }


def storage_path(root: str, path: str) -> str:
    """Maps a path recorded by PHP to this container. Source installs store absolute host
    paths (e.g. /opt/tokenhub/storage/rag/...); the worker mounts storage at ``root``."""
    path = path.replace("\\", "/")
    if os.path.isabs(path) and os.path.exists(path):
        return path
    marker = "/storage/"
    if marker in path:
        return os.path.join(root, path.split(marker, 1)[1])
    if os.path.isabs(path):
        return path
    return os.path.join(root, path.removeprefix("storage/"))
