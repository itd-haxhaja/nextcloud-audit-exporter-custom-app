<?php

declare(strict_types=1);

namespace OCA\AuditExport\Tests\Unit;

use OCA\AuditExport\Db\QueryHelper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class QueryHelperTest extends TestCase {

    private QueryHelper $helper;

    protected function setUp(): void {
        $this->helper = new QueryHelper($this->createMock(IDBConnection::class));
    }

    public function testPaginationDefaults(): void {
        $this->assertSame(['limit' => 100, 'offset' => 0], $this->helper->parsePagination(null, null));
    }

    public function testPaginationIsClamped(): void {
        $this->assertSame(500, $this->helper->parsePagination('99999', '0')['limit']);
        $this->assertSame(1, $this->helper->parsePagination('0', '0')['limit']);
        $this->assertSame(1, $this->helper->parsePagination('abc', '0')['limit']);
        $this->assertSame(0, $this->helper->parsePagination('10', '-5')['offset']);
        $this->assertSame(250, $this->helper->parsePagination('10', '250')['offset']);
    }

    public function testParseSince(): void {
        $this->assertNull($this->helper->parseSince(null));
        $this->assertNull($this->helper->parseSince(''));
        $this->assertNull($this->helper->parseSince('not-a-date'));
        $this->assertSame(946684800, $this->helper->parseSince('2000-01-01T00:00:00Z'));
        $this->assertSame(946684800, $this->helper->parseSince('2000-01-01T01:00:00+01:00'));
    }
}
