"""Fail-closed report handling, not an AWS infrastructure test."""
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('terraform_checks', Path(__file__).parents[1] / 'terraform_checks.py')
checks = importlib.util.module_from_spec(spec)
spec.loader.exec_module(checks)

class GateTests(unittest.TestCase):
    def test_no_results_refused(self):
        for report in ({}, {'Results': []}, {'Results': [{'MisconfSummary': {}}]}):
            with self.subTest(report=report), self.assertRaises(RuntimeError):
                checks.report_counts(report)

    def test_severity_and_non_failure_handling(self):
        report = {'Results': [{'MisconfSummary': {'Successes': 1, 'Failures': 2},
                  'Misconfigurations': [
                      {'Status': 'FAIL', 'Severity': 'HIGH'},
                      {'Status': 'FAIL', 'Severity': 'MEDIUM'},
                      {'Status': 'PASS', 'Severity': 'CRITICAL'}]}]}
        counts = checks.report_counts(report)
        self.assertEqual(counts['HIGH'], 1)
        self.assertEqual(counts['MEDIUM'], 1)
        self.assertEqual(counts['CRITICAL'], 0)

    def test_missing_failure_details_refused(self):
        with self.assertRaises(RuntimeError):
            checks.report_counts({'Results': [{'MisconfSummary': {'Failures': 1}}]})

    def test_unknown_severity_refused(self):
        report = {'Results': [{'MisconfSummary': {'Failures': 1},
                              'Misconfigurations': [{'Status': 'FAIL', 'Severity': 'NEW'}]}]}
        with self.assertRaises(RuntimeError):
            checks.report_counts(report)

if __name__ == '__main__':
    unittest.main()
