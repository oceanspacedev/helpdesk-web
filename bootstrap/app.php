<?php

use App\Http\Middleware\VerifyHelpdeskMcpToken;
use App\Http\Middleware\VerifyHelpdeskStaffMcpToken;
use App\Support\SafeUploadedFile;
use BezhanSalleh\FilamentExceptions\FilamentExceptions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToRetrieveMetadata;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

        $middleware->alias([
            'helpdesk.mcp' => VerifyHelpdeskMcpToken::class,
            'helpdesk.staff.mcp' => VerifyHelpdeskStaffMcpToken::class,
        ]);

        $middleware->throttleApi('api');

        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
        $middleware->redirectUsersTo('/admin');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Browser asli tidak pernah mengubah properti #[Locked]; payload seperti ini
        // datang dari pemindai. Livewire sudah membalas 419, jadi tidak perlu dicatat.
        $exceptions->dontReport(CannotUpdateLockedPropertyException::class);

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
                'data.supporting_attachments' => SafeUploadedFile::MISSING_MESSAGE,
                'data.attachments' => SafeUploadedFile::MISSING_MESSAGE,
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
