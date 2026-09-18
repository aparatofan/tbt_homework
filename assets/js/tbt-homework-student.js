/**
 * TBT Homework — the student library.
 *
 * The page arrives complete: every entry is already in the document, written
 * by PHP. This only decides which of them are shown, so there is no request to
 * make, no loading state, and nothing to lose if the script never runs — a
 * student without JavaScript still reads their whole library, just without the
 * search and the filter.
 *
 * No build step, no dependencies.
 */
(function (window, document) {
	'use strict';

	var SET = 'tbth-is-set';

	/**
	 * The text one entry can be searched on.
	 *
	 * The lesson, the class and date line, the homework itself and the
	 * teacher's comment — everything the student wrote or was told, and not
	 * the status tag, which the dropdown next to the search already covers.
	 *
	 * Computed once per entry and kept on the node.
	 *
	 * @param {HTMLElement} entry One entry.
	 */
	function haystack(entry) {
		if (typeof entry.tbthHaystack === 'string') {
			return entry.tbthHaystack;
		}

		var parts = entry.querySelectorAll('.tbth-entry__lesson, .tbth-meta, .tbth-body, .tbth-comment__body');
		var text = '';

		Array.prototype.forEach.call(parts, function (part) {
			text += ' ' + part.textContent;
		});

		entry.tbthHaystack = text.toLowerCase();

		return entry.tbthHaystack;
	}

	/**
	 * Should this entry be shown?
	 *
	 * @param {HTMLElement} entry  One entry.
	 * @param {string}      query  Lowercased, trimmed search text.
	 * @param {string}      status 'all', 'waiting' or 'commented'.
	 */
	function matches(entry, query, status) {
		if (status !== 'all' && entry.getAttribute('data-status') !== status) {
			return false;
		}

		if (!query) {
			return true;
		}

		return haystack(entry).indexOf(query) !== -1;
	}

	/**
	 * Wire one library.
	 *
	 * @param {HTMLElement} app The shortcode's root.
	 */
	function mount(app) {
		if (app.dataset.tbthMounted) {
			return;
		}
		app.dataset.tbthMounted = '1';

		var search = app.querySelector('[data-tbth-search]');
		var clear = app.querySelector('[data-tbth-clear]');
		var filter = app.querySelector('[data-tbth-filter]');
		var list = app.querySelector('[data-tbth-entries]');
		var noresults = app.querySelector('[data-tbth-noresults]');

		// An empty library has neither control, and nothing to filter.
		if (!list || (!search && !filter)) {
			return;
		}

		var entries = Array.prototype.slice.call(app.querySelectorAll('[data-tbth-entry]'));

		function apply() {
			var query = search ? search.value.trim().toLowerCase() : '';
			var status = filter ? filter.value : 'all';
			var shown = 0;

			entries.forEach(function (entry) {
				var ok = matches(entry, query, status);
				entry.hidden = !ok;
				if (ok) {
					shown += 1;
				}
			});

			if (noresults) {
				noresults.hidden = shown !== 0;
			}

			if (filter) {
				filter.classList.toggle(SET, status !== 'all');
			}

			// The × is there only while there is something to clear. Its own
			// value, not the trimmed one: a box holding a space has text in
			// it, whatever it matches.
			if (clear) {
				clear.hidden = !search || '' === search.value;
			}
		}

		/** Empty the box, show everything again, and hand the field back. */
		function clearSearch() {
			search.value = '';
			apply();
			search.focus();
		}

		if (search) {
			search.addEventListener('input', apply);
			// A browser restoring a value on reload should not leave the list
			// disagreeing with the box above it.
			search.addEventListener('change', apply);

			search.addEventListener('keydown', function (event) {
				// Escape clears a search in progress. On an empty field it
				// passes straight through — the browser and the theme both
				// have uses for it.
				if ('Escape' !== event.key || '' === search.value) {
					return;
				}
				event.preventDefault();
				clearSearch();
			});
		}

		if (clear && search) {
			clear.addEventListener('click', clearSearch);
		}

		if (filter) {
			filter.addEventListener('change', apply);
		}

		apply();
	}

	function mountAll() {
		Array.prototype.forEach.call(document.querySelectorAll('.tbth-student'), mount);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mountAll);
	} else {
		mountAll();
	}

	window.TBTHomeworkStudent = {
		mountAll: mountAll
	};
})(window, document);
