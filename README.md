# AI Image Detector

A Docker container (Ubuntu 22.04) running the LAMP stack (Linux, Apache, MySQL, PHP). Users log in, upload an image, and the app reports whether it looks AI-generated or real, with a confidence score.

## Features
- Account registration and login (hashed passwords, sessions)
- Image upload form (JPG, PNG, WEBP, max 3 MB) with preview
- AI-vs-real verdict with a confidence scale
- History table of each user's recent checks
- Rate limit: 5 analyses per user per minute

## How it works
Browser -> Apache -> PHP (`app/index.php`) -> Python (`detect.py`, model `umm-maybe/AI-image-detector`) -> verdict saved in MySQL -> result page.

## Build and run
    docker build -t ai-detector .
    docker run -d -p 8080:80 -v dbdata:/var/lib/mysql -v uploads:/var/www/html/uploads --name ai-detector ai-detector

Open http://localhost:8080, create an account, and upload an image. The first analysis can take up to 30 seconds while the model loads.

## Files
- `Dockerfile`: builds the image (Apache, MySQL, PHP, Python, PyTorch CPU, model)
- `start.sh`: starts MySQL and Apache when the container runs
- `init.sql`: creates the `users` and `results` tables
- `detect.py`: runs the model and prints JSON
- `app/`: `index.php`, `login.php`, `logout.php`, `style.css`

## Security measures
- Passwords hashed with `password_hash`; session ID regenerated on login
- Upload allow-list checked by real MIME type, random server-side filenames, 3 MB limit
- `escapeshellarg` against command injection, prepared statements against SQL injection, `htmlspecialchars` against XSS
- Rate limit per user

## Test results
Tested 19 original image files: 10 real phone photos and 9 AI-generated images (6 from Gemini, 3 from other sources). A model's answer counts as "AI" when its AI probability is 50% or more.

| Model | Real correct | AI correct | Overall |
|---|---|---|---|
| umm-maybe/AI-image-detector (used in the app) | 9/10 | 3/9 | 12/19 (63%) |
| capcheck/ai-human-generated-image-detection | 10/10 | 0/9 | 10/19 (53%) |
| Organika/sdxl-detector | 4/10 | 2/9 | 6/19 (32%) |
| Average of the three | | | 9/19 (47%) |

AI probability per image:

| Image | Truth | umm-maybe | capcheck | sdxl-detector |
|---|---|---|---|---|
| BV3A9756.JPEG | real | 25% | 2% | 96% |
| HDUQ4849.JPG | real | 22% | 1% | 0% |
| HEOJ9834.JPG | real | 9% | 2% | 100% |
| IMG_0367.JPG | real | 2% | 1% | 0% |
| IMG_2711.JPG | real | 66% | 1% | 100% |
| IMG_E0370.JPG | real | 1% | 1% | 100% |
| IMG_E0813.JPG | real | 31% | 1% | 99% |
| JLVY7855.JPG | real | 3% | 1% | 100% |
| NNFV3242.JPG | real | 32% | 1% | 0% |
| WFGB1239.JPG | real | 16% | 1% | 0% |
| Doctor and Patient in a Bright Hospital Room.png | AI | 50% | 1% | 0% |
| Gemini image 1 (9fana) | AI | 40% | 1% | 95% |
| Gemini image 2 (gbbcy) | AI | 24% | 1% | 0% |
| Gemini image 3 (lmq0v) | AI | 23% | 1% | 0% |
| Gemini image 4 (pr4q9) | AI | 15% | 1% | 0% |
| Gemini image 5 (s7bq5) | AI | 31% | 1% | 0% |
| Gemini image 6 (xonkz) | AI | 88% | 1% | 6% |
| Joyful Acoustic Stage Performance.png | AI | 60% | 4% | 0% |
| images.jpeg | AI | 43% | 2% | 98% |

Findings:
- The model already in the app performed best, so it was kept.
- capcheck labelled almost everything as real; sdxl-detector gave extreme 0% or 100% scores and flagged 6 of 10 real photos as AI.
- Averaging the models was worse than using the best one alone.
- Most modern AI images (5 of 6 Gemini images) were missed by the best model. The sample is small (19 images), so results are indicative only.

## Limitations
- The classifier is probabilistic and can be wrong, especially on newer generators; results near 50% are uncertain.

## Run from Docker Hub
    docker run -d -p 8080:80 -v dbdata:/var/lib/mysql -v uploads:/var/www/html/uploads --name ai-detector hendriquelamar/ai-detector:1.0

Then open http://localhost:8080
