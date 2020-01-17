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

use function in_array;
use function is_string;
use ParagonIE\HiddenString\HiddenString;
use PHPUnit\Framework\TestCase;
use Throwable;

class LiveMailboxTest extends TestCase
{
	const RANDOM_MAILBOX_SAMPLE_SIZE = 3;

	/**
	 * Provides constructor arguments for a live mailbox.
	 *
	 * @psalm-return array{0:HiddenString, 1:HiddenString, 2:HiddenString, 3:string, 4?:string}[]
	 */
	public function MailBoxProvider() : array
	{
		$sets = [];

		$imapPath = getenv('PHPIMAP_IMAP_PATH');
		$login = getenv('PHPIMAP_LOGIN');
		$password = getenv('PHPIMAP_PASSWORD');

		if (is_string($imapPath) && is_string($login) && is_string($password)) {
			$sets['CI ENV'] = [new HiddenString($imapPath, true, true), new HiddenString($login, true, true), new HiddenString($password, true, true), sys_get_temp_dir()];
		}

		return $sets;
	}

	/**
	 * @dataProvider MailBoxProvider
	 */
	public function test_get_imap_stream(HiddenString $imapPath, HiddenString $login, HiddenString $password, string $attachmentsDir, string $serverEncoding = 'UTF-8') : void
	{
		$mailbox = new Mailbox($imapPath->getString(), $login->getString(), $password, $attachmentsDir, $serverEncoding);

		/** @var Throwable|null */
		$exception = null;

		try {
			static::assertIsResource($mailbox->getImapStream());
			static::assertTrue($mailbox->hasImapStream());

			$mailboxes = $mailbox->getMailboxes();
			shuffle($mailboxes);

			$mailboxes = array_values($mailboxes);

			$limit = min(count($mailboxes), self::RANDOM_MAILBOX_SAMPLE_SIZE);

			for ($i = 0; $i < $limit; ++$i) {
				static::assertIsArray($mailboxes[$i]);
				static::assertTrue(isset($mailboxes[$i]['shortpath']));
				static::assertIsString($mailboxes[$i]['shortpath']);
				$mailbox->switchMailbox($mailboxes[$i]['shortpath']);

				$check = $mailbox->checkMailbox();

				static::assertIsObject($check);

				foreach ([
					'Date',
					'Driver',
					'Mailbox',
					'Nmsgs',
					'Recent',
				] as $expectedProperty) {
					static::assertTrue(property_exists($check, $expectedProperty));
				}

				static::assertIsString($check->Date, 'Date property of Mailbox::checkMailbox() result was not a string!');

				$unix = strtotime($check->Date);

				if (false === $unix && preg_match('/[+-]\d{1,2}:?\d{2} \([^\)]+\)$/', $check->Date)) {
					/** @var int */
					$pos = mb_strrpos($check->Date, '(');

					// Although the date property is likely RFC2822-compliant, it will not be parsed by strtotime()
					$unix = strtotime(mb_substr($check->Date, 0, $pos));
				}

				static::assertIsInt($unix, 'Date property of Mailbox::checkMailbox() result was not a valid date!');
				static::assertTrue(in_array($check->Driver, ['POP3', 'IMAP', 'NNTP', 'pop3', 'imap', 'nntp'], true), 'Driver property of Mailbox::checkMailbox() result was not of an expected value!');
				static::assertIsInt($check->Nmsgs, 'Nmsgs property of Mailbox::checkMailbox() result was not of an expected type!');
				static::assertIsInt($check->Recent, 'Recent property of Mailbox::checkMailbox() result was not of an expected type!');

				$status = $mailbox->statusMailbox();

				foreach ([
					'messages',
					'recent',
					'unseen',
					'uidnext',
					'uidvalidity',
				] as $expectedProperty) {
					static::assertTrue(property_exists($status, $expectedProperty));
				}

				static::assertSame($check->Nmsgs, $mailbox->countMails(), 'Mailbox::checkMailbox()->Nmsgs did not match Mailbox::countMails()!');
			}
		} catch (Throwable $ex) {
			$exception = $ex;
		} finally {
			$mailbox->disconnect();
		}

		if (null !== $exception) {
			throw $exception;
		}
	}
}
