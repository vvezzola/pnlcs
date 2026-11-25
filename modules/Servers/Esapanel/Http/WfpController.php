<?php

namespace Modules\Servers\Esapanel\Http;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Servers\Esapanel\EsapanelModule;
use Modules\Servers\Esapanel\WfpPart;

/**
 * The customer's WFP accounts, from their service page: a new account, a new
 * password, suspend and resume. A password made is shown once, on the page
 * the customer is sent back to, and kept nowhere.
 */
class WfpController extends Controller
{
    use ResolvesClient;

    public function store(Request $request, Service $service): RedirectResponse
    {
        $this->authorizeService($service);

        // Esapanel's own rule for an account name, so the customer hears it here.
        $request->merge(['name' => strtolower(trim((string) $request->input('name')))]);
        $input = $request->validate([
            'name' => ['required', 'string', 'regex:'.WfpPart::NAME_PATTERN],
            'kind' => ['required', Rule::in(WfpPart::KINDS)],
            'protocols' => ['required', 'array', 'min:1'],
            'protocols.*' => [Rule::in(WfpPart::PROTOCOLS)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->answer($service, (new EsapanelModule)->wfpAction($service, 'create', $input));
    }

    public function act(Service $service, int $account, string $action): RedirectResponse
    {
        $this->authorizeService($service);

        return $this->answer($service, (new EsapanelModule)->wfpAction($service, $action, ['account' => $account]));
    }

    /** The customer's own service, of this module, running. */
    private function authorizeService(Service $service): void
    {
        abort_if($service->client_id !== $this->getClientId(), 403);
        abort_if($service->server?->type !== EsapanelModule::NAME, 404);
        abort_if(strtolower((string) $service->status) !== 'active', 403, __('client.services.suspended_for_action'));
    }

    /** @param  array{success: bool, message: string, data?: array<string, mixed>}  $result */
    private function answer(Service $service, array $result): RedirectResponse
    {
        $back = redirect()->to(route('client.services.show', $service).'#esapanel-service');

        if (! $result['success']) {
            return $back->with('error', $result['message']);
        }

        if (! empty($result['data']['password'])) {
            $back->with('esapanel_password', [
                'username' => (string) ($result['data']['username'] ?? ''),
                'password' => (string) $result['data']['password'],
            ]);
        }

        return $back->with('success', $result['message']);
    }
}
