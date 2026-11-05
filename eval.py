import os, sys, re, glob
os.environ["HF_HOME"] = "/opt/hf"
from transformers import pipeline
from PIL import Image

AI_WORDS = {"ai", "artificial", "fake", "synthetic", "deepfake"}
EXT = (".jpg", ".jpeg", ".png", ".webp")

files = [(f, 0) for f in sorted(glob.glob("/data/real/*")) if f.lower().endswith(EXT)]
files += [(f, 1) for f in sorted(glob.glob("/data/ai/*")) if f.lower().endswith(EXT)]
if not files:
    sys.exit("No images found in ~/testset/real and ~/testset/ai")

probs, ok = {}, []
for m in (sys.argv[1:] or ["umm-maybe/AI-image-detector"]):
    try:
        clf = pipeline("image-classification", model=m)
    except Exception as e:
        print(f"\nSKIPPED {m}: {type(e).__name__}")
        continue
    for path, y in files:
        img = Image.open(path).convert("RGB")
        sc = {r["label"].lower(): r["score"] for r in clf(img, top_k=None)}
        probs[(m, path)] = sum(s for l, s in sc.items() if set(re.split(r"[^a-z0-9]+", l)) & AI_WORDS)
    print("labels for", m, "->", list(sc))
    ok.append(m)

def report(name, get):
    wrong = [(os.path.basename(p), y, get(p)) for p, y in files if (get(p) >= 0.5) != bool(y)]
    print(f"\n{name}: {len(files)-len(wrong)}/{len(files)} correct")
    for n, y, p in wrong:
        print(f"   wrong: {n} is {'AI' if y else 'real'}, model says AI {p*100:.0f}%")

for m in ok:
    report(m, lambda p, m=m: probs[(m, p)])
if len(ok) > 1:
    report("AVERAGE of all", lambda p: sum(probs[(m, p)] for m in ok) / len(ok))

print("\nAI probability per image:")
print("file | truth | " + " | ".join(m.split("/")[-1][:20] for m in ok))
for p, y in files:
    print(f"{os.path.basename(p)[:30]} | {'AI' if y else 'real'} | " + " | ".join(f"{probs[(m,p)]*100:.0f}%" for m in ok))
