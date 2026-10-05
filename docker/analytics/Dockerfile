# Data Speak analytics - FastAPI (local development image)
FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PYTHONIOENCODING=utf-8 LANG=C.UTF-8 LC_ALL=C.UTF-8 \
    DATASPEAK_TMP_DIR=/tmp/dataspeak
WORKDIR /srv/analytics
COPY analytics/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY analytics/ ./
RUN adduser --disabled-password --gecos "" analytics && mkdir -p /tmp/dataspeak && chown analytics /tmp/dataspeak
USER analytics
EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
