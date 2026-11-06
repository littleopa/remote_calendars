/* Remote Calendars – personal settings (no build step, plain DOM) */
(function () {
	'use strict'
	const APP = 'remote_calendars'
	const T = (s, v) => t(APP, s, v)
	const url = (p) => OC.generateUrl('/apps/' + APP + '/api' + p)

	async function api(method, path, body) {
		const res = await fetch(url(path), {
			method,
			headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
			body: body ? JSON.stringify(body) : undefined,
		})
		let data = {}
		try { data = await res.json() } catch (e) { /* empty */ }
		if (!res.ok) throw new Error(data.error || (T('Request failed') + ' (HTTP ' + res.status + ')'))
		return data
	}

	function el(tag, attrs, ...children) {
		const e = document.createElement(tag)
		for (const [k, v] of Object.entries(attrs || {})) {
			if (v === null || v === undefined || v === false) continue
			if (k === 'class') e.className = v
			else if (k.startsWith('on')) e.addEventListener(k.slice(2), v)
			else if (k === 'style') e.setAttribute('style', v)
			else e.setAttribute(k, v === true ? '' : v)
		}
		for (const c of children.flat()) {
			if (c === null || c === undefined || c === false) continue
			e.append(c instanceof Node ? c : document.createTextNode(String(c)))
		}
		return e
	}

	function ago(ts) {
		if (!ts) return T('not yet synchronised')
		const s = Math.max(0, Math.round(Date.now() / 1000 - ts))
		if (s < 60) return T('synchronised just now')
		const m = Math.round(s / 60)
		if (m < 60) return n(APP, 'synchronised %n minute ago', 'synchronised %n minutes ago', m)
		const h = Math.round(m / 60)
		if (h < 48) return n(APP, 'synchronised %n hour ago', 'synchronised %n hours ago', h)
		return T('synchronised on {date}', { date: new Date(ts * 1000).toLocaleString() })
	}

	function host(u) {
		try { return new URL(u).host } catch (e) { return u }
	}

	function statsText(s) {
		if (!s) return ''
		const parts = []
		if (s.pulled) parts.push(n(APP, '%n entry fetched', '%n entries fetched', s.pulled))
		if (s.pushed) parts.push(n(APP, '%n change sent', '%n changes sent', s.pushed))
		if (s.deleted) parts.push(n(APP, '%n entry removed', '%n entries removed', s.deleted))
		if (s.conflicts) parts.push(n(APP, '%n conflict (server version kept)', '%n conflicts (server version kept)', s.conflicts))
		if (s.skipped) parts.push(n(APP, '%n entry skipped', '%n entries skipped', s.skipped))
		if (s.locked) parts.push(T('a synchronisation is already running'))
		return parts.length ? parts.join(', ') : T('everything up to date')
	}

	/** Button that asks for confirmation on first click. */
	function confirmButton(label, confirmLabel, action) {
		let armed = false
		let timer = null
		const b = el('button', { type: 'button', class: 'rc-danger' }, label)
		b.addEventListener('click', async () => {
			if (!armed) {
				armed = true
				b.textContent = confirmLabel
				b.classList.add('rc-armed')
				timer = setTimeout(() => { armed = false; b.textContent = label; b.classList.remove('rc-armed') }, 5000)
				return
			}
			clearTimeout(timer)
			b.disabled = true
			await action(b)
		})
		return b
	}

	const state = { accounts: [], presets: [], interval: 5 }

	// ------------------------------------------------------------ accounts
	async function load() {
		const box = document.getElementById('rc-accounts')
		try {
			const data = await api('GET', '/accounts')
			state.accounts = data.accounts
			renderAccounts()
		} catch (e) {
			box.replaceChildren(el('p', { class: 'rc-msg rc-err' }, e.message))
		}
	}

	function renderAccounts() {
		const box = document.getElementById('rc-accounts')
		document.getElementById('rc-add-title').textContent = state.accounts.length ? T('Connect another calendar') : T('Connect a calendar')
		if (!state.accounts.length) {
			box.replaceChildren(el('p', { class: 'rc-empty' }, T('No remote calendars connected yet.')))
			return
		}
		box.replaceChildren(...state.accounts.map(renderAccount))
	}

	function renderAccount(a) {
		const msg = el('p', { class: 'rc-msg', role: 'status' })
		const extra = el('div', { class: 'rc-extra' })
		const card = el('div', { class: 'rc-card' },
			el('div', { class: 'rc-card-head' },
				el('div', {},
					el('strong', {}, host(a.url)),
					el('span', { class: 'rc-sub' }, ' · ' + a.username)),
				el('div', { class: 'rc-card-buttons' },
					el('button', { type: 'button', onclick: () => moreCalendars(a, extra, msg) }, T('More calendars')),
					el('button', { type: 'button', onclick: () => passwordForm(a, extra, msg) }, T('Change password')),
					confirmButton(T('Remove account'), T('Really remove? (click again)'), async () => {
						try { await api('DELETE', '/accounts/' + a.id); await load() } catch (e) { msg.className = 'rc-msg rc-err'; msg.textContent = e.message }
					}))),
			el('ul', { class: 'rc-list' }, a.calendars.length
				? a.calendars.map((c) => renderBinding(c, msg))
				: el('li', { class: 'rc-empty' }, T('No calendar of this account is connected.'))),
			extra, msg)
		return card
	}

	function renderBinding(c, msg) {
		const status = c.error
			? el('span', { class: 'rc-status rc-err' }, '⚠ ' + c.error)
			: el('span', { class: 'rc-status' }, ago(c.lastSync))
		const syncBtn = el('button', { type: 'button' }, T('Sync now'))
		syncBtn.addEventListener('click', async () => {
			syncBtn.disabled = true
			syncBtn.textContent = T('Synchronising …')
			msg.className = 'rc-msg'
			msg.textContent = ''
			try {
				const r = await api('POST', '/bindings/' + c.id + '/sync')
				await load()
				const m = document.querySelector('#rc-accounts .rc-msg')
				if (m) { m.className = 'rc-msg rc-ok'; m.textContent = c.name + ': ' + statsText(r.stats) }
			} catch (e) {
				await load()
			}
		})
		return el('li', { class: 'rc-item' },
			el('span', { class: 'rc-dot', style: 'background:' + (/^#[0-9a-f]{6}$/i.test(c.color) ? c.color : 'var(--color-primary-element)') }),
			el('div', { class: 'rc-item-main' },
				el('div', {}, el('strong', {}, c.name),
					c.readonly ? el('span', { class: 'rc-badge', title: T('The remote server only allows reading. Changes in Nextcloud are reverted.') }, T('read-only')) : null,
					c.name !== c.remoteName ? el('span', { class: 'rc-sub' }, ' (' + c.remoteName + ')') : null),
				status),
			el('div', { class: 'rc-item-buttons' },
				syncBtn,
				confirmButton(T('Remove'), T('Remove from Nextcloud?'), async () => {
					try { await api('DELETE', '/bindings/' + c.id); await load() } catch (e) { msg.className = 'rc-msg rc-err'; msg.textContent = e.message }
				})))
	}

	function passwordForm(a, extra, msg) {
		const input = el('input', { type: 'password', autocomplete: 'new-password', placeholder: T('New password'), 'aria-label': T('New password') })
		const btn = el('button', { type: 'submit', class: 'primary' }, T('Save'))
		const form = el('form', { class: 'rc-inline' }, input, btn,
			el('button', { type: 'button', onclick: () => extra.replaceChildren() }, T('Cancel')))
		form.addEventListener('submit', async (e) => {
			e.preventDefault()
			btn.disabled = true
			msg.className = 'rc-msg'
			msg.textContent = T('Checking …')
			try {
				await api('POST', '/accounts/' + a.id + '/password', { password: input.value })
				await load()
			} catch (err) {
				btn.disabled = false
				msg.className = 'rc-msg rc-err'
				msg.textContent = err.message
			}
		})
		extra.replaceChildren(form)
		input.focus()
	}

	async function moreCalendars(a, extra, msg) {
		msg.className = 'rc-msg'
		msg.textContent = T('Searching …')
		try {
			const data = await api('POST', '/accounts/' + a.id + '/discover')
			msg.textContent = ''
			extra.replaceChildren(chooser(data.calendars, async (urls) => {
				const r = await api('POST', '/accounts/' + a.id + '/calendars', { calendars: urls })
				return r.results
			}, () => extra.replaceChildren()))
		} catch (e) {
			msg.className = 'rc-msg rc-err'
			msg.textContent = e.message
		}
	}

	// ------------------------------------------------------------ choose calendars
	function chooser(calendars, connect, onDone) {
		const msg = el('p', { class: 'rc-msg', role: 'status' })
		const boxes = []
		const list = el('ul', { class: 'rc-list rc-choose' }, calendars.map((c, i) => {
			const id = 'rc-c-' + Math.random().toString(36).slice(2) + i
			const cb = el('input', { type: 'checkbox', class: 'checkbox', id, checked: !c.connected && calendars.filter((x) => !x.connected).length === 1, disabled: c.connected })
			cb.dataset.url = c.url
			boxes.push(cb)
			return el('li', { class: 'rc-item' },
				cb,
				el('label', { for: id },
					el('span', { class: 'rc-dot', style: 'background:' + (c.color || 'var(--color-primary-element)') }),
					c.name,
					c.writable ? null : el('span', { class: 'rc-badge' }, T('read-only')),
					c.connected ? el('span', { class: 'rc-sub' }, ' – ' + T('already connected')) : null,
					el('span', { class: 'rc-sub' }, ' · ' + c.components.map((x) => x === 'VTODO' ? T('tasks') : T('events')).join(', '))))
		}))
		const btn = el('button', { type: 'button', class: 'primary' }, T('Connect selected calendars'))
		btn.addEventListener('click', async () => {
			const urls = boxes.filter((b) => b.checked && !b.disabled).map((b) => b.dataset.url)
			if (!urls.length) { msg.className = 'rc-msg rc-err'; msg.textContent = T('Please select at least one calendar.'); return }
			btn.disabled = true
			msg.className = 'rc-msg'
			msg.textContent = T('Connecting and importing entries – this can take a moment …')
			try {
				const results = await connect(urls)
				const bad = results.filter((r) => !r.ok)
				await load()
				if (bad.length) {
					msg.className = 'rc-msg rc-err'
					msg.textContent = bad.map((r) => r.name + ': ' + r.error).join(' · ')
				} else {
					onDone(results)
				}
			} catch (e) {
				btn.disabled = false
				msg.className = 'rc-msg rc-err'
				msg.textContent = e.message
			}
		})
		return el('div', { class: 'rc-chooser' },
			el('p', {}, T('Which calendars should appear in Nextcloud?')),
			list,
			el('div', { class: 'rc-actions' }, btn),
			msg)
	}

	// ------------------------------------------------------------ add form
	function initForm() {
		const form = document.getElementById('rc-form')
		const msg = document.getElementById('rc-form-msg')
		const found = document.getElementById('rc-found')
		const presetBox = document.getElementById('rc-presets')
		if (state.presets.length) {
			presetBox.append(el('span', { class: 'rc-sub' }, T('Suggestions:') + ' '))
			for (const p of state.presets) {
				presetBox.append(el('button', {
					type: 'button',
					class: 'rc-chip',
					onclick: () => { document.getElementById('rc-url').value = p.url; document.getElementById('rc-user').focus() },
				}, p.name))
			}
		}
		form.addEventListener('submit', async (e) => {
			e.preventDefault()
			const btn = document.getElementById('rc-search')
			const creds = {
				url: document.getElementById('rc-url').value.trim(),
				username: document.getElementById('rc-user').value.trim(),
				password: document.getElementById('rc-pass').value,
			}
			btn.disabled = true
			msg.className = 'rc-msg'
			msg.textContent = T('Searching …')
			found.replaceChildren()
			try {
				const data = await api('POST', '/discover', creds)
				msg.textContent = ''
				found.replaceChildren(chooser(data.calendars, async (urls) => {
					const r = await api('POST', '/connect', { ...creds, calendars: urls })
					return r.results
				}, (results) => {
					found.replaceChildren()
					form.reset()
					msg.className = 'rc-msg rc-ok'
					msg.textContent = T('Connected. The calendar now appears in the Calendar app and can be edited there.')
				}))
			} catch (err) {
				msg.className = 'rc-msg rc-err'
				msg.textContent = err.message
			} finally {
				btn.disabled = false
			}
		})
	}

	document.addEventListener('DOMContentLoaded', () => {
		if (!document.getElementById('rc-personal')) return
		try { state.presets = OCP.InitialState.loadState(APP, 'presets') || [] } catch (e) { /* none */ }
		try { state.interval = OCP.InitialState.loadState(APP, 'interval') || 5 } catch (e) { /* default */ }
		document.getElementById('rc-intro').after(el('p', { class: 'settings-hint' }, T('Note: if you share a connected calendar with write access, changes made by those people are sent to the remote server with your login.')))
		document.getElementById('rc-intro').textContent = T('Connect calendars from other servers (CalDAV) with your own login. They appear in the Calendar app and can be edited there. Changes made in Nextcloud are sent right away, changes on the other server are fetched every {minutes} minutes. Removing a calendar here only removes the copy in Nextcloud.', { minutes: state.interval })
		initForm()
		load()
	})
})()
