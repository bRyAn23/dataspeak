<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps every exception on /api/* to the error envelope and HTTP table of the Laravel API doc (Section 11).
 */
class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $message, $details, $headers] = match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, $e->getMessage(), $e->details, []],
            $e instanceof ValidationException => [422, 'VALIDATION_FAILED', 'The request could not be validated.', $this->validationDetails($e), []],
            $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Authentication required.', [], []],
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [403, 'FORBIDDEN', 'You are not allowed to access this resource.', [], []],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'NOT_FOUND', 'Resource not found.', [], []],
            $e instanceof MethodNotAllowedHttpException => [405, 'METHOD_NOT_ALLOWED', 'Method not allowed.', [], $e->getHeaders()],
            $e instanceof TooManyRequestsHttpException => [429, 'RATE_LIMITED', 'Too many requests. Please retry shortly.', [], $e->getHeaders()],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'HTTP_ERROR', $e->getMessage() ?: 'Request failed.', [], $e->getHeaders()],
            default => [500, 'INTERNAL_ERROR', 'Unexpected application error.', [], []],
        };

        $body = ['error' => ['code' => $code, 'message' => $message]];
        if ($details !== []) {
            $body['error']['details'] = $details;
        }
        if ($status === 500 && config('app.debug')) {
            $body['error']['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        return new JsonResponse($body, $status, $headers);
    }

    /** @return list<array{field: string, issue: string}> */
    private function validationDetails(ValidationException $e): array
    {
        $details = [];
        foreach ($e->errors() as $field => $issues) {
            foreach ($issues as $issue) {
                $details[] = ['field' => $field, 'issue' => $issue];
            }
        }

        return $details;
    }
}
