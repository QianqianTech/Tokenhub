FROM python:3.12-slim

WORKDIR /worker

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY tokenhub_rag_worker ./tokenhub_rag_worker

CMD ["python", "-m", "tokenhub_rag_worker"]
