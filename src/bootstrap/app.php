<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // prepareException() converts ModelNotFoundException → NotFoundHttpException
        // before custom renderers run, so we check the chained previous exception.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                $previous = $e->getPrevious();
                $message = $previous instanceof ModelNotFoundException
                    ? class_basename($previous->getModel()).' not found.'
                    : 'Not found.';

                return response()->json(['message' => $message], 404);
            }
        });
    })->create();
