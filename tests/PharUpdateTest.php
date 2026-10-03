<?php

namespace Dekor\Devio\Tests;

use Dekor\Devio\Devio;
use Dekor\Devio\Support\PharUpdate;
use RuntimeException;

/** Downloads from a php -S serving $this->tmp/srv. */
final class PharUpdateTest extends TestCase
{
    /** @var resource */
    private $server;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        mkdir("$this->tmp/srv");
        $port = random_int(20000, 60000);
        $this->url = "http://127.0.0.1:$port";
        $this->server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$this->tmp/srv"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }
    }

    protected function tearDown(): void
    {
        proc_terminate($this->server);
        proc_close($this->server);
        parent::tearDown();
    }

    public function test_replaces_the_phar_keeping_its_mode(): void
    {
        file_put_contents("$this->tmp/srv/devio.phar", $this->phar('9.9.9'));
        file_put_contents("$this->tmp/devio.phar", $this->phar(Devio::VERSION));
        chmod("$this->tmp/devio.phar", 0750);

        PharUpdate::run("$this->tmp/devio.phar", "$this->url/devio.phar");

        $this->assertSame($this->phar('9.9.9'), file_get_contents("$this->tmp/devio.phar"));
        $this->assertSame(0750, fileperms("$this->tmp/devio.phar") & 0777);
        $this->assertFileDoesNotExist("$this->tmp/devio.phar.download");
        $this->assertStringContainsString('updated devio.phar: devio ' . Devio::VERSION . ' → 9.9.9', $this->console());
    }

    public function test_up_to_date(): void
    {
        file_put_contents("$this->tmp/srv/devio.phar", $this->phar('9.9.9'));
        file_put_contents("$this->tmp/devio.phar", $this->phar('9.9.9'));

        PharUpdate::run("$this->tmp/devio.phar", "$this->url/devio.phar");

        $this->assertStringContainsString('devio.phar is up to date: devio 9.9.9', $this->console());
    }

    public function test_keeps_the_phar_when_the_download_is_wrong(): void
    {
        file_put_contents("$this->tmp/srv/page.html", '<html>not found</html>');
        file_put_contents("$this->tmp/devio.phar", $this->phar('1.0.0'));

        foreach (["$this->url/page.html" => 'is not a devio.phar', "$this->url/missing.phar" => 'answered 404'] as $url => $error) {
            try {
                PharUpdate::run("$this->tmp/devio.phar", $url);
                $this->fail("$url was accepted");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($error, $e->getMessage());
            }
        }
        $this->assertSame($this->phar('1.0.0'), file_get_contents("$this->tmp/devio.phar"));
    }

    private function phar(string $version): string
    {
        return "<?php /* stub */ __HALT_COMPILER(); ?>\nfinal class Devio { public const VERSION = '$version'; }";
    }
}
