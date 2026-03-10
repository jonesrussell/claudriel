import os
from fastapi import HTTPException, Security
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

security = HTTPBearer()


def verify_api_key(
    credentials: HTTPAuthorizationCredentials = Security(security),
) -> str:
    expected = os.environ.get("CLAUDRIEL_SIDECAR_KEY", "")
    if not expected:
        raise HTTPException(status_code=500, detail="CLAUDRIEL_SIDECAR_KEY not configured")
    if credentials.credentials != expected:
        raise HTTPException(status_code=401, detail="Invalid API key")
    return credentials.credentials
