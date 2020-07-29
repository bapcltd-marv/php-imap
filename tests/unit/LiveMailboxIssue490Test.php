<?php
/**
 * Live Mailbox - PHPUnit tests.
 *
 * Runs tests on a live mailbox
 *
 * @author BAPCLTD-Marv
 */
declare(strict_types=1);

namespace PhpImap;

use function array_keys;
use function bin2hex;
use Exception;
use ParagonIE\HiddenString\HiddenString;
use function random_bytes;
use const TYPEMULTIPART;
use const TYPETEXT;

/**
 * @psalm-type MAILBOX_ARGS = array{
 *	0:HiddenString,
 *	1:HiddenString,
 *	2:HiddenString,
 *	3:string,
 *	4?:string
 * }
 */
class LiveMailboxIssue490Test extends AbstractLiveMailboxTest
{
	/**
	 * @dataProvider MailBoxProvider
	 *
	 * @group live
	 * @group live-issue-490
	 */
	public function test_get_text_attachments(
		HiddenString $imapPath,
		HiddenString $login,
		HiddenString $password,
		string $attachmentsDir,
		string $serverEncoding = 'UTF-8'
	) : void {
		[$mailbox, $remove_mailbox] = $this->getMailbox(
			$imapPath,
			$login,
			$password,
			$attachmentsDir,
			$serverEncoding
		);

		$exception = null;

		try {
			$envelope = [
				'subject' => 'barbushin/php-imap#501: ' . bin2hex(random_bytes(16)),
			];

			[$search_criteria] = $this->SubjectSearchCriteriaAndSubject(
				$envelope
			);

			$search = $mailbox->searchMailbox($search_criteria);

			static::assertCount(
				0,
				$search,
				(
					'If a subject was found,' .
					' then the message is insufficiently unique to assert that' .
					' a newly-appended message was actually created.'
				)
			);

			$message = Imap::mail_compose(
				$envelope,
				[
					[
						'type' => TYPEMULTIPART,
					],
					[
						'type' => TYPETEXT,
						'contents.data' => 'foo',
					],
					[
						'type' => TYPEMULTIPART,
						'subtype' => 'plain',
						'description' => 'bar.txt',
						'disposition.type' => 'attachment',
						'disposition' => ['filename' => 'bar.txt'],
						'type.parameters' => ['name' => 'bar.txt'],
						'contents.data' => 'bar',
					],
					[
						'type' => TYPEMULTIPART,
						'subtype' => 'plain',
						'description' => 'baz.txt',
						'disposition.type' => 'attachment',
						'disposition' => ['filename' => 'baz.txt'],
						'type.parameters' => ['name' => 'baz.txt'],
						'contents.data' => 'baz',
					],
				]
			);

			$mailbox->appendMessageToMailbox($message);

			$search = $mailbox->searchMailbox($search_criteria);

			static::assertCount(
				1,
				$search,
				(
					'If a subject was not found, ' .
					' then Mailbox::appendMessageToMailbox() failed' .
					' despite not throwing an exception.'
				)
			);

			$mail = $mailbox->getMail($search[0], false);

			static::assertSame('foo', $mail->textPlain);

			$attachments = $mail->getAttachments();
			$keys = array_keys($attachments);

			static::assertCount(2, $attachments);

			static::assertSame('bar', $attachments[$keys[0]]->getContents());
			static::assertSame('baz', $attachments[$keys[1]]->getContents());
		} catch (Exception $ex) {
			$exception = $ex;
		} finally {
			$mailbox->switchMailbox($imapPath->getString());
			$mailbox->deleteMailbox($remove_mailbox);
			$mailbox->disconnect();
		}

		if (null !== $exception) {
			throw $exception;
		}
	}
}
