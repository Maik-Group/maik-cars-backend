import hashlib
import csv
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPTS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SCRIPTS))

import ecotrade_audit_detector as detector
from ecotrade_audit_signals import apply_review_policy, collect_ocr_text, has_ecotrade_history, text_signals
from rescan_ecotrade_audit import rescan, write_results

FIXTURES = Path(__file__).resolve().parent / 'fixtures'


class WatermarkRegressionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        detector.prepare_detectors()

    def test_ac4_visual_signal_survives_ocr_subprocess_failure(self):
        path = FIXTURES / 'ecotrade_ac4.png'
        before = hashlib.sha256(path.read_bytes()).hexdigest()
        with patch('pytesseract.image_to_string', side_effect=RuntimeError("(3221225794, '')")):
            result = detector.detect({'path': str(path)}, ocr=True)
        self.assertIn(result['priority'], ['P0', 'P1', 'P2'])
        self.assertTrue(result['requires_visual_review'])
        self.assertIn('learned_lattice_residual', result['signals'])
        self.assertFalse(result['scan_complete'])
        self.assertTrue(result['ocr_errors'])
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), before)
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            write_results(output, [dict(result, members=[])], [])
            with (output / 'watermark_candidates.csv').open(encoding='utf-8-sig', newline='') as stream:
                rows = list(csv.DictReader(stream))
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]['path'], str(path))

    def test_missed_30862751_enters_review_from_ecotrade_processing_history(self):
        members = [{'custom_properties': {'source_url': 'https://www.ecotradegroup.com/uploads/product.png', 'gemini_result': 'edited'}}]
        result = detector.detect({'path': str(FIXTURES / 'ecotrade_30862751.png'), 'ecotrade_history': has_ecotrade_history(members)}, ocr=False)
        self.assertTrue(result['requires_visual_review'])
        self.assertIn(result['priority'], ['P0', 'P1', 'P2'])
        self.assertIn('ecotrade_source_history_review', result['signals'])
        self.assertNotIn('classification', result)
        self.assertEqual(result['visual_signals'], [])
        self.assertEqual(result['visual_priority'], 'NEGATIVE')
        self.assertTrue(result['source_history_only'])

    def test_source_risk_survives_repeated_policy_without_becoming_visual_evidence(self):
        prior = apply_review_policy({'signals': [], 'ocr_complete': True}, True)
        replay = apply_review_policy(prior)
        self.assertEqual(replay['priority'], 'P2')
        self.assertEqual(replay['visual_priority'], 'NEGATIVE')
        self.assertEqual(replay['visual_signals'], [])
        self.assertEqual(replay['risk_signals'], ['ecotrade_source_history_review'])
        self.assertNotIn('classification', replay)

    def test_sparse_sift_descriptors_do_not_abort_remaining_detectors(self):
        descriptors = detector.np.zeros((1, 128), dtype=detector.np.float32)
        self.assertEqual(detector.sift_ratio_matches(descriptors, descriptors), [])

    def test_original_removed_during_scan_remains_a_reportable_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'missing.png'
            result = rescan({'path': str(path), 'sha256': 'missing', 'members': [], 'ecotrade_history': False}, {})
        self.assertEqual(result['priority'], 'ERROR')
        self.assertTrue(result['requires_visual_review'])
        self.assertFalse(result['scan_complete'])

    def test_ocr_recovers_from_a_transient_subprocess_failure(self):
        image = detector.cv2.imread(str(FIXTURES / 'ecotrade_ac4.png'), detector.cv2.IMREAD_GRAYSCALE)
        with patch('pytesseract.image_to_string', side_effect=[RuntimeError('Process failed'), 'Eco Trade', '']):
            text, errors, complete = collect_ocr_text(image, detector.finder.rotate_bound)
        result = apply_review_policy({'text_signal': text, 'signals': text_signals(text), 'ocr_complete': complete})
        self.assertTrue(errors)
        self.assertTrue(result['scan_complete'])
        self.assertEqual(result['priority'], 'P0')
        self.assertTrue(result['requires_visual_review'])

    def test_failed_scans_are_retained_for_review_instead_of_becoming_negatives(self):
        for failure in [{'error': 'Unreadable image'}, {'ocr_complete': False, 'ocr_errors': ['Timed out']}]:
            with self.subTest(failure=failure):
                result = apply_review_policy(dict(failure, signals=[]))
                self.assertEqual(result['priority'], 'ERROR')
                self.assertTrue(result['requires_visual_review'])
                self.assertFalse(result['scan_complete'])

    def test_maik_cat_control_does_not_identify_ecotrade(self):
        result = detector.detect({'path': str(SCRIPTS / 'maik-cat-reference.png')}, ocr=False)
        self.assertEqual(result['priority'], 'NEGATIVE')
        self.assertFalse(result['requires_visual_review'])

    def test_candidate_csv_includes_failed_scan_with_no_visual_signal(self):
        result = apply_review_policy({'sha256': 'unreadable', 'path': 'broken.png', 'error': 'Decode failed', 'signals': [], 'members': []})
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            summary = write_results(output, [result], [])
            with (output / 'watermark_candidates.csv').open(encoding='utf-8-sig', newline='') as stream:
                rows = list(csv.DictReader(stream))
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]['priority'], 'ERROR')
        self.assertEqual(summary['incomplete_scans'], 1)
        self.assertFalse(summary['clean_catalog_claim_permitted'])


if __name__ == '__main__':
    unittest.main()
