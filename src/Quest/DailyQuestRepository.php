<?php

declare(strict_types=1);

namespace MuchoCore\Quest;

use Closure;
use PDO;
use RuntimeException;

/** Writes use one transaction, with clan (if any), account, profile, wallet, quest locks. */
final readonly class DailyQuestRepository
{
    public function __construct(private PDO $pdo, private ?Closure $clock = null) {}

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Daily quest writes require a transaction.');
        }
    }

    private function calendar(): array
    {
        // TIMESTAMPDIFF avoids interpreting a UTC string in a non-UTC SQL session.
        $epoch = $this->clock !== null ? ($this->clock)() : (int)$this->pdo->query(
            "SELECT TIMESTAMPDIFF(SECOND,'1970-01-01 00:00:00',UTC_TIMESTAMP())"
        )->fetchColumn();
        return DailyQuestPolicy::calendar((int)$epoch);
    }

    /** Establish today's baseline before an accepted profile update is written. */
    public function prepare(int $accountId): array
    {
        $this->requireTransaction();
        $account = $this->pdo->prepare('SELECT account_id FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0 FOR UPDATE');
        $account->execute([$accountId]);
        if ($account->fetchColumn() === false) {
            throw new RuntimeException('Unauthorized.');
        }
        $profile = $this->pdo->prepare('SELECT stars,moons,demons FROM profiles WHERE account_id=? FOR UPDATE');
        $profile->execute([$accountId]);
        $scores = $profile->fetch(PDO::FETCH_ASSOC) ?: [];
        $insert = $this->pdo->prepare('INSERT IGNORE INTO mucho_quest_accounts(account_id,stars_high,moons_high,demons_high) VALUES (?,?,?,?)');
        $insert->execute([$accountId,max(0,(int)($scores['stars'] ?? 0)),max(0,(int)($scores['moons'] ?? 0)),max(0,(int)($scores['demons'] ?? 0))]);
        $wallet = $this->wallet($accountId);
        $high = [];
        foreach (['stars','moons','demons'] as $metric) {
            $high[$metric] = max((int)$wallet[$metric . '_high'], (int)($scores[$metric] ?? 0), 0);
        }
        $save = $this->pdo->prepare('UPDATE mucho_quest_accounts SET stars_high=?,moons_high=?,demons_high=? WHERE account_id=?');
        $save->execute([$high['stars'],$high['moons'],$high['demons'],$accountId]);
        $calendar = $this->calendar();
        $quest = $this->pdo->prepare('INSERT INTO mucho_daily_quests(account_id,quest_day,metric,target,reward_xp,last_score) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE last_score=GREATEST(last_score,VALUES(last_score))');
        foreach (DailyQuestPolicy::definitions($calendar['day']) as $definition) {
            $metric = $definition['id'];
            $quest->execute([$accountId,$calendar['day'],$metric,$definition['target'],$definition['reward_xp'],$high[$metric]]);
        }
        return $calendar;
    }

    private function wallet(int $accountId): array
    {
        $query = $this->pdo->prepare('SELECT * FROM mucho_quest_accounts WHERE account_id=? FOR UPDATE');
        $query->execute([$accountId]);
        $wallet = $query->fetch(PDO::FETCH_ASSOC);
        if (!$wallet) {
            throw new RuntimeException('Quest account was not prepared.');
        }
        return $wallet;
    }

    /** Called only by the authenticated, normalized profile-score pipeline. */
    public function recordAcceptedScore(int $accountId, array $scores, array $calendar): void
    {
        $this->requireTransaction();
        $wallet = $this->wallet($accountId);
        foreach (['stars','moons','demons'] as $metric) {
            $value = max((int)$wallet[$metric . '_high'], (int)($scores[$metric] ?? 0), 0);
            $update = $this->pdo->prepare('UPDATE mucho_daily_quests SET progress=LEAST(target,progress+GREATEST(0,?-last_score)),last_score=GREATEST(last_score,?) WHERE account_id=? AND quest_day=? AND metric=? AND claimed_at IS NULL');
            $update->execute([$value,$value,$accountId,$calendar['day'],$metric]);
            // Column names come from the fixed allowlist above, never from HTTP.
            $save = $this->pdo->prepare('UPDATE mucho_quest_accounts SET ' . $metric . '_high=? WHERE account_id=?');
            $save->execute([$value,$accountId]);
        }
    }

    public function snapshot(int $accountId, array $calendar): array
    {
        $this->requireTransaction();
        $wallet = $this->wallet($accountId);
        $query = $this->pdo->prepare("SELECT metric AS id,target,reward_xp,progress,claimed_at FROM mucho_daily_quests WHERE account_id=? AND quest_day=? ORDER BY FIELD(metric,'stars','moons','demons') FOR UPDATE");
        $query->execute([$accountId,$calendar['day']]);
        $quests = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($quests as &$quest) {
            foreach (['target','reward_xp','progress'] as $field) {
                $quest[$field] = (int)$quest[$field];
            }
            $quest['claimed'] = $quest['claimed_at'] !== null;
            $quest['claimable'] = !$quest['claimed'] && $quest['progress'] >= $quest['target'];
            $quest['title'] = match ($quest['id']) {
                'stars' => 'Earn ' . $quest['target'] . ' new stars',
                'moons' => 'Earn ' . $quest['target'] . ' new moons',
                'demons' => 'Complete ' . $quest['target'] . ' new demon',
            };
            unset($quest['claimed_at']);
        }
        unset($quest);
        $xp = (int)$wallet['xp'];
        return array_merge($calendar, [
            'schema_version' => 1,
            'account_id' => $accountId,
            'quests' => $quests,
            'xp' => $xp,
            'claims_count' => (int)$wallet['claims_count'],
            'quest_level' => 1 + intdiv($xp,250),
            'xp_to_next_level' => 250 - ($xp % 250),
            'progress_source' => 'accepted_profile_scores',
        ]);
    }

    public function claim(int $accountId, string $day, string $metric, array $calendar): array
    {
        $this->requireTransaction();
        if (!DailyQuestPolicy::validDay($day) || $day !== $calendar['day']) {
            throw new RuntimeException('Quest expired. Refresh Daily Quests.');
        }
        if (!in_array($metric, ['stars','moons','demons'], true)) {
            throw new RuntimeException('Unknown daily quest.');
        }
        $query = $this->pdo->prepare('SELECT target,progress,reward_xp,claimed_at FROM mucho_daily_quests WHERE account_id=? AND quest_day=? AND metric=? FOR UPDATE');
        $query->execute([$accountId,$day,$metric]);
        $quest = $query->fetch(PDO::FETCH_ASSOC);
        if (!$quest || (int)$quest['progress'] < (int)$quest['target']) {
            throw new RuntimeException('This quest is not complete yet.');
        }
        $claimedNow = $quest['claimed_at'] === null;
        if ($claimedNow) {
            $update = $this->pdo->prepare('UPDATE mucho_daily_quests SET claimed_at=UTC_TIMESTAMP() WHERE account_id=? AND quest_day=? AND metric=? AND claimed_at IS NULL');
            $update->execute([$accountId,$day,$metric]);
            $reward = $this->pdo->prepare('UPDATE mucho_quest_accounts SET xp=xp+?,claims_count=claims_count+1 WHERE account_id=?');
            $reward->execute([(int)$quest['reward_xp'],$accountId]);
        }
        return array_merge($this->snapshot($accountId,$calendar), ['claimed_now' => $claimedNow]);
    }
}
