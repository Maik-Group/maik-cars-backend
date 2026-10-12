"""Rescan current originals; failed and historical-risk images stay in review.

The prior manifest supplies record mappings, not current file hashes. Reuse OCR
only for identical bytes; refresh every visual detector. This is candidate output,
not a clean-catalog certificate or a watermark repair command.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import json
from collections import Counter
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

from ecotrade_audit_detector import detect, prepare_detectors
from ecotrade_audit_signals import apply_review_policy, has_ecotrade_history, text_signals

EXTENSIONS = {'.png', '.jpg', '.jpeg', '.webp', '.gif', '.bmp', '.tif', '.tiff', '.avif'}


def read_json(path: Path):
    return json.loads(path.read_text(encoding='utf-8-sig'))


def current_originals(media_root: Path, prior_report: Path) -> tuple[list[dict], list[dict]]:
    mappings = {str(Path(f['path']).resolve()).lower(): f['mapping'] for f in read_json(prior_report / 'file-index.json') if f['kind'] == 'original'}
    paths = sorted(p for folder in media_root.iterdir() if folder.is_dir() and folder.name.isdigit() for p in folder.iterdir() if p.is_file() and p.suffix.lower() in EXTENSIONS)
    groups = {}
    for path in paths:
        sha = hashlib.sha256(path.read_bytes()).hexdigest()
        member = dict(mappings.get(str(path.resolve()).lower(), {'media_id': int(path.parent.name), 'serial_code': path.stem, 'mapping_status': 'NO_PRIOR_ITEM_MAPPING'}), original_path=str(path.resolve()))
        group = groups.setdefault(sha, {'sha256': sha, 'path': str(path.resolve()), 'members': []})
        group['members'].append(member)
    for group in groups.values():
        group['ecotrade_history'] = has_ecotrade_history(group['members'])
    missing = [dict(member, reason='Missing current original') for path, member in mappings.items() if not Path(path).is_file()]
    return list(groups.values()), missing


def rescan(unit: dict, previous: dict) -> dict:
    path = Path(unit['path'])
    prior = previous.get(unit['sha256'], {})
    reuse_ocr = bool(prior) and not prior.get('error') and prior.get('ocr_complete', True)
    result = detect(unit, ocr=not reuse_ocr)
    if reuse_ocr:
        result.update(text_signal=prior.get('text_signal', ''), ocr_complete=True, ocr_reused_for_identical_sha256=True)
        result['signals'].extend(text_signals(result['text_signal']))
        result = apply_review_policy(result, unit['ecotrade_history'])
    try:
        if hashlib.sha256(path.read_bytes()).hexdigest() != unit['sha256']:
            result['error'] = 'Original changed during scan'
    except OSError as error:
        result['error'] = f'Original unavailable after scan: {error}'
    result = apply_review_policy(result, unit['ecotrade_history'])
    return result


def write_results(output: Path, results: list[dict], missing: list[dict]) -> dict:
    fields = ['sha256', 'path', 'priority', 'visual_priority', 'requires_visual_review', 'scan_complete', 'signals', 'visual_signals', 'risk_signals', 'source_history_only', 'error', 'ocr_errors', 'text_signal', 'template_score', 'feature_score', 'members']
    selections = {'all_unique_originals.csv': results,
                  'watermark_candidates.csv': [r for r in results if r['requires_visual_review']],
                  'detector_errors.csv': [r for r in results if not r['scan_complete']]}
    for name, rows in selections.items():
        with (output / name).open('w', encoding='utf-8-sig', newline='') as stream:
            writer = csv.DictWriter(stream, fieldnames=fields, extrasaction='ignore')
            writer.writeheader()
            for row in rows:
                writer.writerow({key: json.dumps(value, ensure_ascii=False) if isinstance(value, (dict, list)) else value for key, value in row.items()})
    summary = {'detector_version': 'v3-explicit-risk-and-exact-template-replay',
               'unique_originals_scanned': len(results), 'original_files_found': sum(len(r['members']) for r in results),
               'prior_item_mapping_missing': sum(m.get('mapping_status') == 'NO_PRIOR_ITEM_MAPPING' for r in results for m in r['members']),
               'prior_originals_missing_now': len(missing), 'priorities': dict(Counter(r['priority'] for r in results)),
               'review_candidates': len(selections['watermark_candidates.csv']), 'incomplete_scans': len(selections['detector_errors.csv']),
               'ocr_retried_with_recovery': sum(bool(r.get('ocr_errors')) for r in results),
               'clean_catalog_claim_permitted': False,
               'limitations': ['Candidate classification requires visual confirmation.', 'Record mappings use the prior manifest; database ownership was not refreshed.', 'This corrective run scans originals; conversions are outside this run.']}
    (output / 'summary.json').write_text(json.dumps(summary, indent=2), encoding='utf-8')
    (output / 'missing_originals.json').write_text(json.dumps(missing, indent=2), encoding='utf-8')
    return summary


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--media-root', type=Path, required=True)
    parser.add_argument('--prior-report', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    destination = args.output / 'originals-scan.jsonl'
    if destination.exists():
        raise RuntimeError('Choose a fresh output directory; existing scan evidence is immutable.')
    units, missing = current_originals(args.media_root.resolve(), args.prior_report)
    previous = {row['sha256']: row for row in map(json.loads, (args.prior_report / 'originals-scan.jsonl').read_text(encoding='utf-8').splitlines())}
    (args.output / 'manifest.json').write_text(json.dumps(units, indent=2), encoding='utf-8')
    prepare_detectors()
    results = []
    with ThreadPoolExecutor(max_workers=4) as pool, destination.open('w', encoding='utf-8') as stream:
        futures = [pool.submit(rescan, unit, previous) for unit in units]
        for future in as_completed(futures):
            result = future.result()
            results.append(result)
            stream.write(json.dumps(result, ensure_ascii=False) + '\n')
            stream.flush()
            if len(results) % 100 == 0:
                print(f'{len(results)}/{len(units)} originals: {dict(Counter(r["priority"] for r in results))}', flush=True)
    print(json.dumps(write_results(args.output, results, missing), indent=2), flush=True)


if __name__ == '__main__':
    main()
