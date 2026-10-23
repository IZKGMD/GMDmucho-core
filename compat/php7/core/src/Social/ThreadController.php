<?php

declare (strict_types=1);
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
final class ThreadController
{
    public function __construct(ThreadService $service, GdRelationshipEncoder $userEncoder, $creator = null, $actions = null)
    {
        $this->service = $service;
        $this->userEncoder = $userEncoder;
        $this->creator = $creator;
        $this->actions = $actions;
    }
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
    private static function decoded(string $encoded)
    {
        if ($encoded === '' || strlen($encoded) > 22000 || preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $encoded) !== 1) {
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
            $result[] = implode(':', [1, (int) $r['id'], 2, self::b64((string) $r['title']), 3, (int) $r['owner_account_id'], 4, (int) $r['is_closed'], 5, self::when((string) $r['updated_at'])]);
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
            $page = $this->service->nativeThreads($r->postInt('accountID'), $r->gdCredential(), $r->postInt('page'), $r->postInt('filter'), $r->postString('user'));
            return Response::text(Gd2209ThreadWire::threads($page));
        } catch (Throwable $__mcCaught) {
            return Response::text('-1');
        }
    }
    public function getMessages(Request $r): Response
    {
        try {
            $snapshot = $this->service->nativeSnapshot($r->postInt('accountID'), $r->gdCredential(), $this->threadId($r), $r->postInt('beforeID'), $r->postInt('afterID'), $r->postInt('aroundID'));
            return Response::text(Gd2209ThreadWire::snapshot($snapshot, $this->userEncoder));
        } catch (Throwable $__mcCaught) {
            return Response::text('-1');
        }
    }
    public function getMembers(Request $r): Response
    {
        try {
            $data = $this->service->nativeMemberView($r->postInt('accountID'), $r->gdCredential(), $this->threadId($r));
            $users = $data['members'] === [] ? '' : $this->userEncoder->users($data['members'], true);
            return Response::text($users . '##' . Gd2209ThreadWire::invites($data['invites']));
        } catch (Throwable $__mcCaught) {
            return Response::text('-1');
        }
    }
    public function getInvites(Request $r): Response
    {
        try {
            $rows = $this->service->invites($r->postInt('accountID'), $r->gdCredential());
            return Response::text(Gd2209ThreadWire::inboxInvites($rows));
        } catch (Throwable $__mcCaught) {
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
            if ($threadId === 0) {
                if ($this->creator === null || !in_array($r->postInt('isDM'), [0, 1], true)) {
                    return Response::text('-1');
                }
                $isDm = $r->postInt('isDM') === 1;
                $subject = $isDm ? '' : self::decoded($r->postString('subject'));
                $description = $isDm ? '' : ($r->postString('description') === '' ? '' : self::decoded($r->postString('description')));
                $emote = $isDm ? '' : ($r->postString('emote') === '' ? '' : self::decoded($r->postString('emote')));
                $targets = $isDm ? '' : ($r->postString('targets') === '' ? '' : self::decoded($r->postString('targets')));
                if (!$isDm && ($subject === null || $description === null || $emote === null || $targets === null)) {
                    return Response::text('-1');
                }
                $created = $this->creator->start($r->postInt('accountID'), $r->gdCredential(), $body, $isDm, $r->postInt('toAccountID'), (string) $subject, (string) $description, (string) $emote, Gd2209NativeThreadOperations::targetIds((string) $targets));
                return Response::text($created['thread_id'] . ':' . $created['message_id'] . ($isDm ? ':0' : ':' . $created['invites'] . ':0'));
            }
            $messageId = $this->service->sendWithId($r->postInt('accountID'), $r->gdCredential(), $threadId, $body);
            return Response::text($messageId > 0 ? $threadId . ':' . $messageId : '-1');
        } catch (Throwable $__mcCaught) {
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
            $page = $this->service->nativeSearch($r->postInt('accountID'), $r->gdCredential(), $this->threadId($r), $needle, $r->postInt('beforeID'));
            return Response::text($this->encodeMessages($page['rows']) . '#' . $page['remaining']);
        } catch (Throwable $__mcCaught) {
            return Response::text('-1');
        }
    }
    public function update(Request $r): Response
    {
        try {
            $account = $r->postInt('accountID');
            $credential = $r->gdCredential();
            $thread = $this->threadId($r);
            // Actual 2.209 client uses numeric "mode" and Base64 "value".
            if (array_key_exists('mode', $r->post)) {
                $mode = $r->postInt('mode', -1);
                $value = $r->postString('value');
                $needsValue = in_array($mode, [2, 3, 4, 6, 7, 10, 11, 12, 13, 14, 15, 16], true);
                $decoded = $needsValue ? self::decoded($value) : '';
                if ($needsValue && $decoded === null) {
                    return Response::text('-1');
                }
                $ok = (function () use (&$mode, &$account, &$credential, &$thread, &$decoded) {
                    $__mcMatch2 = $mode;
                    if ($__mcMatch2 === 2) {
                        return $this->service->modify($account, $credential, $thread, 'rename', (string) $decoded);
                    }
                    if ($__mcMatch2 === 5) {
                        return $this->service->leave($account, $credential, $thread);
                    }
                    if ($__mcMatch2 === 8) {
                        return $this->service->respondThreadInvite($account, $credential, $thread, true);
                    }
                    if ($__mcMatch2 === 9) {
                        return $this->service->respondThreadInvite($account, $credential, $thread, false);
                    }
                    if ($__mcMatch2 === 12) {
                        return ctype_digit((string) $decoded) && (int) $decoded > 0 && $this->service->deleteOwnMessage($account, $credential, $thread, (int) $decoded);
                    }
                    return (function () use (&$account, &$credential, &$thread, &$mode, &$decoded) {
                        $__mcOptional1 = $this->actions;
                        return $__mcOptional1 === null ? null : $__mcOptional1->change($account, $credential, $thread, $mode, (string) $decoded);
                    })() ?? false;
                })();
                return Response::text($ok ? '1' : '-1');
            }
            // Legacy custom-client adapter, not the GD 2.209 native contract.
            $action = strtolower(trim($r->postString('action')));
            $title = $r->postString('title') ?: $r->postString('name');
            if ($action === 'create' || $thread === 0 && $action === '' && $title !== '') {
                $id = $this->service->create($account, $credential, $title);
                return Response::text($id > 0 ? (string) $id : '-1');
            }
            if ($action === 'invite') {
                $ok = $this->service->invite($account, $credential, $thread, $r->postInt('targetAccountID', $r->postInt('toAccountID')));
                return Response::text($ok ? '1' : '-1');
            }
            if ($action === 'accept' || $action === 'decline') {
                $ok = $this->service->respondInvite($account, $credential, $r->postInt('inviteID', $r->postInt('invitationID')), $action === 'accept');
                return Response::text($ok ? '1' : '-1');
            }
            if ($action === 'leave') {
                return Response::text($this->service->leave($account, $credential, $thread) ? '1' : '-1');
            }
            if ($action === 'rename' || $action === 'close') {
                return Response::text($this->service->modify($account, $credential, $thread, $action, $title) ? '1' : '-1');
            }
            return Response::text('-1');
        } catch (Throwable $__mcCaught) {
            return Response::text('-1');
        }
    }
    private $service;
    private $userEncoder;
    private $creator;
    private $actions;
}
