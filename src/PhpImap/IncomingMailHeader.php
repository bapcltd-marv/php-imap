<?php

declare(strict_types=1);

namespace PhpImap;

/**
 * @see https://github.com/barbushin/php-imap
 *
 * @author Barbushin Sergey http://linkedin.com/in/barbushin
 */
class IncomingMailHeader
{
    /** @var int|null The IMAP message ID - not the "Message-ID:"-header of the email */
    public ?int $id = null;

    /** @var string|null */
    public $imapPath;

    /** @var string|null */
    public $mailboxFolder;

    /** @var bool */
    public bool $isDraft = false;

    /** @var string|null */
    public ?string $date = null;

    /** @var string|null */
    public ?string $headersRaw = null;

    /** @var object|null */
    public ?object $headers = null;

    /** @var string|null */
    public ?string $mimeVersion = null;

    /** @var string|null */
    public ?string $xVirusScanned = null;

    /** @var string|null */
    public ?string $organization = null;

    /** @var string|null */
    public ?string $contentType = null;

    /** @var string|null */
    public ?string $xMailer = null;

    /** @var string|null */
    public ?string $contentLanguage = null;

    /** @var string|null */
    public ?string $xSenderIp = null;

    /** @var string|null */
    public ?string $priority = null;

    /** @var string|null */
    public ?string $importance = null;

    /** @var string|null */
    public ?string $sensitivity = null;

    /** @var string|null */
    public ?string $autoSubmitted = null;

    /** @var string|null */
    public ?string $precedence = null;

    /** @var string|null */
    public ?string $failedRecipients = null;

    /** @var string|null */
    public ?string $subject = null;

    /** @var string|null */
    public ?string $fromHost = null;

    /** @var string|null */
    public ?string $fromName = null;

    /** @var string|null */
    public ?string $fromAddress = null;

    /** @var string|null */
    public ?string $senderHost = null;

    /** @var string|null */
    public ?string $senderName = null;

    /** @var string|null */
    public ?string $senderAddress = null;

    /**
     * @var (string|null)[]
     *
     * @psalm-var array<string, string|null>
     */
    public array $to = [];

    /** @var string|null */
    public ?string $toString = null;

    /**
     * @var (string|null)[]
     *
     * @psalm-var array<string, string|null>
     */
    public array $cc = [];

    /**
     * @var (string|null)[]
     *
     * @psalm-var array<string, string|null>
     */
    public array $bcc = [];

    /**
     * @var (string|null)[]
     *
     * @psalm-var array<string, string|null>
     */
    public array $replyTo = [];

    /** @var string|null */
    public ?string $messageId = null;
}
