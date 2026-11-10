FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 BRIDGE_DB_PATH=/data/bridge.sqlite3
WORKDIR /app
COPY remote_bridge/requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt \
    && useradd --system --uid 10001 --home-dir /nonexistent --shell /usr/sbin/nologin bridge
COPY remote_bridge /app/remote_bridge
RUN mkdir -p /data && chown -R bridge:bridge /app /data
USER bridge
EXPOSE 8777
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8777/healthz', timeout=2)" || exit 1
CMD ["uvicorn", "remote_bridge.server:app", "--host", "0.0.0.0", "--port", "8777", "--proxy-headers", "--forwarded-allow-ips=*"]
