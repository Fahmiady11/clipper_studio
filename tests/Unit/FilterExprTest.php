<?php

namespace Tests\Unit;

use App\Support\FilterExpr;
use PHPUnit\Framework\TestCase;

class FilterExprTest extends TestCase
{
    public function test_sum_nests_terms_as_a_balanced_tree(): void
    {
        $this->assertSame('0', FilterExpr::sum([]));
        $this->assertSame('a', FilterExpr::sum(['a']));
        $this->assertSame('a+b', FilterExpr::sum(['a', 'b']));
        $this->assertSame('a+(b+c)', FilterExpr::sum(['a', 'b', 'c']));
        $this->assertSame('(a+b)+(c+d)', FilterExpr::sum(['a', 'b', 'c', 'd']));
        $this->assertSame('((a+b)+(c+d))+((e+f)+(g+h))', FilterExpr::sum(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']));
    }
}
