<?php
/**
 * @author BAPCLTD-Marv
 */
declare(strict_types=1);

namespace PhpImap\Pagination;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use OutOfBoundsException;
use PhpImap\Mailbox;
use SeekableIterator;

/**
 * @template-implements SeekableIterator<int, IncomingMailLimitIterator>
 */
class IncomingMailSeekableIterator implements Countable, SeekableIterator
{
    private Mailbox $mailbox;

    /** @var bool */
    private $markAsSeen;

    /** @var int */
    private $pageSize;

    /** @var ArrayIterator<int, int> */
    private ArrayIterator $mailIds;

    /** @var int */
    private $page = 0;

    /**
     * @param list<int> $mailIds
     */
    public function __construct(
        Mailbox $mailbox,
        bool $markAsSeen,
        int $pageSize,
        array $mailIds
    ) {
        if ($pageSize < 1) {
            throw new InvalidArgumentException('Argument 3 passed to '.__METHOD__.'() must be greater than zero, '.(string) $pageSize.' given!');
        }

        $this->mailbox = $mailbox;
        $this->markAsSeen = $markAsSeen;
        $this->pageSize = $pageSize;
        $this->mailIds = new ArrayIterator($mailIds);
    }

    public function count(): int
    {
        return (int) \ceil(\count($this->mailIds) / $this->pageSize);
    }

    public function countMailIds(): int
    {
        return \count($this->mailIds);
    }

    public function key(): int
    {
        return $this->page;
    }

    public function seek(int $page): void
    {
        if ($page < 0) {
            throw new InvalidArgumentException('Argument 1 passed to '.__METHOD__.'() must be zero or greater, '.(string) $page.' given!');
        } elseif ($page >= $this->count()) {
            throw new OutOfBoundsException('Argument 1 passed to '.__METHOD__.'() must be in the range 0-'.(string) $this->count().', '.(string) $page.' given!');
        }

        $this->page = $page;
    }

    public function valid(): bool
    {
        return $this->page >= 0 && $this->page < $this->count();
    }

    public function next(): void
    {
        ++$this->page;
    }

    public function rewind(): void
    {
        $this->page = 0;
    }

    public function current(): IncomingMailLimitIterator
    {
        $iterator = new IncomingMailLimitIterator(
            $this->mailIds,
            $this->page * $this->pageSize
        );

        $iterator->SetMailbox($this->mailbox, $this->markAsSeen);

        return $iterator;
    }
}
