<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Protocol/GdLegacyText.php';
require __DIR__ . '/../../src/Protocol/ProtocolText.php';

use MuchoCore\Protocol\GdLegacyText;
use MuchoCore\Protocol\ProtocolText;

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
        'legacy comment',
        19
    ),
    '1.9 incoming comment stays plain text'
);

assertSameValue(
    'legacy comment',
    GdLegacyText::encodeCommentForResponse(
        'legacy comment',
        19
    ),
    '1.9 comment response stays plain text'
);

assertSameValue(
    'legacy comment',
    GdLegacyText::decodeComment(
        'legacy comment',
        19
    ),
    '1.9 incoming comment stays plain text'
);

assertSameValue(
    'modern comment',
    GdLegacyText::decodeComment('modern comment', 20),
    '2.0 comment stays unchanged'
);


$rawProtocolField = "A:B|C#D~E\0F\r\nG";
if (ProtocolText::field($rawProtocolField, 4) !== 'ABCD') {
    fwrite(STDERR, "FAIL protocol field delimiter/control sanitization\n");
    exit(1);
}

$rawProtocolComment = "A:B|C#D~E\0F\r\nG";
if (ProtocolText::comment($rawProtocolComment, 5) !== 'A:BCD') {
    fwrite(STDERR, "FAIL protocol comment delimiter/control sanitization\n");
    exit(1);
}

echo "PASS protocol text sanitizer boundaries\n";

echo "MUCHOCORE_LEGACY_TEXT_OK\n";
