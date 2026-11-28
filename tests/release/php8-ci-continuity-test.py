#!/usr/bin/env python3
"""Guard the PHP 8 acceptance workflow against accidental coverage bypasses."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError("PHP8 CI continuity: " + message)


def main() -> None:
    workflow = (ROOT / ".github/workflows/php8-http-acceptance.yml").read_text(encoding="utf-8")
    validate = (ROOT / ".github/workflows/validate.yml").read_text(encoding="utf-8")
    regression = (ROOT / "tests/integration/regression.sh").read_text(encoding="utf-8")

    # No path filtering: every new PR must exercise the full PHP8 HTTP stack,
    # including pull requests that change only a protocol contract or fixture.
    require("  pull_request: {}" in workflow, "HTTP suite must run on EVERY pull request")
    require("    branches: [main]" in workflow, "main push must run HTTP acceptance")
    require('      - "tests/**"' in workflow, "test changes must trigger main acceptance")
    require('      - ".release/v*.ready"' in workflow, "release marker must trigger acceptance")

    for exact in (
        "bash tests/integration/regression.sh",
        "python3 tests/integration/php8-all-routes-negative-http.py",
        "python3 tests/integration/gd2209-exe-request-emulator.py --http",
        "python3 tests/integration/song-delivery-http.py",
        "python3 tests/integration/player-session-http.py",
    ):
        require(exact in workflow, "missing HTTP suite: " + exact)

    require(
        "python3 tests/integration/gd2209-exe-authenticated-http.py" in regression,
        "regression must execute authenticated GD 2.209 HTTP/SQL replay",
    )
    require("MUCHO_TEST_ALLOW_WRITES" in workflow and "DB_NAME: testgdps" in workflow,
            "HTTP acceptance must be guarded by disposable test database")

    for exact in (
        "python3 tests/release/php8-ci-continuity-test.py",
        "python3 tests/release/endpoint-inventory-growth-test.py",
        "php tests/protocol/endpoint-perfection.php",
    ):
        require(exact in validate, "Validate must enforce " + exact)
    print("PASS php8-ci-continuity: every PR runs authenticated, negative and EXE-shaped HTTP acceptance")


if __name__ == "__main__":
    main()
