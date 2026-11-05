import os, sys, json
os.environ["HF_HOME"] = "/opt/hf"
os.environ["HF_HUB_OFFLINE"] = "1"
from transformers import pipeline
from PIL import Image

clf = pipeline("image-classification", model="umm-maybe/AI-image-detector")
img = Image.open(sys.argv[1]).convert("RGB")
scores = {r["label"].lower(): r["score"] for r in clf(img, top_k=None)}
ai = scores.get("artificial", 0)
print(json.dumps({
    "verdict": "AI-generated" if ai >= 0.5 else "Real",
    "confidence": round(max(ai, 1 - ai) * 100, 1)
}))
