(function () {
	'use strict';

	const settings = window.wmfcSettings;
	const i18n = settings.i18n;
	const target = document.getElementById('wmfc-target');
	const quality = document.getElementById('wmfc-quality');
	const qualityValue = document.getElementById('wmfc-quality-value');
	const scanButton = document.getElementById('wmfc-scan');
	const convertButton = document.getElementById('wmfc-convert');
	const stopButton = document.getElementById('wmfc-stop');
	const rewriteButton = document.getElementById('wmfc-rewrite');
	const deleteButton = document.getElementById('wmfc-delete');
	const summary = document.getElementById('wmfc-summary');
	const progress = document.getElementById('wmfc-progress');
	const progressBar = document.getElementById('wmfc-progress-bar');
	const progressPercent = document.getElementById('wmfc-progress-percent');
	const progressState = document.getElementById('wmfc-progress-state');
	const log = document.getElementById('wmfc-log');
	const metricCount = document.getElementById('wmfc-metric-count');
	const metricOriginal = document.getElementById('wmfc-metric-original');
	const metricOptimized = document.getElementById('wmfc-metric-optimized');
	const metricSaved = document.getElementById('wmfc-metric-saved');
	const metricPercent = document.getElementById('wmfc-metric-percent');
	const metricSavedCard = metricSaved.closest('.wmfc-metric');
	const tabs = Array.from(document.querySelectorAll('[data-wmfc-tab]'));

	let shouldStop = false;
	let libraryTotal = 0;
	let originalsTotal = 0;
	let libraryMetrics = {attachments: 0, originalBytes: 0, optimizedBytes: 0, savedBytes: 0, savedPercent: 0};

	function format(template, values) {
		return values.reduce(function (result, value, index) {
			return result.replace('%' + (index + 1) + '$s', String(value));
		}, template);
	}

	function formatMegabytes(bytes) {
		const megabytes = Number(bytes || 0) / 1048576;
		return megabytes.toLocaleString(undefined, {minimumFractionDigits: megabytes < 10 ? 2 : 1, maximumFractionDigits: 2}) + ' MB';
	}

	function renderMetrics(metrics) {
		libraryMetrics = Object.assign({}, libraryMetrics, metrics || {});
		metricCount.textContent = Number(libraryMetrics.attachments || 0).toLocaleString();
		metricOriginal.textContent = formatMegabytes(libraryMetrics.originalBytes);
		metricOptimized.textContent = formatMegabytes(libraryMetrics.optimizedBytes);
		metricSaved.textContent = formatMegabytes(libraryMetrics.savedBytes);
		metricPercent.textContent = Number(libraryMetrics.savedPercent || 0).toLocaleString(undefined, {maximumFractionDigits: 1}) + '%';
		metricSavedCard.classList.toggle('wmfc-negative', Number(libraryMetrics.savedBytes || 0) < 0);
	}

	function addSessionMetrics(originalBytesDelta, optimizedBytesDelta, attachmentDelta) {
		libraryMetrics.originalBytes = Number(libraryMetrics.originalBytes || 0) + Number(originalBytesDelta || 0);
		libraryMetrics.optimizedBytes = Number(libraryMetrics.optimizedBytes || 0) + Number(optimizedBytesDelta || 0);
		libraryMetrics.savedBytes = libraryMetrics.originalBytes - libraryMetrics.optimizedBytes;
		libraryMetrics.savedPercent = libraryMetrics.originalBytes > 0 ? (libraryMetrics.savedBytes / libraryMetrics.originalBytes) * 100 : 0;
		libraryMetrics.attachments = Number(libraryMetrics.attachments || 0) + Number(attachmentDelta || 0);
		renderMetrics(libraryMetrics);
	}

	async function request(action, values) {
		const body = new URLSearchParams(Object.assign({
			action,
			nonce: settings.nonce,
			target: target.value,
			quality: quality.value,
			batch: settings.batch,
			rewriteBatch: settings.rewriteBatch
		}, values || {}));

		const response = await fetch(settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString()
		});
		let payload;
		try {
			payload = await response.json();
		} catch (error) {
			throw new Error(i18n.networkError);
		}

		if (!response.ok || !payload.success) {
			throw new Error(payload.data && payload.data.message ? payload.data.message : i18n.networkError);
		}

		return payload.data;
	}

	function addLog(message, isError) {
		const item = document.createElement('li');
		item.textContent = message;
		if (isError) {
			item.className = 'wmfc-error';
		}
		log.appendChild(item);
		log.scrollTop = log.scrollHeight;
	}

	function setStatus(text, state) {
		progressState.textContent = text;
		progressState.className = 'wmfc-status-badge';
		if (state) {
			progressState.classList.add(state);
		}
	}

	function setProgress(done, total, statusText, indeterminate) {
		if (statusText) {
			setStatus(statusText, indeterminate ? 'wmfc-running' : '');
		}

		progress.classList.toggle('wmfc-indeterminate', Boolean(indeterminate));
		if (indeterminate) {
			progressBar.style.width = '';
			progressPercent.textContent = '…';
			progress.removeAttribute('aria-valuenow');
			progress.setAttribute('aria-valuetext', statusText || i18n.working);
			return;
		}

		const percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
		progressBar.style.width = percent + '%';
		progressPercent.textContent = percent + '%';
		progress.setAttribute('aria-valuenow', String(percent));
		progress.removeAttribute('aria-valuetext');
	}

	function setBusy(isBusy, canStop) {
		scanButton.disabled = isBusy;
		convertButton.disabled = isBusy || libraryTotal < 1;
		rewriteButton.disabled = isBusy || rewriteButton.dataset.available !== '1';
		deleteButton.disabled = isBusy || deleteButton.dataset.available !== '1';
		stopButton.hidden = !isBusy || !canStop;
		stopButton.disabled = false;
		target.disabled = isBusy;
		quality.disabled = isBusy;
	}

	function activateTab(tabName) {
		tabs.forEach(function (tab) {
			const active = tab.dataset.wmfcTab === tabName;
			tab.classList.toggle('nav-tab-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
			tab.setAttribute('tabindex', active ? '0' : '-1');
			const panel = document.getElementById(tab.getAttribute('aria-controls'));
			panel.hidden = !active;
		});
	}

	async function scan() {
		setBusy(true, false);
		setProgress(0, 0, i18n.scanning, true);
		summary.textContent = i18n.scanningDescription;
		try {
			const data = await request('wmfc_scan');
			libraryTotal = Number(data.convertible || 0);
			originalsTotal = Number(data.withOriginals || 0);
			const rewritePending = Boolean(data.rewritePending);
			rewriteButton.dataset.available = rewritePending ? '1' : '0';
			deleteButton.dataset.available = originalsTotal > 0 && libraryTotal === 0 && data.supported && !rewritePending ? '1' : '0';
			summary.textContent = data.message + (rewritePending ? ' ' + i18n.urlUpdatePending : '');
			renderMetrics(data.metrics);

			if ( libraryTotal === 0 && !rewritePending ) {
				setProgress(1, 1, i18n.ready, false);
				setStatus(i18n.complete, 'wmfc-complete');
			} else {
				setProgress(0, libraryTotal, i18n.ready, false);
			}
		} catch (error) {
			summary.textContent = error.message;
			addLog(error.message, true);
			setProgress(0, 1, i18n.error, false);
			setStatus(i18n.error, 'wmfc-error-state');
		} finally {
			setBusy(false);
		}
	}

	async function convert() {
		if (!window.confirm(i18n.confirmConvert)) {
			return;
		}

		shouldStop = false;
		log.textContent = '';
		setBusy(true, true);
		setProgress(0, libraryTotal, i18n.optimizing, false);
		let lastId = 0;
		let processed = 0;
		let converted = 0;
		let skipped = 0;
		let errors = 0;
		let completed = false;
		let batchSize = Math.max(1, Number(settings.batch || 4));

		try {
			do {
				const started = Date.now();
				const data = await request('wmfc_convert_batch', {lastId, batch: batchSize});
				const elapsed = Date.now() - started;
				lastId = Number(data.lastId || lastId);
				processed += Number(data.processed || 0);
				converted += Number(data.converted || 0);
				skipped += Number(data.skipped || 0);
				errors += Number(data.errorCount || 0);
				addSessionMetrics(data.originalBytesDelta, data.optimizedBytesDelta, data.attachmentDelta);
				(data.messages || []).forEach(function (entry) {
					addLog(entry.message, entry.type === 'error');
				});
				setProgress(processed, libraryTotal, i18n.optimizing, false);
				summary.textContent = format(i18n.convertSummary, [processed, libraryTotal, converted, skipped, errors]);
				if (elapsed < 5000 && batchSize < 8) {
					batchSize += 1;
				} else if (elapsed > 15000 && batchSize > 1) {
					batchSize = Math.max(1, Math.floor(batchSize / 2));
				}
				if (data.done) {
					completed = true;
					break;
				}
			} while (!shouldStop);

			addLog(shouldStop ? i18n.stopped : i18n.conversionFinished);
		} catch (error) {
			addLog(error.message, true);
			summary.textContent = error.message;
			setStatus(i18n.error, 'wmfc-error-state');
		} finally {
			setBusy(false);
			if (completed && !shouldStop) {
				await rewriteReferences(true);
			} else {
				await scan();
			}
		}
	}

	async function rewriteReferences(automatic) {
		if (!automatic && rewriteButton.dataset.available !== '1') {
			return;
		}

		setBusy(true, false);
		setProgress(0, 7, i18n.updatingUrls, false);
		let processed = 0;
		let updated = 0;
		let rewriteBatch = Math.max(100, Number(settings.rewriteBatch || 1000));

		try {
			do {
				const started = Date.now();
				const data = await request('wmfc_rewrite_batch', {rewriteBatch});
				const elapsed = Date.now() - started;
				processed += Number(data.processed || 0);
				updated += Number(data.updated || 0);
				setProgress(Number(data.stageNumber || 1), Number(data.stageTotal || 7), i18n.updatingUrls, false);
				summary.textContent = format(i18n.urlUpdateSummary, [data.stage, processed, updated]);

				if (elapsed < 3000 && rewriteBatch < 2000) {
					rewriteBatch = Math.min(2000, rewriteBatch + 250);
				} else if (elapsed > 12000 && rewriteBatch > 100) {
					rewriteBatch = Math.max(100, Math.floor(rewriteBatch / 2));
				}
				if (data.done) {
					break;
				}
			} while (true);
			addLog(i18n.urlUpdateFinished);
			setStatus(i18n.complete, 'wmfc-complete');
		} catch (error) {
			addLog(error.message, true);
			summary.textContent = error.message;
			setStatus(i18n.error, 'wmfc-error-state');
		} finally {
			setBusy(false);
			await scan();
		}
	}

	async function deleteOriginals() {
		if (!window.confirm(i18n.confirmDelete)) {
			return;
		}

		setBusy(true, false);
		setProgress(0, originalsTotal, i18n.deleting, false);
		let lastId = 0;
		let attachments = 0;
		let deleted = 0;
		let missing = 0;
		let failed = 0;

		try {
			do {
				const data = await request('wmfc_delete_batch', {lastId});
				lastId = Number(data.lastId || lastId);
				attachments += Number(data.processed || 0);
				deleted += Number(data.deleted || 0);
				missing += Number(data.missing || 0);
				failed += Number(data.failed || 0);
				(data.messages || []).forEach(function (entry) {
					addLog(entry.message, entry.type === 'error');
				});
				setProgress(attachments, originalsTotal, i18n.deleting, false);
				summary.textContent = format(i18n.cleanupSummary, [attachments, originalsTotal, deleted, missing, failed]);
				if (data.done) {
					break;
				}
			} while (true);
			addLog(i18n.cleanupFinished);
			setStatus(i18n.complete, 'wmfc-complete');
		} catch (error) {
			addLog(error.message, true);
			summary.textContent = error.message;
			setStatus(i18n.error, 'wmfc-error-state');
		} finally {
			setBusy(false);
			await scan();
		}
	}

	tabs.forEach(function (tab, index) {
		tab.addEventListener('click', function () {
			activateTab(tab.dataset.wmfcTab);
		});
		tab.addEventListener('keydown', function (event) {
			if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
				return;
			}
			event.preventDefault();
			const offset = event.key === 'ArrowRight' ? 1 : -1;
			const next = tabs[(index + offset + tabs.length) % tabs.length];
			activateTab(next.dataset.wmfcTab);
			next.focus();
		});
	});

	quality.addEventListener('input', function () {
		qualityValue.value = quality.value;
		qualityValue.textContent = quality.value;
	});
	scanButton.addEventListener('click', scan);
	convertButton.addEventListener('click', convert);
	rewriteButton.addEventListener('click', function () {
		rewriteReferences(false);
	});
	deleteButton.addEventListener('click', deleteOriginals);
	stopButton.addEventListener('click', function () {
		shouldStop = true;
		stopButton.disabled = true;
	});
	target.addEventListener('change', scan);

	activateTab('optimize');
	renderMetrics(libraryMetrics);
	scan();
}());
