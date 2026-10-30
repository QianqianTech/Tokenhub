FROM python:3.12-slim

WORKDIR /worker

# 1 = also install OCR (rapidocr + onnxruntime) for scanned PDFs and images.
ARG RAG_WORKER_OCR=0

COPY requirements.txt requirements-ocr.txt ./
RUN pip install --no-cache-dir -r requirements.txt \
    && if [ "$RAG_WORKER_OCR" = "1" ]; then pip install --no-cache-dir -r requirements-ocr.txt; fi

COPY tokenhub_rag_worker ./tokenhub_rag_worker

CMD ["python", "-m", "tokenhub_rag_worker"]
