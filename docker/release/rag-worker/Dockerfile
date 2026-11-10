# Pinned by digest through scripts/release/pin-base-images.sh (docker/release/base-images.env).
ARG BASE_IMAGE_PREFIX=
ARG PYTHON_BASE_IMAGE=${BASE_IMAGE_PREFIX}python:3.12-slim
FROM ${PYTHON_BASE_IMAGE}

WORKDIR /worker

# 1 = also install OCR (rapidocr + onnxruntime) for scanned PDFs and images.
ARG RAG_WORKER_OCR=0
ARG PIP_INDEX_URL=https://pypi.org/simple

COPY python-rag-worker/requirements.txt python-rag-worker/requirements-ocr.txt ./
RUN pip install --no-cache-dir -r requirements.txt \
    && if [ "$RAG_WORKER_OCR" = "1" ]; then pip install --no-cache-dir -r requirements-ocr.txt; fi

COPY python-rag-worker/tokenhub_rag_worker ./tokenhub_rag_worker

RUN chmod -R a+rX /worker/tokenhub_rag_worker

CMD ["python", "-m", "tokenhub_rag_worker"]
