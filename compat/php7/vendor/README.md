# PHP7 AEAD dependencies

This directory includes the AES dependency subset of phpseclib 3.0.57 and
ParagonIE constant-time-encoding 1.1.0 under their included MIT licenses.
Exact upstream commits and local file hashes are in `sources.json`.
The libraries are loaded by the native domain autoloader, without Composer.

There is one local patch in phpseclib's `Crypt/Common/SymmetricKey.php`:
the fallback GCM bad-tag branch compares tags with `hash_equals` and throws
directly. The upstream branch recursively decrypts an unchanged GCM clone,
which exhausts memory on authentication failure. No cipher mathematics or
record formats are changed. Known-answer, native OpenSSL cross-check and
tampering tests run on both PHP7.0 and PHP8.3.
