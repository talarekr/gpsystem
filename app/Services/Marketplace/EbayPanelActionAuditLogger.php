<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class EbayPanelActionAuditLogger
{
    public function started(Part $part, string $selectedStep, array $context = []): void
    {
        $this->write('ebay_panel_action_started', $part, 'running', $context + $this->baseContext($selectedStep));
    }

    public function step(Part $part, string $step, array $context = []): void
    {
        $this->write('ebay_panel_action_step', $part, 'running', $context + ['step' => $step] + $this->baseContext($context['selected_step'] ?? null));
    }

    public function completed(Part $part, string $selectedStep, array $context = []): void
    {
        $this->step($part, 'completed', $context + ['selected_step' => $selectedStep, 'status' => 'completed']);
    }

    public function failed(Part $part, Throwable $exception, string $selectedStep, array $context = []): void
    {
        $failure = $this->exceptionContext($exception) + $context + $this->baseContext($selectedStep);
        $this->write('ebay_panel_action_failed', $part, 'error', $failure, $failure['http_status'] ?? null, $exception->getMessage());
    }

    private function write(string $action, Part $part, string $status, array $payload, ?int $httpStatus = null, ?string $message = null): void
    {
        $payload = ['action' => $action, 'part_id' => $part->id, 'channel' => $payload['channel'] ?? 'ebay', 'timestamp' => now()->toISOString()] + $payload;

        Log::info($action, $payload);

        if (! Schema::hasTable('marketplace_sync_logs')) {
            return;
        }

        MarketplaceSyncLog::query()->create([
            'marketplace' => (string) ($payload['channel'] ?? 'ebay'),
            'part_id' => $part->id,
            'action' => $action,
            'status' => $payload['status'] ?? $status,
            'http_status' => $httpStatus,
            'message' => $message,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    private function baseContext(?string $selectedStep): array
    {
        $route = request()?->route();
        $action = $route?->getActionName();

        return [
            'channel' => 'ebay',
            'user_id' => auth()->id(),
            'route' => $route?->getName() ?? Route::currentRouteName(),
            'route_action' => $action,
            'class' => is_string($action) && str_contains($action, '@') ? str($action)->before('@')->toString() : null,
            'method' => is_string($action) && str_contains($action, '@') ? str($action)->after('@')->toString() : null,
            'selected_step' => $selectedStep,
        ];
    }

    private function exceptionContext(Throwable $exception): array
    {
        $context = [
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'trace_first_5' => array_slice($exception->getTrace(), 0, 5),
            'source' => $this->isEbayException($exception) ? 'ebay_api' : 'local_app',
        ];

        if ($exception instanceof RequestException && $exception->response !== null) {
            $context['http_status'] = $exception->response->status();
            $context['response_body'] = $exception->response->body();
            if (method_exists($exception->response, 'effectiveUri')) {
                $context['endpoint_url'] = (string) $exception->response->effectiveUri();
            }
        } elseif ($exception instanceof HttpExceptionInterface) {
            $context['http_status'] = $exception->getStatusCode();
        } elseif ((int) $exception->getCode() === 403 || str_contains($exception->getMessage(), 'HTTP 403')) {
            $context['http_status'] = 403;
        }

        return $context;
    }

    private function isEbayException(Throwable $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'ebay')
            || collect($exception->getTrace())->contains(fn (array $frame): bool => str_contains(strtolower((string) ($frame['class'] ?? '').($frame['file'] ?? '')), 'ebay'));
    }
}
