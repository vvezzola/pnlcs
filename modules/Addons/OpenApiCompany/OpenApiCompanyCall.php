<?php

namespace Modules\Addons\OpenApiCompany;

use Illuminate\Database\Eloquent\Model;

/** One paid call to OpenAPI's Company service. */
class OpenApiCompanyCall extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'openapi_company_calls';

    protected $fillable = ['endpoint', 'vat_code', 'status', 'purpose'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'status' => 'integer'];
    }
}
