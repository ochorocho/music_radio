<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Password prompt for a protected channel link.
 *
 * Core's own `publicshareauth` template cannot be reused: it is typed to
 * `OCP\Share\IShare` and calls `getShareType()` and `getSendPasswordByTalk()` on it. A
 * channel is not a file share, so it has no such object to hand — hence this form.
 *
 * @var array{
 *     wrongpw: bool,
 *     actionUrl: string,
 *     channelTitle: string,
 * } $_
 * @var \OCP\IL10N $l
 */

?>
<form class="music-radio-auth" method="post" action="<?php p($_['actionUrl']); ?>">
	<input type="hidden" name="requesttoken" value="<?php p(\OCP\Util::callRegister()); ?>">
	<!-- The controller only treats this as a password attempt when passwordRequest is
	     not the empty string; core's own form does the same. -->
	<input type="hidden" name="passwordRequest" value="no">

	<h2 class="music-radio-auth__title"><?php p($_['channelTitle']); ?></h2>
	<p class="music-radio-auth__intro">
		<?php p($l->t('This channel is protected with a password.')); ?>
	</p>

	<?php if ($_['wrongpw']) { ?>
		<p class="music-radio-auth__error" role="alert">
			<?php p($l->t('That password was not right. Please try again.')); ?>
		</p>
	<?php } ?>

	<label class="music-radio-auth__label" for="music-radio-password">
		<?php p($l->t('Password')); ?>
	</label>
	<input
		id="music-radio-password"
		type="password"
		name="password"
		autocomplete="current-password"
		autocapitalize="off"
		spellcheck="false"
		required
		autofocus>

	<button type="submit" class="primary">
		<?php p($l->t('Listen')); ?>
	</button>
</form>

<style>
	.music-radio-auth {
		display: flex;
		flex-direction: column;
		gap: 0.75rem;
		max-inline-size: 22rem;
		margin-inline: auto;
		padding: 1rem;
	}

	.music-radio-auth__title {
		margin: 0;
		font-size: 1.25rem;
		overflow-wrap: anywhere;
	}

	.music-radio-auth__intro,
	.music-radio-auth__error {
		margin: 0;
	}

	.music-radio-auth__error {
		color: var(--color-error-text, var(--color-error));
		font-weight: bold;
	}

	.music-radio-auth__label {
		font-weight: bold;
	}

	.music-radio-auth input[type="password"],
	.music-radio-auth button {
		inline-size: 100%;
	}
</style>
