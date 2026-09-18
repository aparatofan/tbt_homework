/**
 * TBT Homework — the teacher's queue.
 *
 * The page arrives complete: every card is already in the document, written by
 * PHP, and the search, the filter and the pager are links and a form. This
 * script does three things — it saves a comment, it collapses a long
 * submission, and it submits the bar when the dropdown changes.
 *
 * Only the first of those is load-bearing. Without JavaScript the queue still
 * reads whole, the filter still works through the bar's own button, and the
 * only thing missing is saving — which is why the box keeps your text and says
 * so when a save fails, rather than clearing it. You may have just written
 * three paragraphs.
 *
 * No build step, no dependencies.
 */
(function (window, document) {
	'use strict';

	var DATA = window.TBTHomeworkTeacherData || {};
	var I18N = DATA.i18n || {};

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
			this.title.appendChild(this.node);
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
		this.saving = false;
	}

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
		});

		body.parentNode.insertBefore(more, body.nextSibling);
	};

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

		var count = new Count(app);

		Array.prototype.forEach.call(app.querySelectorAll('[data-tbth-item]'), function (node) {
			var card = new Item(node, count);

			card.collapse();

			if (card.button) {
				card.button.addEventListener('click', function () {
					card.save();
				});
			}
		});
	}

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
