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
        $result = $this->sanitizer->sanitizeForEbayDe($part, 'Volkswagen Multivan T5 Baujahr 2012 2.0 kompletter Motor Zustand perfekt CCH mit sehr langem Zusatztext');

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

    public function test_part_7843_short_translated_title_is_not_blocked_or_sanitized(): void
    {
        $part = new Part(['name' => 'VOLKSWAGEN Tiguan 2018 2.0 KOMPLETNY DPF SPRAWNY 03N131656G', 'part_number' => '03N131656G']);
        $title = 'Volkswagen Tiguan 2018 2.0 Komplett DPF funktionsfähig 03N131656G';
        $result = $this->sanitizer->sanitizeForEbayDe($part, $title);

        $this->assertSame($title, $result['final_title']);
        $this->assertSame(65, $result['diagnostics']['final_length']);
        $this->assertNull($result['blocker']);
        $this->assertTrue($result['diagnostics']['protected_tokens_preserved']);
        $this->assertSame(['03N131656G', 'DPF'], $result['diagnostics']['protected_tokens']);
        $this->assertSame([], $result['diagnostics']['removed_tokens']);
        $this->assertFalse($result['diagnostics']['cleanup_applied']);
        $this->assertTrue($result['diagnostics']['minimal_cleanup_only']);
    }

    public function test_payload_title_value_can_use_exact_sanitized_final_title(): void
    {
        $part = new Part(['name' => 'VOLKSWAGEN Multivan T5 2012 2.0 KOMPLETNY SILNIK STAN PERFECT CCH']);
        $result = $this->sanitizer->sanitizeForEbayDe($part, 'Volkswagen Multivan T5 Baujahr 2012 2.0 kompletter Motor Zustand perfekt CCH mit sehr langem Zusatztext');
        $payload = ['product' => ['title' => $result['final_title']]];

        $this->assertSame($result['final_title'], $payload['product']['title']);
    }
}
