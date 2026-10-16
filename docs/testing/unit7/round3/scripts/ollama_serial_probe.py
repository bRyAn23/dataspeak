"""Does the local Ollama server run two requests to one model at the same time? (DEF-09 root cause, step 2.2)
Sends two identical short generations concurrently and records when each finished; then sends one alone for reference.
If the second request ends about one single-request duration after the first, the server processed them one at a time.
No dataset content is involved."""
import json, os, threading, time
import httpx
URL = "http://localhost:11434"; MODEL = "qwen3:8b"
def gen(tag, out):
    t0 = time.perf_counter(); r = httpx.post(f"{URL}/api/generate", json={"model": MODEL, "prompt": "Count from one to forty in words.", "stream": False, "think": False, "options": {"num_predict": 40, "temperature": 0}}, timeout=120).json()
    out[tag] = {"wall_s": round(time.perf_counter() - t0, 2), "eval_count": r.get("eval_count"), "eval_s": round(r.get("eval_duration", 0) / 1e9, 2), "load_s": round(r.get("load_duration", 0) / 1e9, 2)}
print(json.dumps({"ollama_version": httpx.get(f"{URL}/api/version").json().get("version"), "OLLAMA_NUM_PARALLEL_env": os.environ.get("OLLAMA_NUM_PARALLEL", "unset"), "loaded": [(m["name"], m.get("size"), m.get("size_vram")) for m in httpx.get(f"{URL}/api/ps").json().get("models", [])]}))
single = {}; gen("warm", single); gen("single", single); print("single request:", json.dumps(single["single"]))
res = {}; ts = [threading.Thread(target=gen, args=(f"concurrent_{i}", res)) for i in (1, 2)]
[t.start() for t in ts]; [t.join() for t in ts]
print("two concurrent requests:", json.dumps(res))
walls = sorted(v["wall_s"] for v in res.values()); evals = [v["eval_s"] for v in res.values()]
verdict = "SERIAL (second finished ~one request after the first)" if walls[1] >= walls[0] + 0.7 * single["single"]["eval_s"] else "PARALLEL (both finished together)"
print("verdict:", verdict, {"first_done_s": walls[0], "second_done_s": walls[1], "single_eval_s": single["single"]["eval_s"], "concurrent_eval_s": evals})
