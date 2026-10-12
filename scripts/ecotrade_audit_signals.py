"""Review policy and recoverable OCR for the read-only watermark audit.

Priorities identify review candidates, never confirmed branding. Source history
is a review safeguard because known translucent wordmarks escaped all detectors.
"""
from __future__ import annotations

import re
from threading import BoundedSemaphore
from typing import Any

import pytesseract

OCR_SLOT = BoundedSemaphore(1)


def collect_ocr_text(image: Any, rotate: Any) -> tuple[str, list[str], bool]:
    texts, errors = [], []
    completed = 0
    for angle in (0, -30):
        candidate = rotate(image, angle) if angle else image
        for attempt in range(2):
            try:
                with OCR_SLOT:
                    text = pytesseract.image_to_string(candidate, config='--psm 11', timeout=18)
                texts.append(text.strip())
                completed += 1
                break
            except (RuntimeError, OSError) as error:
                errors.append(f'orientation={angle}, attempt={attempt + 1}: {error}')
    return ' | '.join(texts), errors, completed == 2


def text_signals(text: str) -> list[str]:
    compact = re.sub('[^a-z]', '', text.lower())
    if 'ecotrade' in compact or 'cotrade' in compact or re.search(r'eco\W+trade', text, re.I):
        return ['ecotrade_ocr']
    if re.search(r'\b(?:trade|ecotra|ecotr)\b', text, re.I):
        return ['partial_brand_ocr']
    return []


def has_ecotrade_history(members: list[dict[str, Any]]) -> bool:
    for member in members:
        properties = member.get('custom_properties') or {}
        values = [properties.get(key, '') for key in ('source', 'source_url', 'import_method', 'gemini_prompt_version')]
        if any('ecotrade' in str(value).lower() for value in values):
            return True
    return False


def review_priority(result: dict[str, Any]) -> str:
    signals = set(result.get('signals', [])) - {'ecotrade_source_history_review'}
    template = result.get('template_score', 0)
    if 'ecotrade_ocr' in signals or template >= .73 or ('geometric_logo_features' in signals and template >= .58):
        return 'P0'
    if template >= .58 or signals & {'geometric_logo_features', 'watermark_alpha_shape'} or ('learned_lattice_residual' in signals and template >= .48):
        return 'P1'
    if signals:
        return 'P2'
    if result.get('error') or result.get('ocr_complete') is False:
        return 'ERROR'
    return 'NEGATIVE'


def apply_review_policy(result: dict[str, Any], historical_risk: bool = False) -> dict[str, Any]:
    history_signal = 'ecotrade_source_history_review'
    historical_risk = historical_risk or history_signal in result.get('signals', [])
    visual_signals = [signal for signal in result.get('signals', []) if signal != history_signal]
    updated = dict(result, signals=visual_signals.copy(), visual_signals=visual_signals,
                   risk_signals=[history_signal] if historical_risk else [])
    updated['visual_priority'] = review_priority(updated)
    updated['priority'] = updated['visual_priority']
    updated['source_history_only'] = historical_risk and not visual_signals
    if historical_risk and updated['visual_priority'] == 'NEGATIVE':
        updated['signals'].append('ecotrade_source_history_review')
        updated['priority'] = 'P2'
    updated['requires_visual_review'] = updated['priority'] in {'P0', 'P1', 'P2', 'ERROR'}
    updated['scan_complete'] = not updated.get('error') and updated.get('ocr_complete', True)
    return updated
