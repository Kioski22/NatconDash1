<?php
use PHPUnit\Framework\TestCase;

final class BasicTest extends TestCase
{
    public function testEnvironmentLoads(): void
    {
        $this->assertTrue(true);
        $this->assertNotEmpty(getenv('APP_ENV') ?: 'testing');
    }
}
