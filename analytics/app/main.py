from fastapi import FastAPI

app = FastAPI(
    title="Data Speak Analytics API",
    version="1.0.0",
)


@app.get("/")
def root():
    return {
        "service": "Data Speak Analytics",
        "status": "running",
    }


@app.get("/health")
def health():
    return {
        "status": "ok",
    }
