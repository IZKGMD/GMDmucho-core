<?php
declare(strict_types=1);

require dirname(__DIR__) . "/vendor/autoload.php";
if (file_exists(dirname(__DIR__) . "/.env")) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
}
$db = new \MuchoCore\Database\Database();
$pdo = $db->connection();

if ($argc < 3) {
    echo "Использование: php rate.php <LEVEL_ID> <STARS> [FEATURE: 0=None, 1=Featured, 2=Epic, 3=Legendary, 4=Mythic] [DEMON_DIFF: 1=Easy, 2=Medium, 3=Hard, 4=Insane, 5=Extreme] [MANUAL_DIFF: 10=Auto, 20=Easy, 30=Normal, 40=Hard, 50=Harder, 60=Insane, 70=Demon]\n";
    exit(1);
}

$levelId = (int)$argv[1];
$stars = (int)$argv[2];
$featureType = (int)($argv[3] ?? ($stars > 0 ? 1 : 0));
$demonDiff = (int)($argv[4] ?? 0);
$manualDiff = (int)($argv[5] ?? 0);
$isDemon = ($stars >= 10 || $manualDiff === 70) ? 1 : 0;
$isAuto = ($stars === 1 || $manualDiff === 10) ? 1 : 0;

if ($manualDiff > 0) {
    $difficulty = match ($manualDiff) {
        10 => 1, 20 => 2, 30 => 3, 40 => 4, 50 => 5, 60 => 6, 70 => 6, default => 0
    };
} else {
    $difficulty = match (true) {
        $stars === 0 => 0, $stars === 1 => 1, $stars === 2 => 2, $stars === 3 => 3,
        $stars <= 5  => 4, $stars <= 7  => 5, $stars <= 9  => 6, default => 6
    };
}

$cpToAdd = 0;
if ($stars > 0) $cpToAdd += 1;
if ($featureType >= 1) $cpToAdd += 1;
if ($featureType >= 2) $cpToAdd += 1;

$stmt = $pdo->prepare("SELECT account_id, name FROM levels WHERE level_id = :id");
$stmt->execute([":id" => $levelId]);
$level = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$level) {
    echo "Ошибка: Уровень с ID {$levelId} не найден!\n";
    exit(1);
}

$stmt = $pdo->prepare("
    UPDATE levels SET
        stars = :stars, difficulty = :difficulty, demon = :demon,
        demon_difficulty = :demon_diff, auto_level = :auto_level,
        featured = :featured, epic = :epic, updated_at = NOW()
    WHERE level_id = :id
");

$stmt->execute([
    ":stars" => $stars, ":difficulty" => $difficulty, ":demon" => $isDemon,
    ":demon_diff" => $demonDiff, ":auto_level" => $isAuto,
    ":featured" => ($featureType > 0 ? 1 : 0), ":epic" => $featureType,
    ":id" => $levelId
]);

if ($cpToAdd > 0) {
    $pdo->prepare("UPDATE profiles SET creator_points = creator_points + :cp WHERE account_id = :acc_id")
        ->execute([":cp" => $cpToAdd, ":acc_id" => (int)$level["account_id"]]);
}

echo "Уровень \"{$level["name"]}\" (ID: {$levelId}) успешно оценен!\n";
echo "Звезды: {$stars} | Сложность: {$difficulty} | Спец-статус: {$featureType} | Начислено CP: {$cpToAdd}\n";
