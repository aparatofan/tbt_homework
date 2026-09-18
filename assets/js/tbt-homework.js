/**
 * TBT Homework — the form under a TBT Notes lesson.
 *
 * Mounts into [data-tbt-slot="lesson-foot"] on tbt-notes:lesson-view. The slot
 * element is replaced on every render, so mounting is idempotent per node,
 * never per lesson id.
 *
 * No build step, no dependencies.
 */
(function (window, document) {
	'use strict';

	var DATA = window.TBTHomeworkData || {};
	var I18N = DATA.i18n || {};
	var MAX = parseInt(DATA.maxLength, 10) || 20000;

	/**
	 * Unsent text, per lesson, for the life of the page.
	 *
	 * A re-render throws our node away and hands us a fresh slot; a student
	 * halfway through a sentence should not lose it because they pressed a
	 * highlight filter.
	 */
	var drafts = {};

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

	/** esc_html() plus nl2br(), on this side of the wire. */
	function toHtml(value) {
		return escapeHtml(value).replace(/\r\n|\r|\n/g, '<br />');
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

	function empty(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	/**
	 * Append a query string, whichever shape the endpoint URL already has.
	 *
	 * @param {string} url    The endpoint.
	 * @param {Object} params Key/value pairs.
	 */
	function withQuery(url, params) {
		var pairs = [];

		Object.keys(params).forEach(function (key) {
			pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key]));
		});

		if (!pairs.length) {
			return url;
		}

		return url + (url.indexOf('?') === -1 ? '?' : '&') + pairs.join('&');
	}

	function request(method, url, payload) {
		var options = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': text(DATA.nonce) }
		};

		if (payload) {
			options.headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify(payload);
		}

		return window.fetch(url, options).then(function (response) {
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
	 * One card, bound to one slot node.
	 *
	 * @param {HTMLElement} slot The Notes slot.
	 */
	function Card(slot) {
		this.slot = slot;
		// Read the ids off the slot itself rather than trusting the event.
		this.lessonId = parseInt(slot.getAttribute('data-lesson-id'), 10) || 0;
		this.classId = parseInt(slot.getAttribute('data-class-id'), 10) || 0;
		this.root = make('section', 'tbth-card');
		this.row = null;
		this.editing = false;
		this.saving = false;
		this.error = '';
		this.loadFailed = false;
	}

	Card.prototype.draft = function (value) {
		if (value === undefined) {
			return Object.prototype.hasOwnProperty.call(drafts, this.lessonId) ? drafts[this.lessonId] : null;
		}
		if (value === null) {
			delete drafts[this.lessonId];
			return null;
		}
		drafts[this.lessonId] = value;
		return value;
	};

	Card.prototype.start = function () {
		this.slot.appendChild(this.root);
		this.renderLoading();
		this.load();
	};

	Card.prototype.load = function () {
		var card = this;

		var url = withQuery(text(DATA.submission), { lesson_id: this.lessonId });

		return request('GET', url).then(function (row) {
			card.row = row || null;
			card.loadFailed = false;
			card.editing = false;
			card.render();
		}).catch(function () {
			card.loadFailed = true;
			card.render();
		});
	};

	/** A quiet line while the request is in flight — never an empty box. */
	Card.prototype.renderLoading = function () {
		empty(this.root);
		this.root.appendChild(make('h2', 'tbth-heading', I18N.heading));
		this.root.appendChild(make('p', 'tbth-loading', I18N.loading));
	};

	Card.prototype.render = function () {
		empty(this.root);
		this.textarea = null;
		this.root.classList.toggle('tbth-is-saving', this.saving);
		this.root.appendChild(make('h2', 'tbth-heading', I18N.heading));

		if (this.loadFailed) {
			this.renderLoadFailure();
			return;
		}

		if (this.error) {
			this.root.appendChild(make('p', 'tbth-error', this.error));
		}

		var closed = !!(this.row && this.row.closed);

		if (!this.row || this.editing) {
			this.renderForm();
		} else {
			this.renderSubmission();
		}

		if (closed) {
			this.renderComment();
		}
	};

	/**
	 * A failed load never costs the student their text: whatever is unsent
	 * stays in the box, and Retry sits next to it.
	 */
	Card.prototype.renderLoadFailure = function () {
		var card = this;

		this.root.appendChild(make('p', 'tbth-error', I18N.loadFailed));

		var retry = make('button', 'tbth-btn tbth-btn--secondary', I18N.retry);
		retry.type = 'button';
		retry.addEventListener('click', function () {
			card.renderLoading();
			card.load();
		});

		var actions = make('div', 'tbth-actions');
		actions.appendChild(retry);
		this.root.appendChild(actions);
	};

	Card.prototype.renderForm = function () {
		var card = this;
		var existing = this.row ? this.row.body : '';
		var draft = this.draft();

		var textarea = make('textarea', 'tbth-textarea');
		textarea.value = draft === null ? text(existing) : draft;
		textarea.placeholder = text(I18N.placeholder);
		textarea.maxLength = MAX;
		textarea.rows = 6;
		textarea.disabled = this.saving;
		textarea.addEventListener('input', function () {
			card.draft(textarea.value);
		});
		this.root.appendChild(textarea);
		this.textarea = textarea;

		this.root.appendChild(make('p', 'tbth-helper', I18N.helper));

		var actions = make('div', 'tbth-actions');

		var submit = make('button', 'tbth-btn tbth-btn--primary', this.saving ? I18N.saving : (this.row ? I18N.save : I18N.send));
		submit.type = 'button';
		submit.disabled = this.saving;
		submit.addEventListener('click', function () {
			card.save();
		});
		actions.appendChild(submit);

		if (this.row) {
			var cancel = make('button', 'tbth-btn tbth-btn--secondary', I18N.cancel);
			cancel.type = 'button';
			cancel.disabled = this.saving;
			cancel.addEventListener('click', function () {
				card.editing = false;
				card.error = '';
				card.draft(null);
				card.render();
			});
			actions.appendChild(cancel);
		}

		this.root.appendChild(actions);
	};

	Card.prototype.renderSubmission = function () {
		var card = this;

		var body = make('div', 'tbth-body');
		body.innerHTML = toHtml(this.row.body);
		this.root.appendChild(body);

		this.root.appendChild(make('p', 'tbth-meta', this.metaLine()));

		if (this.row.closed) {
			return;
		}

		var edit = make('button', 'tbth-btn tbth-btn--secondary', I18N.edit);
		edit.type = 'button';
		edit.addEventListener('click', function () {
			card.editing = true;
			card.error = '';
			card.render();
			if (card.textarea) {
				card.textarea.focus();
			}
		});

		var actions = make('div', 'tbth-actions');
		actions.appendChild(edit);
		this.root.appendChild(actions);
	};

	Card.prototype.metaLine = function () {
		var line = sprintf(I18N.sent, this.row.submitted_at_display);

		if (this.row.updated_at && this.row.updated_at !== this.row.submitted_at) {
			line += ' · ' + sprintf(I18N.updated, this.row.updated_at_display);
		}

		return line;
	};

	Card.prototype.renderComment = function () {
		var block = make('div', 'tbth-comment');
		block.appendChild(make('p', 'tbth-comment__eyebrow', I18N.commentEyebrow));

		var comment = make('div', 'tbth-comment__body');
		comment.innerHTML = toHtml(this.row.comment);
		block.appendChild(comment);

		if (this.row.commented_at_display) {
			block.appendChild(make('p', 'tbth-comment__date', this.row.commented_at_display));
		}

		this.root.appendChild(block);
		this.root.appendChild(make('p', 'tbth-closed', I18N.closed));
	};

	Card.prototype.save = function () {
		var card = this;
		var value = this.textarea ? this.textarea.value : '';

		if (!value.trim()) {
			this.error = I18N.emptyError;
			this.render();
			return;
		}

		this.saving = true;
		this.error = '';
		this.draft(value);
		this.render();

		// class_id is deliberately not sent. The server takes it from the
		// lesson context and would ignore ours.
		request('POST', text(DATA.submission), { lesson_id: this.lessonId, body: value }).then(function (row) {
			card.row = row || null;
			card.saving = false;
			card.editing = false;
			card.draft(null);
			card.render();
		}).catch(function (error) {
			card.saving = false;
			card.error = error.message || I18N.genericError;

			// The teacher commented while this was open: repaint from the
			// server so the student sees the comment and the closed line.
			if (error.status === 409) {
				card.editing = false;
				card.load();
				return;
			}

			card.render();
		});
	};

	/**
	 * Mount into a slot, once per node.
	 *
	 * @param {HTMLElement} slot The Notes slot.
	 */
	function mount(slot) {
		if (!slot || slot.dataset.tbthMounted) {
			return;
		}
		slot.dataset.tbthMounted = '1';

		var card = new Card(slot);
		if (!card.lessonId) {
			return;
		}

		card.start();
	}

	function mountAll() {
		var slots = document.querySelectorAll('[data-tbt-slot="lesson-foot"]');
		Array.prototype.forEach.call(slots, mount);
	}

	document.addEventListener('tbt-notes:lesson-view', mountAll);

	window.TBTHomework = {
		mountAll: mountAll,
		toHtml: toHtml
	};
})(window, document);
