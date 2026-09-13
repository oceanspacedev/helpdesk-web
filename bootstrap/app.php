<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\ValidateSignature;
use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Middleware\VerifyHelpdeskMcpToken;
use App\Http\Middleware\VerifyHelpdeskStaffMcpToken;
use BezhanSalleh\FilamentExceptions\FilamentExceptions;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToRetrieveMetadata;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->use([
            TrustProxies::class,
            HandleCors::class,
            PreventRequestsDuringMaintenance::class,
            ValidatePostSize::class,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
        ]);

        $middleware->web(replace: [
            Illuminate\Cookie\Middleware\EncryptCookies::class => EncryptCookies::class,
            ValidateCsrfToken::class => VerifyCsrfToken::class,
        ]);

        $middleware->alias([
            'auth' => Authenticate::class,
            'auth.basic' => AuthenticateWithBasicAuth::class,
            'cache.headers' => SetCacheHeaders::class,
            'can' => Authorize::class,
            'guest' => RedirectIfAuthenticated::class,
            'password.confirm' => RequirePassword::class,
            'signed' => ValidateSignature::class,
            'throttle' => ThrottleRequests::class,
            'verified' => EnsureEmailIsVerified::class,
            'helpdesk.mcp' => VerifyHelpdeskMcpToken::class,
            'helpdesk.staff.mcp' => VerifyHelpdeskStaffMcpToken::class,
        ]);

        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportWhen(function (Throwable $e): bool {
            return $e instanceof UnableToRetrieveMetadata
                && str_contains($e->getMessage(), 'livewire-tmp');
        });

        $exceptions->reportable(function (Throwable $e) use ($exceptions): void {
            if ($exceptions->handler->shouldReport($e)) {
                FilamentExceptions::report($e);
            }
        });

        $exceptions->render(function (UnableToRetrieveMetadata $exception, Request $request) {
            if (! str_contains($exception->getMessage(), 'livewire-tmp')) {
                return null;
            }

            throw ValidationException::withMessages([
                'data.supporting_attachments' => \App\Support\SafeUploadedFile::MISSING_MESSAGE,
                'data.attachments' => \App\Support\SafeUploadedFile::MISSING_MESSAGE,
            ]);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $exception, Request $request) {
            if (! $request->is('mcp/*')) {
                return null;
            }

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $request->input('id'),
                'error' => [
                    'code' => -32600,
                    'message' => 'Metode HTTP tidak didukung untuk endpoint MCP ini.',
                    'data' => [
                        'allowed_methods' => 'POST',
                    ],
                ],
            ], 405, ['Allow' => 'POST']);
        });
    })
    ->create();
