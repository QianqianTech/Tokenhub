FROM python:3.12-slim

WORKDIR /worker

# 1 = also install OCR (rapidocr + onnxruntime) for scanned PDFs and images.
ARG RAG_WORKER_OCR=0

COPY python-rag-worker/requirements.txt python-rag-worker/requirements-ocr.txt ./
RUN pip install --no-cache-dir -r requirements.txt \
    && if [ "$RAG_WORKER_OCR" = "1" ]; then pip install --no-cache-dir -r requirements-ocr.txt; fi

COPY python-rag-worker/tokenhub_rag_worker ./tokenhub_rag_worker

RUN chmod -R a+rX /worker/tokenhub_rag_worker

CMD ["python", "-m", "tokenhub_rag_worker"]
