<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\CookieJar;
use OCP\Config\IUserConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The jar's rules, which are mostly about refusing things.
 *
 * Validation is worth pinning at this level because it is the only place a mistake can be
 * caught while the person who made it is still looking at the page. Everything it lets
 * through becomes a file handed to a subprocess hours later, on somebody else's import.
 */
class CookieJarTest extends TestCase {

	private const USER = 'owner';
	private const NOW = 1_785_369_600;

	private IUserConfig&MockObject $userConfig;
	private CookieJar $jar;

	/** A jar shaped exactly like a real export, including the HttpOnly prefix. */
	private const REAL = "# Netscape HTTP Cookie File\n"
		. "# This is a generated file!  Do not edit.\n\n"
		. ".youtube.com\tTRUE\t/\tTRUE\t1799999999\tLOGIN_INFO\tAFmmF2s\n"
		. "#HttpOnly_.youtube.com\tTRUE\t/\tTRUE\t1790000000\t__Secure-1PSID\tg.a000\n"
		. ".google.com\tTRUE\t/\tTRUE\t0\tSESSION_ONLY\tzz\n";

	protected function setUp(): void {
		parent::setUp();

		$this->userConfig = $this->createMock(IUserConfig::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$clock = $this->createMock(Clock::class);
		$clock->method('nowSeconds')->willReturn(self::NOW);

		$this->jar = new CookieJar($this->userConfig, $clock, $l10n, new NullLogger());
	}

	// ---------------------------------------------------------------- parsing

	public function testParsesEveryCookieIncludingHttpOnlyAndSessionOnes(): void {
		$cookies = CookieJar::parse(self::REAL);

		self::assertCount(3, $cookies);
		// The HttpOnly prefix is stripped from the domain, not treated as a comment — those
		// are the session cookies that make the jar worth anything.
		self::assertSame('.youtube.com', $cookies[1]['domain']);
		self::assertSame('__Secure-1PSID', $cookies[1]['name']);
		self::assertSame(0, $cookies[2]['expires']);
	}

	public function testCommentsAndBlankLinesAreNotCookies(): void {
		self::assertSame([], CookieJar::parse("# Netscape HTTP Cookie File\n\n   \n"));
	}

	// ------------------------------------------------------------- validating

	public function testARealExportIsAccepted(): void {
		self::assertNull($this->jar->check(self::REAL));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function refusedProvider(): array {
		return [
			'empty' => [''],
			'a JSON export, which the wrong extension produces' => ['[{"domain":".youtube.com","name":"SID"}]'],
			'prose' => ['I could not find the file, sorry'],
			// Space separated rather than tab separated: looks right, parses to nothing.
			'spaces instead of tabs' => [".youtube.com TRUE / TRUE 1799999999 LOGIN_INFO AFmmF2s\n"],
			'a header with no cookies under it' => ["# Netscape HTTP Cookie File\n"],
		];
	}

	#[DataProvider('refusedProvider')]
	public function testThingsThatAreNotACookieJar(string $text): void {
		self::assertNotNull($this->jar->check($text));
	}

	/**
	 * The likeliest real mistake: a valid export taken from the wrong tab. yt-dlp would
	 * accept it and YouTube would ignore it, so without this the symptom is "I added
	 * cookies and nothing changed".
	 */
	public function testAValidJarForTheWrongSiteIsRefused(): void {
		$other = "# Netscape HTTP Cookie File\n"
			. ".example.com\tTRUE\t/\tTRUE\t1799999999\tSESSION\tabc\n";

		self::assertNotNull($this->jar->check($other));
	}

	public function testSomethingWithABinaryPayloadIsRefused(): void {
		self::assertNotNull($this->jar->check(self::REAL . "\0\x01\x02"));
	}

	public function testAnAbsurdlyLargeFileIsRefused(): void {
		self::assertNotNull($this->jar->check(str_repeat('a', CookieJar::MAX_BYTES + 1)));
	}

	// ---------------------------------------------------------------- storing

	/**
	 * The flag is the encryption. Core stores a FLAG_SENSITIVE value encrypted with the
	 * instance secret and masks it in config reports; writing without it would put a
	 * signed-in session in the database in clear text.
	 */
	public function testStoringMarksTheValueSensitiveAndLazy(): void {
		$this->userConfig->expects(self::once())
			->method('setValueString')
			->with(
				self::USER,
				'music_radio',
				CookieJar::CONFIG_COOKIES,
				trim(self::REAL),
				true,
				IUserConfig::FLAG_SENSITIVE,
			);

		self::assertNull($this->jar->store(self::USER, self::REAL));
	}

	/**
	 * The flag on an existing key cannot be changed in place, so a key that ever existed
	 * unflagged would go on storing plaintext for ever. Deleting first is the only way to
	 * be sure of what a write produces.
	 */
	public function testStoringDeletesTheKeyFirstSoTheFlagCannotBeStale(): void {
		$deleted = [];
		$this->userConfig->method('deleteUserConfig')
			->willReturnCallback(static function (string $user, string $app, string $key) use (&$deleted): void {
				$deleted[] = $key;
			});

		$this->jar->store(self::USER, self::REAL);

		self::assertContains(CookieJar::CONFIG_COOKIES, $deleted);
	}

	public function testRefusedContentIsNeverStored(): void {
		$this->userConfig->expects(self::never())->method('setValueString');

		self::assertNotNull($this->jar->store(self::USER, 'not a cookie file'));
	}

	// -------------------------------------------------------------- describing

	public function testDescribeReportsTheShapeAndNeverTheValue(): void {
		$this->stored(trim(self::REAL));

		$described = $this->jar->describe(self::USER);

		self::assertNotNull($described);
		self::assertSame(3, $described['count']);
		self::assertSame(['youtube.com', 'google.com'], $described['domains']);
		// The soonest expiry among the login cookies: __Secure-1PSID, not LOGIN_INFO's
		// later date and not the session cookie's zero.
		self::assertSame(1790000000, $described['expiresAt']);
		self::assertTrue($described['signedIn']);

		self::assertSame(
			['count', 'domains', 'storedAt', 'expiresAt', 'signedIn'],
			array_keys($described),
			'describe() must not grow a field that carries the cookies themselves',
		);
	}

	/**
	 * Taken from a real run: after one import the jar carries YouTube's own short-lived
	 * visitor cookies, and the soonest expiry across the whole set is half an hour away.
	 * Reported naively, a session good for another year reads as already dead.
	 */
	public function testYouTubesOwnTransientCookiesDoNotDateTheSignIn(): void {
		$this->stored(
			"# Netscape HTTP Cookie File\n"
			. ".youtube.com\tTRUE\t/\tTRUE\t1899999999\t__Secure-1PSID\tg.a000\n"
			. ".youtube.com\tTRUE\t/\tTRUE\t1785598226\tYSC\tshortlived\n"
			. ".youtube.com\tTRUE\t/\tTRUE\t1785598300\t__Secure-ROLLOUT_TOKEN\tabc\n",
		);

		$described = $this->jar->describe(self::USER);

		self::assertNotNull($described);
		self::assertSame(1899999999, $described['expiresAt']);
	}

	/**
	 * A jar exported from a window that was never signed in. It stores cleanly, imports
	 * cleanly and changes nothing, so this is the only thing that would ever say so.
	 */
	public function testAJarWithNoLoginCookieIsReportedAsNotSignedIn(): void {
		$this->stored(
			"# Netscape HTTP Cookie File\n"
			. ".youtube.com\tTRUE\t/\tTRUE\t1899999999\tVISITOR_INFO1_LIVE\tanon\n"
			. ".youtube.com\tTRUE\t/\tTRUE\t1899999999\tPREF\tf6=40000000\n",
		);

		$described = $this->jar->describe(self::USER);

		self::assertNotNull($described);
		self::assertFalse($described['signedIn']);
		self::assertNull($described['expiresAt']);
	}

	public function testDescribeSaysNothingWhenNothingIsStored(): void {
		$this->stored('');

		self::assertNull($this->jar->describe(self::USER));
	}

	// ----------------------------------------------------------- lending out

	public function testTheFileIsWrittenPrivateToTheOwner(): void {
		$this->stored(trim(self::REAL));

		$directory = sys_get_temp_dir() . '/mr-cookie-test-' . bin2hex(random_bytes(6));
		mkdir($directory, 0700, true);

		try {
			$path = $this->jar->writeTo(self::USER, $directory);

			self::assertNotNull($path);
			self::assertSame(trim(self::REAL), file_get_contents($path));
			// A server's temporary directory may be shared; the jar must not be readable by
			// whatever else is in there.
			self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
		} finally {
			@unlink($directory . '/' . CookieJar::FILE_NAME);
			@rmdir($directory);
		}
	}

	public function testNothingIsWrittenWhenThereAreNoCookies(): void {
		$this->stored('');

		$directory = sys_get_temp_dir() . '/mr-cookie-test-' . bin2hex(random_bytes(6));
		mkdir($directory, 0700, true);

		try {
			self::assertNull($this->jar->writeTo(self::USER, $directory));
			self::assertFileDoesNotExist($directory . '/' . CookieJar::FILE_NAME);
		} finally {
			@rmdir($directory);
		}
	}

	// -------------------------------------------------------------- refreshing

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function refreshProvider(): array {
		$rotated = "# Netscape HTTP Cookie File\n"
			. ".youtube.com\tTRUE\t/\tTRUE\t1899999999\tLOGIN_INFO\tROTATED\n";

		return [
			'a rotated jar is taken back' => [$rotated, true],
			// All three would replace a working jar with a broken one, and all three are
			// things a killed or failed run can leave behind.
			'an emptied file is not' => ['', false],
			'a truncated file is not' => ["# Netscape HTTP Cookie File\n", false],
			'garbage is not' => ["\0\0\0", false],
		];
	}

	#[DataProvider('refreshProvider')]
	public function testRefreshOnlyAcceptsAJarThatWouldHaveBeenAcceptedAsAPaste(
		string $written,
		bool $expectStored,
	): void {
		$this->stored(trim(self::REAL));

		$path = sys_get_temp_dir() . '/mr-cookie-refresh-' . bin2hex(random_bytes(6));
		file_put_contents($path, $written);

		$this->userConfig->expects($expectStored ? self::once() : self::never())
			->method('setValueString');

		try {
			$this->jar->refreshFrom(self::USER, $path);
		} finally {
			@unlink($path);
		}
	}

	public function testAnUnchangedJarIsNotWrittenBack(): void {
		$this->stored(trim(self::REAL));

		$path = sys_get_temp_dir() . '/mr-cookie-refresh-' . bin2hex(random_bytes(6));
		file_put_contents($path, self::REAL);  // the same jar, as yt-dlp rewrites it: trailing newline and all

		// Every import would otherwise be a config write for no change.
		$this->userConfig->expects(self::never())->method('setValueString');

		try {
			$this->jar->refreshFrom(self::USER, $path);
		} finally {
			@unlink($path);
		}
	}

	/**
	 * The date records when a person last put cookies here, which is what they reason
	 * about. Moving it on every refresh would make a jar from March look like it was
	 * pasted this morning.
	 */
	public function testRefreshDoesNotTouchTheStoredDate(): void {
		$this->stored(trim(self::REAL));

		$path = sys_get_temp_dir() . '/mr-cookie-refresh-' . bin2hex(random_bytes(6));
		file_put_contents(
			$path,
			"# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t1899999999\tLOGIN_INFO\tROTATED\n",
		);

		$this->userConfig->expects(self::never())->method('setValueInt');

		try {
			$this->jar->refreshFrom(self::USER, $path);
		} finally {
			@unlink($path);
		}
	}

	private function stored(string $value): void {
		$this->userConfig->method('getValueString')->willReturn($value);
	}
}
