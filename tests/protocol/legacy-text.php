<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Protocol/GdLegacyText.php';

use MuchoCore\Protocol\GdLegacyText;

function assertSameValue(mixed $expected, mixed $actual, string $name): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            sprintf(
                "FAIL %s: expected %s, got %s\n",
                $name,
                var_export($expected, true),
                var_export($actual, true)
            )
        );
        exit(1);
    }

    echo "PASS {$name}\n";
}

$description = 'A legacy 1.9 description with + / # content';

$encoded = GdLegacyText::encodeDescriptionForStorage(
    $description,
    19
);

assertSameValue(
    base64_encode($description),
    strtr($encoded, '-_', '+/'),
    '1.9 description uses URL-safe base64'
);

assertSameValue(
    $description,
    GdLegacyText::decodeDescriptionForResponse($encoded, 19),
    '1.9 description round-trip'
);

assertSameValue(
    $encoded,
    GdLegacyText::encodeDescriptionUpdateForStorage($encoded, 19),
    '1.9 description update preserves encoded storage'
);

assertSameValue(
    $description,
    GdLegacyText::encodeDescriptionUpdateForStorage(
        $encoded,
        20
    ),
    '2.0 description update normalizes to plain storage'
);

assertSameValue(
    'Modern description',
    GdLegacyText::encodeDescriptionForStorage('Modern description', 20),
    '2.0 description remains protocol-native'
);

assertSameValue(
    'legacy comment',
    GdLegacyText::decodeComment(
        strtr(base64_encode('legacy comment'), '+/', '-_'),
        19
    ),
    '1.9 comment decoding'
);

assertSameValue(
    base64_encode('legacy comment'),
    GdLegacyText::encodeCommentForResponse(
        'legacy comment',
        19
    ),
    '1.9 comment response uses standard base64'
);

assertSameValue(
    'legacy comment',
    GdLegacyText::decodeComment(
        GdLegacyText::encodeCommentForResponse('legacy comment', 19),
        19
    ),
    '1.9 comment response round-trip'
);

assertSameValue(
    'modern comment',
    GdLegacyText::decodeComment('modern comment', 20),
    '2.0 comment stays unchanged'
);

echo "MUCHOCORE_LEGACY_TEXT_OK\n";
