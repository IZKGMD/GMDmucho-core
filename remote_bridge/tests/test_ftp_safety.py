import pytest

from remote_bridge.ftp_client import FTPBridgeError, blocked_remote_secret, safe_remote_path


@pytest.mark.parametrize("value", ["../etc/passwd", "/etc/passwd", "mc/../config.php",
                                    "mc//index.php", r"mc\index.php", "mc/./index.php"])
def test_remote_path_traversal_is_blocked(value):
    with pytest.raises(FTPBridgeError):
        safe_remote_path(value)


def test_remote_root_is_represented_by_empty_relative_path():
    assert safe_remote_path("") == ""
    assert safe_remote_path("mc/index.php") == "mc/index.php"


@pytest.mark.parametrize("value", [
    ".env", "mc/.env", "mc/.secrets/admin_password",
    "mc/config.php", "mc/php7-apk-signer.json", "mc/id_rsa",
    "mc/storage/signing.key",
])
def test_credential_and_signing_key_paths_are_blocked(value):
    assert blocked_remote_secret(value)


def test_normal_php_source_is_not_marked_as_secret():
    assert not blocked_remote_secret("mc/api/levels/getGJLevels.php")
