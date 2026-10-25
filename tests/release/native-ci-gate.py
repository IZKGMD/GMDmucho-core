#!/usr/bin/env python3
"""Exercise exact-commit and matrix-job release authorization with local fixtures."""
import copy
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("native_ci", ROOT / "tools/release/require-native-ci.py")
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
REPO, SHA = "IZKGMD/GMDmucho-core", "a" * 40


def successful_run():
    return dict(id=120, head_sha=SHA, head_branch="main", event="push",
                path=gate.WORKFLOW, head_repository=dict(full_name=REPO),
                status="completed", conclusion="success", run_attempt=2)


def successful_jobs():
    return dict(total_count=2, jobs=[dict(name=name, run_id=120, status="completed", conclusion="success")
                                    for name in sorted(gate.JOBS)])


class NativeGateTest(unittest.TestCase):
    def api(self, runs=None, jobs=None):
        self.calls = []

        def fetch(path):
            self.calls.append(path)
            return copy.deepcopy(jobs if "/attempts/" in path else dict(workflow_runs=runs))
        return fetch

    def test_requires_both_successful_jobs_at_latest_attempt(self):
        result = gate.check_once(REPO, SHA, self.api([successful_run()], successful_jobs()))
        self.assertEqual(result, (120, 2))
        self.assertIn("head_sha=" + SHA, self.calls[0])
        self.assertIn("/runs/120/attempts/2/jobs?", self.calls[1])

    def test_other_commit_branch_repository_event_or_workflow_cannot_authorize(self):
        for changed in (dict(head_sha="b" * 40), dict(head_branch="feature"), dict(event="pull_request"),
                        dict(path=".github/workflows/validate.yml"), dict(head_repository=dict(full_name="fork/repo"))):
            with self.subTest(changed=changed):
                run = dict(successful_run(), **changed)
                self.assertIsNone(gate.check_once(REPO, SHA, self.api([run])))

    def test_failed_or_cancelled_latest_run_cannot_reuse_old_success(self):
        for conclusion in ("failure", "cancelled", "skipped", "timed_out", "neutral"):
            run = dict(successful_run(), id=121, conclusion=conclusion)
            with self.subTest(conclusion=conclusion), self.assertRaises(RuntimeError):
                gate.check_once(REPO, SHA, self.api([successful_run(), run]))

    def test_newer_pending_run_waits_instead_of_reusing_old_success(self):
        run = dict(successful_run(), id=121, status="in_progress", conclusion=None)
        self.assertIsNone(gate.check_once(REPO, SHA, self.api([successful_run(), run])))

    def test_missing_skipped_failed_duplicated_or_wrong_run_jobs_are_rejected(self):
        for variant in ("missing", "skipped", "failure", "running", "duplicate", "wrong_run", "truncated"):
            jobs = successful_jobs()
            if variant == "missing": jobs["jobs"].pop()
            if variant in ("skipped", "failure"): jobs["jobs"][0]["conclusion"] = variant
            if variant == "running": jobs["jobs"][0]["status"] = "in_progress"
            if variant == "duplicate": jobs["jobs"].append(copy.deepcopy(jobs["jobs"][0]))
            if variant == "wrong_run": jobs["jobs"][0]["run_id"] = 119
            if variant == "truncated": jobs["total_count"] = 101
            with self.subTest(variant=variant), self.assertRaises(RuntimeError):
                gate.check_once(REPO, SHA, self.api([successful_run()], jobs))

    def test_wait_is_bounded_without_real_sleep(self):
        now = [0]
        def sleep(seconds): now[0] += seconds
        with self.assertRaisesRegex(RuntimeError, "Timed out"):
            gate.wait_for_native(REPO, SHA, timeout=3, interval=2, api=self.api([]),
                                 clock=lambda: now[0], sleep=sleep)
        self.assertEqual(now[0], 3)

    def test_invalid_inputs_do_not_call_github(self):
        for repo, sha in ((REPO, "main"), ("wrong", SHA), (REPO, "a" * 39)):
            with self.assertRaises(ValueError):
                gate.wait_for_native(repo, sha, api=lambda _: self.fail("Unexpected GitHub request"))

    def test_marker_triggers_native_integration(self):
        workflow = (ROOT / ".github/workflows/php7-native-features.yml").read_text()
        self.assertIn("'.release/v*.ready'", workflow.split("pull_request:")[0])


if __name__ == "__main__":
    unittest.main()
