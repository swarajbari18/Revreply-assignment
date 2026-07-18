<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ConnectedAccount;
use App\Services\Gmail\GmailClientFactory;
use App\Services\Gmail\GmailOAuthService;
use App\Services\Gmail\GmailTokenService;
use App\Services\Gmail\GmailWatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GmailOAuthController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        $userId = (int) $request->query('user_id', '1');
        $oauthService = new GmailOAuthService(GmailClientFactory::make());

        return redirect($oauthService->getAuthUrl($userId));
    }

    public function callback(Request $request): RedirectResponse
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        if ($request->has('error')) {
            Log::warning('Gmail OAuth denied', ['error' => $request->query('error')]);

            return redirect($frontendUrl.'?error=oauth_denied');
        }

        $code = $request->query('code');
        $state = $request->query('state');

        if (empty($code) || empty($state)) {
            return redirect($frontendUrl.'?error=missing_params');
        }

        try {
            $client = GmailClientFactory::make();
            $oauthService = new GmailOAuthService($client);
            $account = $oauthService->handleCallback($code, $state);

            $watchService = new GmailWatchService($client, new GmailTokenService($client));
            $watchService->watch($account);

            return redirect($frontendUrl.'?connected=1');
        } catch (\Throwable $e) {
            Log::error('Gmail OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect($frontendUrl.'?error=oauth_failed');
        }
    }

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->query('user_id', '1');
        $accounts = ConnectedAccount::where('user_id', $userId)->get();

        return response()->json($accounts);
    }

    public function destroy(int $id): JsonResponse
    {
        $account = ConnectedAccount::findOrFail($id);
        $account->markDisconnected();

        return response()->json(['message' => 'Account disconnected successfully']);
    }
}
