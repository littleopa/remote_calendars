/* Remote Calendars – admin settings */
(function () {
	'use strict'
	const T = (s, v) => t('remote_calendars', s, v)
	document.addEventListener('DOMContentLoaded', () => {
		const form = document.getElementById('rc-admin-form')
		if (!form) return
		const msg = document.getElementById('rc-admin-msg')
		form.addEventListener('submit', async (e) => {
			e.preventDefault()
			msg.className = 'rc-msg'
			msg.textContent = T('Saving …')
			try {
				const res = await fetch(OC.generateUrl('/apps/remote_calendars/api/admin'), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					body: JSON.stringify({
						presets: document.getElementById('rc-admin-presets').value,
						interval: parseInt(document.getElementById('rc-admin-interval').value, 10) || 5,
						allowLocal: document.getElementById('rc-admin-local').checked,
					}),
				})
				const data = await res.json()
				if (!res.ok) throw new Error(data.error || res.statusText)
				msg.className = 'rc-msg rc-ok'
				msg.textContent = T('Saved')
			} catch (err) {
				msg.className = 'rc-msg rc-err'
				msg.textContent = err.message
			}
		})
	})
})()
