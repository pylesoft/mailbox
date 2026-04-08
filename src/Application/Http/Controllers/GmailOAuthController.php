<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pyle\Mailbox\Services\OAuth\GmailUserOAuthService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class GmailOAuthController extends Controller
{
    public function __construct(
        private readonly GmailUserOAuthService $oauthService,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $connectionId = $request->integer('mailbox_connection_id');
        $returnTo = $request->query('return_to');
        $userReference = $request->query('user_reference');

        $url = $this->oauthService->authorizationUrl(
            connectionId: $connectionId > 0 ? $connectionId : null,
            returnTo: is_string($returnTo) ? $returnTo : null,
            userReference: is_string($userReference) ? $userReference : null,
        );

        return redirect()->away($url);
    }

    public function callback(Request $request): Response|RedirectResponse
    {
        $error = $request->query('error');
        $errorDescription = $request->query('error_description');
        $state = $request->query('state');

        if (is_string($error) && $error !== '') {
            return $this->failureResponse(
                error: $error,
                state: is_string($state) ? $state : null,
                status: 400,
                errorDescription: is_string($errorDescription) ? $errorDescription : null,
            );
        }

        $code = $request->query('code');

        if (! is_string($state) || $state === '' || ! is_string($code) || $code === '') {
            return $this->failureResponse(
                error: 'missing_code_or_state',
                state: is_string($state) ? $state : null,
                status: 422,
            );
        }

        try {
            $result = $this->oauthService->handleCallback($state, $code);
        } catch (Throwable $exception) {
            return $this->failureResponse(
                error: 'oauth_callback_failed',
                state: $state,
                status: 400,
                errorDescription: $exception->getMessage(),
            );
        }

        if ($result->returnTo !== null) {
            return redirect()->to($this->appendQuery(
                url: $result->returnTo,
                query: array_filter([
                    'mailbox_oauth' => 'success',
                    'mailbox_oauth_token_id' => (string) $result->token->id,
                    'user_reference' => $result->userReference,
                ], static fn (mixed $value): bool => is_string($value) && $value !== ''),
            ));
        }

        return response()->json([
            'success' => true,
            'token_id' => $result->token->id,
            'provider' => $result->token->provider,
            'email' => $result->token->email,
            'external_user_id' => $result->token->external_user_id,
            'expires_at' => $result->token->expires_at?->toIso8601String(),
        ]);
    }

    private function failureResponse(
        string $error,
        ?string $state,
        int $status,
        ?string $errorDescription = null,
    ): Response|RedirectResponse {
        $stateContext = is_string($state) && $state !== ''
            ? $this->oauthService->stateContext($state)
            : null;
        $returnTo = $stateContext['return_to'] ?? null;

        if (is_string($returnTo) && $returnTo !== '') {
            return redirect()->to($this->appendQuery(
                url: $returnTo,
                query: array_filter([
                    'mailbox_oauth' => 'error',
                    'mailbox_oauth_error' => $error,
                    'mailbox_oauth_error_description' => $errorDescription,
                    'user_reference' => $stateContext['user_reference'] ?? null,
                ], static fn (mixed $value): bool => is_string($value) && $value !== ''),
            ));
        }

        return response()->json([
            'success' => false,
            'error' => $error,
            'error_description' => $errorDescription,
        ], $status);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function appendQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }
}
