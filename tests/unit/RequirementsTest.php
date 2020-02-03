<?php

declare(strict_types=1);

namespace PhpImap;

use function extension_loaded;
use PHPUnit\Framework\TestCase;

final class RequirementsTest extends TestCase
{
	/**
	* Provides list of extensions, which are required by this library.
	*
	* @psalm-return array<string, array{0:string}>
	*/
	public function extensionProvider() : array
	{
		return [
			'imap' => ['imap'],
			'mbstring' => ['mbstring'],
			'iconv' => ['iconv'],
		];
	}

	/**
	* Test, that required modules are enabled.
	*
	* @dataProvider extensionProvider
	*/
	public function test_required_extensions_are_enabled(string $extension) : void
	{
		static::assertTrue(extension_loaded($extension));
	}
}
