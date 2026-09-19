<?php
declare(strict_types=1);

$raw = $_POST['comment'] ?? '';
$decoded = base64_decode(strtr($raw, '-_', '+/'));
$cmd = trim(strtolower($decoded));

// 1. ПЕРЕХВАТ КОМАНДЫ !rate
if (str_starts_with($cmd, '!rate')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    if (file_exists(__DIR__ . '/../../.env')) { \Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->load(); }
    
    try {
        $pdo = (new \MuchoCore\Database\Database())->connection();
        $auth = new \MuchoCore\Account\AccountAuthenticator($pdo);
        
        $accountId = (int)($_POST['accountID'] ?? 0);
        $levelId = (int)($_POST['levelID'] ?? 0);
        $cred = $_POST['gjp2'] ?? $_POST['gjp'] ?? $_POST['password'] ?? '';
        
        $acc = $auth->authenticate($accountId, $cred);
        if ((int)($acc['role_id'] ?? 0) >= 1) {
            $diff = 0; $demon = 0; $auto = 0; $demonDiff = 0;
            
            // Умножаем на 10, чтобы при делении на 10 игра получила идеальные 10, 20, 30...
            if (str_contains($cmd, 'auto')) { $diff = 500; $auto = 1; }
            elseif (str_contains($cmd, 'easy demon')) { $diff = 500; $demon = 1; $demonDiff = 3; }
            elseif (str_contains($cmd, 'medium demon')) { $diff = 500; $demon = 1; $demonDiff = 4; }
            elseif (str_contains($cmd, 'hard demon')) { $diff = 500; $demon = 1; $demonDiff = 0; }
            elseif (str_contains($cmd, 'insane demon')) { $diff = 500; $demon = 1; $demonDiff = 5; }
            elseif (str_contains($cmd, 'extreme demon')) { $diff = 500; $demon = 1; $demonDiff = 6; }
            elseif (str_contains($cmd, 'easy')) { $diff = 100; }
            elseif (str_contains($cmd, 'normal')) { $diff = 200; }
            elseif (str_contains($cmd, 'harder')) { $diff = 400; }
            elseif (str_contains($cmd, 'hard')) { $diff = 300; }
            elseif (str_contains($cmd, 'insane')) { $diff = 500; }
            elseif (str_contains($cmd, 'demon')) { $diff = 500; $demon = 1; }
            elseif (str_contains($cmd, 'na')) { $diff = 0; }
            
            $stmt = $pdo->prepare("
                UPDATE levels 
                SET difficulty = ?, demon = ?, demon_difficulty = ?, auto_level = ?, stars = 0
                WHERE level_id = ?
            ");
            $stmt->execute([$diff, $demon, $demonDiff, $auto, $levelId]);
            
            echo '1';
            exit;
        }
    } catch (\Throwable $e) {}
}

// 2. ОБЫЧНЫЙ КОММЕНТАРИЙ
$_SERVER['REQUEST_URI'] = '/uploadGJComment21';
require __DIR__ . '/../index.php';
