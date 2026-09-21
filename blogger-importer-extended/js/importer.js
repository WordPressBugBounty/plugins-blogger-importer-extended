(function () {
	'use strict';

	var BOOT = window.BIE_BOOT || {};


	function BieError(code, status, message, data) {
		var e = new Error(message || code);
		e.code = code;
		e.status = status;
		e.data = data || {};
		return e;
	}

	var TERMINAL = ['bie_license_limit', 'bie_forbidden', 'bie_bad_blog_id', 'bie_decode_failed'];

	function isRetryable(err) {
		if (TERMINAL.indexOf(err.code) !== -1) { return false; }
		if (err.status === 403 || err.status === 400 || err.status === 404) { return false; }
		return true;
	}

	var TIMEOUTS = { image: 60000, batch: 45000, "default": 20000 };

	function rest(route, payload, kind) {
		var url = BOOT.root + (BOOT.root.indexOf('?') !== -1 ? route.replace('?', '&') : route);
		var controller = new AbortController();
		var ms = TIMEOUTS[kind] || TIMEOUTS["default"];
		var timer = setTimeout(function () { controller.abort(); }, ms);

		var opts = {
			method: payload ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': BOOT.nonce },
			signal: controller.signal
		};

		if (payload) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(payload);
		}

		return fetch(url, opts).then(function (res) {
			clearTimeout(timer);
			return res.text().then(function (text) {
				var body = null;
				try {
					body = JSON.parse(text);
				} catch (e) {
					throw BieError('bie_bad_gateway', res.status, 'Server returned an unreadable response.', { snippet: text.slice(0, 300) });
				}
				if (!res.ok) {
					throw BieError(body.code || 'bie_internal', res.status, body.message, body.data);
				}
				return body;
			});
		}).catch(function (err) {
			clearTimeout(timer);
			if (err.name === 'AbortError') {
				throw BieError('bie_upstream_timeout', 0, 'Request timed out.');
			}
			if (err.code) { throw err; }
			throw BieError('bie_network', 0, err.message || 'Network error.');
		});
	}

	function remoteFetch(params, kind) {
		var base = BOOT.apiBase;
		var args = Object.assign({}, BOOT.apiArgs, params);
		var qs = Object.keys(args)
			.filter(function (k) { return args[k] !== '' && args[k] !== null && args[k] !== undefined; })
			.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(args[k]); })
			.join('&');

		var controller = new AbortController();
		var timer = setTimeout(function () { controller.abort(); }, TIMEOUTS[kind] || TIMEOUTS["default"]);

		return fetch(base + '?' + qs, {
			method: 'GET',
			mode: 'cors',
			credentials: 'omit',
			cache: 'no-store',
			signal: controller.signal
		}).then(function (res) {
			clearTimeout(timer);
			return res.text().then(function (text) {
				var body;
				try {
					body = JSON.parse(text);
				} catch (e) {
					throw BieError('bie_bad_gateway', res.status, 'The import service returned an unreadable response.', { snippet: text.slice(0, 300) });
				}
				if (body && body.message) {
					if (res.status >= 500) {
						throw BieError('bie_upstream_unavailable', res.status, body.message);
					}
					throw BieError('bie_license_limit', 403, body.message);
				}
				if (body && body.error && body.error.message) {
					throw BieError('bie_upstream_error', 502, body.error.message);
				}
				if (!res.ok) {
					throw BieError('bie_upstream_unavailable', res.status, 'Upstream error.');
				}
				return body;
			});
		}).catch(function (err) {
			clearTimeout(timer);
			if (err.name === 'AbortError') {
				throw BieError('bie_upstream_timeout', 0, 'Request timed out.');
			}
			if (err.code) { throw err; }
			throw BieError('bie_direct_unavailable', 0, err.message || 'Could not reach the import service directly.');
		});
	}

	function sleep(ms) {
		return new Promise(function (r) { setTimeout(r, ms); });
	}

	function backoff(attempt) {
		var base = Math.pow(4, attempt) * 1000;
		return base * (0.75 + Math.random() * 0.5);
	}

	function withRetry(fn, kind, onWarn, isStopped) {
		var attempts = 3;
		function attempt(n) {
			return fn().catch(function (err) {
				if (n >= attempts || !isRetryable(err) || (isStopped && isStopped())) { throw err; }
				if (onWarn) { onWarn(err, n + 1); }
				return sleep(backoff(n)).then(function () {
					if (isStopped && isStopped()) { throw err; }
					return attempt(n + 1);
				});
			});
		}
		return attempt(0);
	}


	function Pool(limit) {
		this.limit = limit;
		this.active = 0;
		this.waiting = [];
	}

	Pool.prototype.run = function (task) {
		var self = this;
		return new Promise(function (resolve, reject) {
			self.waiting.push({ task: task, resolve: resolve, reject: reject });
			self._next();
		});
	};

	Pool.prototype._next = function () {
		if (this.active >= this.limit || !this.waiting.length) { return; }
		var job = this.waiting.shift();
		this.active++;
		var self = this;
		Promise.resolve()
			.then(job.task)
			.then(job.resolve, job.reject)
			.then(function () {
				self.active--;
				self._next();
			});
	};

	Pool.prototype.idle = function () {
		return this.active === 0 && this.waiting.length === 0;
	};


	function Importer(opts) {
		this.blogId = opts.blogId;
		this.options = opts.options;
		this.ui = opts.ui;

		this.direct = opts.direct !== false && !!(BOOT.apiBase && BOOT.apiArgs);
		this.blogspotDomain = opts.blogspotDomain || '';

		this.pool = new Pool(BOOT.limits && BOOT.limits.concurrency || 3);
		this.counts = { posts: 0, pages: 0, images: 0, comments: 0, skipped: 0 };
		this.warnings = [];
		this.stopped = false;
		this.isStopped = function () { return this.stopped; }.bind(this);
		this.lastSettled = Date.now();
	}

	Importer.prototype.warn = function (code, message, context) {
		this.warnings.push({ code: code, message: message, context: context || '' });
		this.ui.warnings(this.warnings);
	};

	Importer.prototype.touch = function () {
		this.lastSettled = Date.now();
	};

	Importer.prototype.handleItem = function (item, type) {
		var self = this;

		return withRetry(function () {
			return rest('/post', {
				blog_id: self.blogId,
				type: type,
				item: item,
				skip_authors: self.options.skipAuthors ? 1 : 0,
				skip_images: self.options.skipImages ? 1 : 0
			});
		}, 'default', null, self.isStopped).then(function (res) {

			self.touch();

			if (!res.created) {
				self.counts.skipped++;
				self.ui.progress(self.counts);
				return;
			}

			if (type === 'pages') { self.counts.pages++; } else { self.counts.posts++; }
			self.ui.progress(self.counts);

			var comments = (!self.options.skipComments && type === 'posts' && item.comments)
				? self.importComments(res.post_id, item.id)
				: Promise.resolve();

			var images = (res.images && res.images.length)
				? self.importImages(res.post_id, res.images)
				: Promise.resolve(0);

			return Promise.all([comments, images]).then(function (settled) {
				if (res.needs_finalize && !self.stopped) {
					return self.finalize(res.post_id, settled[1] > 0);
				}
			});

		}).catch(function (err) {
			self.warn(err.code, err.message, 'post ' + (item.title || item.id));
			if (!isRetryable(err)) { throw err; }
		});
	};

	Importer.prototype.importComments = function (postId, bloggerPostId) {
		var self = this;
		var token = '';

		function page() {
			return withRetry(function () {
				return rest('/comments', {
					blog_id: self.blogId,
					post_id: postId,
					blogger_post_id: bloggerPostId,
					page_token: token
				}, 'batch');
			}, 'default').then(function (res) {
				self.touch();
				self.counts.comments += res.inserted;
				self.ui.progress(self.counts);
				if (res.next_page_token) {
					token = res.next_page_token;
					return page();
				}
			});
		}

		return page().catch(function (err) {
			self.warn(err.code, err.message, 'comments for post ' + postId);
		});
	};

	Importer.prototype.importImages = function (postId, urls) {
		var self = this;
		var abandoned = 0;

		return urls.reduce(function (chain, src) {
			return chain.then(function () {
				if (self.stopped) { abandoned++; return; }
				return withRetry(function () {
					return rest('/image', { post_id: postId, src: src }, 'image');
				}, 'image', null, self.isStopped).then(function (res) {
					self.touch();
					if (res.attachment_id) {
						self.counts.images++;
						self.ui.progress(self.counts);
					} else if (res.warning) {
						self.warn('bie_image_failed', res.warning, src);
					}
				}).catch(function (err) {
					abandoned++;
					self.warn(err.code, err.message, src);
				});
			});
		}, Promise.resolve()).then(function () {
			return abandoned;
		});
	};

	Importer.prototype.finalize = function (postId, abandonRemaining) {
		var self = this;
		return withRetry(function () {
			return rest('/finalize', {
				post_id: postId,
				abandon_remaining: abandonRemaining ? 1 : 0
			});
		}, 'default').then(function () {
			self.touch();
		}).catch(function (err) {
			self.warn(err.code, err.message, 'finalize post ' + postId);
		});
	};

	Importer.prototype.runPhase = function (type) {
		var self = this;
		var token = '';

		function fetchPage() {
			var params = {
				blog_id: self.blogId,
				query: type,
				page_token: token,
				n: BOOT.pageSize || 25,
				blogspot_domain: self.blogspotDomain || ''
			};
			if (self.options.skipComments) { params.skip_comments = 1; }
			if (self.options.skipImages) { params.skip_images = 1; }
			if (self.options.convertFormatting) { params.convert_formatting = 1; }

			if (self.direct) {
				return remoteFetch(params, 'batch').then(function (body) {
					return {
						items: (body && body.items) || [],
						next_page_token: (body && body.nextPageToken) || ''
					};
				}).catch(function (err) {
					if (err.code !== 'bie_direct_unavailable') { throw err; }
					self.direct = false;
					self.warn('bie_direct_unavailable', 'Reading via the server instead.', '');
					return viaServer();
				});
			}

			return viaServer();
		}

		function viaServer() {
			return rest('/batch', {
				blog_id: self.blogId,
				type: type,
				page_token: token,
				skip_comments: self.options.skipComments ? 1 : 0,
				skip_images: self.options.skipImages ? 1 : 0,
				convert_formatting: self.options.convertFormatting ? 1 : 0
			}, 'batch');
		}

		function nextBatch() {
			if (self.stopped) { return Promise.resolve(); }

			return withRetry(fetchPage, 'batch', null, self.isStopped).then(function (res) {

				self.touch();

				var items = res.items || [];

				return Promise.all(items.map(function (item) {
					return self.pool.run(function () {
						if (self.stopped) { return; }
						return self.handleItem(item, type);
					});
				})).then(function () {
					var mark = (type === 'posts' && (self.direct || self.stopped))
						? rest('/cursor', { blog_id: self.blogId, page_token: self.stopped ? token : (res.next_page_token || '') }).catch(function () {})
						: Promise.resolve();

					return mark.then(function () {
						if (res.next_page_token && !self.stopped) {
							token = res.next_page_token;
							return nextBatch();
						}
					});
				});

			});
		}

		return nextBatch();
	};

	Importer.prototype.drainPending = function () {
		var self = this;
		var after = 0;

		function page() {
			return rest('/pending?after=' + after + '&limit=100', null).then(function (res) {
				var items = res.items || [];
				if (!items.length) { return; }

				items.forEach(function (entry) {
					if (entry.post_id > after) { after = entry.post_id; }
				});

				return Promise.all(items.map(function (entry) {
					return self.pool.run(function () {
						if (self.stopped) { return; }
						var work = entry.todo.length
							? self.importImages(entry.post_id, entry.todo)
							: Promise.resolve(0);
						return work.then(function (abandoned) {
							if (self.stopped) { return; }
							return self.finalize(entry.post_id, abandoned > 0);
						});
					});
				})).then(function () {
					if (res.more && !self.stopped) { return page(); }
				});
			});
		}

		return page().catch(function (err) {
			self.warn(err.code, err.message, 'resuming outstanding work');
		});
	};

	Importer.prototype.run = function () {
		var self = this;

		self.ui.started();

		return self.drainPending()
			.then(function () { return self.runPhase('posts'); })
			.then(function () {
				if (self.options.skipPages) { return; }
				return self.runPhase('pages');
			})
			.then(function () {
				return self.drainPending();
			})
			.then(function () {
				if (self.stopped) {
					self.ui.stopped(self.counts);
					return;
				}
				return rest('/complete', {}).then(function () {
					self.ui.done(self.counts, self.warnings);
				});
			})
			.catch(function (err) {
				if (self.stopped) {
					self.ui.stopped(self.counts);
					return;
				}
				self.ui.failed(err, self.counts, self.warnings);
			});
	};

	Importer.prototype.stop = function () {
		this.stopped = true;
	};

	window.BIEImporter = Importer;
	window.BIERest = rest;

})();
