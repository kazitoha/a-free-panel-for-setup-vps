<?php

namespace Tests\Unit;

use App\Exceptions\InvalidSiteSpec;
use App\Support\SiteSpec;
use PHPUnit\Framework\TestCase;

class SiteSpecTest extends TestCase
{
    public function test_it_validates_and_builds_a_php_site(): void
    {
        $spec = SiteSpec::validate([
            'domain' => 'Example.COM',
            'wildcard' => false,
            'type' => 'php',
            'php_version' => '8.3',
            'docroot' => '/var/www/example.com/public',
        ]);

        $this->assertSame('example.com', $spec['domain']);

        $content = SiteSpec::build($spec);

        $this->assertStringStartsWith("example.com {\n", $content);
        $this->assertStringContainsString('root * /var/www/example.com/public', $content);
        $this->assertStringContainsString('php_fastcgi unix//run/php/php8.3-fpm.sock', $content);
        $this->assertStringContainsString('file_server', $content);
        $this->assertStringNotContainsString('reverse_proxy', $content);
    }

    public function test_it_adds_the_wildcard_address(): void
    {
        $spec = SiteSpec::validate([
            'domain' => 'example.com',
            'wildcard' => true,
            'type' => 'static',
            'docroot' => '/var/www/site',
        ]);

        $this->assertStringStartsWith("example.com, *.example.com {\n", SiteSpec::build($spec));
    }

    public function test_it_rejects_directive_injection_in_every_field(): void
    {
        $attempts = [
            ['domain' => "example.com {\n\tabort"],
            ['domain' => 'example.com}', 'type' => 'static', 'docroot' => '/var/www/x'],
            ['domain' => 'example.com', 'type' => 'php', 'php_version' => "8.3\nabort", 'docroot' => '/var/www/x'],
            ['domain' => 'example.com', 'type' => 'static', 'docroot' => "/var/www/x\n\tabort"],
            ['domain' => 'example.com', 'type' => 'proxy', 'target' => "127.0.0.1:8080\nabort"],
        ];

        foreach ($attempts as $input) {
            try {
                SiteSpec::validate($input + ['wildcard' => false]);
                $this->fail('Expected InvalidSiteSpec for: '.json_encode($input));
            } catch (InvalidSiteSpec) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_rejects_document_roots_outside_var_www(): void
    {
        $this->expectException(InvalidSiteSpec::class);

        SiteSpec::validate([
            'domain' => 'example.com',
            'type' => 'static',
            'docroot' => '/etc/passwd',
        ]);
    }

    public function test_it_rejects_path_traversal_in_document_root(): void
    {
        $this->expectException(InvalidSiteSpec::class);

        SiteSpec::validate([
            'domain' => 'example.com',
            'type' => 'static',
            'docroot' => '/var/www/../etc/shadow',
        ]);
    }

    public function test_it_requires_a_php_version_for_php_sites(): void
    {
        try {
            SiteSpec::validate([
                'domain' => 'example.com',
                'type' => 'php',
                'docroot' => '/var/www/site',
            ]);
            $this->fail('Expected InvalidSiteSpec');
        } catch (InvalidSiteSpec $exception) {
            $this->assertArrayHasKey('php_version', $exception->errors);
        }
    }

    public function test_it_parses_what_it_builds(): void
    {
        $original = SiteSpec::validate([
            'domain' => 'example.com',
            'wildcard' => true,
            'type' => 'proxy',
            'target' => '127.0.0.1:3000',
        ]);

        $parsed = SiteSpec::parse(SiteSpec::build($original));

        $this->assertNotNull($parsed);
        $this->assertSame('example.com', $parsed['domain']);
        $this->assertTrue($parsed['wildcard']);
        $this->assertSame('proxy', $parsed['type']);
        $this->assertSame('127.0.0.1:3000', $parsed['target']);
    }

    public function test_parse_rejects_foreign_files(): void
    {
        $this->assertNull(SiteSpec::parse("route * /foo {}\n"));
        $this->assertNull(SiteSpec::parse(''));
    }

    public function test_it_derives_and_checks_file_names(): void
    {
        $this->assertSame('example.com', SiteSpec::nameFor('Example.COM'));

        $this->assertTrue(SiteSpec::isValidName('example.com'));
        $this->assertFalse(SiteSpec::isValidName('../etc/passwd'));
        $this->assertFalse(SiteSpec::isValidName('a..b'));
        $this->assertFalse(SiteSpec::isValidName(''));
        $this->assertFalse(SiteSpec::isValidName('-bad.com'));
    }
}
