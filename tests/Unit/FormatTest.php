<?php

namespace Tests\Unit;

use App\Support\Format;
use App\Support\Markdown;
use PHPUnit\Framework\TestCase;

/** Format angka di email harus sama dengan tampilan frontend (src/utils/formatters.ts) */
class FormatTest extends TestCase
{
    public function test_co2_switches_to_tonnes_from_one_thousand_kg(): void
    {
        $this->assertSame('462.00 kg CO₂e', Format::co2(462));
        $this->assertSame('999.99 kg CO₂e', Format::co2(999.99));
        $this->assertSame('1.00 ton CO₂e', Format::co2(1000));
        $this->assertSame('1234.57 ton CO₂e', Format::co2(1234567));
    }

    public function test_change_is_signed_with_one_decimal_comma(): void
    {
        $this->assertSame('+100,0%', Format::change(100));
        $this->assertSame('-25,5%', Format::change(-25.46));
        $this->assertSame('0,0%', Format::change(-0.04));
        $this->assertSame('+1.346,0%', Format::change(1346));
    }

    public function test_markdown_escape_keeps_table_cells_intact(): void
    {
        $this->assertSame('A \| B', Markdown::escape('A | B'));
    }
}
