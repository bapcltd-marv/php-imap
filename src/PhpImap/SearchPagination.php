<?php
/**
 * @author BAPCLTD-Marv
 */
declare(strict_types=1);

namespace PhpImap;

class SearchPagination
{
    public int $pageSize;

    public bool $disableServerEncoding;

    public bool $markAsSeen;

    private Mailbox $mailbox;

    public function __construct(
        Mailbox $mailbox,
        int $pageSize,
        bool $disableServerEncoding = false,
        bool $markAsSeen = false
    ) {
        $this->mailbox = $mailbox;
        $this->pageSize = $pageSize;
        $this->disableServerEncoding = $disableServerEncoding;
        $this->markAsSeen = $markAsSeen;
    }

    public function searchMailbox(
        string $criteria = 'ALL'
    ): Pagination\IncomingMailSeekableIterator {
        return $this->PaginateIncomingMail(
            $this->mailbox->searchMailbox(
                $criteria,
                $this->disableServerEncoding
            )
        );
    }

    public function searchMailboxFrom(
        string $criteria,
        string $sender,
        string ...$senders
    ): Pagination\IncomingMailSeekableIterator {
        if ($this->disableServerEncoding) {
            $mailIds = $this->mailbox->searchMailboxFromDisableServerEncoding(
                $criteria,
                $sender,
                ...$senders
            );
        } else {
            $mailIds = $this->mailbox->searchMailboxFrom(
                $criteria,
                $sender,
                ...$senders
            );
        }

        return $this->PaginateIncomingMail($mailIds);
    }

    public function searchMailboxMergeResults(
        string $single_criteria,
        string ...$criteria
    ): Pagination\IncomingMailSeekableIterator {
        if ($this->disableServerEncoding) {
            $mailIds = $this->mailbox->searchMailboxMergeResultsDisableServerEncoding(
                $single_criteria,
                ...$criteria
            );
        } else {
            $mailIds = $this->mailbox->searchMailboxMergeResults(
                $single_criteria,
                ...$criteria
            );
        }

        return $this->PaginateIncomingMail($mailIds);
    }

    /**
     * @param list<int> $mailIds
     */
    private function PaginateIncomingMail(
        array $mailIds
    ): Pagination\IncomingMailSeekableIterator {
        return new Pagination\IncomingMailSeekableIterator(
            $this->mailbox,
            $this->markAsSeen,
            $this->pageSize,
            $mailIds
        );
    }
}
