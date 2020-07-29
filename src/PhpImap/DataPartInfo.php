<?php

declare(strict_types=1);

namespace PhpImap;

use function imap_base64;
use function imap_binary;
use function imap_utf8;
use function preg_replace;
use function quoted_printable_decode;
use function trim;

/**
 * @see https://github.com/barbushin/php-imap
 *
 * @author nickl- http://github.com/nickl-
 */
class DataPartInfo
{
	const TEXT_PLAIN = 0;

	const TEXT_HTML = 1;

	/**
	 * @readonly
	 */
	public int $id;

	/**
	 * @var int|mixed
	 *
	 * @readonly
	 */
	public $encoding;

	public ?string $charset = null;

	/**
	 * @var 0|string
	 *
	 * @readonly
	 */
	public $part;

	/**
	 * @readonly
	 */
	public Mailbox $mail;

	/**
	 * @readonly
	 */
	public int $options;

	protected ?string $data = null;

	/**
	 * @param 0|string $part
	 * @param int|mixed $encoding
	 */
	public function __construct(Mailbox $mail, int $id, $part, $encoding, int $options)
	{
		$this->mail = $mail;
		$this->id = $id;
		$this->part = $part;
		$this->encoding = $encoding;
		$this->options = $options;
	}

	public function fetch() : string
	{
		if (0 === $this->part) {
			$this->data = Imap::body($this->mail->getImapStream(), $this->id, $this->options);
		} else {
			$this->data = Imap::fetchbody($this->mail->getImapStream(), $this->id, $this->part, $this->options);
		}

		return $this->decodeAfterFetch();
	}

	protected function decodeAfterFetch() : string
	{
		switch ($this->encoding) {
			case ENC8BIT:
				$this->data = imap_utf8((string) $this->data);
				break;
			case ENCBINARY:
				$this->data = imap_binary((string) $this->data);
				break;
			case ENCBASE64:
				$this->data = preg_replace('~[^a-zA-Z0-9+=/]+~s', '', (string) $this->data); // https://github.com/barbushin/php-imap/issues/88
				$this->data = imap_base64((string) $this->data);
				break;
			case ENCQUOTEDPRINTABLE:
				$this->data = quoted_printable_decode((string) $this->data);
				break;
		}

		return $this->convertEncodingAfterFetch();
	}

	protected function convertEncodingAfterFetch() : string
	{
		if (isset($this->charset) && ! empty(trim($this->charset))) {
			$this->data = $this->mail->convertStringEncoding(
				(string) $this->data, // Data to convert
				$this->charset, // FROM-Encoding (Charset)
				$this->mail->getServerEncoding() // TO-Encoding (Charset)
			);
		}

		return (null === $this->data) ? '' : $this->data;
	}
}
