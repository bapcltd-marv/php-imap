<?php
/**
* @author BAPCLTD-Marv
*/
declare(strict_types=1);

namespace PhpImap;

use ParagonIE\HiddenString\HiddenString;
use PHPUnit\Framework\TestCase;

class IncomingMailTest extends TestCase
{
	public function test_set_header() : void
	{
		$mail = new IncomingMail();
		$header = new IncomingMailHeader();

		$mail->id = '1';
		$header->id = '2';

		$mail->isDraft = true;
		$header->isDraft = false;

		$mail->date = date(DATE_RFC3339, 0);
		$header->date = date(DATE_RFC3339, 60 * 60 * 24);

		$mail->setHeader($header);

		foreach (
			[
				'id',
				'isDraft',
				'date',
			] as $property
		) {
			/** @var scalar|array|object|resource|null */
			$headerPropertyValue = $header->$property;
			static::assertSame($headerPropertyValue, $mail->$property);
		}
	}

	public function test_data_part_info() : void
	{
		$mail = new IncomingMail();
		$mailbox = new Mailbox('', '', new HiddenString('', true, true));

		$data_part = new Fixtures\DataPartInfo($mailbox, 1, ENCOTHER, 'UTF-8', 0);
		$data_part->setData('foo');

		static::assertSame('foo', $data_part->fetch());

		$mail->addDataPartInfo($data_part, DataPartInfo::TEXT_PLAIN);

		static::assertSame('foo', $mail->textPlain);

		static::assertTrue($mail->__isset('textPlain'));
	}

	public function test_attachments() : void
	{
		$mail = new IncomingMail();

		static::assertFalse($mail->hasAttachments());
		static::assertSame([], $mail->getAttachments());

		$attachments = [
			new IncomingMailAttachment(),
		];

		foreach ($attachments as $i => $attachment) {
			$attachment->id = (string) $i;
			$mail->addAttachment($attachment);
		}

		static::assertTrue($mail->hasAttachments());
		static::assertSame($attachments, $mail->getAttachments());

		foreach ($attachments as $attachment) {
			static::assertIsString($attachment->id);
			static::assertTrue($mail->removeAttachment($attachment->id));
		}

		static::assertFalse($mail->hasAttachments());
		static::assertSame([], $mail->getAttachments());

		foreach ($attachments as $attachment) {
			static::assertIsString($attachment->id);
			static::assertFalse($mail->removeAttachment($attachment->id));
		}
	}
}
