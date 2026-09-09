<?php

namespace Tests\Unit;

use App\Services\Tidal\TidalResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The three shapes a Tidal call can come back as, and how each one reads. */
class TidalResultTest extends TestCase
{
    #[Test]
    public function a_document_succeeded(): void
    {
        $result = TidalResult::document(['data' => []], 200);

        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->isNotFound());
        $this->assertSame('HTTP 200', $result->summary());
    }

    #[Test]
    public function a_rejection_names_the_status_and_tidals_own_detail(): void
    {
        $result = TidalResult::rejected(400, 'Invalid resource ID');

        $this->assertFalse($result->succeeded());
        $this->assertSame('HTTP 400 — Invalid resource ID', $result->summary());
        $this->assertSame(['status' => 400, 'detail' => 'Invalid resource ID'], $result->context());
    }

    #[Test]
    public function a_404_is_an_answer_rather_than_a_fault(): void
    {
        // Callers cache the empty list this implies instead of treating it as an outage.
        $this->assertTrue(TidalResult::rejected(404, null)->isNotFound());
        $this->assertFalse(TidalResult::rejected(500, null)->isNotFound());
    }

    #[Test]
    public function an_unreachable_host_names_no_status(): void
    {
        $result = TidalResult::unreachable('cURL error 28: Operation timed out');

        // Naming a number when none arrived would be a lie; the distinction is what tells
        // an egress problem apart from a Tidal one.
        $this->assertNull($result->status);
        $this->assertSame('could not reach Tidal: cURL error 28: Operation timed out', $result->summary());
    }
}
