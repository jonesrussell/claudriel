FROM python:3.11-slim

WORKDIR /srv/agent

COPY . .

RUN pip install --no-cache-dir -e .

ENTRYPOINT ["python", "-m", "claudriel_agent"]
