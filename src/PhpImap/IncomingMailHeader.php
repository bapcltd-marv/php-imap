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
	/** @var int|null $id The IMAP message ID - not the "Message-ID:"-header of the email */
	public ?int $id = null;

	public bool $isDraft = false;

	public ?string $date = null;

	public ?string $headersRaw = null;

	public ?object $headers = null;

	public ?string $priority = null;

	public ?string $importance = null;

	public ?string $sensitivity = null;

	public ?string $autoSubmitted = null;

	public ?string $precedence = null;

	public ?string $failedRecipients = null;

	public ?string $subject = null;

	public ?string $fromHost = null;

	public ?string $fromName = null;

	public ?string $fromAddress = null;

	public ?string $senderHost = null;

	public ?string $senderName = null;

	public ?string $senderAddress = null;

	/**
	* @var (string|null)[]
	*
	* @psalm-var array<string, string|null>
	*/
	public array $to = [];

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

	public ?string $messageId = null;
}
