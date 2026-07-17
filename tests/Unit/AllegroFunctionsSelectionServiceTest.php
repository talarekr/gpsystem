<?php

namespace Tests\Unit;

use App\Services\Marketplace\AllegroFunctionsParameterService;
use App\Services\Marketplace\AllegroFunctionsSelectionService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class AllegroFunctionsSelectionServiceTest extends TestCase
{
    public function test_normalize_input_removes_duplicates_and_empty_values(): void
    {
        $service = new AllegroFunctionsSelectionService($this->createMock(AllegroFunctionsParameterService::class));
        $this->assertSame(['1', '2'], $service->normalizeInput(['1', '', 2, '1']));
    }

    public function test_nested_arrays_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $service = new AllegroFunctionsSelectionService($this->createMock(AllegroFunctionsParameterService::class));
        $service->normalizeInput([['nested']]);
    }
}
