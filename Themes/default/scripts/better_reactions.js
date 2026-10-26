/** Better Reactions & Likes 0.1.0 */
(() => {
	'use strict';

	const VERSION = '0.1.0';
	const CLOSE_DELAY = 260;
	const fallbackReactions = [
		{key: 'like', label: 'Like', glyph: '👍', image: '', native: true},
		{key: 'love', label: 'Love', glyph: '❤️', image: ''},
		{key: 'laugh', label: 'Laugh', glyph: '😂', image: ''},
		{key: 'wow', label: 'Wow', glyph: '😮', image: ''},
		{key: 'sad', label: 'Sad', glyph: '😢', image: ''},
		{key: 'celebrate', label: 'Celebrate', glyph: '🎉', image: ''},
		{key: 'fire', label: 'Fire', glyph: '🔥', image: ''}
	];
	const reactions = Array.isArray(window.betterReactionsTypes) && window.betterReactionsTypes.length
		? window.betterReactionsTypes
		: fallbackReactions;
	const brConfig = window.betterReactionsConfig || {showCounts: true, showWho: true, profileLinks: true, whoHover: true, showLabels: true, animateIcons: true, iconSize: 'comfortable', pickerVisible: 7, pickerTitle: 'React', pickerAll: 'All', pickerMore: 'More', pickerLess: 'Less', reactionFailed: 'Reaction failed', topicStateFallback: 'State', topicStateTitle: '%1$d reaction%2$s — threshold %3$d', whoMore: 'and %d more', chooseReaction: 'Choose a reaction', switchLikeFailed: 'Could not switch to Like', pickerCategories: true};

	let topicState = window.betterReactionsTopicState || {topic: 0, states: []};

	function renderTopicStates(states) {
		topicState.states = Array.isArray(states) ? states : [];
		let holder = document.querySelector('.br-topic-states');
		const title = document.querySelector('.display_title, #forumposts .cat_bar .catbg');
		if (!title) return;
		if (!holder) { holder = document.createElement('span'); holder.className = 'br-topic-states'; title.appendChild(holder); }
		holder.innerHTML = '';
		topicState.states.forEach(state => {
			const badge = document.createElement(state.message ? 'a' : 'span');
			badge.className = 'br-topic-state';
			if (state.message) badge.href = `${scriptUrl()}?topic=${Number(topicState.topic || 0)}.msg${Number(state.message)}#msg${Number(state.message)}`;
			if (state.image) { const img=document.createElement('img'); img.src=state.image; img.alt=''; badge.appendChild(img); }
			else { const icon=document.createElement('span'); icon.setAttribute('aria-hidden','true'); icon.textContent=state.glyph || '✓'; badge.appendChild(icon); }
			badge.appendChild(document.createTextNode(' ' + (state.label || brConfig.topicStateFallback || 'State')));
			badge.title = String(brConfig.topicStateTitle || '%1$d reaction%2$s — threshold %3$d').replace('%1$d', String(Number(state.count || 0))).replace('%2$s', Number(state.count || 0) === 1 ? '' : 's').replace('%3$d', String(Number(state.threshold || 1)));
			holder.appendChild(badge);
		});
		holder.hidden = topicState.states.length === 0;
	}

	async function refreshTopicStates() {
		if (!Number(topicState.topic || 0)) return;
		try {
			const response = await fetch(endpoint({sa:'topicstate', topic:Number(topicState.topic)}), {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin',cache:'no-store'});
			const data = await response.json();
			if (response.ok && data.ok) renderTopicStates(data.states || []);
		} catch (err) { console.error('Better Reactions topic state:', err); }
	}

	const thumbSvg = '<svg class="br-thumb" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.4 10.2 11.7 3c.3-.7 1.2-1 1.8-.6 1.4.8 2 2.5 1.5 4l-.8 2.5h4.4c1.8 0 3 1.7 2.5 3.4l-1.8 6.3a2.7 2.7 0 0 1-2.6 2H8.4V10.2Zm-5.5.2h3.3v10.2H2.9V10.4Z"/></svg>';
	const touchLikeMode = window.matchMedia && window.matchMedia('(hover: none), (pointer: coarse)').matches;
	const reactorCache = new Map();
	let pinnedWho = null;


	function scriptUrl() {
		if (typeof window.smf_scripturl === 'string' && window.smf_scripturl.length)
			return window.smf_scripturl;
		return window.location.origin + window.location.pathname;
	}

	function endpoint(params = {}) {
		const url = new URL(scriptUrl(), window.location.href);
		url.searchParams.set('action', 'betterreactions');
		Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, String(value)));
		return url.toString();
	}

	function setPickerState(control, open) {
		const picker = control.querySelector('.br-picker');
		const native = control.querySelector('a.msg_like');
		if (!picker || !native) return;
		picker.hidden = !open;
		control.classList.toggle('br-picker-open', open);
		native.setAttribute('aria-expanded', open ? 'true' : 'false');
	}

	function closeOtherPickers(except) {
		document.querySelectorAll('.smflikebutton.better-reactions-native.br-picker-open').forEach(control => {
			if (control !== except) setPickerState(control, false);
		});
	}

	function scheduleClose(control) {
		window.clearTimeout(Number(control.dataset.brCloseTimer || 0));
		const timer = window.setTimeout(() => setPickerState(control, false), CLOSE_DELAY);
		control.dataset.brCloseTimer = String(timer);
	}

	function cancelClose(control) {
		window.clearTimeout(Number(control.dataset.brCloseTimer || 0));
		control.dataset.brCloseTimer = '0';
	}

	function showError(control, message) {
		let box = control.parentElement ? control.parentElement.querySelector('.br-inline-error') : null;
		if (!box && control.parentElement) {
			box = document.createElement('span');
			box.className = 'br-inline-error';
			control.parentElement.appendChild(box);
		}
		if (!box) return;
		box.textContent = message || brConfig.reactionFailed || 'Reaction failed';
		box.hidden = false;
		window.clearTimeout(Number(box.dataset.timer || 0));
		box.dataset.timer = String(window.setTimeout(() => { box.hidden = true; }, 3500));
	}

	function nativeIsLiked(native) {
		return !!(native && native.querySelector('.main_icons.unlike'));
	}

	async function clearCustomReaction(id, control) {
		const body = new URLSearchParams();
		body.set('sa', 'clear');
		body.set('id', String(id));
		if (typeof window.smf_session_var === 'string' && typeof window.smf_session_id === 'string')
		body.set(window.smf_session_var, window.smf_session_id);

		const response = await fetch(endpoint(), {
			method: 'POST',
			headers: {
				'Accept': 'application/json',
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: body.toString(),
			credentials: 'same-origin',
			cache: 'no-store'
		});

		const raw = await response.text();
		let data;
		try { data = JSON.parse(raw); }
		catch (e) { throw new Error('Reaction server returned invalid JSON (' + response.status + ')'); }
		if (!response.ok || !data.ok) throw new Error(data.error || 'Could not clear current reaction');

		for (const cacheKey of [...reactorCache.keys()]) if (cacheKey.startsWith(`${id}:`)) reactorCache.delete(cacheKey);
		renderSummary(id, data.summary || {}, '');
		markSelected(control, '');
		if (Array.isArray(data.topic_states)) renderTopicStates(data.topic_states);
		return data;
	}

	function waitForNativeLikeState(control, liked, timeout = 4000) {
		return new Promise((resolve, reject) => {
			const matches = () => nativeIsLiked(control.querySelector('a.msg_like')) === liked;
			if (matches()) return resolve();

			const observer = new MutationObserver(() => {
				if (matches()) {
					observer.disconnect();
					window.clearTimeout(timer);
					resolve();
				}
			});
			observer.observe(control, {childList: true, subtree: true, attributes: true, attributeFilter: ['class']});
			const timer = window.setTimeout(() => {
				observer.disconnect();
				reject(new Error('SMF Like action did not finish'));
			}, timeout);
		});
	}

	async function react(id, key, control) {
		const body = new URLSearchParams();
		body.set('sa', 'react');
		body.set('id', String(id));
		body.set('reaction', key);
		if (typeof window.smf_session_var === 'string' && typeof window.smf_session_id === 'string')
			body.set(window.smf_session_var, window.smf_session_id);

		const response = await fetch(endpoint(), {
			method: 'POST',
			headers: {
				'Accept': 'application/json',
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: body.toString(),
			credentials: 'same-origin',
			cache: 'no-store'
		});

		const raw = await response.text();
		let data;
		try {
			data = JSON.parse(raw);
		} catch (e) {
			throw new Error('Reaction server returned invalid JSON (' + response.status + ')');
		}
		if (!response.ok || !data.ok)
			throw new Error(data.error === 'server_error' ? (brConfig.reactionFailed || 'Reaction failed') : (data.error || ((brConfig.reactionFailed || 'Reaction failed') + ' (' + response.status + ')')));

		for (const cacheKey of [...reactorCache.keys()]) if (cacheKey.startsWith(`${id}:`)) reactorCache.delete(cacheKey);
		renderSummary(id, data.summary || {}, data.selected || '');
		markSelected(control, data.selected || '');
		if (Array.isArray(data.topic_states)) renderTopicStates(data.topic_states);
		return data;
	}

	function markSelected(control, selected) {
		control.dataset.brSelected = selected || '';
		control.querySelectorAll('.br-reaction-option').forEach(option => {
			const active = option.dataset.reaction === selected;
			option.classList.toggle('is-selected', active);
			option.setAttribute('aria-pressed', active ? 'true' : 'false');
			if (active && option.classList.contains('br-picker-extra')) {
				const picker = option.closest('.br-picker');
				if (picker) {
					picker.classList.add('br-picker-expanded');
					const more = picker.querySelector('.br-picker-more');
					if (more) { more.textContent = brConfig.pickerLess || 'Less'; more.setAttribute('aria-expanded', 'true'); }
				}
			}
		});
	}

	function enhance(control) {
		if (!control) return;
		const native = control.querySelector('a.msg_like');
		if (!native) return;
		const match = control.id.match(/^msg_(\d+)_likes$/);
		if (!match) return;
		const id = Number(match[1]);

		// SMF redraws the contents of the native Like <li> after a Like/Unlike
		// AJAX request. The <li> can therefore remain marked as enhanced while
		// its replacement <a class="msg_like"> has lost our markup/listeners.
		// Only bail out when the *current* native anchor and picker are ready.
		if (
			control.dataset.brEnhanced === '1'
			&& native.dataset.brNativeWired === '1'
			&& native.querySelector('.br-thumb')
			&& control.querySelector('.br-picker')
		) return;

		const firstEnhance = control.dataset.brEnhanced !== '1';
		control.dataset.brEnhanced = '1';
		control.classList.add('better-reactions-native');

		const oldIcon = native.querySelector('.main_icons');
		if (oldIcon) oldIcon.classList.add('br-stock-like-icon');
		native.classList.add('br-like-button');
		native.setAttribute('aria-haspopup', 'menu');
		native.setAttribute('aria-expanded', 'false');
		if (!native.querySelector('.br-thumb')) native.insertAdjacentHTML('afterbegin', thumbSvg);

		let picker = control.querySelector('.br-picker');
		if (!picker) {
			picker = document.createElement('div');
			picker.className = 'br-picker';
			picker.hidden = true;
			picker.setAttribute('role', 'menu');
			picker.setAttribute('aria-label', brConfig.chooseReaction || 'Choose a reaction');

			const head = document.createElement('div');
			head.className = 'br-picker-head';
			head.textContent = brConfig.pickerTitle || 'React';
			picker.appendChild(head);

			const categories = [...new Set(reactions.map(r => String(r.category || 'General').trim() || 'General'))];
			const useCategories = brConfig.pickerCategories !== false && categories.length > 1;
			if (useCategories) {
				const tabs = document.createElement('div');
				tabs.className = 'br-category-tabs';
				[brConfig.pickerAll || 'All', ...categories].forEach((category, index) => {
					const tab = document.createElement('button');
					tab.type = 'button';
					tab.className = 'br-category-tab' + (index === 0 ? ' br-category-active' : '');
					tab.dataset.category = category;
					tab.textContent = category;
					tab.setAttribute('aria-pressed', index === 0 ? 'true' : 'false');
					tabs.appendChild(tab);
				});
				picker.appendChild(tabs);
			}

			const grid = document.createElement('div');
			grid.className = 'br-picker-grid';
			const visibleLimit = Math.max(3, Math.min(12, Number(brConfig.pickerVisible || 7)));
			reactions.forEach((r, index) => {
				const button = document.createElement('button');
				button.type = 'button';
				button.className = 'br-reaction-option' + (index >= visibleLimit ? ' br-picker-extra' : '');
				button.dataset.reaction = r.key;
				button.dataset.category = String(r.category || 'General').trim() || 'General';
				button.title = r.label;
				button.setAttribute('aria-label', r.label);
				button.setAttribute('aria-pressed', 'false');
				button.innerHTML = `${r.image ? `<img class="br-reaction-image" src="${r.image}" alt="">` : `<span class="br-glyph" aria-hidden="true">${r.glyph}</span>`}<span class="br-reaction-label">${r.label}</span>`;
				grid.appendChild(button);
			});
			picker.appendChild(grid);

			if (reactions.length > visibleLimit) {
				const more = document.createElement('button');
				more.type = 'button';
				more.className = 'br-picker-more';
				more.setAttribute('aria-expanded', 'false');
				more.textContent = brConfig.pickerMore || 'More';
				more.addEventListener('click', e => {
					e.preventDefault();
					e.stopPropagation();
					const expanded = picker.classList.toggle('br-picker-expanded');
					more.setAttribute('aria-expanded', expanded ? 'true' : 'false');
					more.textContent = expanded ? (brConfig.pickerLess || 'Less') : (brConfig.pickerMore || 'More');
				});
				picker.appendChild(more);
			}
			control.appendChild(picker);
		}

		let chips = document.querySelector(`.br-summary[data-br-message="${id}"]`);
		if (brConfig.showCounts !== false && !chips) {
			chips = document.createElement('span');
			chips.className = 'br-summary';
			chips.dataset.brMessage = String(id);
			const list = control.closest('ul');
			if (list) list.insertAdjacentElement('afterend', chips);
			else if (control.parentElement) control.parentElement.appendChild(chips);
		} else if (brConfig.showCounts === false && chips) {
			chips.remove();
		}

		// These listeners belong to the persistent <li>, so install them once.
		if (firstEnhance) {
			control.addEventListener('mouseenter', () => cancelClose(control));
			control.addEventListener('mouseleave', () => {
				if (!touchLikeMode) scheduleClose(control);
			});
		}

		// These listeners belong to SMF's replaceable native anchor. Reinstall
		// them whenever SMF has supplied a fresh <a class="msg_like"> node.
		if (native.dataset.brNativeWired !== '1') {
			native.dataset.brNativeWired = '1';

			native.addEventListener('mouseenter', () => {
				if (touchLikeMode) return;
				cancelClose(control);
				closeOtherPickers(control);
				setPickerState(control, true);
			});

			native.addEventListener('focus', () => {
				closeOtherPickers(control);
				setPickerState(control, true);
			});

			// Touch has no hover. First tap opens the tray. A tray-triggered native
			// Like uses the bypass flag so SMF receives its normal click untouched.
			native.addEventListener('click', e => {
				if (native.dataset.brNativeBypass === '1') {
					native.dataset.brNativeBypass = '0';
					return;
				}

				// A native Like replaces any Better Reactions selection. Do this even
				// when the member clicks the visible Like control directly rather than
				// choosing 👍 from the tray.
				if (control.dataset.brSelected && !nativeIsLiked(native)) {
					e.preventDefault();
					e.stopImmediatePropagation();
					const selected = control.dataset.brSelected;
					control.classList.add('br-working');
					clearCustomReaction(id, control).then(() => {
						const currentNative = control.querySelector('a.msg_like');
						if (currentNative) {
							currentNative.dataset.brNativeBypass = '1';
							currentNative.click();
						}
					}).catch(err => {
						control.dataset.brSelected = selected;
						console.error('Better Reactions:', err);
						showError(control, err.message || brConfig.switchLikeFailed || 'Could not switch to Like');
					}).finally(() => control.classList.remove('br-working'));
					return;
				}

				if (!touchLikeMode) return;

				e.preventDefault();
				e.stopImmediatePropagation();
				closeOtherPickers(control);
				setPickerState(control, !control.classList.contains('br-picker-open'));
			}, true);
		}

		if (picker.dataset.brPickerWired !== '1') {
			picker.dataset.brPickerWired = '1';
			picker.addEventListener('mouseenter', () => cancelClose(control));
			picker.addEventListener('mouseleave', () => {
				if (!touchLikeMode) scheduleClose(control);
			});

			picker.addEventListener('keydown', e => {
				if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(e.key)) return;
				const buttons = [...picker.querySelectorAll('.br-reaction-option')].filter(button => button.offsetParent !== null && !button.disabled);
				if (!buttons.length) return;
				e.preventDefault();
				let index = buttons.indexOf(document.activeElement);
				if (e.key === 'Home') index = 0;
				else if (e.key === 'End') index = buttons.length - 1;
				else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') index = index <= 0 ? buttons.length - 1 : index - 1;
				else index = index < 0 || index >= buttons.length - 1 ? 0 : index + 1;
				buttons[index].focus();
			});

			picker.addEventListener('click', async e => {
				const categoryTab = e.target.closest('.br-category-tab');
				if (categoryTab) {
					e.preventDefault();
					e.stopPropagation();
					const allLabel = brConfig.pickerAll || 'All';
					const category = categoryTab.dataset.category || allLabel;
					picker.querySelectorAll('.br-category-tab').forEach(tab => {
						const active = tab === categoryTab;
						tab.classList.toggle('br-category-active', active);
						tab.setAttribute('aria-pressed', active ? 'true' : 'false');
					});
					picker.querySelectorAll('.br-reaction-option').forEach(option => {
						option.hidden = category !== allLabel && option.dataset.category !== category;
					});
					const more = picker.querySelector('.br-picker-more');
					if (category === allLabel) {
						picker.classList.remove('br-picker-category-view', 'br-picker-expanded');
						if (more) { more.hidden = false; more.setAttribute('aria-expanded', 'false'); more.textContent = brConfig.pickerMore || 'More'; }
					} else {
						picker.classList.add('br-picker-category-view', 'br-picker-expanded');
						if (more) more.hidden = true;
					}
					const first = picker.querySelector('.br-reaction-option:not([hidden])');
					if (first) first.focus();
					return;
				}

				const button = e.target.closest('.br-reaction-option');
				if (!button) return;
				e.preventDefault();
				e.stopPropagation();
				const key = button.dataset.reaction;

				if (key === 'like') {
					picker.querySelectorAll('.br-reaction-option').forEach(b => { b.disabled = true; });
					control.classList.add('br-working');
					try {
						if (control.dataset.brSelected) await clearCustomReaction(id, control);
						setPickerState(control, false);
						const currentNative = control.querySelector('a.msg_like');
						if (currentNative) {
							currentNative.dataset.brNativeBypass = '1';
							currentNative.click();
						}
					} catch (err) {
						console.error('Better Reactions:', err);
						showError(control, err.message || brConfig.switchLikeFailed || 'Could not switch to Like');
					} finally {
						picker.querySelectorAll('.br-reaction-option').forEach(b => { b.disabled = false; });
						control.classList.remove('br-working');
					}
					return;
				}

				picker.querySelectorAll('.br-reaction-option').forEach(b => { b.disabled = true; });
				control.classList.add('br-working');
				try {
					// Non-Like reactions replace a native Like. Let SMF perform its own
					// Unlike AJAX first so its counters, alerts and markup stay authoritative.
					const currentNative = control.querySelector('a.msg_like');
					if (nativeIsLiked(currentNative)) {
						currentNative.dataset.brNativeBypass = '1';
						currentNative.click();
						await waitForNativeLikeState(control, false);
					}
					await react(id, key, control);
					setPickerState(control, false);
				} catch (err) {
					console.error('Better Reactions:', err);
					showError(control, err.message || brConfig.reactionFailed || 'Reaction failed');
				} finally {
					picker.querySelectorAll('.br-reaction-option').forEach(b => { b.disabled = false; });
					control.classList.remove('br-working');
				}
			});
		}
	}

	function closeWho(except = null) {
		document.querySelectorAll('.br-who.br-open').forEach(pop => {
			if (pop !== except) {
				pop.classList.remove('br-open', 'br-pinned');
				pop.hidden = true;
			}
		});
		if (!except) pinnedWho = null;
	}

	function reactorKey(id, reaction) {
		return `${id}:${reaction}`;
	}

	async function getReactors(id, reaction) {
		const key = reactorKey(id, reaction);
		if (reactorCache.has(key)) return reactorCache.get(key);

		const promise = (async () => {
			const response = await fetch(endpoint({sa: 'reactors', id, reaction}), {
				headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
				credentials: 'same-origin',
				cache: 'no-store'
			});
			const raw = await response.text();
			const data = JSON.parse(raw);
			if (!response.ok || !data.ok) throw new Error(data.error || 'Could not load reactions');
			return data;
		})();

		reactorCache.set(key, promise);
		try { return await promise; }
		catch (err) { reactorCache.delete(key); throw err; }
	}

	function buildWhoContent(pop, reaction, data) {
		pop.innerHTML = '';
		const title = document.createElement('div');
		title.className = 'br-who-title';
		if (reaction.image) { const img = document.createElement('img'); img.className = 'br-who-icon'; img.src = reaction.image; img.alt = ''; title.appendChild(img); title.appendChild(document.createTextNode(' ' + reaction.label)); } else { title.textContent = `${reaction.glyph} ${reaction.label}`; }
		pop.appendChild(title);

		const list = document.createElement('div');
		list.className = 'br-who-list';
		(data.names || []).forEach(person => {
			const row = document.createElement('div');
			row.className = 'br-who-name';

			if (person.url) {
				const link = document.createElement('a');
				link.className = 'br-who-profile';
				link.href = person.url;
				link.textContent = person.name || 'Former member';
				row.appendChild(link);
			} else {
				row.textContent = person.name || 'Former member';
			}

			list.appendChild(row);
		});
		if (!list.children.length) {
			const row = document.createElement('div');
			row.className = 'br-who-name';
			row.textContent = 'No reactions yet';
			list.appendChild(row);
		}
		pop.appendChild(list);

		if (data.truncated) {
			const more = document.createElement('div');
			more.className = 'br-who-more';
			more.textContent = String(brConfig.whoMore || 'and %d more').replace('%d', String(Math.max(0, Number(data.total || 0) - (data.names || []).length)));
			pop.appendChild(more);
		}
	}

	async function showWho(chip, pin = false) {
		const id = Number(chip.dataset.brMessage || 0);
		const key = chip.dataset.reaction || '';
		const reaction = reactions.find(r => r.key === key);
		if (!id || !reaction) return;

		let pop = chip.querySelector('.br-who');
		if (!pop) {
			pop = document.createElement('span');
			pop.className = 'br-who';
			pop.hidden = true;
			chip.appendChild(pop);
		}

		closeWho(pop);
		pop.hidden = false;
		pop.classList.add('br-open');
		if (pin) {
			pop.classList.add('br-pinned');
			pinnedWho = pop;
		}
		if (!pop.dataset.loaded) {
			pop.innerHTML = '<span class="br-who-loading">Loading…</span>';
			try {
				const data = await getReactors(id, key);
				buildWhoContent(pop, reaction, data);
				pop.dataset.loaded = '1';
			} catch (err) {
				pop.innerHTML = '';
				const error = document.createElement('span');
				error.className = 'br-who-loading';
				error.textContent = 'Could not load names';
				pop.appendChild(error);
				console.error('Better Reactions reactors:', err);
			}
		}
	}

	function wireWhoChip(chip) {
		if (chip.dataset.brWhoWired === '1') return;
		chip.dataset.brWhoWired = '1';
		chip.tabIndex = 0;
		chip.setAttribute('role', 'button');
		chip.setAttribute('aria-label', `${chip.dataset.reactionLabel || 'Reaction'}: show who reacted`);

		chip.addEventListener('mouseenter', () => {
			if (!touchLikeMode && brConfig.whoHover !== false && !pinnedWho) showWho(chip, false);
		});
		chip.addEventListener('mouseleave', () => {
			const pop = chip.querySelector('.br-who');
			if (pop && !pop.classList.contains('br-pinned')) {
				pop.classList.remove('br-open');
				pop.hidden = true;
			}
		});
		chip.addEventListener('click', e => {
			e.preventDefault();
			e.stopPropagation();
			const pop = chip.querySelector('.br-who');
			if (pop && pop.classList.contains('br-pinned')) {
				pop.classList.remove('br-open', 'br-pinned');
				pop.hidden = true;
				pinnedWho = null;
				return;
			}
			showWho(chip, true);
		});
		chip.addEventListener('keydown', e => {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				chip.click();
			}
		});
	}

	function renderSummary(id, data, selected) {
		if (brConfig.showCounts === false) {
			const control = document.getElementById(`msg_${id}_likes`);
			if (control) markSelected(control, selected || (data && data.mine) || '');
			return;
		}
		const target = document.querySelector(`.br-summary[data-br-message="${id}"]`);
		if (!target) return;
		const counts = data && data.counts ? data.counts : {};
		const mine = selected || (data && data.mine) || '';
		target.innerHTML = '';

		reactions.filter(r => r.key !== 'like' && Number(counts[r.key] || 0) > 0).forEach(r => {
			const chip = document.createElement('span');
			chip.className = 'br-chip' + (mine === r.key ? ' is-mine' : '');
			chip.dataset.brMessage = String(id);
			chip.dataset.reaction = r.key;
			chip.dataset.reactionLabel = r.label;
			chip.title = `${r.label}: ${counts[r.key]} — hover or click to see who reacted`;
			chip.innerHTML = `${r.image ? `<img class="br-chip-image" src="${r.image}" alt="">` : `<span aria-hidden="true">${r.glyph}</span>`}<strong>${counts[r.key]}</strong>`;
			target.appendChild(chip);
			if (brConfig.showWho !== false) wireWhoChip(chip);
		});

		const control = document.getElementById(`msg_${id}_likes`);
		if (control) markSelected(control, mine);
	}

	async function loadSummaries() {
		const ids = [...document.querySelectorAll('.br-summary[data-br-message]')]
			.map(el => el.dataset.brMessage)
			.filter(Boolean);
		if (!ids.length) return;
		try {
			const response = await fetch(endpoint({sa: 'summary', ids: ids.join(',')}), {
				headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
				credentials: 'same-origin',
				cache: 'no-store'
			});
			const raw = await response.text();
			const data = JSON.parse(raw);
			if (!response.ok || !data.ok) throw new Error(data.error || 'Summary request failed');
			Object.entries(data.summary || {}).forEach(([id, summary]) => renderSummary(id, summary, summary.mine || ''));
		} catch (err) {
			console.error('Better Reactions summary:', err);
		}
	}

	function watchNativeLikeRedraws() {
		if (!window.MutationObserver || !document.body) return;

		let queued = false;
		const refresh = () => {
			queued = false;
			document.querySelectorAll('li.smflikebutton').forEach(enhance);
			if (Number(topicState.topic || 0)) refreshTopicStates();
		};

		const observer = new MutationObserver(mutations => {
			const relevant = mutations.some(mutation => {
				const target = mutation.target.nodeType === 1 ? mutation.target : mutation.target.parentElement;
				if (target && target.closest && target.closest('li.smflikebutton')) return true;
				return [...mutation.addedNodes].some(node => node.nodeType === 1 && (
					node.matches?.('li.smflikebutton, a.msg_like')
					|| node.querySelector?.('li.smflikebutton, a.msg_like')
				));
			});

			if (relevant && !queued) {
				queued = true;
				window.requestAnimationFrame(refresh);
			}
		});

		observer.observe(document.body, {childList: true, subtree: true});
	}

	function init() {
		document.documentElement.classList.toggle('br-hide-labels', brConfig.showLabels === false);
		document.documentElement.classList.toggle('br-no-icon-animation', brConfig.animateIcons === false);
		document.documentElement.classList.add('br-icon-size-' + (['compact','comfortable','large'].includes(brConfig.iconSize) ? brConfig.iconSize : 'comfortable'));
		document.querySelectorAll('li.smflikebutton').forEach(enhance);
		watchNativeLikeRedraws();
		loadSummaries();
		renderTopicStates(topicState.states || []);

		document.addEventListener('click', e => {
			if (!e.target.closest('.smflikebutton.better-reactions-native')) closeOtherPickers(null);
			if (!e.target.closest('.br-chip')) closeWho(null);
		});
		document.addEventListener('keydown', e => {
			if (e.key === 'Escape') { closeOtherPickers(null); closeWho(null); }
		});
	}

	document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init) : init();
	window.BetterReactions = {version: VERSION, ready: true};
})();
