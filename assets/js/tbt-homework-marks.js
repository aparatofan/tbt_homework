/**
 * TBT Homework — the teacher's notes, drawn over the words they belong to.
 *
 * Shared by all three places a body can carry notes: the teacher's queue, the
 * student's library and the card under the lesson. The UI says "note"; the
 * code says "mark", because "note" is TBT Notes' word.
 *
 * Two halves:
 *
 * - canonical(), segments() and render() mirror TBT_Homework_Marks in PHP and
 *   must produce the same markup. The teacher's script counts offsets on
 *   whichever of the two drew the body, so a difference between them is a
 *   mark saved on the wrong words.
 * - layout() and watch() draw the notes. A note sits above the first word of
 *   its highlight, in Kalam, in red; two that would touch on one line go to
 *   two heights; only lines that carry a note get the extra room, which the
 *   strut inside each highlight provides.
 *
 * No build step, no dependencies.
 */
(function (window, document) {
	'use strict';

	var DATA = window.TBTHomeworkMarksData || {};
	var I18N = DATA.i18n || {};

	/** A note's line box, its 2px gap, and the 3px lift off the strut. */
	var NOTE_STEP = 22;
	var NOTE_LIFT = 3;

	/** Anchors whose tops are this close are on one line. */
	var SAME_LINE = 6;

	/** Notes closer than this on one line go to two heights. */
	var CLEARANCE = 10;

	/**
	 * Whitespace, written out. PHP's TBT_Homework_Marks::SPACE_CLASS is the
	 * same list, so both sides agree on what "trimmed" means.
	 */
	var SPACE = /^[\u0009-\u000D\u0020\u00A0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000\uFEFF]$/;
	var FIRST_WORD = /^[^\u0009-\u000D\u0020\u00A0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000\uFEFF]+/;

	var noteSeq = 0;
	var watched = [];
	var openFull = null;

	function text(value) {
		return value === null || value === undefined ? '' : String(value);
	}

	function escapeHtml(value) {
		return text(value).replace(/[&<>"']/g, function (character) {
			switch (character) {
				case '&': return '&amp;';
				case '<': return '&lt;';
				case '>': return '&gt;';
				case '"': return '&quot;';
				default: return '&#039;';
			}
		});
	}

	function sprintf(template, value) {
		return text(template).replace('%s', text(value));
	}

	function isInt(value) {
		return typeof value === 'number' && isFinite(value) && Math.floor(value) === value;
	}

	/* ---------------------------------------------------------------------
	 * The mirror of TBT_Homework_Marks.
	 * --------------------------------------------------------------------- */

	/** \r\n, then \r, become \n. */
	function canonical(body) {
		return text(body).replace(/\r\n/g, '\n').replace(/\r/g, '\n');
	}

	/** A string as code points, which is what every offset counts. */
	function points(value) {
		return Array.from(text(value));
	}

	function isSpace(point) {
		return SPACE.test(point);
	}

	/** What is wrong with one mark's range and quote, or ''. */
	function problem(mark, cps) {
		if (!mark || !isInt(mark.start) || !isInt(mark.end) || mark.start < 0 || mark.start >= mark.end || mark.end > cps.length) {
			return 'bad_range';
		}

		var words = cps.slice(mark.start, mark.end);

		if (typeof mark.quote !== 'string' || words.join('') !== mark.quote) {
			return 'quote_mismatch';
		}

		if (words.indexOf('\n') !== -1) {
			return 'crosses_line';
		}

		if (isSpace(words[0]) || isSpace(words[words.length - 1])) {
			return 'untrimmed';
		}

		return '';
	}

	function sorted(marks) {
		return marks.slice().sort(function (a, b) {
			return (a.start - b.start) || (a.end - b.end);
		});
	}

	/**
	 * Plain and marked pieces of the canonical body, in order. A mark that
	 * does not fit the body is left out rather than drawn on the wrong words.
	 *
	 * @param {string} body  The stored body.
	 * @param {Array}  marks Decoded marks.
	 * @return {Array<{text: string, mark: ?Object}>}
	 */
	function segments(body, marks) {
		var cps = points(canonical(body));
		var usable = (Array.isArray(marks) ? marks : []).filter(function (mark) {
			return mark && typeof mark.note === 'string' && problem(mark, cps) === '';
		});
		var out = [];
		var pos = 0;

		sorted(usable).forEach(function (mark) {
			if (mark.start < pos) {
				return;
			}

			if (mark.start > pos) {
				out.push({ text: cps.slice(pos, mark.start).join(''), mark: null });
			}

			out.push({ text: cps.slice(mark.start, mark.end).join(''), mark: mark });
			pos = mark.end;
		});

		if (pos < cps.length || !out.length) {
			out.push({ text: cps.slice(pos).join(''), mark: null });
		}

		return out;
	}

	function firstWord(quote) {
		var match = FIRST_WORD.exec(quote);
		return match ? match[0] : quote;
	}

	/** One highlight; the pending one has no note and no data-tbth-mark. */
	function markHtml(quote, mark, pending) {
		var first = firstWord(quote);
		var inner = '<span class="tbth-mark__anchor"><span class="tbth-mark__strut" aria-hidden="true"></span>' +
			escapeHtml(first) + '</span>' + escapeHtml(quote.slice(first.length));

		if (pending) {
			return '<mark class="tbth-mark tbth-mark--pending" data-tbth-pending>' + inner + '</mark>';
		}

		var note = escapeHtml(mark.note);

		return '<mark class="tbth-mark" data-tbth-mark data-start="' + mark.start + '" data-end="' + mark.end +
			'" data-note="' + note + '" title="' + note + '">' + inner + '</mark>';
	}

	/**
	 * The body as HTML. Without pending, the same bytes PHP's render() writes.
	 *
	 * @param {string}  body    The stored body.
	 * @param {Array}   marks   Decoded marks.
	 * @param {Object=} pending { start, end } — the selection the popover is
	 *                          open on. The teacher's queue only.
	 */
	function render(body, marks, pending) {
		var list = Array.isArray(marks) ? marks.slice() : [];

		if (pending) {
			var cps = points(canonical(body));
			list.push({
				start: pending.start,
				end: pending.end,
				quote: cps.slice(pending.start, pending.end).join(''),
				note: '',
				pending: true
			});
		}

		return segments(body, list).map(function (segment) {
			if (!segment.mark) {
				return escapeHtml(segment.text).replace(/\n/g, '<br />\n');
			}
			return markHtml(segment.text, segment.mark, segment.mark.pending === true);
		}).join('');
	}

	/* ---------------------------------------------------------------------
	 * The note layer.
	 * --------------------------------------------------------------------- */

	function wrapperOf(body) {
		return body ? body.parentNode : null;
	}

	function layerOf(wrap) {
		for (var node = wrap.firstChild; node; node = node.nextSibling) {
			if (node.nodeType === 1 && node.classList.contains('tbth-marks-layer')) {
				return node;
			}
		}
		return null;
	}

	/** The opened long note, closed. Only one is open on the page. */
	function closeFull(returnFocus) {
		if (!openFull) {
			return;
		}

		var was = openFull;
		openFull = null;

		if (was.card.parentNode) {
			was.card.parentNode.removeChild(was.card);
		}

		if (returnFocus && was.trigger && was.trigger.isConnected) {
			was.trigger.focus();
		}
	}

	/**
	 * Open a long note in a small card over the note itself.
	 *
	 * @param {HTMLElement} note    The .tbth-mark-note.
	 * @param {HTMLElement} trigger What to hand the focus back to.
	 */
	function showFull(note, trigger) {
		var wrap = note.parentNode && note.parentNode.parentNode;
		if (!wrap) {
			return;
		}

		if (openFull && openFull.note === note) {
			closeFull(false);
			return;
		}
		closeFull(false);

		var card = document.createElement('div');
		card.className = 'tbth-mark-full';
		card.setAttribute('role', 'note');
		card.textContent = text(note.getAttribute('data-note'));
		wrap.appendChild(card);

		var left = parseFloat(note.style.left) || 0;
		var max = Math.max(0, wrap.clientWidth - card.offsetWidth);
		card.style.left = Math.max(0, Math.min(left, max)) + 'px';
		card.style.top = ((parseFloat(note.style.top) || 0) - 9) + 'px';

		openFull = { card: card, note: note, trigger: trigger };
	}

	/**
	 * Draw, or redraw, the note layer for one body.
	 *
	 * The prototype's layout(), ported rather than redesigned: measure each
	 * highlight's anchor on its line, stagger notes that would touch, let the
	 * struts grow, then measure again and place.
	 *
	 * @param {HTMLElement} body The .tbth-body.
	 */
	function layout(body) {
		var wrap = wrapperOf(body);
		if (!wrap) {
			return;
		}

		if (openFull && wrap.contains(openFull.card)) {
			closeFull(false);
		}

		// 1. A fresh layer.
		var old = layerOf(wrap);
		if (old) {
			wrap.removeChild(old);
		}

		var marks = body.querySelectorAll('[data-tbth-mark]');
		if (!marks.length) {
			return;
		}

		var markable = body.hasAttribute('data-tbth-markable');
		var layer = document.createElement('div');
		layer.className = 'tbth-marks-layer';
		wrap.appendChild(layer);

		// 2. One note per highlight; 3. tiers reset, widths measured.
		var items = Array.prototype.map.call(marks, function (mark) {
			var note = document.createElement('div');
			var words = document.createElement('span');
			var value = text(mark.getAttribute('data-note'));

			noteSeq += 1;
			note.className = 'tbth-mark-note';
			note.setAttribute('data-note', value);
			note.setAttribute('data-start', text(mark.getAttribute('data-start')));

			words.className = 'tbth-mark-note__text';
			words.id = 'tbth-mark-note-' + noteSeq;
			words.textContent = value;
			note.appendChild(words);

			if (markable) {
				note.setAttribute('role', 'button');
				note.tabIndex = 0;
				note.setAttribute('aria-label', sprintf(I18N.editNote || 'Edit note: %s', value));
			}

			layer.appendChild(note);

			mark.setAttribute('aria-describedby', words.id);
			mark.removeAttribute('title');
			mark.setAttribute('data-tier', '0');

			return { mark: mark, note: note, anchor: mark.querySelector('.tbth-mark__anchor') || mark };
		});

		var box = wrap.getBoundingClientRect();

		items.forEach(function (item) {
			var rect = item.anchor.getBoundingClientRect();
			item.line = Math.round(rect.top);
			item.left = rect.left - box.left;

			if (item.note.scrollWidth > item.note.clientWidth + 1) {
				var more = document.createElement('button');
				more.type = 'button';
				more.className = 'tbth-mark-note__more';
				more.setAttribute('aria-label', I18N.readMore || 'Read the full note');
				item.note.classList.add('is-long');
				item.note.appendChild(more);
			}

			item.width = item.note.offsetWidth;
		});

		// 4. Group by line, then left to right: a note that would come within
		// CLEARANCE of one already on the first height goes to the second.
		var maxWidth = wrap.clientWidth;
		var lines = [];

		items.slice().sort(function (a, b) {
			return (a.line - b.line) || (a.left - b.left);
		}).forEach(function (item) {
			var line = null;

			for (var i = 0; i < lines.length; i++) {
				if (Math.abs(lines[i].top - item.line) < SAME_LINE) {
					line = lines[i];
					break;
				}
			}

			if (!line) {
				line = { top: item.line, low: [], high: [] };
				lines.push(line);
			}

			item.x = Math.max(0, Math.min(item.left, maxWidth - item.width));

			var clash = line.low.some(function (span) {
				return item.x < span[1] + CLEARANCE && item.x + item.width + CLEARANCE > span[0];
			});

			item.tier = clash ? 1 : 0;
			(clash ? line.high : line.low).push([item.x, item.x + item.width]);
			item.mark.setAttribute('data-tier', String(item.tier));
		});

		// 5. The struts have grown: measure again, and place.
		box = wrap.getBoundingClientRect();

		items.forEach(function (item) {
			var rect = item.anchor.getBoundingClientRect();
			item.note.style.left = item.x + 'px';
			item.note.style.top = (rect.top - box.top - NOTE_LIFT - NOTE_STEP * (item.tier + 1)) + 'px';
		});
	}

	/**
	 * Lay one body out now, and again whenever its width or its fonts change.
	 *
	 * A change of width includes one from 0: a card hidden by a filter and
	 * shown again. Never while the body is held — the teacher's popover is
	 * open on it — because redrawing would move the words out from under it.
	 *
	 * @param {HTMLElement} body The .tbth-body.
	 */
	function watch(body) {
		if (!body || body.tbthMarksWatch) {
			if (body) {
				relayout(body);
			}
			return;
		}

		var state = { width: -1, observer: null };
		body.tbthMarksWatch = state;
		watched.push(body);

		if (window.ResizeObserver) {
			state.observer = new window.ResizeObserver(function () {
				if (!body.isConnected) {
					unwatch(body);
					return;
				}
				relayout(body);
			});
			state.observer.observe(body);
		}

		relayout(body);
	}

	function unwatch(body) {
		var state = body.tbthMarksWatch;

		if (state && state.observer) {
			state.observer.disconnect();
		}

		body.tbthMarksWatch = null;
		watched = watched.filter(function (node) {
			return node !== body;
		});
	}

	/** Lay out when the width has changed, or always when forced. */
	function relayout(body, force) {
		var state = body.tbthMarksWatch;
		var width = body.clientWidth;

		if (body.tbthMarksHold || width === 0) {
			return;
		}

		if (state) {
			if (!force && state.width === width) {
				return;
			}
			state.width = width;
		}

		layout(body);
	}

	/** Every watched body, once the fonts change under it. */
	function relayoutAll() {
		watched.slice().forEach(function (body) {
			if (!body.isConnected) {
				unwatch(body);
				return;
			}
			relayout(body, true);
		});
	}

	/**
	 * Hold or release a body's layout while a popover is open on it.
	 *
	 * @param {HTMLElement} body The .tbth-body.
	 * @param {boolean}     on   Hold when true.
	 */
	function hold(body, on) {
		body.tbthMarksHold = !!on;
	}

	/* ---------------------------------------------------------------------
	 * Page-wide: the triangle, outside clicks, Esc, fonts, a resize with no
	 * ResizeObserver.
	 * --------------------------------------------------------------------- */

	document.addEventListener('click', function (event) {
		var target = event.target;
		if (!target || !target.closest) {
			return;
		}

		var more = target.closest('.tbth-mark-note__more');
		if (more) {
			event.preventDefault();
			event.stopPropagation();
			showFull(more.parentNode, more);
			return;
		}

		// On a read-only page, the whole of a long note opens it too.
		var note = target.closest('.tbth-mark-note.is-long');
		if (note && note.getAttribute('role') !== 'button') {
			showFull(note, null);
		}
	});

	document.addEventListener('mousedown', function (event) {
		if (!openFull) {
			return;
		}

		var target = event.target;
		if (openFull.card.contains(target) || (target.closest && target.closest('.tbth-mark-note__more'))) {
			return;
		}

		closeFull(false);
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && openFull) {
			closeFull(true);
		}
	});

	if (document.fonts) {
		if (document.fonts.ready) {
			document.fonts.ready.then(relayoutAll, function () {});
		}

		// Kalam is asked for only once a note is on the page, which can be
		// after fonts.ready has already settled.
		if (document.fonts.addEventListener) {
			document.fonts.addEventListener('loadingdone', relayoutAll);
		}
	}

	if (!window.ResizeObserver) {
		window.addEventListener('resize', function () {
			watched.slice().forEach(function (body) {
				relayout(body);
			});
		});
	}

	window.TBTHomeworkMarks = {
		canonical: canonical,
		points: points,
		isSpace: isSpace,
		segments: segments,
		render: render,
		layout: layout,
		watch: watch,
		hold: hold,
		relayout: function (body) {
			relayout(body, true);
		},
		closeFull: closeFull
	};
})(window, document);
