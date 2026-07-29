/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Entry point for the page an anonymous listener sees behind a shared link.
 */
import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import PublicApp from './PublicApp.vue'

const app = createApp(PublicApp)
app.mixin({ methods: { t, n } })
app.mount('#content')
