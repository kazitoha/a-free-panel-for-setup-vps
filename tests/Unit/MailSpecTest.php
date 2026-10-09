<?php

namespace Tests\Unit;

use App\Exceptions\InvalidMailRecord;
use App\Support\MailSpec;
use PHPUnit\Framework\TestCase;

class MailSpecTest extends TestCase
{
    public function test_domain_names_are_normalized(): void
    {
        $this->assertSame('example.com', MailSpec::domain('Example.COM.'));
        $this->assertSame('example.com', MailSpec::domain('  example.com  '));
    }

    public function test_domain_rejects_garbage(): void
    {
        foreach (['', 'nodot', '-bad.com', 'a..b.com', "ev\nil.com", str_repeat('a', 300).'.com'] as $bad) {
            try {
                MailSpec::domain($bad);
                $this->fail("Expected rejection for: {$bad}");
            } catch (InvalidMailRecord $exception) {
                $this->assertArrayHasKey('domain', $exception->errors);
            }
        }
    }

    public function test_local_parts_are_normalized(): void
    {
        $this->assertSame('hello', MailSpec::localPart('  Hello  '));
        $this->assertSame('john.doe+tag', MailSpec::localPart('john.doe+tag'));
        $this->assertSame('a_b-c.d', MailSpec::localPart('a_b-c.d'));
    }

    public function test_local_part_rejects_garbage(): void
    {
        $bad = ['', 'bad name', '-leading', 'trailing-', 'a..b', '.', 'em@il', str_repeat('a', 65)];

        foreach ($bad as $name) {
            try {
                MailSpec::localPart($name);
                $this->fail("Expected rejection for: {$name}");
            } catch (InvalidMailRecord $exception) {
                $this->assertArrayHasKey('name', $exception->errors);
            }
        }
    }

    public function test_password_rules(): void
    {
        $this->assertSame('correct horse', MailSpec::password('correct horse'));

        foreach (['short1', str_repeat('x', 257), "pass\x00word"] as $password) {
            try {
                MailSpec::password($password);
                $this->fail('Expected password rejection.');
            } catch (InvalidMailRecord $exception) {
                $this->assertArrayHasKey('password', $exception->errors);
            }
        }
    }

    public function test_description_length_is_capped(): void
    {
        $this->assertSame('Sales inbox', MailSpec::description('  Sales inbox  '));

        $this->expectException(InvalidMailRecord::class);
        MailSpec::description(str_repeat('x', 201));
    }
}
