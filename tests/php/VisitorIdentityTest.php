<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Service\VisitorIdentity;
use OCP\IRequest;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Telling one anonymous visitor from another.
 *
 * The value arrives in a cookie, which is to say from whoever is asking — so most of what
 * matters here is what happens when it is not what we issued. It is also the only thing
 * standing between "remove the track I uploaded" and "remove any track", which is why the
 * rejections below are spelled out one at a time.
 */
class VisitorIdentityTest extends TestCase {

	private const KEY = 'nqpuvei0gr9js1g26mi2w97npa3ya30h';

	private IRequest&MockObject $request;
	private VisitorIdentity $identity;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn(self::KEY);

		$this->identity = new VisitorIdentity($this->request, $secureRandom);
	}

	private function withCookie(mixed $value): void {
		$this->request->method('getCookie')->willReturn($value);
	}

	// ------------------------------------------------------------ reading it

	public function testAKeyWeIssuedIsAccepted(): void {
		$this->withCookie(self::KEY);

		self::assertSame(self::KEY, $this->identity->current());
	}

	/**
	 * The regression this class was written around: keys are generated from letters and
	 * digits, and were once validated as hexadecimal — so every key the app issued was
	 * rejected, and every upload fell back to being credited to nobody.
	 */
	public function testTheIssuedAlphabetPassesItsOwnValidation(): void {
		$issued = $this->identity->issue();
		$this->withCookie($issued);

		self::assertSame($issued, $this->identity->current());
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function rejectedCookieProvider(): array {
		return [
			'absent' => [null],
			'empty' => [''],
			'too short' => [str_repeat('a', 31)],
			'too long' => [str_repeat('a', 33)],
			'uppercase' => [strtoupper(self::KEY)],
			'punctuation' => [str_repeat('a', 31) . '!'],
			'a path traversal' => ['../../etc/passwd'],
			'sql-ish' => ["' or '1'='1"],
			'a prefixed value that would match a credit string' => ['?link:' . self::KEY],
			'not a string at all' => [['array']],
		];
	}

	#[DataProvider('rejectedCookieProvider')]
	public function testAnythingElseCountsAsHavingNoKey(mixed $cookie): void {
		$this->withCookie($cookie);

		self::assertNull($this->identity->current());
	}

	// ------------------------------------------------------------- crediting

	public function testAnUploadIsCreditedToTheVisitor(): void {
		$credit = $this->identity->creditFor(self::KEY);

		self::assertSame('?link:' . self::KEY, $credit);
		// `?` cannot appear in a Nextcloud user id, which is what keeps a visitor key from
		// ever being mistaken for one.
		self::assertStringStartsWith('?', $credit);
	}

	public function testAVisitorWithNoKeyFallsBackToTheAnonymousSentinel(): void {
		self::assertSame(Track::ADDED_BY_PUBLIC_LINK, $this->identity->creditFor(null));
	}

	public function testACreditFitsTheColumn(): void {
		// `added_by` is a 64-character column shared with real user ids.
		self::assertLessThanOrEqual(64, strlen($this->identity->creditFor(self::KEY)));
	}

	// ------------------------------------------------------------- ownership

	public function testAVisitorOwnsWhatTheyUploaded(): void {
		self::assertTrue($this->identity->owns('?link:' . self::KEY, self::KEY));
	}

	public function testAVisitorDoesNotOwnSomebodyElsesUpload(): void {
		self::assertFalse($this->identity->owns('?link:' . str_repeat('b', 32), self::KEY));
	}

	/**
	 * Otherwise a visitor with no cookie would inherit everything uploaded before this
	 * existed.
	 */
	public function testHavingNoKeyOwnsNothing(): void {
		self::assertFalse($this->identity->owns(Track::ADDED_BY_PUBLIC_LINK, null));
		self::assertFalse($this->identity->owns('?link:' . self::KEY, null));
	}

	public function testAVisitorNeverOwnsASignedInUsersTrack(): void {
		self::assertFalse($this->identity->owns('admin', self::KEY));
		// Even if a user id could somehow be the key itself, the prefix keeps them apart.
		self::assertFalse($this->identity->owns(self::KEY, self::KEY));
	}

	public function testOlderAnonymousUploadsStayNobodys(): void {
		self::assertFalse($this->identity->owns(Track::ADDED_BY_PUBLIC_LINK, self::KEY));
	}

	// ---------------------------------------------------------- recognising

	public function testBothFormsOfLinkUploadAreRecognised(): void {
		self::assertTrue(VisitorIdentity::isLinkUpload(Track::ADDED_BY_PUBLIC_LINK));
		self::assertTrue(VisitorIdentity::isLinkUpload('?link:' . self::KEY));
		self::assertFalse(VisitorIdentity::isLinkUpload('admin'));
	}
}
