<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Protocol\GdLevelDownloadEncoder;
use PDO;
use RuntimeException;

final readonly class LevelTransferService
{
    private const MAX_LEVEL_DATA = 32 * 1024 * 1024;
    private const MAX_LEVEL_INFO = 1024 * 1024;

    public function __construct(
        private PDO $pdo,
        private AccountAuthenticator $auth,
        private LevelTransferRepository $repository,
        private LevelDownloadTracker $downloadTracker,
        private GdLevelDownloadEncoder $downloadEncoder
    ) {}

    public function upload(
        int $accountId,
        string $gjp,
        array $data
    ): int {
        $this->auth->authenticate($accountId, $gjp);

        $levelId = $this->intField(
            $data,
            'levelID',
            0,
            0,
            PHP_INT_MAX
        );

        /*
         * Критично: нельзя обновлять чужой уровень просто передав
         * его levelID.
         */
        if ($levelId > 0) {
            $existing = $this->repository->findLevel($levelId);

            if (
                !$existing ||
                (int)($existing['account_id'] ?? 0) !== $accountId
            ) {
                throw new RuntimeException('Level ownership mismatch.');
            }
        }

        $name = trim(
            $this->stringField(
                $data,
                'levelName',
                'Unnamed',
                64
            )
        );

        if ($name === '') {
            $name = 'Unnamed';
        }

        if (
            preg_match('/[\x00-\x1F\x7F]/', $name) === 1
        ) {
            throw new RuntimeException('Invalid level name.');
        }

        $levelString = $this->stringField(
            $data,
            'levelString',
            '',
            self::MAX_LEVEL_DATA
        );

        if ($levelString === '') {
            throw new RuntimeException('Empty level data.');
        }

        $gameVersion = $this->intField(
            $data,
            'gameVersion',
            22,
            1,
            1000
        );

        $levelData = [
            'account_id' => $accountId,

            'name' => $name,

            'description' => $this->normalizeDescription(
                $data['levelDesc'] ?? '',
                $gameVersion
            ),

            'level_version' => $this->intField(
                $data,
                'levelVersion',
                1,
                1,
                1000000
            ),

            'game_version' => $gameVersion,

            'binary_version' => $this->intField(
                $data,
                'binaryVersion',
                0,
                0,
                1000000
            ),

            'length' => $this->intField(
                $data,
                'levelLength',
                0,
                0,
                10
            ),

            'audio_track' => $this->intField(
                $data,
                'audioTrack',
                0,
                0,
                1000000
            ),

            'song_id' => $this->intField(
                $data,
                'songID',
                0,
                0,
                PHP_INT_MAX
            ),

            'auto_level' => $this->boolInt($data, 'auto'),

            'copy_password' => $this->stringField(
                $data,
                'password',
                $gameVersion > 17 ? '0' : '1',
                64
            ),

            'original_level_id' => $this->intField(
                $data,
                'original',
                0,
                0,
                PHP_INT_MAX
            ),

            'two_player' => $this->boolInt(
                $data,
                'twoPlayer'
            ),

            'object_count' => $this->intField(
                $data,
                'objects',
                0,
                0,
                5000000
            ),

            'coins' => $this->intField(
                $data,
                'coins',
                0,
                0,
                1000
            ),

            'requested_stars' => $this->intField(
                $data,
                'requestedStars',
                0,
                0,
                100
            ),

            'is_unlisted' => $this->unlistedLevelState($data),

            'wt' => $this->intField(
                $data,
                'wt',
                0,
                0,
                PHP_INT_MAX
            ),

            'wt2' => $this->intField(
                $data,
                'wt2',
                0,
                0,
                PHP_INT_MAX
            ),

            'extra_string' => $this->stringField(
                $data,
                'extraString',
                '29_29_29_40_29_29_29_29_29_29_29_29_29_29_29_29',
                65536
            ),

            'level_data' => $levelString,

            'level_info' => $this->stringField(
                $data,
                'levelInfo',
                '',
                self::MAX_LEVEL_INFO
            ),

            'ldm' => $this->boolInt($data, 'ldm'),

            'settings_string' => $this->stringField(
                $data,
                'settingsString',
                '',
                65536
            ),

            'song_ids' => $this->numberListField(
                $data,
                'songIDs'
            ),

            'sfx_ids' => $this->numberListField(
                $data,
                'sfxIDs'
            ),

            'ts' => $this->intField(
                $data,
                'ts',
                0,
                0,
                PHP_INT_MAX
            )
        ];

        if ($levelId > 0) {
            $levelData['level_id'] = $levelId;
        }

        return $this->repository->saveLevel($levelData);
    }

    public function download(
        int $levelId,
        int $gameVersion,
        bool $extras,
        bool $incrementDownloads = false,
        int $accountId = 0,
        string $credential = '',
        string $clientIp = ''
    ): string {
        $timelyId=0;

        if($levelId<0){

            $timely=$this->resolveTimelyLevel(
                $levelId
            );

            if($timely===null){
                return '-1';
            }

            $levelId=(int)$timely['level_id'];
            $timelyId=(int)$timely['timely_id'];
        }

        if($levelId<=0){
            return '-1';
        }

        $level=$this->repository->findLevel(
            $levelId
        );

        if(!$level){
            return '-1';
        }

        if ((int)($level['is_unlisted'] ?? 0) === 2) {
            if ($accountId <= 0 || $credential === '') {
                return '-1';
            }

            try {
                $this->auth->authenticate(
                    $accountId,
                    $credential
                );
            } catch (\Throwable) {
                return '-1';
            }

            $ownerId = (int)($level['account_id'] ?? 0);

            if (
                $ownerId !== $accountId &&
                !$this->isFriend(
                    $accountId,
                    $ownerId
                )
            ) {
                return '-1';
            }
        }

        if ($incrementDownloads) {
            $this->downloadTracker->record(
                $levelId,
                $clientIp
            );
        }

        $response=$this->downloadEncoder->encode(
            $level,
            $gameVersion,
            $extras
        );

        if(
            $timelyId>0 &&
            $response!=='-1'
        ){
            $response=$this->decorateTimelyResponse(
                $response,
                $timelyId,
                $gameVersion,
                $level
            );
        }

        return $response;
    }


    private function resolveTimelyLevel(
        int $negativeId
    ): ?array {

        $kind=match($negativeId){
            -1 => 'daily',
            -2 => 'weekly',
            -3 => 'event',
            default => null,
        };

        if($kind===null){
            return null;
        }

        $q=$this->pdo->prepare("
            SELECT
                id,
                level_id

            FROM mucho_daily_rotation

            WHERE kind=:kind
              AND enabled=1
              AND starts_at<=UTC_TIMESTAMP()
              AND ends_at>UTC_TIMESTAMP()

            ORDER BY
                starts_at DESC,
                id DESC

            LIMIT 1
        ");

        $q->execute([
            'kind'=>$kind
        ]);

        $row=$q->fetch(PDO::FETCH_ASSOC);

        if(!$row){
            return null;
        }

        $timelyId=(int)$row['id'];

        if($negativeId===-2){
            $timelyId+=100001;
        }elseif($negativeId===-3){
            $timelyId+=200001;
        }

        return [
            'level_id'=>(int)$row['level_id'],
            'timely_id'=>$timelyId,
        ];
    }


    private function decorateTimelyResponse(
        string $response,
        int $timelyId,
        int $gameVersion,
        array $level
    ): string {

        $parts=explode('#',$response);

        if(count($parts)<3){
            return $response;
        }

        $main=$parts[0];

        if(
            strpos(
                $main,
                ':41:'
            )===false
        ){
            $main.=':41:'.$timelyId;
        }

        $parts[0]=$main;

        /*
         * Rebuild download hash #2 because
         * timely ID is one of its inputs.
         */

        $userId=$this->protocolInt(
            $main,
            6,
            (int)(
                $level['user_id']
                ?? $level['account_id']
                ?? 0
            )
        );

        $stars=$this->protocolInt(
            $main,
            18,
            (int)($level['stars'] ?? 0)
        );

        $demon=$this->protocolInt(
            $main,
            17,
            (int)($level['demon'] ?? 0)
        );

        $levelId=$this->protocolInt(
            $main,
            1,
            (int)($level['level_id'] ?? 0)
        );

        $verifiedCoins=$this->protocolInt(
            $main,
            38,
            (int)($level['coins_verified'] ?? 0)
        );

        $featured=$this->protocolInt(
            $main,
            19,
            (int)($level['featured'] ?? 0)
        );

        $encodedPassword=$this->protocolString(
            $main,
            27,
            '0'
        );

        $password=$this->decodePassword(
            $encodedPassword,
            $gameVersion
        );

        $hashInput=
            $userId.
            $stars.
            $demon.
            $levelId.
            $verifiedCoins.
            $featured.
            $password.
            $timelyId;

        $parts[2]=sha1(
            $hashInput.
            'xI25fpAapCQg'
        );


        /*
         * Daily downloads traditionally append
         * creator information after hash #2.
         */
        if(count($parts)===3){

            $creator=$this->creatorString(
                (int)($level['account_id'] ?? 0)
            );

            if($creator!==''){
                $parts[]=$creator;
            }
        }

        return implode('#',$parts);
    }


    private function protocolInt(
        string $data,
        int $key,
        int $fallback
    ): int {

        $value=$this->protocolString(
            $data,
            $key,
            ''
        );

        if(
            $value==='' ||
            preg_match('/^-?\d+$/',$value)!==1
        ){
            return $fallback;
        }

        return (int)$value;
    }


    private function protocolString(
        string $data,
        int $key,
        string $fallback
    ): string {

        if(
            preg_match(
                '/(?:^|:)'.preg_quote(
                    (string)$key,
                    '/'
                ).':([^:#]*)/',
                $data,
                $m
            )===1
        ){
            return (string)$m[1];
        }

        return $fallback;
    }


    private function decodePassword(
        string $value,
        int $gameVersion
    ): string {

        if(
            $value==='0' ||
            $value==='1' ||
            $gameVersion<=19
        ){
            return $value;
        }

        $encoded=strtr(
            $value,
            '-_',
            '+/'
        );

        $mod=strlen($encoded)%4;

        if($mod!==0){
            $encoded.=
                str_repeat('=',4-$mod);
        }

        $raw=base64_decode(
            $encoded,
            true
        );

        if($raw===false){
            return '0';
        }

        $key='26364';
        $out='';
        $keyLength=strlen($key);

        for(
            $i=0,
            $len=strlen($raw);
            $i<$len;
            $i++
        ){
            $out.=chr(
                ord($raw[$i]) ^
                ord($key[$i%$keyLength])
            );
        }

        return $out;
    }


    private function creatorString(
        int $accountId
    ): string {

        if($accountId<=0){
            return '';
        }

        $q=$this->pdo->prepare("
            SELECT
                a.account_id,
                a.username,
                COALESCE(
                    p.user_id,
                    a.account_id
                ) AS user_id

            FROM accounts a

            LEFT JOIN profiles p
              ON p.account_id=a.account_id

            WHERE a.account_id=:id

            LIMIT 1
        ");

        $q->execute([
            'id'=>$accountId
        ]);

        $row=$q->fetch(PDO::FETCH_ASSOC);

        if(!$row){
            return '';
        }

        return
            (int)$row['user_id'].
            ':'.
            \MuchoCore\Protocol\ProtocolText::username(
                $row['username'] ?? 'Player'
            ).
            ':'.
            (int)$row['account_id'];
    }


    private function isFriend(
        int $accountId,
        int $targetAccountId
    ): bool {
        if ($accountId <= 0 || $targetAccountId <= 0) {
            return false;
        }

        $q = $this->pdo->prepare(
            'SELECT 1
             FROM friends
             WHERE (account_id=:a1 AND friend_account_id=:b1)
                OR (account_id=:b2 AND friend_account_id=:a2)
             LIMIT 1'
        );

        $q->execute([
            'a1' => $accountId,
            'b1' => $targetAccountId,
            'b2' => $targetAccountId,
            'a2' => $accountId,
        ]);

        return (bool)$q->fetchColumn();
    }

    public function updateDescription(
        int $levelId,
        int $accountId,
        string $gjp,
        string $description
    ): bool {
        $this->auth->authenticate(
            $accountId,
            $gjp
        );

        if ($levelId <= 0 || $accountId <= 0) {
            return false;
        }

        if (strlen($description) > 8192) {
            throw new RuntimeException(
                'Level description too large.'
            );
        }

        $description = $this->normalizeDescription(
            $description,
            20
        );

        $existing = $this->repository->findLevel(
            $levelId
        );

        if (
            !$existing ||
            (int)($existing['account_id'] ?? 0) !== $accountId
        ) {
            return false;
        }

        return $this->repository->updateDescription(
            $levelId,
            $accountId,
            $description
        );
    }

    public function delete(
        int $levelId,
        int $accountId,
        string $gjp
    ): bool {
        $this->auth->authenticate(
            $accountId,
            $gjp
        );

        $existing = $this->repository->findLevel($levelId);

        if (
            !$existing ||
            (int)($existing['account_id'] ?? 0) !== $accountId
        ) {
            return false;
        }

        return $this->repository->deleteLevel(
            $levelId,
            $accountId
        );
    }

    private function stringField(
        array $data,
        string $key,
        string $default,
        int $maxLength
    ): string {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        if (!is_scalar($data[$key])) {
            throw new RuntimeException(
                'Invalid field: ' . $key
            );
        }

        $value = (string)$data[$key];

        if (strlen($value) > $maxLength) {
            throw new RuntimeException(
                'Field too large: ' . $key
            );
        }

        return $value;
    }

    private function intField(
        array $data,
        string $key,
        int $default,
        int $min,
        int $max
    ): int {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];

        if (
            !(is_int($value) || is_string($value)) ||
            preg_match('/^-?\d+$/', (string)$value) !== 1
        ) {
            throw new RuntimeException(
                'Invalid integer: ' . $key
            );
        }

        $number = (int)$value;

        if ($number < $min || $number > $max) {
            throw new RuntimeException(
                'Integer out of range: ' . $key
            );
        }

        return $number;
    }

    private function unlistedLevelState(
        array $data
    ): int {
        $value = $data['unlisted2']
            ?? $data['unlisted1']
            ?? $data['unlisted']
            ?? 0;

        if (
            is_string($value) &&
            preg_match('/^-?\d+$/', $value) === 1
        ) {
            $value = (int)$value;
        }

        if (!is_int($value)) {
            throw new RuntimeException(
                'Invalid unlisted state.'
            );
        }

        return max(0, min(2, $value));
    }

    private function numberListField(
        array $data,
        string $key
    ): string {
        if (!array_key_exists($key, $data)) {
            return '';
        }

        $value = $data[$key];

        if (!is_scalar($value)) {
            throw new RuntimeException(
                'Invalid list field: ' . $key
            );
        }

        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d+(?:,\d+)*$/', $value) !== 1) {
            throw new RuntimeException(
                'Invalid numeric list: ' . $key
            );
        }

        return $value;
    }

    private function normalizeDescription(
        mixed $value,
        int $gameVersion
    ): string {
        if (!is_scalar($value)) {
            throw new RuntimeException(
                'Invalid level description.'
            );
        }

        $input = (string)$value;

        if (strlen($input) > 8192) {
            throw new RuntimeException(
                'Level description too large.'
            );
        }

        $raw = $input;

        if ($gameVersion >= 20) {
            $decoded = base64_decode(
                strtr($input, '-_', '+/'),
                true
            );

            if ($decoded !== false) {
                $raw = $decoded;
            }
        }

        $opening = substr_count($raw, '<c');
        $closing = substr_count($raw, '</c>');

        if ($opening > $closing) {
            $raw .= str_repeat(
                '</c>',
                $opening - $closing
            );
        }

        return strtr(
            base64_encode($raw),
            '+/',
            '-_'
        );
    }

    private function boolInt(
        array $data,
        string $key
    ): int {
        if (!array_key_exists($key, $data)) {
            return 0;
        }

        return ((string)$data[$key] === '1') ? 1 : 0;
    }
}
