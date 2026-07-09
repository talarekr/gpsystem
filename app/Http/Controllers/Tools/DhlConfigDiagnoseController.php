<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceSyncLog;
use App\Models\Order;
use App\Services\Shipments\DhlShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class DhlConfigDiagnoseController extends Controller
{
    private const CODE_MARKER = 'dhl_response_parser_recovery_v1';

    public function __invoke(Request $request): JsonResponse
    {
        $endpoint = (string) config('services.dhl.endpoint', '');
        $login = config('services.dhl.login');
        $password = config('services.dhl.password');
        $accountNumber = config('services.dhl.account_number');
        $testMode = config('services.dhl.test_mode');
        $mode = $this->mode($testMode);
        $endpointClassification = $this->classifyEndpoint($endpoint);
        $lastError = $this->lastCreateShipmentError();
        $serviceSelection = $this->serviceSelection($request, $lastError);

        $missing = [];
        foreach ([
            'services.dhl.endpoint' => $endpoint,
            'services.dhl.login' => $login,
            'services.dhl.password' => $password,
            'services.dhl.account_number' => $accountNumber,
        ] as $key => $value) {
            if (blank($value)) {
                $missing[] = $key;
            }
        }

        $warnings = [];
        $modeMatchesEndpoint = null;
        if ($mode !== 'unknown' && $endpointClassification !== 'unknown') {
            $modeMatchesEndpoint = $mode === $endpointClassification;
            if (! $modeMatchesEndpoint) {
                $warnings[] = 'DHL runtime mode does not match the configured DHL endpoint classification.';
            }
        }
        if (blank($login) || blank($password)) {
            $warnings[] = 'DHL username/password are missing at runtime or resolved to blank values.';
        }
        if (blank($accountNumber)) {
            $warnings[] = 'DHL billing account number is missing at runtime.';
        }
        if (app()->configurationIsCached()) {
            $warnings[] = 'Laravel configuration is cached; clear config cache after changing .env values.';
        }

        return response()->json([
            'code_marker' => self::CODE_MARKER,
            'required_env_or_config_keys' => [
                'DHL_API_ENDPOINT',
                'DHL24_WSDL',
                'DHL_API_LOGIN',
                'DHL24_LOGIN',
                'DHL24_USERNAME',
                'DHL_API_PASSWORD',
                'DHL24_PASSWORD',
                'DHL_ACCOUNT_NUMBER',
                'DHL24_ACCOUNT_NUMBER',
                'DHL_TEST_MODE',
                'DHL24_MODE',
                'DHL_DEFAULT_SERVICE',
                'DHL24_DEFAULT_SERVICE_TYPE',
                'DHL24_DEFAULT_INTERNATIONAL_SERVICE_TYPE',
                'DHL_DEFAULT_INTERNATIONAL_SERVICE',
                'DHL_LABEL_TYPE',
                'DHL24_LABEL_TYPE',
                'DHL_DROP_OFF_TYPE',
                'DHL24_DEFAULT_DROP_OFF_TYPE',
                'config:services.dhl.endpoint',
                'config:services.dhl.login',
                'config:services.dhl.password',
                'config:services.dhl.account_number',
                'config:services.dhl.test_mode',
                'config:services.dhl.default_service',
                'config:services.dhl.default_international_service',
            ],
            'diagnostic_routes' => [
                'canonical' => url('/admin/tools/dhl/config-diagnose'),
                'orders_alias' => url('/admin/tools/orders/dhl-diagnose'),
                'canonical_route_name' => 'admin.tools.dhl.config-diagnose',
                'orders_alias_route_name' => 'admin.tools.orders.dhl-diagnose',
            ],
            'dhl_config_runtime' => [
                'enabled' => filled($endpoint) && filled($login) && filled($password) && filled($accountNumber),
                'mode' => $mode,
                'endpoint_present' => filled($endpoint),
                'endpoint_classification' => $endpointClassification,
                'username_present' => filled($login),
                'username_length' => is_string($login) ? mb_strlen($login) : null,
                'password_present' => filled($password),
                'password_length' => is_string($password) ? mb_strlen($password) : null,
                'account_number_present' => filled($accountNumber),
                'account_number' => filled($accountNumber) ? (string) $accountNumber : null,
                'costs_center_present' => filled(data_get($lastError, 'costs_center')),
                'costs_center' => data_get($lastError, 'costs_center'),
                'shipper_config_present' => filled(config('services.dhl.shipper')),
                'missing_runtime_config' => $missing,
            ],
            'auth_data_shape' => [
                'fields' => ['username', 'password'],
                'has_auth_data' => true,
                'username_present' => filled($login),
                'password_present' => filled($password),
                'account_number_in_auth' => false,
                'billing_account_number' => filled($accountNumber) ? (string) $accountNumber : null,
            ],
            'environment_consistency' => [
                'mode_matches_endpoint' => $modeMatchesEndpoint,
                'warnings' => $warnings,
            ],
            'config_source_notes' => [
                'uses_config_files' => true,
                'uses_env_directly_in_runtime' => false,
                'possible_config_cache_issue' => app()->configurationIsCached(),
                'notes' => [
                    'DHL values are loaded in config/services.php and consumed via config("services.dhl.*") in runtime code.',
                    'If .env was changed but config cache was not cleared, runtime may still use old DHL credentials.',
                    'billingAccountNumber is read from config services.dhl.account_number; costsCenter is read from the shipment form parcel.mpk, not from a DHL env key.',
                ],
            ],
            'dhl_service_selection' => $serviceSelection,
            'dhl_response_parse_diagnostics' => $this->responseParseDiagnostics($request),
            'last_dhl_create_shipment_error' => $lastError,
            'probable_causes' => $this->probableCauses($login, $password, $accountNumber, $modeMatchesEndpoint, $lastError),
            'what_user_should_check_in_env' => [
                'Check that all DHL credential variables used by the project exist and are not blank: DHL_API_LOGIN/DHL24_LOGIN/DHL24_USERNAME and DHL_API_PASSWORD/DHL24_PASSWORD.',
                'Check that DHL_API_ENDPOINT or DHL24_WSDL points to the same DHL environment as the credentials.',
                'Check that DHL_TEST_MODE/DHL24_MODE matches the endpoint environment.',
                'Check that DHL_ACCOUNT_NUMBER=2520734 or DHL24_ACCOUNT_NUMBER=2520734 is the correct billing account for these DHL credentials.',
                'Check that the shipment MPK/costsCenter value 1142 is valid for this DHL account if DHL requires it.',
                'For PL to non-PL shipments set DHL24_DEFAULT_INTERNATIONAL_SERVICE_TYPE (or DHL_DEFAULT_INTERNATIONAL_SERVICE) to an export service enabled on the DHL account, e.g. EK.',
                'After changing .env, run php artisan config:clear (or rebuild config cache) before retrying.',
                'Verify that test credentials are not used with a production endpoint, and production credentials are not used with a sandbox endpoint.',
            ],
        ]);
    }



    private function responseParseDiagnostics(Request $request): array
    {
        $orderId = $request->integer('order_id') ?: null;
        $dhl = app(DhlShipmentService::class);
        $log = $dhl->lastCreateShipmentLog($orderId);
        $response = $log ? data_get($log->payload ?? [], 'response') : null;
        $parsed = $dhl->parseCreateShipmentResponse($response);
        $shipment = $orderId ? \App\Models\Shipment::query()->where('order_id', $orderId)->where('carrier', 'dhl')->latest('id')->first() : null;
        $localLabelExists = $shipment?->label_path ? Storage::disk('local')->exists($shipment->label_path) : false;
        $hasResult = is_array($response) && array_key_exists('createShipmentResult', $response);
        $hasLabel = filled(data_get($response, 'createShipmentResult.label')) || filled(data_get($response, 'label'));
        $storedSanitized = $parsed['remote_created'] && filled(data_get($response, 'createShipmentResult.label.labelContent', data_get($response, 'labelContent'))) && ! $parsed['has_label_content'];
        $recoveryAvailable = (bool) ($parsed['remote_created'] && $parsed['has_label_content'] && ! $shipment && ! $localLabelExists);
        $warnings = [];
        if ($parsed['remote_created'] && ! $shipment) $warnings[] = 'DHL returned tracking and labelContent, but local persistence failed after parsing/validation.';
        $diagnostics = [
            'last_create_shipment_log_found' => (bool) $log,
            'last_create_shipment_log_id' => $log?->id,
            'last_create_shipment_status' => $log?->status,
            'response_has_createShipmentResult' => $hasResult,
            'response_tracking_number' => $parsed['tracking_number'],
            'response_package_tracking_number' => $parsed['package_tracking_number'],
            'response_has_label' => $hasLabel,
            'response_label_format' => $parsed['label_format'],
            'response_has_label_content' => $parsed['has_label_content'],
            'parser_currently_would_accept_response' => (bool) ($parsed['tracking_number'] && $parsed['has_label_content']),
            'local_shipment_exists' => (bool) $shipment,
            'local_label_exists' => $localLabelExists,
            'recovery_available' => $recoveryAvailable,
            'would_create_duplicate_if_clicked_again' => (bool) ($parsed['remote_created'] || $shipment || $localLabelExists),
            'warnings' => $warnings,
        ];
        if ($storedSanitized) {
            $diagnostics['recovery_available'] = false;
            $diagnostics['reason'] = 'Stored log response is sanitized and does not contain labelContent. Remote DHL shipment was created, but PDF cannot be recovered from local logs.';
        }
        return $diagnostics;
    }

    private function serviceSelection(Request $request, array $lastError): array
    {
        $orderId = $request->integer('order_id') ?: null;
        $order = $orderId ? Order::query()->find($orderId) : null;
        $dhl = app(DhlShipmentService::class);
        $form = $dhl->defaults($order);

        if (! $order && $orderId) {
            $diagnostics = $dhl->serviceSelectionDiagnostics($form, data_get($lastError, 'service_type'));
            $diagnostics['blocking_reasons'][] = 'Order '.$orderId.' was not found; using default DHL form values for diagnostics.';

            return $diagnostics;
        }

        return $dhl->serviceSelectionDiagnostics($form, data_get($lastError, 'service_type'));
    }

    private function mode(mixed $testMode): string
    {
        return is_bool($testMode) ? ($testMode ? 'sandbox' : 'production') : 'unknown';
    }

    private function classifyEndpoint(string $endpoint): string
    {
        $value = strtolower($endpoint);
        if ($value === '') {
            return 'unknown';
        }
        if (str_contains($value, 'sandbox') || str_contains($value, 'test')) {
            return 'sandbox';
        }
        if (str_contains($value, 'dhl') || str_contains($value, 'production') || str_contains($value, 'prod')) {
            return 'production';
        }
        return 'unknown';
    }

    private function lastCreateShipmentError(): array
    {
        $log = MarketplaceSyncLog::query()
            ->where('marketplace', 'dhl')
            ->where('action', 'createShipment')
            ->where('status', 'error')
            ->latest('created_at')
            ->first();

        if (! $log) {
            return ['found' => false];
        }

        $request = Arr::get($log->payload ?? [], 'request', []);

        return [
            'found' => true,
            'attempted_at' => optional($log->created_at)->format('Y-m-d H:i:s'),
            'message' => $log->message,
            'duration_ms' => $log->duration_ms,
            'service_type' => data_get($request, 'shipment.shipmentInfo.serviceType'),
            'receiver_country' => data_get($request, 'shipment.ship.receiver.address.country'),
            'billing_account_number' => data_get($request, 'shipment.shipmentInfo.billing.billingAccountNumber'),
            'costs_center' => data_get($request, 'shipment.shipmentInfo.billing.costsCenter'),
            'request_had_auth_data' => array_key_exists('authData', (array) $request),
            'response_was_null' => Arr::get($log->payload ?? [], 'response') === null,
        ];
    }

    private function probableCauses(mixed $login, mixed $password, mixed $accountNumber, ?bool $modeMatchesEndpoint, array $lastError): array
    {
        $causes = [];
        if (blank($login) || blank($password)) {
            $causes[] = 'brak credentiali DHL w runtime';
        }
        if ($modeMatchesEndpoint === false) {
            $causes[] = 'zły endpoint/mode DHL';
        }
        if (blank($accountNumber)) {
            $causes[] = 'brak numeru konta DHL w runtime';
        }
        if (str_contains(strtolower((string) ($lastError['message'] ?? '')), 'autoryz')) {
            $causes[] = 'złe credentiale DHL albo credentiale z innego środowiska niż endpoint';
        }
        if (app()->configurationIsCached()) {
            $causes[] = 'cache configu Laravel może wskazywać stare wartości .env';
        }

        return array_values(array_unique($causes));
    }
}
