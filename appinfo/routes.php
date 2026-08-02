<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// One request per settings page, not one per field — see SettingsController.
		['name' => 'settings#saveAdmin', 'url' => '/settings/admin', 'verb' => 'POST'],
		// The web equivalent of `occ music_radio:ytdlp:install --force`, which the status
		// text used to have to tell administrators to go and run in a terminal.
		['name' => 'settings#installYtDlp', 'url' => '/settings/admin/ytdlp', 'verb' => 'POST'],
		['name' => 'settings#savePersonal', 'url' => '/settings/personal', 'verb' => 'POST'],

		// Channels
		['name' => 'channel#index', 'url' => '/api/v1/channels', 'verb' => 'GET'],
		['name' => 'channel#create', 'url' => '/api/v1/channels', 'verb' => 'POST'],
		['name' => 'channel#show', 'url' => '/api/v1/channels/{id}', 'verb' => 'GET'],
		['name' => 'channel#update', 'url' => '/api/v1/channels/{id}', 'verb' => 'PUT'],
		['name' => 'channel#destroy', 'url' => '/api/v1/channels/{id}', 'verb' => 'DELETE'],

		// Playlist
		['name' => 'track#index', 'url' => '/api/v1/channels/{id}/tracks', 'verb' => 'GET'],
		['name' => 'track#create', 'url' => '/api/v1/channels/{id}/tracks', 'verb' => 'POST'],
		['name' => 'track#reorder', 'url' => '/api/v1/channels/{id}/tracks/order', 'verb' => 'PUT'],
		['name' => 'track#update', 'url' => '/api/v1/channels/{id}/tracks/{trackId}', 'verb' => 'PUT'],
		['name' => 'track#destroy', 'url' => '/api/v1/channels/{id}/tracks/{trackId}', 'verb' => 'DELETE'],

		// Voting. A toggle rather than separate cast/withdraw verbs: pressing the same
		// control twice is what a listener does, and the server answers with the state
		// after either way.
		['name' => 'vote#toggle', 'url' => '/api/v1/channels/{id}/tracks/{trackId}/vote', 'verb' => 'POST'],

		// Importing audio from a link. No public-link equivalent on purpose: an anonymous
		// visitor starting server-side downloads against the owner's quota is a different
		// proposition from uploading a file they already have.
		['name' => 'import#index', 'url' => '/api/v1/channels/{id}/imports', 'verb' => 'GET'],
		['name' => 'import#create', 'url' => '/api/v1/channels/{id}/imports', 'verb' => 'POST'],
		['name' => 'import#destroy', 'url' => '/api/v1/channels/{id}/imports/{importId}', 'verb' => 'DELETE'],

		// Audio. Consumed by an <audio> element, so it carries no CSRF token — see
		// StreamController for how access is checked instead.
		['name' => 'stream#track', 'url' => '/api/v1/channels/{id}/tracks/{trackId}/stream', 'verb' => 'GET'],
		// What a listener actually plays: a span of the programme rather than one track, so
		// a locked phone crosses track boundaries without needing JavaScript to be awake.
		['name' => 'stream#programme', 'url' => '/api/v1/channels/{id}/programme', 'verb' => 'GET'],

		// Broadcast state and control.
		['name' => 'playback#time', 'url' => '/api/v1/time', 'verb' => 'GET'],
		['name' => 'playback#state', 'url' => '/api/v1/channels/{id}/state', 'verb' => 'GET'],
		['name' => 'playback#control', 'url' => '/api/v1/channels/{id}/control', 'verb' => 'POST'],
		['name' => 'playback#settings', 'url' => '/api/v1/channels/{id}/playback-settings', 'verb' => 'PUT'],

		// Sharing
		['name' => 'share#index', 'url' => '/api/v1/channels/{id}/shares', 'verb' => 'GET'],
		['name' => 'share#create', 'url' => '/api/v1/channels/{id}/shares', 'verb' => 'POST'],
		['name' => 'share#update', 'url' => '/api/v1/channels/{id}/shares/{shareId}', 'verb' => 'PUT'],
		['name' => 'share#setPassword', 'url' => '/api/v1/channels/{id}/shares/{shareId}/password', 'verb' => 'PUT'],
		['name' => 'share#destroy', 'url' => '/api/v1/channels/{id}/shares/{shareId}', 'verb' => 'DELETE'],

		// Public share page.
		//
		// ⚠ These three names are dictated by AuthPublicShareController, which builds its
		// own redirect targets by reflecting on the class name — `ChannelPublicController`
		// minus the suffix. The URL parameter must literally be `token` for the same
		// reason. Renaming either silently breaks the password redirect.
		['name' => 'ChannelPublic#showShare', 'url' => '/s/{token}', 'verb' => 'GET'],
		['name' => 'ChannelPublic#showAuthenticate', 'url' => '/s/{token}/authenticate/{redirect}', 'verb' => 'GET'],
		['name' => 'ChannelPublic#authenticate', 'url' => '/s/{token}/authenticate/{redirect}', 'verb' => 'POST'],

		// Token-scoped API for anonymous listeners.
		['name' => 'publicApi#state', 'url' => '/api/v1/public/{token}/state', 'verb' => 'GET'],
		['name' => 'publicApi#tracks', 'url' => '/api/v1/public/{token}/tracks', 'verb' => 'GET'],
		['name' => 'publicApi#stream', 'url' => '/api/v1/public/{token}/tracks/{trackId}/stream', 'verb' => 'GET'],
		['name' => 'publicApi#programme', 'url' => '/api/v1/public/{token}/programme', 'verb' => 'GET'],
		['name' => 'publicApi#upload', 'url' => '/api/v1/public/{token}/tracks', 'verb' => 'POST'],
		// The visitor's own upload, or anything at all on a link that curates — see
		// PublicApiController::destroyTrack.
		['name' => 'publicApi#destroyTrack', 'url' => '/api/v1/public/{token}/tracks/{trackId}', 'verb' => 'DELETE'],
		['name' => 'publicApi#vote', 'url' => '/api/v1/public/{token}/tracks/{trackId}/vote', 'verb' => 'POST'],

		// Being the DJ through a link. Both are off unless the owner granted CONTROL or
		// EDIT_PLAYLIST on that link, which is never the default — see Permission::LINK_ALLOWED.
		// Declared before the {trackId} routes above would ever be consulted for a PUT, but
		// listed here so the whole controlling surface reads together.
		['name' => 'publicApi#control', 'url' => '/api/v1/public/{token}/control', 'verb' => 'POST'],
		['name' => 'publicApi#playbackSettings', 'url' => '/api/v1/public/{token}/playback-settings', 'verb' => 'PUT'],
		['name' => 'publicApi#reorderTracks', 'url' => '/api/v1/public/{token}/tracks/order', 'verb' => 'PUT'],

		// Importing through a link. Off unless the owner switched it on for that link —
		// see PublicApiController::createImport for why this one is gated so heavily.
		['name' => 'publicApi#imports', 'url' => '/api/v1/public/{token}/imports', 'verb' => 'GET'],
		['name' => 'publicApi#createImport', 'url' => '/api/v1/public/{token}/imports', 'verb' => 'POST'],
		['name' => 'publicApi#destroyImport', 'url' => '/api/v1/public/{token}/imports/{importId}', 'verb' => 'DELETE'],
	],

	/**
	 * The remote import worker.
	 *
	 * OCS rather than ordinary routes, and that is the whole point of them: an OCS
	 * controller reached with `OCS-APIRequest: true` is the supported way for a client that
	 * is not a browser to make an authenticated call, and it is what exempts these from a
	 * CSRF token there is no session to get one from. The worker signs in with HTTP Basic
	 * and an app password, exactly as any other Nextcloud client does.
	 *
	 * Reachable at /ocs/v2.php/apps/music_radio/api/v1/worker/… — see WorkerController for
	 * why being signed in is not by itself enough to use any of them.
	 */
	'ocs' => [
		['name' => 'worker#status', 'url' => '/api/v1/worker', 'verb' => 'GET'],
		['name' => 'worker#claim', 'url' => '/api/v1/worker/claim', 'verb' => 'POST'],
		// The worker script this app ships, so `music-radio-worker update` can keep itself
		// in step with the server. Fetched only when the checksum on the greeting differs.
		['name' => 'worker#script', 'url' => '/api/v1/worker/script', 'verb' => 'GET'],

		// Everything below names a job, and holding its lease is what permits the call.
		['name' => 'worker#metadata', 'url' => '/api/v1/worker/jobs/{importId}/metadata', 'verb' => 'POST'],
		['name' => 'worker#progress', 'url' => '/api/v1/worker/jobs/{importId}/progress', 'verb' => 'POST'],
		['name' => 'worker#cookies', 'url' => '/api/v1/worker/jobs/{importId}/cookies', 'verb' => 'GET'],
		['name' => 'worker#returnCookies', 'url' => '/api/v1/worker/jobs/{importId}/cookies', 'verb' => 'POST'],
		// PUT, and the audio is the entire body: that is what lets the server read it as a
		// stream instead of holding a whole MP3 in memory. Its parameters are in the query
		// string for the same reason.
		['name' => 'worker#audio', 'url' => '/api/v1/worker/jobs/{importId}/audio', 'verb' => 'PUT'],
		['name' => 'worker#fail', 'url' => '/api/v1/worker/jobs/{importId}/fail', 'verb' => 'POST'],
		['name' => 'worker#release', 'url' => '/api/v1/worker/jobs/{importId}/release', 'verb' => 'POST'],
	],
];
