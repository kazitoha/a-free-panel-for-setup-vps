<?php

namespace Tests\Unit;

use App\Exceptions\InvalidDnsRecord;
use App\Support\DnsSpec;
use PHPUnit\Framework\TestCase;

class DnsSpecTest extends TestCase
{
    public function test_zone_names_are_normalized(): void
    {
        $this->assertSame('example.com', DnsSpec::zoneName('Example.COM.'));
        $this->assertSame('example.com', DnsSpec::zoneName('  example.com  '));
    }

    public function test_zone_name_rejects_garbage(): void
    {
        foreach (['', 'nodot', '-bad.com', 'a..b.com', "ev\nil.com", str_repeat('a', 300).'.com'] as $bad) {
            try {
                DnsSpec::zoneName($bad);
                $this->fail("Expected rejection for: {$bad}");
            } catch (InvalidDnsRecord) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_record_names_normalize_to_absolute_form(): void
    {
        $this->assertSame('example.com.', DnsSpec::recordName('example.com', '@'));
        $this->assertSame('example.com.', DnsSpec::recordName('example.com', 'example.com'));
        $this->assertSame('www.example.com.', DnsSpec::recordName('example.com', 'www'));
        $this->assertSame('www.example.com.', DnsSpec::recordName('example.com', 'www.example.com.'));
        $this->assertSame('*.example.com.', DnsSpec::recordName('example.com', '*'));
        $this->assertSame('_dmarc.example.com.', DnsSpec::recordName('example.com', '_dmarc'));
    }

    public function test_dotted_names_not_under_the_zone_become_relative(): void
    {
        // Everything must land inside the zone; "other.net" is treated as
        // relative labels rather than an out-of-zone absolute name.
        $this->assertSame('other.net.example.com.', DnsSpec::recordName('example.com', 'other.net'));
        $this->assertSame('www.other.net.example.com.', DnsSpec::recordName('example.com', 'www.other.net.'));
        $this->assertSame('_sip._tcp.example.com.', DnsSpec::recordName('example.com', '_sip._tcp'));

        $this->assertNull(DnsSpec::recordName('example.com', 'bad name'));
        $this->assertNull(DnsSpec::recordName('example.com', 'inv@lid'));
    }

    public function test_a_record_validation(): void
    {
        $record = DnsSpec::record('example.com', [
            'name' => 'www', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => '300',
        ]);

        $this->assertSame('www.example.com.', $record['name']);
        $this->assertSame('203.0.113.10', $record['content']);
        $this->assertSame(300, $record['ttl']);

        $this->expectException(InvalidDnsRecord::class);
        DnsSpec::record('example.com', ['name' => '@', 'type' => 'A', 'content' => '999.1.1.1']);
    }

    public function test_mx_content_gets_normalized(): void
    {
        $record = DnsSpec::record('example.com', [
            'name' => '@', 'type' => 'MX', 'content' => '10 mail.example.com', 'ttl' => 3600,
        ]);

        $this->assertSame('10 mail.example.com.', $record['content']);

        $this->expectException(InvalidDnsRecord::class);
        DnsSpec::record('example.com', ['name' => '@', 'type' => 'MX', 'content' => 'mail.example.com']);
    }

    public function test_srv_and_caa_normalization(): void
    {
        $srv = DnsSpec::record('example.com', [
            'name' => '_sip._tcp', 'type' => 'SRV', 'content' => '10 60 5060 sip.example.com', 'ttl' => 3600,
        ]);

        $this->assertSame('10 60 5060 sip.example.com.', $srv['content']);

        $caa = DnsSpec::record('example.com', [
            'name' => '@', 'type' => 'CAA', 'content' => '0 issue letsencrypt.org', 'ttl' => 3600,
        ]);

        $this->assertSame('0 issue "letsencrypt.org"', $caa['content']);
    }

    public function test_line_breaks_are_rejected_everywhere(): void
    {
        $this->expectException(InvalidDnsRecord::class);

        DnsSpec::record('example.com', [
            'name' => "@\nIN A", 'type' => 'A', 'content' => "1.2.3.4\nid IN TXT hacked",
        ]);
    }

    public function test_ttl_bounds_are_enforced(): void
    {
        foreach (['0', '10', '9999999', 'abc'] as $bad) {
            try {
                DnsSpec::record('example.com', ['name' => '@', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => $bad]);
                $this->fail("Expected ttl rejection for {$bad}");
            } catch (InvalidDnsRecord $exception) {
                $this->assertArrayHasKey('ttl', $exception->errors);
            }
        }
    }

    public function test_unknown_types_are_rejected(): void
    {
        $this->expectException(InvalidDnsRecord::class);

        DnsSpec::record('example.com', ['name' => '@', 'type' => 'ANY', 'content' => 'x']);
    }

    public function test_soa_content_shape(): void
    {
        $soa = DnsSpec::soaContent('ns1.example.com', 'admin.example.com', 2026100901);

        $this->assertSame('ns1.example.com. admin.example.com. 2026100901 10800 3600 604800 3600', $soa);
    }
}
