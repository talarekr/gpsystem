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

    public function test_short_translated_title_is_used_without_changes(): void
    {
        $part = new Part(['name' => 'Audi A4 8K gałka zmiany biegów 8K1713041AN']);
        $title = 'Audi A4 8K Schaltknauf 8K1713041AN';

        $result = $this->sanitizer->sanitizeForEbayDe($part, $title);

        $this->assertSame($title, $result['final_title']);
        $this->assertSame($title, $result['translated_title']);
        $this->assertNull($result['suggested_short_title']);
        $this->assertTrue($result['ok']);
        $this->assertNull($result['blocker']);
        $this->assertFalse($result['diagnostics']['requires_manual_review']);
        $this->assertFalse($result['diagnostics']['title_was_shortened']);
    }

    public function test_long_translated_title_is_not_silently_replaced_by_shortened_title(): void
    {
        $part = new Part(['name' => 'VOLKSWAGEN Multivan T5 2012 2.0 KOMPLETNY SILNIK STAN PERFECT CCH']);
        $title = 'Volkswagen Multivan T5 Baujahr 2012 2.0 kompletter Motor Zustand perfekt CCH mit sehr langem Zusatztext';

        $result = $this->sanitizer->sanitizeForEbayDe($part, $title);

        $this->assertSame($title, $result['final_title']);
        $this->assertSame($title, $result['translated_title']);
        $this->assertGreaterThan(EbayTitleSanitizer::LIMIT, mb_strlen($result['final_title']));
        $this->assertSame('ebay_title_needs_review', $result['blocker']);
        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['suggested_short_title']);
        $this->assertLessThanOrEqual(EbayTitleSanitizer::LIMIT, mb_strlen($result['suggested_short_title']));
        $this->assertTrue($result['diagnostics']['requires_manual_review']);
        $this->assertTrue($result['diagnostics']['title_was_shortened']);
        $this->assertNotSame($result['suggested_short_title'], $result['final_title']);
    }

    public function test_only_whitespace_is_normalized_for_translated_title(): void
    {
        $part = new Part(['name' => 'Polski tytuł']);
        $result = $this->sanitizer->sanitizeForEbayDe($part, "Deutscher   Titel\n1:1");

        $this->assertSame('Deutscher Titel 1:1', $result['final_title']);
        $this->assertTrue($result['diagnostics']['cleanup_applied']);
        $this->assertSame([], $result['diagnostics']['removed_tokens']);
        $this->assertTrue($result['diagnostics']['minimal_cleanup_only']);
    }
}
