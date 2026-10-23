# PHP7 original-domain build

The checked-in `compat/php7/core` files are generated from original sources,
including their tracked migrations and twelve isolated integration fixtures.
They run without a parser, Composer or modern `src/` includes on the host.

Build prerequisites: PHP 8.2+ and the official `nikic/PHP-Parser` tag `v5.6.2`
(commit `3a454ca033b9e06b63282ce19562e892747449bb`). The parser is build-only.

```sh
MUCHO_PHP_PARSER_ROOT=/absolute/path/to/PHP-Parser \
  php tools/release/compile-php7-domain.php
php tests/compat/php7-compiled-domain-pure.php
```

The lowering operates on the AST, preserving strings, SQL and protocol bytes.
It expands promoted properties, arrow closures, strict match expressions,
null-safe calls, throw expressions, first-class method callables, destructuring,
multi-catch and object defaults. Named calls are accepted only when their order
matches a declared positional signature; unsupported cases fail the build.
Readonly/type declarations unavailable on PHP7 cannot be enforced by that
language version. Runtime JSON and argument-array subprocess behavior use
bounded compatibility helpers. Generated code retains a source hash manifest.

Passing syntax/loading checks does not certify deployment or complete parity.
PHP extension API differences, schema/data upgrades, native configuration,
administrator adapters and real-client acceptance require separate tests.
In particular, PHP7.0's OpenSSL API cannot directly use PHP7.1's AEAD arguments;
the existing encrypted cloud-save service needs a tested compatibility path.

The native PHP7 integration workflow executes compiled original fixtures on
PHP7.0 and PHP8.3 with disposable MariaDB databases. Its tests include actual
clan membership/quests, appearance, private threads, following, rewards,
moderation, cases/sanctions and editor music contracts. These fixtures cannot
run against a production database.
