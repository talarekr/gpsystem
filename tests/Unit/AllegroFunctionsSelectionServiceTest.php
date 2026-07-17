<?php

namespace Tests\Unit;

use App\Services\Marketplace\AllegroFunctionsParameterService;
use App\Services\Marketplace\AllegroFunctionsSelectionService;
use App\Services\Marketplace\AllegroManualParameterSelectionService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class AllegroFunctionsSelectionServiceTest extends TestCase
{
    public function test_normalize_input_removes_duplicates_and_empty_values(): void
    {
        $service = new AllegroFunctionsSelectionService($this->createMock(AllegroFunctionsParameterService::class), new AllegroManualParameterSelectionService());
        $this->assertSame(['1', '2'], $service->normalizeInput(['1', '', 2, '1']));
    }

    public function test_nested_arrays_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $service = new AllegroFunctionsSelectionService($this->createMock(AllegroFunctionsParameterService::class), new AllegroManualParameterSelectionService());
        $service->normalizeInput([['nested']]);
    }
}
