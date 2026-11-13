<?php
declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Protocol\GdMessageEncoder;

final readonly class MessageService
{
    public function __construct(
        private MessageRepository $repository,
        private AccountAuthenticator $auth,
        private GdMessageEncoder $encoder,
        private RelationshipRepository $relationships
    ) {}

    public function getMessages(
        int $id,
        string $gjp,
        int $page,
        bool $sent
    ): string {
        $this->auth->authenticate($id, $gjp);
        $page = min(1000, max(0, $page));

        return $this->encoder->encodeList(
            $this->repository->list($id, $sent, $page),
            $this->repository->count($id, $sent),
            max(0, $page) * 10,
            10,
            $sent
        );
    }

    public function readMessage(
        int $id,
        string $gjp,
        int $messageId
    ): string {
        $this->auth->authenticate($id, $gjp);

        $m = $this->repository->read($messageId, $id);

        if (!$m) {
            return '-1';
        }

        return $this->encoder->encodeMessage(
            $m,
            (int)$m['account_id'] === $id
        );
    }

    public function sendMessage(
        int $id,
        string $gjp,
        int $to,
        string $subject,
        string $body
    ): bool {
        try {
            $this->auth->authenticate($id, $gjp);
        } catch (\Throwable $exception) {
            MessageTrace::emit('authentication', 'rejected');
            throw $exception;
        }

        $decodedSubject = $this->encoder->decodeSubject($subject);
        if ($decodedSubject === null) {
            MessageTrace::emit('decoding', 'invalid_subject');
            return false;
        }

        $decodedBody = $this->encoder->decodeBody($body);
        if ($decodedBody === null) {
            MessageTrace::emit('decoding', 'invalid_body');
            return false;
        }

        if ($this->repository->isMessageBanned($id)) {
            MessageTrace::emit('policy', 'message_banned');
            return false;
        }

        if ($to <= 0 || $to === $id) {
            MessageTrace::emit('policy', 'invalid_target');
            return false;
        }
        if ($decodedSubject === '') {
            MessageTrace::emit('policy', 'empty_subject');
            return false;
        }
        if ($decodedBody === '') {
            MessageTrace::emit('policy', 'empty_body');
            return false;
        }
        if (strlen($decodedSubject) > 255) {
            MessageTrace::emit('policy', 'subject_too_long');
            return false;
        }
        if (strlen($decodedBody) > 65535) {
            MessageTrace::emit('policy', 'body_too_long');
            return false;
        }
        if (!$this->relationships->canMessage($id, $to)) {
            MessageTrace::emit('policy', 'recipient_policy');
            return false;
        }

        $saved = $this->repository->send(
            $id,
            $to,
            $decodedSubject,
            $decodedBody
        );
        MessageTrace::emit('storage', $saved ? 'stored' : 'insert_rejected');
        return $saved;
    }

    public function deleteMessage(
        int $id,
        string $gjp,
        int $messageId,
        bool $sent
    ): bool {
        $this->auth->authenticate($id, $gjp);

        return $messageId > 0
            && $this->repository->delete(
                $messageId,
                $id,
                $sent
            );
    }
}
