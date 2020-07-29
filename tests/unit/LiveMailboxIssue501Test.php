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

use function bin2hex;
use Exception;
use function imap_mime_header_decode;
use ParagonIE\HiddenString\HiddenString;
use function random_bytes;
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
class LiveMailboxIssue501Test extends AbstractLiveMailboxTest
{
	/**
	 * @group offline
	 * @group offline-issue-501
	 */
	public function test_decode_mime_str_empty() : void
	{
		static::assertSame([], imap_mime_header_decode(''));

		// example credentials nabbed from MailboxTest::testConstructorTrimsPossibleVariables()
		$imapPath = ' {imap.example.com:993/imap/ssl}INBOX     ';
		$login = '    php-imap@example.com';
		$password = new HiddenString('  v3rY!53cEt&P4sSWöRd$', true, true);
		// directory names can contain spaces before AND after on Linux/Unix systems. Windows trims these spaces automatically.
		$attachmentsDir = '.';
		$serverEncoding = 'UTF-8  ';

		$mailbox = new Mailbox($imapPath, $login, $password, $attachmentsDir, $serverEncoding);

		static::assertSame('', $mailbox->decodeMimeStr(''));
	}

	/**
	 * @dataProvider MailBoxProvider
	 *
	 * @group live
	 * @group live-issue-501
	 */
	public function test_get_empty_body(
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

			$mailbox->appendMessageToMailbox(Imap::mail_compose(
				$envelope,
				[
					[
						'type' => TYPETEXT,
						'contents.data' => '',
					],
				]
			));

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

			static::assertSame('', $mail->textPlain);
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
