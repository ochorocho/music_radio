/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import App from './App.vue'

const app = createApp(App)
app.mixin({ methods: { t, n } })
app.mount('#content')
