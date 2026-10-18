<?php

declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Protocol\GdRelationshipEncoder;
use MuchoCore\Protocol\Gd2209ThreadWire;
use Throwable;

/**
 * Native GD 2.209 thread endpoint adapter.
 * Parameter/wire contracts still need real-client packet evidence.
 */
final readonly class ThreadController
{
    public function __construct(
        private ThreadService $service,
        private GdRelationshipEncoder $userEncoder,
    ) {}

    private static function b64(string $value): string
    {
        return strtr(base64_encode($value), '+/', '-_');
    }

    private static function when(string $date): int
    {
        $t = strtotime($date);
        return $t === false ? 0 : $t;
    }

    private function threadId(Request $r): int
    {
        return $r->postInt('threadID', $r->postInt('threadId'));
    }

    /** Native thread bodies and searches use URL-safe Base64. */
    private static function decoded(string $encoded): ?string
    {
        if ($encoded === '' || strlen($encoded) > 22000 ||
            preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $encoded) !== 1) {
            return null;
        }
        $normalized = strtr($encoded, '-_', '+/');
        $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
        $decoded = base64_decode($normalized, true);
        if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
            return null;
        }
        if (rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== rtrim($encoded, '=')) {
            return null;
        }
        return $decoded;
    }

    private function encodeThreads(array $rows): string
    {
        if ($rows === []) {
            return '-2';
        }
        $result = [];
        foreach ($rows as $r) {
            $result[] = implode(':', [
                1, (int)$r['id'],
                2, self::b64((string)$r['title']),
                3, (int)$r['owner_account_id'],
                4, (int)$r['is_closed'],
                5, self::when((string)$r['updated_at']),
            ]);
        }
        return implode('|', $result);
    }

    private function encodeMessages(array $rows): string
    {
        return Gd2209ThreadWire::messages($rows);
    }

    public function getThreads(Request $r): Response
    {
        try {
            $page = $this->service->nativeThreads(
                $r->postInt('accountID'), $r->gdCredential(),
                $r->postInt('page'), $r->postInt('filter')
            );
            return Response::text(Gd2209ThreadWire::threads($page));
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function getMessages(Request $r): Response
    {
        try {
            $snapshot = $this->service->nativeSnapshot(
                $r->postInt('accountID'), $r->gdCredential(),
                $this->threadId($r), $r->postInt('beforeID'),
                $r->postInt('afterID'), $r->postInt('aroundID')
            );
            return Response::text(Gd2209ThreadWire::snapshot(
                $snapshot, $this->userEncoder
            ));
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function getMembers(Request $r): Response
    {
        try {
            $data = $this->service->nativeMemberView(
                $r->postInt('accountID'), $r->gdCredential(), $this->threadId($r)
            );
            $users = $data['members'] === []
                ? '' : $this->userEncoder->users($data['members'], true);
            return Response::text(
                $users . '##' . Gd2209ThreadWire::invites($data['invites'])
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function getInvites(Request $r): Response
    {
        try {
            $rows = $this->service->invites(
                $r->postInt('accountID'), $r->gdCredential()
            );
            if ($rows === []) {
                return Response::text('none');
            }
            $out = [];
            foreach ($rows as $row) {
                $out[] = implode(':', [
                    1, (int)$row['id'],
                    2, (int)$row['thread_id'],
                    3, (int)$row['inviter_account_id'],
                    4, self::b64((string)$row['title']),
                    5, self::when((string)$row['created_at']),
                ]);
            }
            return Response::text(implode('|', $out));
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function send(Request $r): Response
    {
        try {
            $body = self::decoded($r->postString('body'));
            if ($body === null) {
                return Response::text('-1');
            }
            $threadId = $this->threadId($r);
            $messageId = $this->service->sendWithId(
                $r->postInt('accountID'), $r->gdCredential(), $threadId, $body
            );
            return Response::text($messageId > 0 ? $threadId . ':' . $messageId : '-1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function search(Request $r): Response
    {
        try {
            $needle = self::decoded($r->postString('query'));
            if ($needle === null) {
                return Response::text('-1');
            }
            $rows = $this->service->search(
                $r->postInt('accountID'), $r->gdCredential(), $this->threadId($r),
                $needle, $r->postInt('page')
            );
            return Response::text($this->encodeMessages($rows) . '#0');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function update(Request $r): Response
    {
        try {
            $account = $r->postInt('accountID');
            $credential = $r->gdCredential();
            $thread = $this->threadId($r);
            $action = strtolower(trim($r->postString('action')));
            $title = $r->postString('title') ?: $r->postString('name');

            if ($action === 'create' || ($thread === 0 && $action === '' && $title !== '')) {
                $id = $this->service->create($account, $credential, $title);
                return Response::text($id > 0 ? (string)$id : '-1');
            }
            if ($action === 'invite') {
                $ok = $this->service->invite(
                    $account, $credential, $thread,
                    $r->postInt('targetAccountID', $r->postInt('toAccountID'))
                );
                return Response::text($ok ? '1' : '-1');
            }
            if ($action === 'accept' || $action === 'decline') {
                $ok = $this->service->respondInvite(
                    $account, $credential,
                    $r->postInt('inviteID', $r->postInt('invitationID')),
                    $action === 'accept'
                );
                return Response::text($ok ? '1' : '-1');
            }
            if ($action === 'leave') {
                return Response::text($this->service->leave(
                    $account, $credential, $thread
                ) ? '1' : '-1');
            }
            if ($action === 'rename' || $action === 'close') {
                return Response::text($this->service->modify(
                    $account, $credential, $thread, $action, $title
                ) ? '1' : '-1');
            }
            return Response::text('-1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
