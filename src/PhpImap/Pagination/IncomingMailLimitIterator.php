<?php
/**
 * @author BAPCLTD-Marv
 */
declare(strict_types=1);

namespace PhpImap\Pagination;

use ArrayIterator;
use Iterator;
use LimitIterator as Base;
use OutOfBoundsException;
use PhpImap\IncomingMail;
use PhpImap\Mailbox;
use RuntimeException;

class IncomingMailLimitIterator extends Base
{
    private ?Mailbox $mailbox = null;

    private bool $markAsSeen = false;

    /**
     * @param ArrayIterator<int, int> $iterator
     */
    public function __construct(
        Iterator $iterator,
        int $offset = 0,
        int $count = -1
    ) {
        parent::__construct($iterator, $offset, $count);
    }

    public function SetMailbox(Mailbox $mailbox, bool $markAsSeen): void
    {
        if (isset($this->mailbox)) {
            throw new RuntimeException('Mailbox already set!');
        }

        $this->mailbox = $mailbox;
        $this->markAsSeen = $markAsSeen;
    }

    public function current(): IncomingMail
    {
        if (!isset($this->mailbox)) {
            throw new RuntimeException('Mailbox not set!');
        }

        /** @var int|null */
        $id = parent::current();

        if (!\is_int($id)) {
            throw new OutOfBoundsException('No mail at index '.(string) $this->key());
        }

        return $this->mailbox->getMail($id, $this->markAsSeen);
    }
}
