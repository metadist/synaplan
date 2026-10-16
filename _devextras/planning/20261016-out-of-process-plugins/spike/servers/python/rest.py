# OP0 spike — not product code. Started by ../../run.sh (fastapi==0.143.2, uvicorn==0.54.0).
from fastapi import FastAPI

app = FastAPI(title="python-rest-tools", version="1.0.0")


@app.get("/word-stats", operation_id="word_stats", summary="Count words in a text")
def word_stats(text: str) -> dict:
    return {"words": len(text.split()), "characters": len(text), "runtime": "python-fastapi"}
