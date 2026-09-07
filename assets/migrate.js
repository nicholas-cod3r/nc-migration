(function ($) {
	'use strict';

	var settings = window.ncMigrationAdmin || {};
	var urls = settings.urls || {};
	var i18n = settings.i18n || {};

	var filesToMigrate = [];
	var unreadableDirs = [];
	var filesToProcess = [];
	var errorCounter = 0;
	var successCounter = 0;
	var totalFiles = 0;
	var progressStopped = false;
	var processIndex = 0;
	var migrationBatchSize = 500;
	var selectedTargetId = '';
	var selectedTargetName = '';
	var analysisReady = false;
	var analyzeCursor = null;

	var analyzeButton;
	var migrateButton;
	var transferdbButton;
	var clearButton;
	var stopmigrateButton;
	var progressDiv;
	var progressBar;
	var progressCounter;
	var progressPercentage;
	var resultBox;
	var sourcePathParam;
	var sourceUrlParam;
	var targetPathParam;
	var targetUrlParam;
	var targetsSelect;

	function getNonce() {
		return settings.nonce || '';
	}

	function ajaxPost(url, data) {
		return $.ajax({
			type: 'POST',
			url: url,
			dataType: 'json',
			data: data,
			beforeSend: function (xhr) {
				xhr.setRequestHeader('X-WP-Nonce', getNonce());
			}
		});
	}

	function normalizeResponse(response) {
		if (typeof response === 'string') {
			return JSON.parse(response);
		}
		return response;
	}

	function analyze() {
		setUiStatusAnalyzationProgress();
		writeLog('>>> Analyzation started');
		getSelectedTargetId();
		filesToMigrate = [];
		unreadableDirs = [];
		analyzeCursor = null;
		analyzeRequestAsync();
	}

	/**
	 * The server scans the source tree in time-boxed batches (see Migration_File_Sync::scan_batch()
	 * on the PHP side) because a huge uploads folder can't be walked and returned in one request.
	 * This keeps re-posting the cursor from each response until responseJson.done comes back true.
	 */
	function analyzeRequestAsync() {
		var data = { targetID: selectedTargetId };
		if (analyzeCursor) {
			data.cursor = JSON.stringify(analyzeCursor);
		}

		ajaxPost(urls.analyze, data)
			.done(function (response) {
				try {
					var responseJson = normalizeResponse(response);
					if (responseJson.return_code != 0) {
						analysisReady = false;
						writeLogError(responseJson.return_message);
						setUiStatusWindowLoaded();
						writeLog('<<< Analyzation ended');
						return;
					}

					filesToMigrate = filesToMigrate.concat(responseJson.files || []);
					unreadableDirs = unreadableDirs.concat(responseJson.unreadable_dirs || []);
					writeLogDebug('Scanned so far, differences found=' + filesToMigrate.length);

					if (!responseJson.done) {
						analyzeCursor = responseJson.cursor || null;
						analyzeRequestAsync();
						return;
					}

					totalFiles = filesToMigrate.length;
					analysisReady = totalFiles > 0;
					setUiStatusWindowLoaded();
					if (analysisReady) {
						setUiStatusAnalyzationFinished();
					}
					analyzeReport();
					analyzeWarningsReport();
					writeLog('<<< Analyzation ended');
				} catch (e) {
					analysisReady = false;
					writeLogError(e);
					setUiStatusWindowLoaded();
					writeLog('<<< Analyzation ended');
				}
			})
			.fail(function (xhr, textStatus, errorThrown) {
				try {
					writeLogError(xhr.status + ': ' + textStatus + (errorThrown ? ' — ' + errorThrown : ''));
				} finally {
					writeLog('<<< Analyzation ended');
					setUiStatusWindowLoaded();
				}
			});
	}

	function analyzeReport() {
		writeLogSuccess('Total differences: ' + totalFiles + ' file(s)');

		var newFiles = [];
		var modifiedFiles = [];
		filesToMigrate.forEach(function (file) {
			if (file.status === 'N') {
				newFiles.push(file);
			} else if (file.status === 'M') {
				modifiedFiles.push(file);
			}
		});

		writeLogFileGroup('New file(s): ' + newFiles.length, newFiles);
		writeLogFileGroup('Modified file(s): ' + modifiedFiles.length, modifiedFiles);
	}

	/**
	 * Directories the server couldn't read mid-scan (removed/renamed/permission denied) — their
	 * contents were skipped, not confirmed to have no differences, so this is surfaced separately
	 * from "Total differences" rather than silently folded into a lower count.
	 */
	function analyzeWarningsReport() {
		if (!unreadableDirs.length) {
			return;
		}
		writeLogWarning('Unreadable director(y/ies) skipped during scan: ' + unreadableDirs.length);
		writeLogItemGroup('Skipped director(y/ies): ' + unreadableDirs.length, unreadableDirs, function (dir) {
			return dir || '(source root)';
		});
	}

	function fileRelativePath(file) {
		var path = file.path || '';
		var name = file.name || '';
		if (!path) {
			return name;
		}
		return path.replace(/\/$/, '') + '/' + name;
	}

	/**
	 * Console-style collapsible group: collapsed "▶ label", expanded list of file paths.
	 */
	function writeLogFileGroup(label, files) {
		writeLogItemGroup(label, files, fileRelativePath);
	}

	/**
	 * Console-style collapsible group: collapsed "▶ label", expanded list built from `items`
	 * via `textFn`. Shared by writeLogFileGroup() (Migrate_File descriptors) and the unreadable
	 * directory report (plain relative-path strings).
	 */
	function writeLogItemGroup(label, items, textFn) {
		var $details = $('<details class="migration-log-group"></details>');
		var $summary = $('<summary class="migration-log-group__summary"></summary>');
		$summary.text(label);
		$details.append($summary);

		if (!items.length) {
			appendLog($details);
			return;
		}

		var $list = $('<div class="migration-log-group__list"></div>');
		items.forEach(function (item) {
			var $row = $('<div class="migration-log-group__item"></div>');
			$row.text(textFn(item));
			$list.append($row);
		});
		$details.append($list);
		appendLog($details);
	}

	function migrate() {
		var confirmMessage = (i18n.migrateConfirm || '')
			.replace('%1$s', sourcePathParam.text())
			.replace('%2$s', targetPathParam.text());
		if (!window.confirm(confirmMessage)) {
			return;
		}

		getSelectedTargetId();
		setUiStatusMigrationProgress();
		errorCounter = 0;
		successCounter = 0;
		processIndex = 0;
		filesToProcess = [];
		progressStopped = false;
		setUiStatusUpdateProgress();
		writeLog('>>> Migration started (do not close this window until finished)');
		migrateAjaxRecursiveAsync();
	}

	function migrateAjaxRecursiveAsync() {
		var continueProcessing = false;

		if (filesToProcess.length === 0) {
			if (processIndex < filesToMigrate.length) {
				filesToProcess = [];
				writeLogDebug('New batch index=' + processIndex);
				var k;
				for (k = processIndex; k < processIndex + migrationBatchSize && k < filesToMigrate.length; k++) {
					filesToProcess.push(filesToMigrate[k]);
				}
				processIndex = k;
				continueProcessing = true;
			} else {
				writeLogDebug('The end. Index reached last element=' + processIndex);
				migrationFinishedReport();
			}
		} else {
			continueProcessing = true;
		}

		if (!continueProcessing) {
			return;
		}

		var filesToProcessOriginalCount = filesToProcess.length;
		ajaxPost(urls.copy, {
			targetID: selectedTargetId,
			files: JSON.stringify(filesToProcess)
		})
			.done(function (response) {
				try {
					var responseJson = normalizeResponse(response);
					filesToProcess = responseJson.remaining_files || [];
					writeLogDebug('Remaining files in batch=' + filesToProcess.length);
					handleMigrateRestResult(responseJson);
					setUiStatusUpdateProgress();

					if (progressStopped) {
						writeLog('Progress stopped by user.');
						setUiProgressDiv(false);
						migrationFinishedReport();
						return;
					}

					if (filesToProcess.length > 0) {
						if (filesToProcess.length < filesToProcessOriginalCount) {
							migrateAjaxRecursiveAsync();
						} else {
							writeLogWarning('Stopped migration because unexpectedly no files processed in last batch.');
							migrationFinishedReport();
						}
					} else {
						migrateAjaxRecursiveAsync();
					}
				} catch (e) {
					writeLogError(e);
					writeLogWarning('Stopped migration due to unexpected exception');
					migrationFinishedReport();
				}
			})
			.fail(function (xhr, textStatus, errorThrown) {
				try {
					writeLogError(xhr.status + ': ' + textStatus + (errorThrown ? ' — ' + errorThrown : ''));
					writeLogWarning('Stopped migration due to unexpected result');
					migrationFinishedReport();
				} finally {
					setUiStatusWindowLoaded();
				}
			});
	}

	function handleMigrateRestResult(responseJson) {
		var failed = responseJson.failed_files || [];
		var success = responseJson.success_files || [];

		failed.forEach(function (file) {
			writeLogError(
				(file.path || '') + (file.name || '') + ': ' + (file.error || '') + ' => ' + JSON.stringify(file.message)
			);
		});
		errorCounter += failed.length;
		successCounter += success.length;

		if (responseJson.return_code != 0) {
			writeLogWarning(responseJson.return_message || 'Some files failed in this batch; continuing.');
		}
	}

	function migrationFinishedReport() {
		setUiStatusMigrationFinished();
		writeLog('=== Migration report ===');
		writeLog('Successfully copied files: ' + successCounter);
		if (errorCounter > 0) {
			writeLogColored('Failed files: ' + errorCounter, 'red');
		}
		writeLog('<<< Migration process ended.');
	}

	function stopMigrate() {
		progressStopped = true;
		stopmigrateButton.prop('disabled', true);
		writeLog('Stopping shortly, be patient...');
	}

	function transferdb() {
		getSelectedTargetName();
		var confirmMessage = (i18n.dbConfirm || '').replace('%s', selectedTargetName);
		if (!window.confirm(confirmMessage)) {
			return;
		}

		getSelectedTargetId();
		setUiStatusTransferdbProgress();
		writeLog('>>> Transfer database started');
		transferdbAsync();
	}

	function transferdbAsync() {
		ajaxPost(urls.exportdb, { targetID: selectedTargetId })
			.done(function (response) {
				try {
					var responseJson = normalizeResponse(response);
					(responseJson.messages || []).forEach(function (msg) {
						writeLog(msg);
					});
					if (responseJson.return_code == 0) {
						writeLogSuccess('Database transferred.');
					} else {
						writeLogError('Database transfer failed: ' + responseJson.return_message);
						(responseJson.errors || []).forEach(function (error) {
							writeLogError(error);
						});
					}
				} catch (e) {
					writeLogError(e);
				} finally {
					setUiStatusTransferdbFinished();
					writeLog('<<< Transfer database ended');
				}
			})
			.fail(function (xhr, textStatus, errorThrown) {
				try {
					writeLogError(xhr.status + ': ' + textStatus + (errorThrown ? ' — ' + errorThrown : ''));
				} finally {
					setUiStatusTransferdbFinished();
					writeLog('<<< Transfer database ended');
				}
			});
	}

	function processConfigLoadResult(messages) {
		var first = true;
		(messages || []).forEach(function (message) {
			if (first) {
				writeLogError(message);
				first = false;
			} else {
				writeLog(message);
			}
		});
	}

	function fillTargetCombo(targets) {
		if (!targetsSelect.length) {
			return;
		}
		(targets || []).forEach(function (item, index) {
			targetsSelect.append(new Option(item.Name, index));
		});
		targetsSelect.trigger('change');
	}

	function onTargetComboChange() {
		var value = targetsSelect.val();
		if (value === null || value === '') {
			return;
		}
		var config = settings.config;
		if (!config || !config.Source || !config.Targets) {
			return;
		}
		sourcePathParam.text(config.Source.Path);
		sourceUrlParam.text(settings.sourceUrl || '');
		targetPathParam.text(config.Targets[value].Path);
		targetUrlParam.text(config.Targets[value].URL);
		analysisReady = false;
		migrateButton.prop('disabled', true);
	}

	function appendLog($el) {
		resultBox.append($el);
		var el = resultBox[0];
		if (el) {
			el.scrollTop = el.scrollHeight;
		}
	}

	function writeLogColored(text, color) {
		var $line = $('<div></div>');
		if (color) {
			$line.css('color', color);
		}
		$line.text(String(text));
		appendLog($line);
	}

	function writeLog(text) {
		writeLogColored(text, '');
	}

	function writeLogError(text) {
		writeLogColored('ERROR: ' + text, 'red');
	}

	function writeLogWarning(text) {
		writeLogColored('WARNING: ' + text, 'orange');
	}

	function writeLogSuccess(text) {
		writeLogColored(text, 'green');
	}

	function writeLogDebug(text) {
		if (settings.debug) {
			console.debug('[migration]', text);
		}
	}

	function setUiStatusLoadError() {
		analyzeButton.prop('disabled', true);
		transferdbButton.prop('disabled', true);
		migrateButton.prop('disabled', true);
		stopmigrateButton.prop('disabled', true);
		setUiTargetsEnabled(false);
		setUiProgressDiv(false);
	}

	function setUiStatusWindowLoaded() {
		analyzeButton.prop('disabled', false);
		transferdbButton.prop('disabled', false);
		migrateButton.prop('disabled', !analysisReady);
		stopmigrateButton.prop('disabled', true);
		setUiTargetsEnabled(true);
		setUiProgressDiv(false);
	}

	function setUiStatusAnalyzationProgress() {
		analyzeButton.prop('disabled', true);
		transferdbButton.prop('disabled', true);
		migrateButton.prop('disabled', true);
		stopmigrateButton.prop('disabled', true);
		setUiTargetsEnabled(false);
		setUiProgressDiv(false);
	}

	function setUiStatusAnalyzationFinished() {
		analyzeButton.prop('disabled', false);
		transferdbButton.prop('disabled', false);
		migrateButton.prop('disabled', false);
		stopmigrateButton.prop('disabled', true);
		setUiTargetsEnabled(true);
		setUiProgressDiv(false);
	}

	function setUiStatusMigrationProgress() {
		analyzeButton.prop('disabled', true);
		transferdbButton.prop('disabled', true);
		migrateButton.prop('disabled', true);
		stopmigrateButton.prop('disabled', false);
		setUiTargetsEnabled(false);
		setUiProgressDiv(true);
	}

	function setUiStatusMigrationFinished() {
		analyzeButton.prop('disabled', false);
		transferdbButton.prop('disabled', false);
		migrateButton.prop('disabled', true);
		stopmigrateButton.prop('disabled', true);
		analysisReady = false;
		setUiTargetsEnabled(true);
		setUiProgressDiv(false);
	}

	function setUiStatusTransferdbProgress() {
		analyzeButton.prop('disabled', true);
		transferdbButton.prop('disabled', true);
		migrateButton.prop('disabled', true);
		stopmigrateButton.prop('disabled', true);
		setUiTargetsEnabled(false);
		setUiProgressDiv(false);
	}

	function setUiStatusTransferdbFinished() {
		analyzeButton.prop('disabled', false);
		transferdbButton.prop('disabled', false);
		migrateButton.prop('disabled', !analysisReady);
		stopmigrateButton.prop('disabled', true);
		setUiTargetsEnabled(true);
		setUiProgressDiv(false);
	}

	function setUiStatusUpdateProgress() {
		var processed = successCounter + errorCounter;
		progressBar.prop('max', totalFiles);
		progressBar.prop('value', processed);
		progressCounter.text(processed + ' / ' + totalFiles);
		var percent = totalFiles > 0 ? Math.round((processed / totalFiles) * 100) : 0;
		progressPercentage.text(percent + '%');
	}

	function setUiProgressDiv(visible) {
		if (visible) {
			progressDiv.css('display', 'inline-block');
		} else {
			progressDiv.hide();
		}
	}

	function setUiTargetsEnabled(enable) {
		targetsSelect.prop('disabled', !enable);
	}

	function clearResult() {
		if (window.confirm(i18n.clearConfirm || 'Clear?')) {
			resultBox.empty();
		}
	}

	function getSelectedTargetId() {
		selectedTargetId = targetsSelect.find('option').filter(':selected').val();
	}

	function getSelectedTargetName() {
		selectedTargetName = targetsSelect.find('option').filter(':selected').text();
	}

	function bindUi() {
		analyzeButton = $('#analyze');
		migrateButton = $('#migrate');
		transferdbButton = $('#transferdb');
		clearButton = $('#clear');
		stopmigrateButton = $('#stopmigrate');
		progressDiv = $('#progress-div');
		progressBar = $('#progress-bar');
		progressCounter = $('#progress-counter');
		progressPercentage = $('#progress-percentage');
		resultBox = $('#result');
		sourcePathParam = $('#SourcePath');
		sourceUrlParam = $('#SourceUrl');
		targetPathParam = $('#TargetPath');
		targetUrlParam = $('#TargetUrl');
		targetsSelect = $('#targets');

		analyzeButton.on('click', analyze);
		migrateButton.on('click', migrate);
		transferdbButton.on('click', transferdb);
		clearButton.on('click', clearResult);
		stopmigrateButton.on('click', stopMigrate);
		targetsSelect.on('change', onTargetComboChange);

		$('#migration-protect-export').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);
			ajaxPost(urls.protectExport, {})
				.done(function (response) {
					var responseJson = normalizeResponse(response);
					if (responseJson && responseJson.return_code == 0) {
						window.location.reload();
						return;
					}
					window.alert(i18n.protectFailed || 'Failed to update .htaccess.');
					$btn.prop('disabled', false);
				})
				.fail(function (xhr) {
					var message = i18n.protectFailed || 'Failed to update .htaccess.';
					if (xhr.responseJSON && xhr.responseJSON.message) {
						message = xhr.responseJSON.message;
					}
					window.alert(message);
					$btn.prop('disabled', false);
				});
		});
	}

	$(function () {
		bindUi();

		var configErrors = settings.configErrors || [];
		if (configErrors.length === 0 && settings.config) {
			fillTargetCombo(settings.config.Targets);
			setUiStatusWindowLoaded();
		} else {
			setUiStatusLoadError();
			processConfigLoadResult(configErrors);
		}
	});
})(jQuery);
