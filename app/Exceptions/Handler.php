<?php

namespace App\Exceptions;

use BezhanSalleh\FilamentExceptions\FilamentExceptions;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            if ($this->shouldReport($e)) {
                FilamentExceptions::report($e);
            }
        });
    }

    /**
     * Render integration API errors without exposing debug internals.
     */
    public function render($request, Throwable $e)
    {
        if (
            $e instanceof MethodNotAllowedHttpException
            && $request->is('api/integrations/whatsapp/*')
        ) {
            $message = 'Metode HTTP tidak didukung untuk endpoint integrasi WhatsApp.';

            return response()->json([
                'ok' => false,
                'status' => 'validation_error',
                'result_status' => 'validation_error',
                'message' => $message,
                'data' => null,
                'count' => 0,
                'error' => [
                    'code' => 'method_not_allowed',
                    'message' => $message,
                    'allowed_methods' => $e->getHeaders()['Allow'] ?? null,
                ],
            ], 405);
        }

        return parent::render($request, $e);
    }
}
