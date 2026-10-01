<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use RuntimeException;

final class SqlDumpTokenizer
{
    /**
     * @return iterable<int,string>
     */
    public static function statements($handle, int $maxBytes = 536870912): iterable
    {
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to read the SQL dump.');
        }

        $statement = '';
        $quote = null;
        $blockComment = false;
        $lineComment = false;
        $bytes = 0;

        while (($line = fgets($handle)) !== false) {
            $bytes += strlen($line);

            if ($bytes > $maxBytes) {
                throw new RuntimeException(
                    'The uncompressed SQL dump is larger than the 512 MB safety limit.'
                );
            }

            $length = strlen($line);

            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];
                $next = $i + 1 < $length ? $line[$i + 1] : '';

                if ($lineComment) {
                    if ($char === "\n") {
                        $lineComment = false;
                    }
                    continue;
                }

                if ($blockComment) {
                    if ($char === '*' && $next === '/') {
                        $blockComment = false;
                        $i++;
                    }
                    continue;
                }

                if ($quote !== null) {
                    $statement .= $char;

                    if ($char === '\\') {
                        if ($i + 1 < $length) {
                            $statement .= $line[$i + 1];
                            $i++;
                        }
                        continue;
                    }

                    if ($char === $quote) {
                        if ($i + 1 < $length && $line[$i + 1] === $quote) {
                            $statement .= $line[$i + 1];
                            $i++;
                        } else {
                            $quote = null;
                        }
                    }

                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $blockComment = true;
                    $i++;
                    continue;
                }

                if ($char === '-' && $next === '-') {
                    $lineComment = true;
                    $i++;
                    continue;
                }

                if ($char === '#') {
                    $lineComment = true;
                    continue;
                }

                if ($char === "'" || $char === '"' || $char === chr(96)) {
                    $quote = $char;
                    $statement .= $char;
                    continue;
                }

                if ($char === ';') {
                    $trimmed = trim($statement);

                    if ($trimmed !== '') {
                        yield $trimmed;
                    }

                    $statement = '';
                    continue;
                }

                $statement .= $char;
            }
        }

        $trimmed = trim($statement);

        if ($trimmed !== '') {
            yield $trimmed;
        }
    }
}
