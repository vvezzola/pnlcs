<?php

namespace Modules\Addons\OpenApiCompany;

use RuntimeException;

/** OpenAPI could not be reached, refused the token, or failed on its side. */
class OpenApiCompanyUnavailable extends RuntimeException {}
