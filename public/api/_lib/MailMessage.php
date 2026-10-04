<?php
declare(strict_types=1);

namespace Evh;

final class MailMessage
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<array{path: string, name: string}> $attachments
     */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly array $cc = [],
        public readonly ?string $replyTo = null,
        public readonly array $attachments = [],
    ) {
    }
}
