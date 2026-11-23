<?php

namespace Modules\Addons\OpenApiCompany;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OpenApiCompanyController extends Controller
{
    /** One VAT number looked up from the addon page, to try the token. */
    public function lookup(Request $request, CompanyRegister $register): RedirectResponse
    {
        $vatCode = CompanyRegister::normalise((string) $request->validate(['vat_code' => ['required', 'string', 'max:20']])['vat_code']);
        if (! CompanyRegister::isValidVat($vatCode)) {
            return back()->with('error', __('messages.openapi_company.invalid_vat'));
        }

        try {
            $company = $register->find($vatCode, 'admin');
        } catch (OpenApiCompanyUnavailable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('openapi_company_result', $company?->toArray() ?? ['vatCode' => $vatCode, 'missing' => true]);
    }
}
