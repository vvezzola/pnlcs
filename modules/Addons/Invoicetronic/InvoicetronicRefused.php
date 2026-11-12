<?php

namespace Modules\Addons\Invoicetronic;

use RuntimeException;

/** Invoicetronic refused the file: sending it again unchanged would be refused again. */
class InvoicetronicRefused extends RuntimeException {}
