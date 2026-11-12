<?php

namespace Modules\Addons\Invoicetronic;

use RuntimeException;

/** Invoicetronic could not be reached, or failed on its side: the next run tries again. */
class InvoicetronicUnavailable extends RuntimeException {}
