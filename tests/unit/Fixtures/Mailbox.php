<?php
/**
* @author BAPCLTD-Marv
*/
declare(strict_types=1);

namespace PhpImap\Fixtures;

use ParagonIE\HiddenString\HiddenString;
use PhpImap\Mailbox as Base;

class Mailbox extends Base
{
	public function getImapPassword(): HiddenString
	{
		return $this->imapPassword;
	}
}
