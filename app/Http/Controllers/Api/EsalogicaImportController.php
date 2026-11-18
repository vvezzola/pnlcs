<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Esalogica only: the import and billing-simulation commands, reachable
 * through the API so they can be run without a shell on the server.
 *
 * Not in ApiPermissionMap, so only a full administrator's credential may
 * call them. The sheet travels in the request and lives in a temporary file
 * for the length of the call; nothing of it stays on the server. The import
 * writes only with write=1; the simulation never writes.
 */
class EsalogicaImportController extends BaseApiController
{
    public function importAbbonamenti(Request $request): JsonResponse
    {
        $data = $request->validate([
            'csv' => 'required|string|max:2000000',
            'write' => 'nullable|boolean',
        ]);

        $file = tempnam(sys_get_temp_dir(), 'esa-import-');
        try {
            file_put_contents($file, $data['csv']);
            $code = Artisan::call('esalogica:import-abbonamenti', ['file' => $file, '--write' => (bool) ($data['write'] ?? false)]);
            $output = Artisan::output();
        } finally {
            @unlink($file);
        }

        return $code === 0
            ? $this->success(['written' => (bool) ($data['write'] ?? false), 'output' => $output])
            : $this->error(trim($output) ?: 'Import refused', 422);
    }

    public function simulaFatture(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d']);

        $file = tempnam(sys_get_temp_dir(), 'esa-sim-');
        try {
            Artisan::call('esalogica:simula-fatture', array_filter(['--date' => $data['date'] ?? null, '--out' => $file]));
            $output = Artisan::output();
            $csv = (string) file_get_contents($file);
        } finally {
            @unlink($file);
        }

        return $this->success(['output' => $output, 'csv' => $csv]);
    }
}
