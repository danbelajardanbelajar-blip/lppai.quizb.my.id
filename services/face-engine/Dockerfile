FROM python:3.12-slim
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app.py engine.py download_models.py ./
COPY licenses ./licenses
RUN python download_models.py && useradd --system --uid 10001 face
USER face
EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000", "--no-access-log", "--limit-concurrency", "8"]
