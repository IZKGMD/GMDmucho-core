<?php
declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdRelationshipEncoder
{
    public function requests(
        array $rows,
        int $total,
        int $offset,
        bool $sent
    ): string {
        if ($rows === []) {
            return '-2';
        }

        $out = [];

        foreach ($rows as $r) {
            $peer = $sent
                ? (int)$r['to_account_id']
                : (int)$r['account_id'];

            $out[] = implode(':', [
                1, ProtocolText::username($r['username'] ?? 'Player'),
                2, $r['user_id'],
                9, $r['cube'] ?? 1,
                10, $r['color1'] ?? 0,
                11, $r['color2'] ?? 3,
                14, 0,
                15, $r['special'] ?? 0,
                16, $peer,
                32, $r['id'],
                35, $r['comment'] ?? '',
                41, ((int)$r['is_read'] === 0 ? 1 : 0),
                37, $this->date((string)$r['created_at']),
            ]);
        }

        return implode('|', $out)
            .'#'.$total.':'.$offset.':10';
    }

    public function users(array $rows): string
    {
        if ($rows === []) {
            return '-2';
        }

        $out = [];

        foreach ($rows as $r) {
            $out[] = implode(':', [
                1, ProtocolText::username($r['username'] ?? 'Player'),
                2, $r['user_id'],
                9, $r['cube'] ?? 1,
                10, $r['color1'] ?? 0,
                11, $r['color2'] ?? 3,
                14, 0,
                15, $r['special'] ?? 0,
                16, $r['account_id'],
                18, 0,
                41, $r['is_new'] ?? 0,
            ]);
        }

        return implode('|', $out);
    }

    private function date(string $value): string
    {
        $t = strtotime($value);
        return $t === false ? '' : date('d/m/Y G.i', $t);
    }
}
