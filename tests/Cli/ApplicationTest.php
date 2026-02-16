<?php

declare(strict_types=1);

namespace HybridId\Tests\Cli;

use HybridId\Cli\Application;
use HybridId\Cli\BufferedOutput;
use HybridId\HybridIdGenerator;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // generate
    // -------------------------------------------------------------------------

    public function testGenerateDefaultProfile(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate']);

        $this->assertSame(0, $exitCode);
        $lines = $output->getLines();
        $this->assertCount(1, $lines);
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{20}$/', $lines[0]);
    }

    public function testGenerateCompactProfile(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '-p', 'compact']);

        $this->assertSame(0, $exitCode);
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{16}$/', $output->getLines()[0]);
    }

    public function testGenerateExtendedProfile(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '--profile', 'extended']);

        $this->assertSame(0, $exitCode);
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{24}$/', $output->getLines()[0]);
    }

    public function testGenerateWithCount(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '-n', '5']);

        $this->assertSame(0, $exitCode);
        $this->assertCount(5, $output->getLines());
    }

    public function testGenerateWithPrefix(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '--prefix', 'usr']);

        $this->assertSame(0, $exitCode);
        $this->assertStringStartsWith('usr_', $output->getLines()[0]);
    }

    public function testGenerateWithNode(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '--node', 'A1']);

        $this->assertSame(0, $exitCode);
        $id = $output->getLines()[0];
        $this->assertSame('A1', HybridIdGenerator::extractNode($id));
    }

    public function testGenerateWithAllOptions(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run([
            'hybrid-id', 'generate',
            '-p', 'extended',
            '-n', '3',
            '--node', 'Z9',
            '--prefix', 'txn',
        ]);

        $this->assertSame(0, $exitCode);
        $lines = $output->getLines();
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertStringStartsWith('txn_', $line);
            $this->assertSame(28, strlen($line)); // 3 + 1 + 24
        }
    }

    // -------------------------------------------------------------------------
    // generate errors
    // -------------------------------------------------------------------------

    public function testGenerateRejectsInvalidProfile(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '-p', 'nonexistent']);

        $this->assertSame(1, $exitCode);
        $this->assertNotEmpty($output->getErrors());
        $this->assertStringContainsString('Invalid profile', $output->getErrors()[0]);
    }

    public function testGenerateRejectsZeroCount(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '-n', '0']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('positive integer', $output->getErrors()[0]);
    }

    public function testGenerateRejectsExcessiveCount(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '-n', '100001']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('must not exceed 100,000', $output->getErrors()[0]);
    }

    public function testGenerateRejectsNonIntegerCount(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '-n', 'abc']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('valid integer', $output->getErrors()[0]);
    }

    public function testGenerateRejectsMissingProfileValue(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '--profile']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Missing value', $output->getErrors()[0]);
    }

    public function testGenerateRejectsMissingCountValue(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '--count']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Missing value', $output->getErrors()[0]);
    }

    public function testGenerateRejectsUnknownOption(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '--invalid']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown option', $output->getErrors()[0]);
    }

    public function testGenerateRejectsInvalidPrefix(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '--prefix', 'USR']);

        $this->assertSame(1, $exitCode);
        $this->assertNotEmpty($output->getErrors());
    }

    // -------------------------------------------------------------------------
    // inspect
    // -------------------------------------------------------------------------

    public function testInspectValidId(): void
    {
        $gen = new HybridIdGenerator(node: 'A1');
        $id = $gen->generate('usr');

        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'inspect', $id]);

        $this->assertSame(0, $exitCode);
        $text = $output->getOutput();
        $this->assertStringContainsString('ID:', $text);
        $this->assertStringContainsString('Prefix:     usr', $text);
        $this->assertStringContainsString('Profile:    standard', $text);
        $this->assertStringContainsString('Node:       A1', $text);
        $this->assertStringContainsString('Valid:      yes', $text);
    }

    public function testInspectUnprefixedId(): void
    {
        $gen = new HybridIdGenerator();
        $id = $gen->generate();

        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'inspect', $id]);

        $this->assertSame(0, $exitCode);
        $text = $output->getOutput();
        $this->assertStringContainsString('ID:', $text);
        $this->assertStringNotContainsString('Prefix:', $text);
    }

    public function testInspectInvalidIdReturnsError(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'inspect', 'invalid']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid HybridId', $output->getErrors()[0]);
    }

    public function testInspectMissingIdReturnsError(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'inspect']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Usage:', $output->getErrors()[0]);
    }

    // -------------------------------------------------------------------------
    // profiles
    // -------------------------------------------------------------------------

    public function testProfilesShowsTable(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'profiles']);

        $this->assertSame(0, $exitCode);
        $text = $output->getOutput();
        $this->assertStringContainsString('compact', $text);
        $this->assertStringContainsString('standard', $text);
        $this->assertStringContainsString('extended', $text);
        $this->assertStringContainsString('35.7 bits', $text);
        $this->assertStringContainsString('59.5 bits', $text);
        $this->assertStringContainsString('83.4 bits', $text);
    }

    // -------------------------------------------------------------------------
    // help
    // -------------------------------------------------------------------------

    public function testHelpReturnsZero(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'help']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Usage:', $output->getOutput());
    }

    public function testHelpAliases(): void
    {
        foreach (['--help', '-h'] as $alias) {
            $output = new BufferedOutput();
            $app = new Application($output);

            $exitCode = $app->run(['hybrid-id', $alias]);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('Usage:', $output->getOutput());
        }
    }

    public function testUnknownCommandShowsHelpAndReturnsError(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'foobar']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown command: foobar', $output->getErrors()[0]);
        $this->assertStringContainsString('Usage:', $output->getOutput());
    }

    public function testNoArgsShowsHelp(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Usage:', $output->getOutput());
    }

    // -------------------------------------------------------------------------
    // Sanitization
    // -------------------------------------------------------------------------

    public function testSanitizesAnsiEscapeSequences(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', "\033[31mmalicious"]);

        $this->assertSame(1, $exitCode);
        // ESC character (0x1b) should be stripped
        $this->assertStringContainsString('[31mmalicious', $output->getErrors()[0]);
        $this->assertStringNotContainsString("\033", $output->getErrors()[0]);
    }

    public function testSanitizesTruncatesLongInput(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $longInput = str_repeat('x', 500);
        $exitCode = $app->run(['hybrid-id', 'inspect', $longInput]);

        $this->assertSame(1, $exitCode);
        // Error message should contain the truncated input (256 chars max)
        $error = $output->getErrors()[0];
        $this->assertStringNotContainsString(str_repeat('x', 257), $error);
    }

    public function testSanitizesNullBytes(): void
    {
        $output = new BufferedOutput();
        $app = new Application($output);

        $exitCode = $app->run(['hybrid-id', 'inspect', "test\x00id"]);

        $this->assertSame(1, $exitCode);
        // Null byte should be stripped
        $this->assertStringNotContainsString("\x00", $output->getErrors()[0]);
    }
}
