<?php

namespace Tests\Unit;

use App\Models\Part;
use App\Services\Marketplace\EbayTitleSanitizer;
use PHPUnit\Framework\TestCase;

class EbayTitleSanitizerTest extends TestCase
{
    private EbayTitleSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new EbayTitleSanitizer();
    }

    public function test_removes_unwanted_baujahr_and_preserves_year_and_engine_code(): void
    {
        $part = new Part(['name' => 'VOLKSWAGEN Multivan T5 2012 2.0 KOMPLETNY SILNIK STAN PERFECT CCH']);
        $result = $this->sanitizer->sanitizeForEbayDe($part, 'Volkswagen Multivan T5 Baujahr 2012 2.0 kompletter Motor Zustand perfekt CCH');

        $this->assertLessThanOrEqual(80, mb_strlen($result['final_title']));
        $this->assertStringNotContainsString('Baujahr', $result['final_title']);
        $this->assertStringContainsString('2012', $result['final_title']);
        $this->assertStringContainsString('CCH', $result['final_title']);
        $this->assertTrue($result['diagnostics']['protected_tokens_preserved']);
    }

    public function test_keeps_year_label_when_polish_title_explicitly_mentions_production_year(): void
    {
        $part = new Part(['name' => 'VOLKSWAGEN Multivan T5 rok produkcji 2012 silnik CCH']);
        $result = $this->sanitizer->sanitizeForEbayDe($part, 'Volkswagen Multivan T5 Baujahr 2012 Motor CCH');

        $this->assertLessThanOrEqual(80, mb_strlen($result['final_title']));
        $this->assertStringContainsString('Baujahr 2012', $result['final_title']);
        $this->assertTrue($result['diagnostics']['protected_tokens_preserved']);
    }

    public function test_oem_code_is_not_cut_by_trimming(): void
    {
        $part = new Part(['name' => 'AUDI A4 licznik 5NA920791B bardzo długi opis elementu wyposażenia samochodu', 'part_number' => '5NA920791B']);
        $result = $this->sanitizer->sanitizeForEbayDe($part, 'Audi A4 Kombiinstrument sehr guter Zustand perfekter Zustand langer Beschreibungstext 5NA920791B');

        $this->assertLessThanOrEqual(80, mb_strlen($result['final_title']));
        $this->assertStringContainsString('5NA920791B', $result['final_title']);
        $this->assertTrue($result['diagnostics']['protected_tokens_preserved']);
    }

    public function test_short_clean_title_is_not_destroyed(): void
    {
        $part = new Part(['name' => 'Audi A4 8K Schaltknauf 8K1713041AN']);
        $title = 'Audi A4 8K Schaltknauf 8K1713041AN';
        $result = $this->sanitizer->sanitizeForEbayDe($part, $title);

        $this->assertSame($title, $result['final_title']);
        $this->assertFalse($result['diagnostics']['cleanup_applied']);
    }

    public function test_payload_title_value_can_use_exact_sanitized_final_title(): void
    {
        $part = new Part(['name' => 'VOLKSWAGEN Multivan T5 2012 2.0 KOMPLETNY SILNIK STAN PERFECT CCH']);
        $result = $this->sanitizer->sanitizeForEbayDe($part, 'Volkswagen Multivan T5 Baujahr 2012 2.0 kompletter Motor Zustand perfekt CCH');
        $payload = ['product' => ['title' => $result['final_title']]];

        $this->assertSame($result['final_title'], $payload['product']['title']);
    }
}
