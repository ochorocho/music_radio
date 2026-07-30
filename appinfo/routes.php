<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

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

		// Importing audio from a link. No public-link equivalent on purpose: an anonymous
		// visitor starting server-side downloads against the owner's quota is a different
		// proposition from uploading a file they already have.
		['name' => 'import#index', 'url' => '/api/v1/channels/{id}/imports', 'verb' => 'GET'],
		['name' => 'import#create', 'url' => '/api/v1/channels/{id}/imports', 'verb' => 'POST'],
		['name' => 'import#destroy', 'url' => '/api/v1/channels/{id}/imports/{importId}', 'verb' => 'DELETE'],

		// Audio. Consumed by an <audio> element, so it carries no CSRF token — see
		// StreamController for how access is checked instead.
		['name' => 'stream#track', 'url' => '/api/v1/channels/{id}/tracks/{trackId}/stream', 'verb' => 'GET'],

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
		['name' => 'publicApi#upload', 'url' => '/api/v1/public/{token}/tracks', 'verb' => 'POST'],
	],
];
