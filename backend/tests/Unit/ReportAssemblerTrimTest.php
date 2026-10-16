<?php

namespace Tests\Unit;

use App\Services\Reports\ReportAssembler;
use PHPUnit\Framework\TestCase;

/** DEF-02: repeated headline sentence inside a chart explanation. */
class ReportAssemblerTrimTest extends TestCase
{
    public function test_leading_repeated_sentence_is_removed(): void
    {
        $head = 'Average Units Sold was +75.5% higher for North than for the lowest Region group.';
        $second = $head.' The difference in Units Sold between Region groups is statistically meaningful, not a coincidence.';

        $this->assertSame('The difference in Units Sold between Region groups is statistically meaningful, not a coincidence.', ReportAssembler::trimRepeatedLead($second, [$head]));
    }

    public function test_unrelated_and_identical_texts_are_left_alone(): void
    {
        $this->assertSame('North accounted for 55.1% of Units Sold.', ReportAssembler::trimRepeatedLead('North accounted for 55.1% of Units Sold.', ['Average Units Sold was higher for North.']));
        $this->assertSame('Same sentence.', ReportAssembler::trimRepeatedLead('Same sentence.', ['Same sentence.']));
    }

    public function test_partial_word_overlap_does_not_trim(): void
    {
        // The prior text is a prefix of a longer word sequence but the remainder does not start a new sentence.
        $this->assertSame('Revenue rose sharply in the north.', ReportAssembler::trimRepeatedLead('Revenue rose sharply in the north.', ['Revenue rose']));
    }
}
