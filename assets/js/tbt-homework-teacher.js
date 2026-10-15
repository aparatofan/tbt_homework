/**
 * TBT Homework — the teacher's queue.
 *
 * The page arrives complete: every card is already in the document, written by
 * PHP, and the search, the filter and the pager are links and a form. This
 * script saves a comment, adds and edits notes on a submission's words,
 * collapses a long submission, submits the bar when the dropdown changes, and
 * works the search box's ×.
 *
 * Saving is the load-bearing part. Without JavaScript the queue still reads
 * whole, the notes already saved still show their text on hover, the filter
 * still works through the bar's own button, the search box still types and
 * submits, and the only thing missing is saving — which is why the box keeps
 * your text and says so when a save fails, rather than clearing it. You may
 * have just written three paragraphs.
 *
 * Notes: select a few words in a submission and write a short correction.
 * The body is drawn by TBTHomeworkMarks (the same markup PHP writes), offsets
 * are code points into the canonical body, and the whole list goes to /marks
 * with the hash of the body as drawn, so a note never lands on text the
 * student changed while this page was open.
 *
 * No build step, no dependencies.
 */
(function (window, document) {
	'use strict';

	var DATA = window.TBTHomeworkTeacherData || {};
	var I18N = DATA.i18n || {};
	var MARKS = window.TBTHomeworkMarks || null;
	var NOTE_MAX = parseInt(DATA.noteMax, 10) || 200;

	/** The popover's quote is shortened past this many characters. */
	var QUOTE_MAX = 40;

	/** The one popover on the page, and the card it belongs to. */
	var pop = null;

	/** A card whose body should be redrawn on the next mouseup. */
	var redrawOnUp = null;

	/** About eight lines of the body's 1.6 line-height, in pixels of slack. */
	var CLIP_SLACK = 4;

	function text(value) {
		return value === null || value === undefined ? '' : String(value);
	}

	function sprintf(template, value) {
		return text(template).replace('%s', text(value));
	}

	function make(tag, className, content) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (content !== undefined && content !== null) {
			node.textContent = content;
		}
		return node;
	}

	function request(url, payload) {
		return window.fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': text(DATA.nonce),
				'Content-Type': 'application/json'
			},
			body: JSON.stringify(payload)
		}).then(function (response) {
			return response.json().catch(function () {
				return null;
			}).then(function (body) {
				if (!response.ok) {
					var error = new Error((body && body.message) || I18N.genericError);
					error.code = body && body.code;
					error.status = response.status;
					throw error;
				}
				return body;
			});
		});
	}

	/**
	 * The count beside the title.
	 *
	 * It counts what is waiting across every class, so a save or a clear moves
	 * it by one whatever page you are on. Zero is no pill at all, not a grey
	 * zero, so it is removed at zero and built again when it comes back.
	 *
	 * @param {HTMLElement} app The shortcode's root.
	 */
	function Count(app) {
		this.app = app;
		this.title = app.querySelector('.tbth-libbar__title');
		// The title zone is the heading, the pill, then the line that joins it
		// to the search. A pill built here goes where PHP would have put it,
		// before that line, not after it.
		this.line = this.title ? this.title.querySelector('.tbth-libbar__line') : null;
		this.node = app.querySelector('[data-tbth-count]');
		this.value = this.node ? parseInt(this.node.getAttribute('data-tbth-count'), 10) || 0 : 0;
	}

	Count.prototype.move = function (by) {
		this.value += by;

		if (this.value < 0) {
			this.value = 0;
		}

		if (this.value === 0) {
			if (this.node && this.node.parentNode) {
				this.node.parentNode.removeChild(this.node);
			}
			this.node = null;
			return;
		}

		if (!this.node) {
			if (!this.title) {
				return;
			}
			this.node = make('span', 'tbth-count');
			this.title.insertBefore(this.node, this.line);
		}

		this.node.setAttribute('data-tbth-count', String(this.value));
		this.node.setAttribute('aria-label', sprintf(I18N.countLabel, this.value));
		this.node.textContent = String(this.value);
	};

	/**
	 * One card: its comment box, and its collapsed body.
	 *
	 * @param {HTMLElement} item  The card.
	 * @param {Count}       count The page's waiting count.
	 */
	function Item(item, count) {
		this.item = item;
		this.count = count;
		this.id = parseInt(item.getAttribute('data-id'), 10) || 0;
		this.box = item.querySelector('[data-tbth-comment]');
		this.button = item.querySelector('[data-tbth-save]');
		this.status = item.querySelector('[data-tbth-status]');
		this.error = item.querySelector('[data-tbth-error]');
		this.body = item.querySelector('[data-tbth-body]');
		this.wrap = this.body ? this.body.parentNode : null;
		this.meta = item.querySelector('[data-tbth-marks-meta]');
		this.hash = this.body ? text(this.body.getAttribute('data-tbth-hash')) : '';
		this.more = null;
		this.saving = false;
		this.marking = false;
		this.locked = !MARKS || !this.body || !this.body.hasAttribute('data-tbth-markable');
		this.source = this.body ? this.readSource() : '';
		this.marks = this.body ? this.readMarks() : [];
	}

	/**
	 * The canonical body, read back off the page.
	 *
	 * The body is drawn as text, a highlight's anchor and strut hold no text,
	 * and every <br /> keeps its newline after it, so the text of the body is
	 * the canonical body the offsets count into.
	 */
	Item.prototype.readSource = function () {
		return text(this.body.textContent);
	};

	/** The saved marks, read back off their highlights. */
	Item.prototype.readMarks = function () {
		var cps = MARKS ? MARKS.points(this.source) : [];

		return Array.prototype.map.call(this.body.querySelectorAll('[data-tbth-mark]'), function (node) {
			var start = parseInt(node.getAttribute('data-start'), 10);
			var end = parseInt(node.getAttribute('data-end'), 10);

			return {
				start: start,
				end: end,
				quote: cps.slice(start, end).join(''),
				note: text(node.getAttribute('data-note'))
			};
		});
	};

	/**
	 * Collapse a submission that runs past about eight lines.
	 *
	 * The class is added here rather than in the markup, so a reader with no
	 * script gets the whole thing.
	 */
	Item.prototype.collapse = function () {
		var body = this.body;

		if (!body) {
			return;
		}

		// A card with notes is never clipped: a note over a hidden line
		// would float over nothing.
		if (this.marks.length) {
			return;
		}

		body.classList.add('tbth-item__body--clipped');

		if (body.scrollHeight <= body.clientHeight + CLIP_SLACK) {
			body.classList.remove('tbth-item__body--clipped');
			return;
		}

		var more = make('button', 'tbth-item__more', I18N.showMore);
		more.type = 'button';
		more.setAttribute('aria-expanded', 'false');

		more.addEventListener('click', function () {
			var clipped = body.classList.toggle('tbth-item__body--clipped');
			more.textContent = clipped ? I18N.showMore : I18N.showLess;
			more.setAttribute('aria-expanded', clipped ? 'false' : 'true');

			if (MARKS) {
				MARKS.relayout(body);
			}
		});

		var after = this.wrap || body;
		after.parentNode.insertBefore(more, after.nextSibling);
		this.more = more;
	};

	/** The first note unclips the card, for good. */
	Item.prototype.unclip = function () {
		if (this.body) {
			this.body.classList.remove('tbth-item__body--clipped');
		}

		if (this.more && this.more.parentNode) {
			this.more.parentNode.removeChild(this.more);
		}

		this.more = null;
	};

	/* -----------------------------------------------------------------
	 * Notes.
	 * ----------------------------------------------------------------- */

	/**
	 * Draw the body again from the marks in hand, and lay the notes out.
	 *
	 * @param {Object=} pending { start, end } — the selection being noted.
	 * @param {number=} active  The start of the mark being edited.
	 */
	Item.prototype.redraw = function (pending, active) {
		if (redrawOnUp === this) {
			redrawOnUp = null;
		}

		if (!MARKS || !this.body) {
			return;
		}

		this.body.innerHTML = MARKS.render(this.source, this.marks, pending || null);

		if (active !== undefined && active !== null) {
			var mark = this.body.querySelector('[data-tbth-mark][data-start="' + active + '"]');
			if (mark) {
				mark.classList.add('is-active');
			}
		}

		MARKS.relayout(this.body);
	};

	/** " · 3 notes · Closed for changes", or nothing. */
	Item.prototype.paintMeta = function () {
		if (!this.meta) {
			return;
		}

		var count = this.marks.length;

		this.meta.textContent = count
			? ' · ' + sprintf(count === 1 ? I18N.noteOne : I18N.noteMany, count) + ' · ' + text(I18N.closedForChanges)
			: '';
	};

	/** Where a point in the body falls, in code points. */
	Item.prototype.offset = function (container, at) {
		var range = document.createRange();
		range.setStart(this.body, 0);
		range.setEnd(container, at);
		return MARKS.points(range.toString()).length;
	};

	/**
	 * A mouseup in the body's wrapper: a click on a note or a highlight
	 * edits it; a selection inside one paragraph adds one.
	 *
	 * @param {MouseEvent} event The mouseup.
	 */
	Item.prototype.pointerUp = function (event) {
		var target = event.target;

		if (this.locked || this.marking || !target || !target.closest) {
			return;
		}

		// Inside the popover, the opened long note, or on a triangle: those
		// have their own handlers.
		if (target.closest('.tbth-mark-pop, .tbth-mark-full, .tbth-mark-note__more')) {
			return;
		}

		var note = target.closest('.tbth-mark-note');
		if (note) {
			this.openEdit(parseInt(note.getAttribute('data-start'), 10));
			return;
		}

		var selection = window.getSelection();
		if (!selection || selection.rangeCount === 0) {
			return;
		}

		if (selection.isCollapsed) {
			var mark = target.closest('[data-tbth-mark]');
			if (mark && this.body.contains(mark)) {
				this.openEdit(parseInt(mark.getAttribute('data-start'), 10));
			}
			return;
		}

		var range = selection.getRangeAt(0);

		if (!this.body.contains(range.startContainer) || !this.body.contains(range.endContainer)) {
			return;
		}

		var cps = MARKS.points(this.source);
		var start = this.offset(range.startContainer, range.startOffset);
		var end = this.offset(range.endContainer, range.endOffset);

		while (start < end && MARKS.isSpace(cps[start])) {
			start += 1;
		}
		while (end > start && MARKS.isSpace(cps[end - 1])) {
			end -= 1;
		}

		if (end <= start) {
			return;
		}

		if (cps.slice(start, end).indexOf('\n') !== -1) {
			selection.removeAllRanges();
			this.say(I18N.crossesLine);
			return;
		}

		var overlaps = this.marks.some(function (mark) {
			return start < mark.end && end > mark.start;
		});

		if (overlaps) {
			selection.removeAllRanges();
			this.say(I18N.overlaps);
			return;
		}

		selection.removeAllRanges();
		this.say('');
		closePopFor(this);
		this.redraw({ start: start, end: end });
		this.openPop(null, { start: start, end: end });
	};

	/** Edit the mark that starts here. */
	Item.prototype.openEdit = function (start) {
		var index = -1;

		this.marks.forEach(function (mark, i) {
			if (mark.start === start) {
				index = i;
			}
		});

		if (index === -1) {
			return;
		}

		this.say('');
		// Closed before the redraw: an open popover holds the layout.
		closePopFor(this);
		this.redraw(null, start);
		this.openPop(index, null);
	};

	/**
	 * The add or edit popover, under the selection's last line.
	 *
	 * @param {?number} index   The mark being edited, or null to add one.
	 * @param {?Object} pending { start, end } when adding.
	 */
	Item.prototype.openPop = function (index, pending) {
		var card = this;

		closePop(false);

		var editing = index !== null && index !== undefined;
		var mark = editing ? this.marks[index] : null;
		var span = mark || pending;
		var quote = MARKS.points(this.source).slice(span.start, span.end).join('');
		var shown = MARKS.points(quote).length > QUOTE_MAX
			? MARKS.points(quote).slice(0, QUOTE_MAX - 2).join('') + '…'
			: quote;

		var node = make('div', 'tbth-mark-pop');
		node.setAttribute('role', 'dialog');
		node.setAttribute('aria-label', editing ? I18N.editNote : I18N.addNoteLabel);

		var fieldId = 'tbth-mark-field-' + this.id;
		var label = make('label', 'tbth-mark-pop__label');
		label.setAttribute('for', fieldId);

		// The label is built from text pieces, never from HTML: the quote is
		// the student's writing.
		var template = text(editing ? I18N.editNoteOn : I18N.noteOn);
		var cut = template.indexOf('%s');
		label.appendChild(document.createTextNode(cut === -1 ? template : template.slice(0, cut)));
		label.appendChild(make('span', 'tbth-mark-pop__quote', shown));
		if (cut !== -1) {
			label.appendChild(document.createTextNode(template.slice(cut + 2)));
		}
		node.appendChild(label);

		var field = make('textarea', 'tbth-mark-pop__field');
		field.id = fieldId;
		field.rows = 1;
		field.maxLength = NOTE_MAX;
		field.placeholder = text(I18N.placeholder);
		field.value = mark ? mark.note : '';
		node.appendChild(field);

		var actions = make('div', 'tbth-mark-pop__actions');

		var save = make('button', 'tbth-btn tbth-btn--primary', editing ? I18N.saveNote : I18N.addNote);
		save.type = 'button';
		actions.appendChild(save);

		var cancel = make('button', 'tbth-btn tbth-btn--secondary', I18N.cancel);
		cancel.type = 'button';
		actions.appendChild(cancel);

		var remove = null;
		if (editing) {
			remove = make('button', 'tbth-btn tbth-mark-pop__delete', I18N.deleteNote);
			remove.type = 'button';
			actions.appendChild(remove);
		}

		node.appendChild(actions);
		node.appendChild(make('p', 'tbth-mark-pop__keys', I18N.noteKeys));

		this.wrap.appendChild(node);

		// Placed under the selection's last line, clamped inside the body.
		var target = this.body.querySelector(editing ? '[data-tbth-mark][data-start="' + mark.start + '"]' : '[data-tbth-pending]');
		var box = this.wrap.getBoundingClientRect();
		var left = 0;
		var top = this.body.offsetHeight;

		if (target) {
			var rects = target.getClientRects();
			var last = rects.length ? rects[rects.length - 1] : target.getBoundingClientRect();
			var anchor = target.querySelector('.tbth-mark__anchor') || target;
			left = anchor.getBoundingClientRect().left - box.left;
			top = last.bottom - box.top + 10;
		}

		node.style.left = Math.max(0, Math.min(left, this.wrap.clientWidth - node.offsetWidth)) + 'px';
		node.style.top = top + 'px';

		pop = {
			node: node,
			card: this,
			field: field,
			buttons: [save, cancel].concat(remove ? [remove] : [])
		};

		MARKS.hold(this.body, true);

		field.focus();
		field.setSelectionRange(field.value.length, field.value.length);

		// A note is one line: newlines, typed or pasted, are dropped.
		field.addEventListener('input', function () {
			if (/[\r\n]/.test(field.value)) {
				field.value = field.value.replace(/[\r\n]+/g, ' ');
			}
		});

		field.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				if (!card.marking) {
					card.commit(index, pending, field.value);
				}
			} else if (event.key === 'Escape') {
				event.preventDefault();
				event.stopPropagation();
				if (!card.marking) {
					closePop(true);
				}
			}
		});

		save.addEventListener('click', function () {
			card.commit(index, pending, field.value);
		});

		cancel.addEventListener('click', function () {
			closePop(true);
		});

		if (remove) {
			remove.addEventListener('click', function () {
				card.commit(index, null, null);
			});
		}
	};

	/**
	 * Build the whole new list and send it.
	 *
	 * @param {?number} index   The mark being edited or deleted, or null.
	 * @param {?Object} pending { start, end } when adding.
	 * @param {?string} value   The note; null deletes the mark at index.
	 */
	Item.prototype.commit = function (index, pending, value) {
		var card = this;
		var editing = index !== null && index !== undefined;
		var next;

		if (this.marking) {
			return;
		}

		if (value !== null) {
			value = text(value).replace(/[\r\n]+/g, ' ').trim();

			// An empty note is not saved: the popover stays, and so does the
			// cursor in it.
			if (!value) {
				if (pop && pop.field) {
					pop.field.focus();
				}
				return;
			}
		}

		if (!editing) {
			var cps = MARKS.points(this.source);
			next = this.marks.concat([{
				start: pending.start,
				end: pending.end,
				quote: cps.slice(pending.start, pending.end).join(''),
				note: value
			}]);
		} else if (value === null) {
			next = this.marks.filter(function (mark, i) {
				return i !== index;
			});
		} else {
			next = this.marks.map(function (mark, i) {
				return i === index
					? { start: mark.start, end: mark.end, quote: mark.quote, note: value }
					: mark;
			});
		}

		next.sort(function (a, b) {
			return a.start - b.start;
		});

		this.marking = true;
		this.say('');
		setPopBusy(true);

		request(text(DATA.marks), { id: this.id, marks: next, hash: this.hash }).then(function (saved) {
			card.marking = false;
			card.marks = (saved && Array.isArray(saved.marks)) ? saved.marks : next;

			if (card.marks.length) {
				card.unclip();
			}

			closePop(false);
			card.redraw();
			card.paintMeta();
		}).catch(function (error) {
			card.marking = false;
			card.say(error.message || I18N.notesFailed);

			// The student changed the homework under the page: nothing here
			// can be saved against it until it is reloaded.
			if (error.code === 'tbt_homework_body_changed') {
				card.lock();
				return;
			}

			// The old marks are still the ones on screen; the popover stays
			// open with the text in it, to try again.
			setPopBusy(false);
		});
	};

	/** No more marking on this card until the page is reloaded. */
	Item.prototype.lock = function () {
		this.locked = true;

		if (this.body) {
			this.body.removeAttribute('data-tbth-markable');
		}

		closePop(false);
		this.redraw();
	};

	/**
	 * Close the popover.
	 *
	 * @param {boolean} redraw Redraw its card's body now. A click outside
	 *                         closes on mousedown and redraws on mouseup, so
	 *                         the text is not pulled out from under a new
	 *                         selection.
	 */
	function closePop(redraw) {
		if (!pop) {
			return;
		}

		var was = pop;
		pop = null;

		if (was.node.parentNode) {
			was.node.parentNode.removeChild(was.node);
		}

		if (MARKS && was.card.body) {
			MARKS.hold(was.card.body, false);
		}

		if (redraw) {
			was.card.redraw();
		}

		return was.card;
	}

	/**
	 * Close the popover before this card redraws itself. One open on another
	 * card has that card redrawn, so nothing pending is left behind there.
	 *
	 * @param {Item} card The card about to redraw.
	 */
	function closePopFor(card) {
		closePop(!!pop && pop.card !== card);
	}

	function setPopBusy(on) {
		if (!pop) {
			return;
		}

		pop.field.readOnly = on;
		pop.buttons.forEach(function (button) {
			button.disabled = on;
		});
	}

	/** Show one line, or none. */
	Item.prototype.say = function (message) {
		if (!this.error) {
			return;
		}

		this.error.textContent = text(message);
		this.error.hidden = !message;
	};

	/**
	 * Save, replace or clear the comment.
	 *
	 * Clearing is a real state change — it hands the homework back to the
	 * student to edit — so it is asked about in words before it happens.
	 */
	Item.prototype.save = function () {
		var card = this;
		var value = this.box ? this.box.value : '';
		var clearing = !value.trim();

		if (this.saving || !this.id) {
			return;
		}

		if (clearing && this.item.getAttribute('data-status') === 'commented') {
			if (!window.confirm(I18N.confirmClear)) {
				return;
			}
		}

		// Nothing to clear and nothing to write.
		if (clearing && this.item.getAttribute('data-status') !== 'commented') {
			return;
		}

		this.saving = true;
		this.say('');
		this.item.classList.add('tbth-is-saving');

		if (this.button) {
			this.button.disabled = true;
			this.button.textContent = I18N.saving;
		}

		request(text(DATA.comment), { id: this.id, comment: value }).then(function (saved) {
			card.saving = false;
			card.done(saved);
		}).catch(function (error) {
			card.saving = false;
			card.settle();
			// The text stays in the box: it is the only copy of it.
			card.say(error.message || I18N.saveFailed);
		});
	};

	/** Put the card back in its resting state, whatever happened. */
	Item.prototype.settle = function () {
		this.item.classList.remove('tbth-is-saving');

		if (this.button) {
			this.button.disabled = false;
			this.button.textContent = I18N.save;
		}
	};

	/**
	 * The card after a save.
	 *
	 * It stays exactly where it is, showing the saved comment and its date,
	 * with the box still open for a correction — a card that vanished under
	 * the cursor because the filter says Waiting would make a typo unfixable
	 * without a reload. On the next page load it is where the filter says it
	 * belongs.
	 *
	 * @param {Object} saved The row as the server stored it.
	 */
	Item.prototype.done = function (saved) {
		this.settle();

		if (!saved) {
			return;
		}

		var was = this.item.getAttribute('data-status');
		var now = text(saved.status);

		this.item.setAttribute('data-status', now);

		if (this.box) {
			this.box.value = text(saved.comment);
		}

		if (this.status) {
			this.status.className = 'tbt-tag tbth-item__status tbth-item__status--' + now;
			this.status.textContent = 'commented' === now
				? (saved.commented_at_display ? sprintf(I18N.commentedOn, saved.commented_at_display) : I18N.commented)
				: I18N.waiting;
		}

		if (was !== now) {
			this.count.move('commented' === now ? -1 : 1);
		}
	};

	/**
	 * The search box's ×.
	 *
	 * It shows while there is something to clear, empties the box and hands
	 * the field back. It does not submit: the box is a query parameter here,
	 * so the emptied field is applied by the next submit — Enter, or changing
	 * the dropdown — and the summary line's "Clear filters" is the one click
	 * that resets the whole bar at once.
	 *
	 * @param {HTMLElement} app The shortcode's root.
	 */
	function search(app) {
		var box = app.querySelector('[data-tbth-search]');
		var clear = app.querySelector('[data-tbth-clear]');

		if (!box || !clear) {
			return;
		}

		function paint() {
			// The box's own value, not a trimmed one: a box holding a space
			// has text in it, whatever it matches.
			clear.hidden = '' === box.value;
		}

		box.addEventListener('input', paint);
		box.addEventListener('change', paint);

		clear.addEventListener('click', function () {
			box.value = '';
			paint();
			box.focus();
		});

		// The field arrives carrying whatever was searched for, so the × is
		// decided here rather than in the markup.
		paint();
	}

	/**
	 * Wire one queue.
	 *
	 * @param {HTMLElement} app The shortcode's root.
	 */
	function mount(app) {
		if (app.dataset.tbthMounted) {
			return;
		}
		app.dataset.tbthMounted = '1';

		// The dropdown is a filter on a form, so changing it asks the server
		// for the filtered list. Without this the bar's own button does it.
		var filter = app.querySelector('[data-tbth-filter]');
		if (filter && filter.form) {
			filter.addEventListener('change', function () {
				filter.form.submit();
			});
		}

		search(app);

		var count = new Count(app);

		Array.prototype.forEach.call(app.querySelectorAll('[data-tbth-item]'), function (node) {
			var card = new Item(node, count);

			card.collapse();

			if (card.button) {
				card.button.addEventListener('click', function () {
					card.save();
				});
			}

			if (MARKS && card.body) {
				MARKS.watch(card.body);
			}

			if (!card.locked && card.wrap) {
				card.wrap.addEventListener('mouseup', function (event) {
					card.pointerUp(event);
				});

				card.wrap.addEventListener('keydown', function (event) {
					var note = event.target.closest && event.target.closest('.tbth-mark-note');

					if (!note || card.locked || card.marking || event.target !== note) {
						return;
					}

					if (event.key === 'Enter' || event.key === ' ') {
						event.preventDefault();
						card.openEdit(parseInt(note.getAttribute('data-start'), 10));
					}
				});
			}
		});
	}

	// A click outside the popover closes it on mousedown; the body it was on
	// is redrawn on mouseup, after any new selection has been taken.
	document.addEventListener('mousedown', function (event) {
		if (!pop || pop.card.marking) {
			return;
		}

		var target = event.target;
		if (pop.node.contains(target) || (target.closest && target.closest('.tbth-mark-note'))) {
			return;
		}

		redrawOnUp = closePop(false);
	});

	document.addEventListener('mouseup', function () {
		if (redrawOnUp) {
			redrawOnUp.redraw();
		}
	});

	function mountAll() {
		Array.prototype.forEach.call(document.querySelectorAll('.tbth-teacher'), mount);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mountAll);
	} else {
		mountAll();
	}

	window.TBTHomeworkTeacher = {
		mountAll: mountAll
	};
})(window, document);
