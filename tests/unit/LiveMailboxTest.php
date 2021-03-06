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

use function array_values;
use function base64_encode;
use function bin2hex;
use function count;
use function current;
use function date;
use const ENCBASE64;
use function file_get_contents;
use Generator;
use function in_array;
use function mb_strrpos;
use function mb_substr;
use function min;
use ParagonIE\HiddenString\HiddenString;
use function preg_match;
use function property_exists;
use function random_bytes;
use function shuffle;
use const SORTARRIVAL;
use function str_replace;
use function strtotime;
use Throwable;
use const TYPEAPPLICATION;
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
 * @psalm-type COMPOSE_ENVELOPE = array{
 *	subject?:string
 * }
 * @psalm-type COMPOSE_BODY = list<array{
 *	type?:int,
 *	encoding?:int,
 *	charset?:string,
 *	subtype?:string,
 *	description?:string,
 *	disposition?:array{filename:string}
 * }>
 *
 * @todo see @todo of Imap::mail_compose()
 */
class LiveMailboxTest extends AbstractLiveMailboxTest
{
	const RANDOM_MAILBOX_SAMPLE_SIZE = 3;

	const ISSUE_EXPECTED_ATTACHMENT_COUNT = [
		448 => 1,
		391 => 2,
	];

	/**
	 * @dataProvider MailBoxProvider
	 *
	 * @group live
	 */
	public function test_get_imap_stream(HiddenString $imapPath, HiddenString $login, HiddenString $password, string $attachmentsDir, string $serverEncoding = 'UTF-8') : void
	{
		[$mailbox, $remove_mailbox] = $this->getMailbox(
			$imapPath,
			$login,
			$password,
			$attachmentsDir,
			$serverEncoding
		);

		/** @var Throwable|null */
		$exception = null;

		try {
			$mailbox->getImapStream();
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
			$mailbox->switchMailbox($imapPath->getString());
			$mailbox->deleteMailbox($remove_mailbox);
			$mailbox->disconnect();
		}

		if (null !== $exception) {
			throw $exception;
		}
	}

	/**
	 * @psalm-return Generator<int, array{0:COMPOSE_ENVELOPE, 1:COMPOSE_BODY, 2:string}, mixed, void>
	 */
	public function ComposeProvider() : Generator
	{
		$random_subject = 'test: ' . bin2hex(random_bytes(16));

		yield [
			['subject' => $random_subject],
			[
				[
					'type' => TYPETEXT,
					'contents.data' => 'test',
				],
			],
			(
				'Subject: ' . $random_subject . "\r\n" .
				'MIME-Version: 1.0' . "\r\n" .
				'Content-Type: TEXT/PLAIN; CHARSET=US-ASCII' . "\r\n" .
				"\r\n" .
				'test' . "\r\n"
			),
		];

		$random_subject = 'barbushin/php-imap#448: dot first:' . bin2hex(random_bytes(16));

		yield [
			['subject' => $random_subject],
			[
				[
					'type' => TYPEAPPLICATION,
					'encoding' => ENCBASE64,
					'subtype' => 'octet-stream',
					'description' => '.gitignore',
					'disposition.type' => 'attachment',
					'disposition' => ['filename' => '.gitignore'],
					'type.parameters' => ['name' => '.gitignore'],
					'contents.data' => base64_encode(
						file_get_contents(__DIR__ . '/../../.gitignore')
					),
				],
			],
			(
				'Subject: ' . $random_subject . "\r\n" .
				'MIME-Version: 1.0' . "\r\n" .
				'Content-Type: APPLICATION/octet-stream; name=.gitignore' . "\r\n" .
				'Content-Transfer-Encoding: BASE64' . "\r\n" .
				'Content-Description: .gitignore' . "\r\n" .
				'Content-Disposition: attachment; filename=.gitignore' . "\r\n" .
				"\r\n" .
				base64_encode(
					file_get_contents(__DIR__ . '/../../.gitignore')
				) . "\r\n"
			),
		];

		$random_subject = 'barbushin/php-imap#448: dot last: ' . bin2hex(random_bytes(16));

		yield [
			['subject' => $random_subject],
			[
				[
					'type' => TYPEAPPLICATION,
					'encoding' => ENCBASE64,
					'subtype' => 'octet-stream',
					'description' => 'gitignore.',
					'disposition.type' => 'attachment',
					'disposition' => ['filename' => 'gitignore.'],
					'type.parameters' => ['name' => 'gitignore.'],
					'contents.data' => base64_encode(
						file_get_contents(__DIR__ . '/../../.gitignore')
					),
				],
			],
			(
				'Subject: ' . $random_subject . "\r\n" .
				'MIME-Version: 1.0' . "\r\n" .
				'Content-Type: APPLICATION/octet-stream; name=gitignore.' . "\r\n" .
				'Content-Transfer-Encoding: BASE64' . "\r\n" .
				'Content-Description: gitignore.' . "\r\n" .
				'Content-Disposition: attachment; filename=gitignore.' . "\r\n" .
				"\r\n" .
				base64_encode(
					file_get_contents(__DIR__ . '/../../.gitignore')
				) . "\r\n"
			),
		];

		$random_subject = 'barbushin/php-imap#391: ' . bin2hex(random_bytes(16));

		$random_attachment_a = base64_encode(random_bytes(16));
		$random_attachment_b = base64_encode(random_bytes(16));

		yield [
			['subject' => $random_subject],
			[
				[
					'type' => TYPEMULTIPART,
				],
				[
					'type' => TYPETEXT,
					'contents.data' => 'test',
				],
				[
					'type' => TYPEAPPLICATION,
					'encoding' => ENCBASE64,
					'subtype' => 'octet-stream',
					'description' => 'foo.bin',
					'disposition.type' => 'attachment',
					'disposition' => ['filename' => 'foo.bin'],
					'type.parameters' => ['name' => 'foo.bin'],
					'contents.data' => $random_attachment_a,
				],
				[
					'type' => TYPEAPPLICATION,
					'encoding' => ENCBASE64,
					'subtype' => 'octet-stream',
					'description' => 'foo.bin',
					'disposition.type' => 'attachment',
					'disposition' => ['filename' => 'foo.bin'],
					'type.parameters' => ['name' => 'foo.bin'],
					'contents.data' => $random_attachment_b,
				],
			],
			(
				'Subject: ' . $random_subject . "\r\n" .
				'MIME-Version: 1.0' . "\r\n" .
				'Content-Type: MULTIPART/MIXED; BOUNDARY="{{REPLACE_BOUNDARY_HERE}}"' . "\r\n" .
				"\r\n" .
				'--{{REPLACE_BOUNDARY_HERE}}' . "\r\n" .
				'Content-Type: TEXT/PLAIN; CHARSET=US-ASCII' . "\r\n" .
				"\r\n" .
				'test' . "\r\n" .
				'--{{REPLACE_BOUNDARY_HERE}}' . "\r\n" .
				'Content-Type: APPLICATION/octet-stream; name=foo.bin' . "\r\n" .
				'Content-Transfer-Encoding: BASE64' . "\r\n" .
				'Content-Description: foo.bin' . "\r\n" .
				'Content-Disposition: attachment; filename=foo.bin' . "\r\n" .
				"\r\n" .
				$random_attachment_a . "\r\n" .
				'--{{REPLACE_BOUNDARY_HERE}}' . "\r\n" .
				'Content-Type: APPLICATION/octet-stream; name=foo.bin' . "\r\n" .
				'Content-Transfer-Encoding: BASE64' . "\r\n" .
				'Content-Description: foo.bin' . "\r\n" .
				'Content-Disposition: attachment; filename=foo.bin' . "\r\n" .
				"\r\n" .
				$random_attachment_b . "\r\n" .
				'--{{REPLACE_BOUNDARY_HERE}}--' . "\r\n"
			),
		];
	}

	/**
	 * @dataProvider ComposeProvider
	 *
	 * @group compose
	 *
	 * @psalm-param COMPOSE_ENVELOPE $envelope
	 * @psalm-param COMPOSE_BODY $body
	 */
	public function test_mail_compose(array $envelope, array $body, string $expected_result) : void
	{
		$actual_result = Imap::mail_compose($envelope, $body);

		$expected_result = $this->ReplaceBoundaryHere(
			$expected_result,
			$actual_result
		);

		static::assertSame($expected_result, $actual_result);
	}

	/**
	 * @dataProvider AppendProvider
	 *
	 * @group live
	 *
	 * @depends test_append
	 *
	 * @psalm-param MAILBOX_ARGS $mailbox_args
	 * @psalm-param COMPOSE_ENVELOPE $envelope
	 * @psalm-param COMPOSE_BODY $body
	 */
	public function test_append_nudges_mailbox_count(
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

		$count = $mailbox->countMails();

		$message = [$envelope, $body];

		if ($pre_compose) {
			$message = Imap::mail_compose($envelope, $body);
		}

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

		static::assertSame(
			$count + 1,
			$mailbox->countMails(),
			(
				'If the message count did not increase' .
				' then either the message was not appended,' .
				' or a mesage was removed while the test was running.'
			)
		);

		$mailbox->deleteMail($search[0]);

		$mailbox->expungeDeletedMails();

		$mailbox->switchMailbox($path->getString());
		$mailbox->deleteMailbox($remove_mailbox);

		static::assertCount(
			0,
			$mailbox->searchMailbox($search_criteria),
			(
				'If a subject was found,' .
				' then the message is was not expunged as requested.'
			)
		);
	}

	/**
	 * @dataProvider AppendProvider
	 *
	 * @group live
	 *
	 * @depends test_append
	 *
	 * @psalm-param MAILBOX_ARGS $mailbox_args
	 * @psalm-param COMPOSE_ENVELOPE $envelope
	 * @psalm-param COMPOSE_BODY $body
	 */
	public function test_append_single_search_matches_sort(
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

		$message = [$envelope, $body];

		if ($pre_compose) {
			$message = Imap::mail_compose($envelope, $body);
		}

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

		static::assertSame(
			$search,
			$mailbox->sortMails(SORTARRIVAL, true, $search_criteria)
		);

		static::assertSame(
			$search,
			$mailbox->sortMails(SORTARRIVAL, false, $search_criteria)
		);

		static::assertSame(
			$search,
			$mailbox->sortMails(SORTARRIVAL, false, $search_criteria, 'UTF-8')
		);

		static::assertTrue(in_array(
			$search[0],
			$mailbox->sortMails(SORTARRIVAL, false, null),
			true
		));

		$mailbox->deleteMail($search[0]);

		$mailbox->expungeDeletedMails();

		$mailbox->switchMailbox($path->getString());
		$mailbox->deleteMailbox($remove_mailbox);

		static::assertCount(
			0,
			$mailbox->searchMailbox($search_criteria),
			(
				'If a subject was found,' .
				' then the message is was not expunged as requested.'
			)
		);
	}

	/**
	 * @dataProvider AppendProvider
	 *
	 * @group live
	 *
	 * @depends test_append
	 *
	 * @psalm-param MAILBOX_ARGS $mailbox_args
	 * @psalm-param COMPOSE_ENVELOPE $envelope
	 * @psalm-param COMPOSE_BODY $body
	 */
	public function test_append_retrieval_matches_expected(
		array $mailbox_args,
		array $envelope,
		array $body,
		string $expected_compose_result,
		bool $pre_compose
	) : void {
		if ($this->MaybeSkipAppendTest($envelope)) {
			return;
		}

		[$search_criteria, $search_subject] = $this->SubjectSearchCriteriaAndSubject($envelope);

		[$mailbox, $remove_mailbox, $path] = $this->getMailboxFromArgs(
			$mailbox_args
		);

		$message = [$envelope, $body];

		if ($pre_compose) {
			$message = Imap::mail_compose($envelope, $body);
		}

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

		$actual_result = $mailbox->getMailMboxFormat($search[0]);

		static::assertSame(
			$this->ReplaceBoundaryHere(
				$expected_compose_result,
				$actual_result
			),
			$actual_result
		);

		$actual_result = $mailbox->getRawMail($search[0]);

		static::assertSame(
			$this->ReplaceBoundaryHere(
				$expected_compose_result,
				$actual_result
			),
			$actual_result
		);

		$mail = $mailbox->getMail($search[0], false);

		static::assertSame(
			$search_subject,
			$mail->subject,
			(
				'If a retrieved mail did not have a matching subject' .
				' despite being found via search,' .
				' then something has gone wrong.'
			)
		);

		$info = $mailbox->getMailsInfo($search);

		static::assertCount(1, $info);

		static::assertSame(
			$search_subject,
			$info[0]->subject,
			(
				'If a retrieved mail did not have a matching subject' .
				' despite being found via search,' .
				' then something has gone wrong.'
			)
		);

		if (1 === preg_match(
			'/^barbushin\/php-imap#(448|391):/',
			$envelope['subject'] ?? '',
			$matches
		)) {
			static::assertTrue($mail->hasAttachments());

			$attachments = $mail->getAttachments();

			static::assertCount(self::ISSUE_EXPECTED_ATTACHMENT_COUNT[
				(int) $matches[1]],
				$attachments
			);

			if ('448' === $matches[1]) {
				static::assertSame(
					file_get_contents(__DIR__ . '/../../.gitignore'),
					current($attachments)->getContents()
				);
			}
		}

		$mailbox->deleteMail($search[0]);

		$mailbox->expungeDeletedMails();

		$mailbox->switchMailbox($path->getString());
		$mailbox->deleteMailbox($remove_mailbox);

		static::assertCount(
			0,
			$mailbox->searchMailbox($search_criteria),
			(
				'If a subject was found,' .
				' then the message is was not expunged as requested.'
			)
		);
	}

	/**
	 * @param string $expected_result
	 * @param string $actual_result
	 *
	 * @return string
	 */
	protected function ReplaceBoundaryHere(
		$expected_result,
		$actual_result
	) {
		if (
			1 === preg_match('/{{REPLACE_BOUNDARY_HERE}}/', $expected_result) &&
			1 === preg_match(
				'/Content-Type: MULTIPART\/MIXED; BOUNDARY="([^"]+)"/',
				$actual_result,
				$matches
			)
		) {
			$expected_result = str_replace(
				'{{REPLACE_BOUNDARY_HERE}}',
				$matches[1],
				$expected_result
			);
		}

		return $expected_result;
	}
}
