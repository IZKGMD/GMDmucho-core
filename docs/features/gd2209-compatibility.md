# Geometry Dash 2.209 compatibility work

This branch starts the 2.209 port on top of the existing private-image and local
moderation branch. It does **not** certify a complete 2.209 client or launcher.

## Implemented server change

Friend-list dictionary key **64** carries the Unix timestamp at which the
friendship began. The value is documented by the implementation in
[BetterInfo's GJUserCell](https://github.com/Cvolton/betterinfo-geode/blob/5c791be35276feb231027ab6295f21db6d499f43/src/hooks/GJUserCell.cpp).

New accepted friendships save one timestamp for both sides. The migration
preserves existing dates and leaves unknown historical dates NULL. Unknown
dates and blocked-user lists omit key 64. Older clients ignore the added key.
Run the normal core database migrations before using the changed repository.

## Inspect an actual 2.209 Windows client

Run with Python 3.10+ on the PC containing the new game:

~~~powershell
python tools/client/client-probe.py --input GeometryDash.exe --output gd2209-report.json --claimed-version 2.209
~~~

The probe does not execute or modify the EXE. It extracts PHP endpoint names
from ASCII, UTF-16 and Base64 HTTP URLs, hashes and PE metadata. It does not
include arbitrary extracted strings or game bytes. The claimed version is a
user label, not automatic version detection.

Compare that small JSON report to the actual core route normalizer and route
inventory (no database or server boot required):

~~~sh
php bin/mucho-client-audit.php gd2209-report.json
~~~

On the current VPS deployment:

~~~sh
cd /opt/mucho-core
docker compose -f docker-compose.yml -f docker-compose.moderation.yml \
  exec -T app php bin/mucho-client-audit.php gd2209-report.json
~~~

Exit 0 means the discovered names map to registered core routes; exit 1 reports
unmapped names; exit 2 means invalid input. This does not prove wire formats,
authentication, actual use of each static string or the native ABI. Do not
invent handlers that return success for unknown requests. Real-client request
traces are still required, using the existing optional MUCHO_CLIENT_TRACE.

## Native client and loader boundary

As inspected on 2026-10-16, upstream Geode **5.11.2** fixes some 2.209 forward
compatibility crashes and text input, but still targets **2.2081**:

- [Geode changelog](https://github.com/geode-sdk/geode/blob/3909856/CHANGELOG.md)
- [SDK target](https://github.com/geode-sdk/geode/blob/3909856/CMakeLists.txt)
- [2.209 bindings work](https://github.com/geode-sdk/bindings/tree/0ac0fcb72a4ef9e8c18d7d55136394e47e5eca6e/bindings/2.209)

The bindings contain changed signatures and missing addresses. Changing mod.json
to accept 2.209 or reusing the old menu RVA is insufficient and can crash the
game. Existing MuchoClient / Android loader version guards and the Windows
launcher text hash remain in place. The Windows launcher does not yet patch
2.209. Its exact EXE is needed to verify addresses, code signatures and startup.
No game binary is redistributed by these tools.
