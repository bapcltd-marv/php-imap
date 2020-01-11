<?php

namespace PhpImap\Fixtures;

use PhpImap\Mailbox as Base;

class Mailbox extends Base
{
    public function getImapPassword()
    {
        return $this->imapPassword;
    }
}
