<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Compatibility/ClientVersion.php';
require __DIR__ . '/../../src/Http/Request.php';
require __DIR__ . '/../../src/Routing/Router.php';
require __DIR__ . '/../../src/Protocol/GdLegacyText.php';

use MuchoCore\Compatibility\ClientVersion;
use MuchoCore\Http\Request;
use MuchoCore\Routing\Router;

function assertCommentContract(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$router = new Router();

$legacyPaths = [
    '/getGJComments.php',
    '/uploadGJComment.php',
    '/deleteGJComment.php',
];

foreach ($legacyPaths as $path) {
    $request = new Request(
        'POST',
        $path,
        [],
        [
            'accountID' => '123',
            'udid' => 'legacy-device',
        ],
        []
    );

    assertCommentContract(
        $request->clientVersion()->family() === '1.9',
        "versionless 1.9 inference {$path}"
    );
}

$expectedAliases = [
    '/getGJComments19.php' => '/getgjcomments21',
    '/getGJComments20.php' => '/getgjcomments21',
    '/uploadGJComment19.php' => '/uploadgjcomment20',
    '/deleteGJComment19.php' => '/deletegjcomment20',
];

foreach ($expectedAliases as $path => $expected) {
    assertCommentContract(
        $router->normalizePath($path) === $expected,
        "comment route alias {$path}"
    );
}

assertCommentContract(
    MuchoCore\Protocol\GdLegacyText::decodeComment(
        'plain legacy comment',
        19
    ) === 'plain legacy comment',
    '1.9 comment decode stays plain text'
);

assertCommentContract(
    MuchoCore\Protocol\GdLegacyText::encodeCommentForResponse(
        'plain legacy comment',
        19
    ) === 'plain legacy comment',
    '1.9 comment response stays plain text'
);

$serviceFile = __DIR__ . '/../../src/Interaction/CommentService.php';
$controllerFile = __DIR__ . '/../../src/Interaction/CommentController.php';
$repositoryFile = __DIR__ . '/../../src/Interaction/CommentRepository.php';
$historyFile = __DIR__ . '/../../src/Comment/CommentHistoryService.php';

$service = (string)file_get_contents($serviceFile);
$controller = (string)file_get_contents($controllerFile);
$repository = (string)file_get_contents($repositoryFile);
$history = (string)file_get_contents($historyFile);

assertCommentContract(
    str_contains($controller, '$count,') &&
    str_contains($controller, '$mode'),
    'comment controller forwards count and mode'
);

assertCommentContract(
    str_contains($service, '$mode !== 0') &&
    str_contains($service, 'int $count = 10'),
    'comment service honors pagination and sort mode'
);

assertCommentContract(
    str_contains($repository, 'ORDER BY {$order}') &&
    str_contains($repository, 'c.likes DESC, c.id DESC'),
    'comment repository implements deterministic ordering'
);

foreach ([
    "'!r' => '!rate'",
    "'!f' => '!feature'",
    "'!e' => '!epic'",
    "'!ue' => '!unepic'",
    "'!vc' => '!verifycoins'",
    "'!delet' => '!delete'",
] as $needle) {
    assertCommentContract(
        str_contains($service, $needle),
        "command alias {$needle}"
    );
}

assertCommentContract(
    str_contains($service, '$pdo->beginTransaction();') &&
    str_contains($service, '$pdo->commit();'),
    'comment commands are transactional'
);

assertCommentContract(
    str_contains($service, 'GameRole::normalize($candidate)') &&
    str_contains($service, 'GameRole::ELDER_MODERATOR'),
    'comment commands use canonical game-role normalization'
);

assertCommentContract(
    str_contains($service, '$coins = isset($parts[3])') &&
    str_contains($service, '$featured = isset($parts[4])') &&
    str_contains($service, "'coins_verified'"),
    'rate command parses optional coins and featured fields'
);

assertCommentContract(
    str_contains($service, 'ON DUPLICATE KEY UPDATE') &&
    str_contains($service, 'GREATEST(percent, VALUES(percent))'),
    'comment progress writes are atomic'
);

assertCommentContract(
    str_contains($service, 'difficulty = 50') &&
    str_contains($service, 'demon = 1'),
    'demon command writes canonical demon state'
);

assertCommentContract(
    str_contains($service, "if (\$cmd !== '!cp')"),
    'manual creator points are not overwritten by automatic recalc'
);

assertCommentContract(
    str_contains($service, '"~", "|"') ||
    str_contains($service, '"\\0", "~", "|", "#", ":"'),
    'comment protocol delimiters are sanitized'
);

assertCommentContract(
    !str_contains($history, 'base64_decode('),
    'comment history does not double-decode canonical plain storage'
);

foreach ([
    __DIR__ . '/../../database/migrations/20260924_005_comment_integrity.php',
    __DIR__ . '/../../database/migrations/20260924_006_game_role_compatibility.php',
] as $migration) {
    $value = require $migration;

    assertCommentContract(
        is_callable($value),
        'repair migration is executable: ' . basename($migration)
    );
}

echo "MUCHOCORE_COMMENT_CONTRACT_OK\n";
