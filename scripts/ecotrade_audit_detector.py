"""Read-only multi-signal detection; all matches require semantic review."""
import os
import time
from pathlib import Path

import cv2
import numpy as np
import pytesseract

from ecotrade_audit_signals import collect_ocr_text, text_signals, apply_review_policy
import find_ecotrade_watermark as finder
import ecotrade_watermark as wm

ROOT = Path(__file__).resolve().parents[1]
cv2.setNumThreads(1)
os.environ['OMP_THREAD_LIMIT'] = '1'
pytesseract.pytesseract.tesseract_cmd = 'C:/Program Files/Tesseract-OCR/tesseract.exe'

def sift_ratio_matches(reference_descriptors, image_descriptors):
    pairs = cv2.BFMatcher().knnMatch(reference_descriptors, image_descriptors, k=2)
    return [pair[0] for pair in pairs if len(pair) == 2 and pair[0].distance < .72 * pair[1].distance]

def prepare_detectors():
    global BANK, DESCRIPTORS, PROFILE
    ref = cv2.imread(str(ROOT / 'scripts/ecotrade-reference.png'))
    # Complete brand word pair, distinctive logo, and trade-word residual.
    crops = [('brand', ref[340:725, 320:905]), ('logo', ref[615:830, 115:335]), ('trade', ref[340:610, 530:910])]
    BANK = []
    DESCRIPTORS = []
    sift = cv2.SIFT_create(nfeatures=900, contrastThreshold=.008)
    for label, crop in crops:
        gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
        gray = cv2.normalize(gray, None, 0, 255, cv2.NORM_MINMAX)
        kp, desc = sift.detectAndCompute(gray, None)
        DESCRIPTORS.append((label, crop.shape[:2], kp, desc))
        for width in [45, 65, 90, 125, 175, 245, 345]:
            small = cv2.resize(gray, (width, max(14, round(gray.shape[0] * width / gray.shape[1]))))
            for angle in [-12, 0, 12]:
                rotated = finder.rotate_bound(small, angle) if angle else small
                edge = finder.make_edge_image(rotated)
                BANK.append((label, edge))
    PROFILE = wm.load_eco_profile()

def detect(unit, ocr=True):
    started = time.monotonic()
    result = dict(unit, signals=[], priority='NEGATIVE', text_signal='', error='')
    try:
        rgba = wm.read_rgba(Path(unit['path']))
        if rgba is None:
            raise ValueError('OpenCV cannot decode current image')
        full = wm.flatten_white(rgba)
        scale = min(1, 640 / max(full.shape[:2]))
        bgr = cv2.resize(full, (round(full.shape[1] * scale), round(full.shape[0] * scale)))
        gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
        edge = finder.make_edge_image(gray)
        hits = []
        for template_index, (label, template) in enumerate(BANK):
            h, w = template.shape
            if h >= gray.shape[0] or w >= gray.shape[1]:
                continue
            matches = cv2.matchTemplate(edge, template, cv2.TM_CCOEFF_NORMED)
            _, score, _, loc = cv2.minMaxLoc(matches)
            hits.append((float(score), label, [loc[0], loc[1], w, h], template_index))
        hits.sort(reverse=True)
        best = hits[0] if hits else (0, '', [0, 0, 20, 20])
        result['template_score'] = round(best[0], 4)
        result['crop_box'] = [round(x / scale) for x in best[2]]
        distinct = [x for x in hits if x[0] >= .50]
        result['template_hits'] = len(distinct)
        if best[0] >= .48:
            result['signals'].append('ecotrade_template')
        if len(distinct) >= 3:
            result['signals'].append('multiple_template_scales')
        if hits:
            # Repeated peaks of the strongest brand-specific template, excluding one neighbourhood.
            chosen = BANK[best[3]][1]
            matches = cv2.matchTemplate(edge, chosen, cv2.TM_CCOEFF_NORMED)
            repeats = []
            for _ in range(5):
                _, score, _, loc = cv2.minMaxLoc(matches)
                if score < .48:
                    break
                repeats.append([round(score, 4), loc])
                x, y = loc
                matches[max(0, y - chosen.shape[0]//2):min(matches.shape[0], y + chosen.shape[0]//2+1), max(0, x - chosen.shape[1]//2):min(matches.shape[1], x + chosen.shape[1]//2+1)] = -1
            result['repetition_count'] = len(repeats)
            if len(repeats) >= 2:
                result['signals'].append('repeated_ecotrade_template')
        enhanced = cv2.createCLAHE(clipLimit=2, tileGridSize=(8, 8)).apply(gray)
        sift = cv2.SIFT_create(nfeatures=1100, contrastThreshold=.008)
        kp, desc = sift.detectAndCompute(enhanced, None)
        feature_best = (0, 0, 0, '')
        if desc is not None:
            for label, shape, refkp, refdesc in DESCRIPTORS:
                if refdesc is None:
                    continue
                good = sift_ratio_matches(refdesc, desc)
                if len(good) < 4:
                    continue
                src = np.float32([refkp[m.queryIdx].pt for m in good]).reshape(-1, 1, 2)
                dst = np.float32([kp[m.trainIdx].pt for m in good]).reshape(-1, 1, 2)
                matrix, mask = cv2.findHomography(src, dst, cv2.RANSAC, 3)
                inliers = int(mask.sum()) if mask is not None else 0
                if matrix is not None and inliers >= 4:
                    hull = cv2.convexHull(src[mask.ravel() > 0])
                    coverage = float(cv2.contourArea(hull) / (shape[0] * shape[1]))
                    score = inliers / max(1, len(good))
                    if inliers > feature_best[0]:
                        feature_best = (inliers, score, coverage, label)
        result.update(feature_inliers=feature_best[0], feature_score=round(feature_best[1], 4), feature_coverage=round(feature_best[2], 4))
        if feature_best[0] >= 5 and feature_best[1] >= .45 and feature_best[2] >= .06:
            result['signals'].append('geometric_logo_features')
        # Independent residual and alpha correlation against the learned diagonal lattice.
        coverage = cv2.resize(PROFILE, (gray.shape[1], gray.shape[0]))
        obj = cv2.resize(wm.object_mask(rgba).astype(np.uint8), (gray.shape[1], gray.shape[0])) > 0
        amp, se, count = wm.trace_amplitude(bgr, coverage, obj)
        core, rim = wm.zone_residuals(bgr, coverage, obj)
        result.update(lattice_amplitude=round(amp, 3), lattice_sigma=round(abs(amp)/max(se, .001), 3), residual_core=round(core, 3), residual_rim=round(rim, 3))
        if abs(amp) >= 7 and abs(amp)/max(se, .001) >= 12:
            result['signals'].append('learned_lattice_residual')
        alpha, valid = wm.alpha_evidence(rgba)
        cov_full = cv2.resize(PROFILE, (rgba.shape[1], rgba.shape[0]))
        alpha_score = float(np.corrcoef(alpha[valid], cov_full[valid])[0, 1]) if valid.sum() > 300 and alpha[valid].std() > 1 else 0
        result['alpha_score'] = round(alpha_score, 4) if np.isfinite(alpha_score) else 0
        if result['alpha_score'] >= .35:
            result['signals'].append('watermark_alpha_shape')
        if ocr:
            ocr_gray = cv2.resize(enhanced, None, fx=1.3, fy=1.3)
            text, ocr_errors, ocr_complete = collect_ocr_text(ocr_gray, finder.rotate_bound)
            result.update(text_signal=text[:700], ocr_errors=ocr_errors, ocr_complete=ocr_complete)
            result['signals'].extend(text_signals(text))
        result = apply_review_policy(result, unit.get('ecotrade_history', False))
    except Exception as exc:
        result.update(error=str(exc))
        result = apply_review_policy(result, unit.get('ecotrade_history', False))
    result['elapsed_seconds'] = round(time.monotonic()-started, 2)
    return result
