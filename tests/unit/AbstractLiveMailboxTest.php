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
use function date;
use Generator;
use function getenv;
use function is_string;
use ParagonIE\HiddenString\HiddenString;
use PHPUnit\Framework\TestCase;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;
use Throwable;

/**
 * @psalm-type MAILBOX_ARGS = array{
 *	0:HiddenString,
 *	1:HiddenString,
 *	2:HiddenString,
 *	3:string,
 *	4?:string
 * }
 * @psalm-type COMPOSE_ENVELOPE = array{
 *	subject?:string
 * }
 * @psalm-type COMPOSE_BODY = list<array{
 *	type?:int,
 *	encoding?:int,
 *	charset?:string,
 *	subtype?:string,
 *	description?:string,
 *  'disposition.type'?:string,
 *  'type.parameters'?:array{name:string},
 *  'contents.data'?:string,
 *  id?:string,
 *	disposition?:array{filename:string}
 * }>
 */
abstract class AbstractLiveMailboxTest extends TestCase
{
	/**
	 * Provides constructor arguments for a live mailbox.
	 *
	 * @psalm-return MAILBOX_ARGS[]
	 */
	public function MailBoxProvider() : array
	{
		$sets = [];

		$imapPath = getenv('PHPIMAP_IMAP_PATH');
		$login = getenv('PHPIMAP_LOGIN');
		$password = getenv('PHPIMAP_PASSWORD');

		if (is_string($imapPath) && is_string($login) && is_string($password)) {
			$sets['CI ENV'] = [new HiddenString($imapPath), new HiddenString($login), new HiddenString($password, true, true), sys_get_temp_dir()];
		}

		return $sets;
	}

	/**
	 * @psalm-return Generator<int, array{0:COMPOSE_ENVELOPE, 1:COMPOSE_BODY, 2:string}, mixed, void>
	 */
	public function ComposeProvider() : Generator
	{
		yield from [];
	}

	/**
	 * @psalm-return Generator<int, array{
	 *	0:MAILBOX_ARGS,
	 *	1:COMPOSE_ENVELOPE,
	 *	2:COMPOSE_BODY,
	 *	3:string,
	 *	4:bool
	 * }, mixed, void>
	 */
	public function AppendProvider() : Generator
	{
		foreach ($this->MailBoxProvider() as $mailbox_args) {
			foreach ($this->ComposeProvider() as $compose_args) {
				[$envelope, $body, $expected_compose_result] = $compose_args;

				yield [$mailbox_args, $envelope, $body, $expected_compose_result, false];
			}

			foreach ($this->ComposeProvider() as $compose_args) {
				[$envelope, $body, $expected_compose_result] = $compose_args;

				yield [$mailbox_args, $envelope, $body, $expected_compose_result, true];
			}
		}
	}

	/**
	 * @dataProvider AppendProvider
	 *
	 * @group live
	 *
	 * @depends test_get_imap_stream
	 * @depends test_mail_compose
	 *
	 * @psalm-param MAILBOX_ARGS $mailbox_args
	 * @psalm-param COMPOSE_ENVELOPE $envelope
	 * @psalm-param COMPOSE_BODY $body
	 */
	public function test_append(
		array $mailbox_args,
		array $envelope,
		array $body,
		string $_expected_compose_result,
		bool $pre_compose
	) : void {
		if ($this->MaybeSkipAppendTest($envelope)) {
			return;
		}

		[$search_criteria] = $this->SubjectSearchCriteriaAndSubject($envelope);

		[$mailbox, $remove_mailbox, $path] = $this->getMailboxFromArgs(
			$mailbox_args
		);

		/** @var Throwable|null */
		$exception = null;

		$mailboxDeleted = false;

		try {
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

			$message = [$envelope, $body];

			if ($pre_compose) {
				$message = Imap::mail_compose($envelope, $body);
			}

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

			$mailbox->deleteMail($search[0]);

			$mailbox->expungeDeletedMails();

			$mailbox->switchMailbox($path->getString());
			$mailbox->deleteMailbox($remove_mailbox);
			$mailboxDeleted = true;

			static::assertCount(
				0,
				$mailbox->searchMailbox($search_criteria),
				(
					'If a subject was found,' .
					' then the message is was not expunged as requested.'
				)
			);
		} catch (Throwable $ex) {
			$exception = $ex;
		} finally {
			$mailbox->switchMailbox($path->getString());
			if ( ! $mailboxDeleted) {
				$mailbox->deleteMailbox($remove_mailbox);
			}
			$mailbox->disconnect();
		}

		if (null !== $exception) {
			throw $exception;
		}
	}

	/**
	 * Get instance of Mailbox, pre-set to a random mailbox.
	 *
	 * @param string $attachmentsDir
	 * @param string $serverEncoding
	 *
	 * @return mixed[]
	 *
	 * @psalm-return array{0:Mailbox, 1:string, 2:HiddenString}
	 */
	protected function getMailbox(HiddenString $imapPath, HiddenString $login, HiddenString $password, $attachmentsDir, $serverEncoding = 'UTF-8')
	{
		$mailbox = new Mailbox($imapPath->getString(), $login->getString(), $password, $attachmentsDir, $serverEncoding);

		$random = 'test-box-' . date('c') . bin2hex(random_bytes(4));

		$mailbox->createMailbox($random);

		$mailbox->switchMailbox($random, false);

		return [$mailbox, $random, $imapPath];
	}

	/**
	 * @psalm-param MAILBOX_ARGS $mailbox_args
	 *
	 * @return mixed[]
	 *
	 * @psalm-return array{0:Mailbox, 1:string, 2:HiddenString}
	 */
	protected function getMailboxFromArgs(array $mailbox_args) : array
	{
		[$path, $username, $password, $attachments_dir] = $mailbox_args;

		return $this->getMailbox(
			$path,
			$username,
			$password,
			$attachments_dir,
			$mailbox_args[4] ?? 'UTF-8'
		);
	}

	/**
	 * Get subject search criteria and subject.
	 *
	 * @psalm-param array{subject?:mixed} $envelope
	 *
	 * @psalm-return array{0:string, 1:string}
	 */
	protected function SubjectSearchCriteriaAndSubject(array $envelope) : array
	{
		/** @var string|null */
		$subject = $envelope['subject'] ?? null;

		static::assertIsString($subject);

		$search_criteria = sprintf('SUBJECT "%s"', (string) $subject);

		/** @psalm-var array{0:string, 1:string} */
		return [$search_criteria, (string) $subject];
	}

	protected function MaybeSkipAppendTest(array $envelope) : bool
	{
		if ( ! isset($envelope['subject'])) {
			static::markTestSkipped(
				'Cannot search for message by subject, no subject specified!'
			);

			return true;
		}

		return false;
	}
}
