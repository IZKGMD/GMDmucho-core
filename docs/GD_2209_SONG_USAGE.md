# GD 2.209 — getGJSongUsage

Implements the documented 2.209 wire contract from
[GD Docs](https://boomlings.dev/endpoints/songs/getGJSongUsage).

Input: \`songID\` (positive Newgrounds song ID). The common GD request
fields remain optional for this read-only endpoint.

Wire shape:

\`\`\`text
totalLevels,ratedLevels,ratedDownloads#tier:difficulty:count|...
\`\`\`

- Only listed, nondeleted MuchoCore levels are counted.
- \`ratedLevels\` includes only records where \`stars > 0\`.
- \`ratedDownloads\` is the sum of downloads on rated levels only.
- Groupings are based on rated levels.
- Tier: \`0\` Rated, \`1\` Featured, \`2\` Epic,
  \`3\` Legendary, \`4\` Mythic.
- Difficulty: \`0\` Auto, \`1\` Easy, \`2\` Normal, \`3\` Hard,
  \`4\` Harder, \`5\` Insane, \`6\` Demon.
- MuchoCore's storage uses \`epic=2\` for Mythic and
  \`epic=3\` for Legendary; the response reorders those to protocol tiers.
- No usage is encoded as \`0,0,0#\`; missing/invalid \`songID\` is \`-1\`.
- No database schema changes or credentials are needed.

MariaDB integration verifies grouping, zero usage, invalid IDs, unlisted/deleted
exclusion, sorting and exact delimiters:

\`\`\`sh
php tests/application/gd2209-song-usage-integration.php
\`\`\`

This is a tested server-side response based on public protocol documentation.
Actual GD 2.209 client acceptance must still be verified before a *100%*
compatibility claim.
