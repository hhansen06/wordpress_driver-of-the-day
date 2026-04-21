/**
 * Driver of the Day — Frontend JavaScript
 *
 * Reads window.dotdInstances (set inline by the PHP shortcode) and
 * bootstraps one widget per shortcode placement.
 *
 * State machine:
 *   phase === 'before'  → show "voting starts on …"
 *   phase === 'open' && !alreadyVoted → show voting form
 *   phase === 'open' && alreadyVoted  → show live results
 *   phase === 'closed'               → show final results
 */
(function () {
	'use strict';

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text != null) e.textContent = text;
		return e;
	}

	function formatDate(dateStr) {
		// dateStr: "YYYY-MM-DD"
		if (!dateStr) return dateStr;
		var parts = dateStr.split('-');
		if (parts.length !== 3) return dateStr;
		return parts[2] + '.' + parts[1] + '.' + parts[0];
	}

	// -------------------------------------------------------------------------
	// Card builder
	// -------------------------------------------------------------------------

	function buildCard(participant, isSelectable) {
		var card = el('div', 'dotd-card');
		card.dataset.participantId = participant.id;
		card.setAttribute('role', isSelectable ? 'button' : 'article');
		if (isSelectable) {
			card.setAttribute('tabindex', '0');
			card.setAttribute('aria-pressed', 'false');
		}

		var nr = el('div', 'dotd-card-nr', '#' + participant.start_nr);
		var driver = el('div', 'dotd-card-driver', participant.driver_name);
		var codriver = el('div', 'dotd-card-codriver', participant.codriver_name || '');
		var vehicle = el('div', 'dotd-card-vehicle', participant.vehicle || '');
		var klasse = el('div', 'dotd-card-klasse', participant.klasse || '');

		card.appendChild(nr);
		card.appendChild(driver);
		if (participant.codriver_name) card.appendChild(codriver);
		if (participant.vehicle)     card.appendChild(vehicle);
		if (participant.klasse)      card.appendChild(klasse);

		return card;
	}

	// -------------------------------------------------------------------------
	// Results bar builder
	// -------------------------------------------------------------------------

	function appendResultBar(card, votes, total, t) {
		var pct = total > 0 ? (votes / total * 100) : 0;
		var pctStr = pct.toFixed(1);

		var wrap = el('div', 'dotd-bar-wrap');
		var track = el('div', 'dotd-bar-track');
		var fill = el('div', 'dotd-bar-fill');
		fill.style.width = '0%'; // animated later
		track.appendChild(fill);

		var label = el('div', 'dotd-bar-label');
		var unit = votes === 1 ? t.voteUnitSingular : t.voteUnitPlural;
		var labelVotes = el('span', '', votes + ' ' + unit);
		var labelPct = el('span', '', pctStr + ' %');
		label.appendChild(labelVotes);
		label.appendChild(labelPct);

		wrap.appendChild(track);
		wrap.appendChild(label);
		card.appendChild(wrap);

		// Animate bar after a short delay
		requestAnimationFrame(function () {
			requestAnimationFrame(function () {
				fill.style.width = pctStr + '%';
			});
		});
	}

	// -------------------------------------------------------------------------
	// Widget renderer
	// -------------------------------------------------------------------------

	function renderWidget(instance) {
		var widgetEl = document.getElementById(instance.widgetId);
		if (!widgetEl) return;

		var d = instance.data;
		var t = d.i18n;

		widgetEl.innerHTML = '';

		// --- Phase: before ---
		if (d.phase === 'before') {
			var msg = el('div', 'dotd-status-msg', t.votingNotOpen + ' ' + formatDate(d.dateFrom) + '.');
			widgetEl.appendChild(msg);
			return;
		}

		// --- Phase: closed ---
		if (d.phase === 'closed') {
			renderResults(widgetEl, d, true, t);
			return;
		}

		// --- Phase: open, already voted ---
		if (d.alreadyVoted) {
			var voted = el('div', 'dotd-status-msg is-voted', t.alreadyVoted);
			widgetEl.appendChild(voted);
			renderResults(widgetEl, d, false, t);
			return;
		}

		// --- Phase: open, not yet voted ---
		renderVotingForm(widgetEl, d, t);
	}

	// -------------------------------------------------------------------------
	// Voting form
	// -------------------------------------------------------------------------

	function renderVotingForm(widgetEl, d, t) {
		var selectedId = null;
		var grid = el('div', 'dotd-grid');
		widgetEl.appendChild(grid);

		var errorEl = el('p', 'dotd-error');
		errorEl.style.display = 'none';
		widgetEl.appendChild(errorEl);

		d.participants.forEach(function (p) {
			var card = buildCard(p, true);
			grid.appendChild(card);

			var voteBtn = el('button', 'dotd-btn dotd-btn-primary dotd-card-vote-btn', t.btnVote);
			voteBtn.type = 'button';
			card.appendChild(voteBtn);

			function markSelected() {
				var cards = grid.querySelectorAll('.dotd-card');
				cards.forEach(function (c) {
					c.classList.remove('is-selected');
					c.setAttribute('aria-pressed', 'false');
				});
				card.classList.add('is-selected');
				card.setAttribute('aria-pressed', 'true');
				selectedId = parseInt(p.id, 10);
			}

			function handleCardVoteFlow() {
				var participantId = parseInt(p.id, 10);
				if (selectedId !== participantId) {
					markSelected();
					return;
				}
				submitVote(d, participantId, widgetEl, voteBtn, errorEl, t);
			}

			card.addEventListener('click', handleCardVoteFlow);
			card.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					handleCardVoteFlow();
				}
			});

			voteBtn.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				submitVote(d, parseInt(p.id, 10), widgetEl, voteBtn, errorEl, t);
			});
		});
	}

	function setCardButtonsDisabled(widgetEl, disabled) {
		var buttons = widgetEl.querySelectorAll('.dotd-card-vote-btn');
		buttons.forEach(function (button) {
			button.disabled = disabled;
		});
	}

	// -------------------------------------------------------------------------
	// AJAX vote submission
	// -------------------------------------------------------------------------

	function submitVote(d, participantId, widgetEl, btn, errorEl, t) {
		setCardButtonsDisabled(widgetEl, true);
		errorEl.style.display = 'none';

		var body = new URLSearchParams();
		body.append('action',         'dotd_submit_vote');
		body.append('nonce',          d.nonce);
		body.append('event_id',       d.eventId);
		body.append('participant_id', participantId);

		fetch(d.ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body:        body.toString(),
		})
			.then(function (resp) { return resp.json(); })
			.then(function (json) {
				if (json.success) {
					// Update local data and re-render as results
					d.alreadyVoted      = true;
					d.results           = json.data.results;
					d.total             = json.data.total;
					d.votedParticipantId = json.data.participant_id;
					renderWidget({ widgetId: widgetEl.id, data: d });
				} else {
					var msg = (json.data && json.data.message) ? json.data.message : t.errorGeneric;
					if (json.data && json.data.already_voted) {
						d.alreadyVoted = true;
						d.results = {};
						d.total   = 0;
						renderWidget({ widgetId: widgetEl.id, data: d });
					} else {
						errorEl.textContent  = msg;
						errorEl.style.display = 'block';
						setCardButtonsDisabled(widgetEl, false);
					}
				}
			})
			.catch(function () {
				errorEl.textContent  = t.errorGeneric;
				errorEl.style.display = 'block';
				setCardButtonsDisabled(widgetEl, false);
			});
	}

	// -------------------------------------------------------------------------
	// Results renderer
	// -------------------------------------------------------------------------

	function renderResults(widgetEl, d, isFinal, t) {
		widgetEl.classList.add('is-results');

		var resultsHeader = el('h3', 'dotd-results-headline',
			isFinal ? t.votingClosed : t.resultsHeadline);
		widgetEl.appendChild(resultsHeader);

		var results = d.results || {};
		var total   = d.total   || 0;

		// Sort participants by votes descending
		var sorted = (d.participants || []).slice().sort(function (a, b) {
			var va = results[a.id] || 0;
			var vb = results[b.id] || 0;
			return vb - va;
		});

		var maxVotes = sorted.length > 0 ? (results[sorted[0].id] || 0) : 0;

		var grid = el('div', 'dotd-grid');
		widgetEl.appendChild(grid);

		sorted.forEach(function (p) {
			var card = buildCard(p, false);
			var votes = results[p.id] || 0;
			var isWinner = (votes === maxVotes && maxVotes > 0);

			if (isWinner) {
				card.classList.add('is-winner');
				var badge = el('span', 'dotd-winner-badge');
				badge.textContent = '🏆';
				card.insertBefore(badge, card.firstChild);
			}

			appendResultBar(card, votes, total, t);
			grid.appendChild(card);
		});

		var footer = el('div', 'dotd-results-footer',
			t.totalVotes + ': ' + total);
		widgetEl.appendChild(footer);
	}

	// -------------------------------------------------------------------------
	// Bootstrap all instances once DOM is ready
	// -------------------------------------------------------------------------

	function boot() {
		var instances = window.dotdInstances || [];
		instances.forEach(function (inst) {
			renderWidget(inst);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
