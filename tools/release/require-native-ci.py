#!/usr/bin/env python3
"""Require successful native matrix jobs for the exact main release commit."""
import argparse
import json
import re
import subprocess
import time

WORKFLOW = ".github/workflows/php7-native-features.yml"
JOBS = {"integration (7.0)", "integration (8.3)"}


def github_api(path):
    result = subprocess.run(["gh", "api", path], check=True, capture_output=True,
                            text=True, timeout=45)
    return json.loads(result.stdout)


def check_once(repository, sha, api=github_api):
    data = api("repos/%s/actions/workflows/php7-native-features.yml/runs"
               "?event=push&branch=main&head_sha=%s&per_page=100" % (repository, sha))
    runs = [run for run in data["workflow_runs"]
            if run.get("head_sha") == sha and run.get("event") == "push"
            and run.get("head_branch") == "main" and run.get("path") == WORKFLOW
            and (run.get("head_repository") or {}).get("full_name") == repository]
    if not runs:
        return None
    run = max(runs, key=lambda item: int(item["id"]))
    if run.get("status") != "completed":
        return None
    if run.get("conclusion") != "success":
        raise RuntimeError("Native workflow %s did not succeed: %s" %
                           (run["id"], run.get("conclusion")))
    run_id, attempt = int(run["id"]), int(run["run_attempt"])
    jobs = api("repos/%s/actions/runs/%d/attempts/%d/jobs?per_page=100" %
               (repository, run_id, attempt))
    if jobs.get("total_count", 0) > 100:
        raise RuntimeError("Native job list is incomplete")
    for name in JOBS:
        matching = [job for job in jobs["jobs"] if job.get("name") == name]
        if len(matching) != 1 or matching[0].get("run_id") != run_id:
            raise RuntimeError("Required native job is missing or ambiguous: " + name)
        if matching[0].get("status") != "completed" or matching[0].get("conclusion") != "success":
            raise RuntimeError("Required native job did not pass: " + name)
    return run_id, attempt


def wait_for_native(repository, sha, timeout=900, interval=15, api=github_api,
                    clock=time.monotonic, sleep=time.sleep):
    if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository):
        raise ValueError("Invalid repository")
    if not re.fullmatch(r"[a-f0-9]{40}", sha):
        raise ValueError("Invalid release SHA")
    if timeout <= 0 or interval <= 0:
        raise ValueError("Positive timeout and interval required")
    deadline = clock() + timeout
    while True:
        result = check_once(repository, sha, api)
        if result:
            print("NATIVE_RELEASE_CI_OK sha=%s run=%d attempt=%d" % (sha, *result))
            return result
        remaining = deadline - clock()
        if remaining <= 0:
            raise RuntimeError("Timed out waiting for native PHP7/PHP8 integration at " + sha)
        sleep(min(interval, remaining))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repository", required=True)
    parser.add_argument("--sha", required=True)
    args = parser.parse_args()
    try:
        wait_for_native(args.repository, args.sha)
    except (ValueError, KeyError, TypeError, RuntimeError, subprocess.SubprocessError) as error:
        parser.exit(1, "Native release CI verification failed: %s\n" % error)


if __name__ == "__main__":
    main()
