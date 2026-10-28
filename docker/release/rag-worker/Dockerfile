FROM python:3.12-slim

WORKDIR /worker

COPY python-rag-worker/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY python-rag-worker/tokenhub_rag_worker ./tokenhub_rag_worker

CMD ["python", "-m", "tokenhub_rag_worker"]
