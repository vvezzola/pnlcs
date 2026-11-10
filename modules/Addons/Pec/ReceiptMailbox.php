<?php

namespace Modules\Addons\Pec;

/** Where the PEC provider leaves its receipts: the sender's own mailbox. */
interface ReceiptMailbox
{
    public function open(): void;

    /** @return list<int> */
    public function search(string $header, string $value): array;

    public function fetch(int $uid): string;

    public function close(): void;
}
