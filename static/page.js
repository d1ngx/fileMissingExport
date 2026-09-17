(function(){
	if (window.__fileMissingExportInited) return;
	window.__fileMissingExportInited = true;

	var looping = false;
	var lastState = {};
	var $box = function(){ return $('.file-missing-export-page'); };

	var panelHtml = function(){
		return '<div class="desc-box mb-5">'+LNG['fileMissingExport.page.desc']+'</div>\
			<ul class="desc-box">\
				<li>'+LNG['fileMissingExport.page.tip1']+'</li>\
				<li>'+LNG['fileMissingExport.page.tip2']+'</li>\
				<li>'+LNG['fileMissingExport.page.tip3']+'</li>\
			</ul>\
			<div class="opt-row">\
				<div class="opt-item">\
					<label>'+LNG['fileMissingExport.opt.ioType']+'</label>\
					<select class="opt-ioType"></select>\
				</div>\
				<div class="opt-item">\
					<label><input type="checkbox" class="opt-recycle" checked> '+LNG['fileMissingExport.opt.recycle']+'</label>\
				</div>\
				<div class="opt-item">\
					<label><input type="checkbox" class="opt-history"> '+LNG['fileMissingExport.opt.history']+'</label>\
				</div>\
				<div class="opt-item">\
					<label>'+LNG['fileMissingExport.opt.batch']+'</label>\
					<input type="number" class="opt-batch" min="50" max="2000" value="300">\
				</div>\
			</div>\
			<div class="btn-row">\
				<button type="button" class="kui-btn kui-btn-blue act-start">'+LNG['fileMissingExport.btn.start']+'</button>\
				<button type="button" class="kui-btn act-continue">'+LNG['fileMissingExport.btn.continue']+'</button>\
				<button type="button" class="kui-btn act-stop">'+LNG['fileMissingExport.btn.stop']+'</button>\
				<button type="button" class="kui-btn act-reset">'+LNG['fileMissingExport.btn.reset']+'</button>\
				<button type="button" class="kui-btn act-csv">'+LNG['fileMissingExport.btn.downloadCsv']+'</button>\
				<button type="button" class="kui-btn act-txt">'+LNG['fileMissingExport.btn.downloadTxt']+'</button>\
			</div>\
			<div class="mb-10">'+LNG['fileMissingExport.stat.progress']+': \
				<span class="status-tag status-text">'+LNG['fileMissingExport.status.idle']+'</span>\
				<span class="percent-text ml-10">0%</span>\
			</div>\
			<div class="progress-bar"><span></span></div>\
			<div class="stat-grid">\
				<div class="stat-card"><div class="k">'+LNG['fileMissingExport.stat.scanned']+'</div><div class="v v-scanned">0</div></div>\
				<div class="stat-card"><div class="k">'+LNG['fileMissingExport.stat.missing']+'</div><div class="v v-missing">0</div></div>\
				<div class="stat-card"><div class="k">'+LNG['fileMissingExport.stat.total']+'</div><div class="v v-total">0</div></div>\
				<div class="stat-card"><div class="k">runId</div><div class="v v-runid" style="font-size:13px;word-break:break-all;">-</div></div>\
			</div>\
			<div class="danger-box">\
				<div class="danger-title">'+LNG['fileMissingExport.clean.title']+'</div>\
				<div class="danger-desc">'+LNG['fileMissingExport.clean.desc']+'</div>\
				<button type="button" class="kui-btn kui-btn-red act-clean" disabled>'+LNG['fileMissingExport.btn.clean']+'</button>\
				<button type="button" class="kui-btn act-clean-continue" disabled>'+LNG['fileMissingExport.btn.cleanContinue']+'</button>\
				<div class="clean-stat mt-10"><span class="clean-status-text">'+LNG['fileMissingExport.clean.status.idle']+'</span> · \
					'+LNG['fileMissingExport.stat.cleanProcessed']+' <span class="v-cdone">0</span>/<span class="v-ctotal">0</span> · \
					'+LNG['fileMissingExport.stat.cleanSource']+' <span class="v-csource">0</span> · \
					'+LNG['fileMissingExport.stat.cleanFile']+' <span class="v-cfile">0</span> · \
					'+LNG['fileMissingExport.stat.cleanSkip']+' <span class="v-cskip">0</span>\
				</div>\
			</div>\
			<div class="mt-15">'+LNG['fileMissingExport.stat.recent']+'</div>\
			<div class="recent-list"></div>';
	};

	var options = function(){
		var $el = $box();
		return {
			ioType: $el.find('.opt-ioType').val() || 0,
			includeRecycle: $el.find('.opt-recycle').prop('checked') ? 1 : 0,
			includeHistory: $el.find('.opt-history').prop('checked') ? 1 : 0,
			batch: $el.find('.opt-batch').val() || 300
		};
	};

	var fillStorage = function(list, current){
		var $sel = $box().find('.opt-ioType');
		if (!$sel.length || !list || !list.length) return;
		if (!$sel.data('filled')) {
			var html = '';
			_.each(list, function(item){
				html += '<option value="'+item.id+'">'+_.escape(item.name)+'</option>';
			});
			$sel.html(html).data('filled', 1);
		}
		if (!looping) $sel.val(current || 0);
	};

	var renderRecent = function(list){
		var $list = $box().find('.recent-list');
		if (!list || !list.length) { $list.html(''); return; }
		var html = '';
		for (var i = list.length - 1; i >= 0; i--) {
			var item = list[i];
			html += '<div class="row"><div class="path">'+_.escape(item.pathDisplay || '')+'</div>\
				<div class="meta">'+_.escape(item.reasonText || '')+' · '+_.escape(item.kindText || '')+' · '+_.escape(item.sizeText || '')+'</div></div>';
		}
		$list.html(html);
	};

	var applyState = function(data){
		if (!data) return;
		lastState = data;
		var $el = $box();
		fillStorage(data.storageList || [], data.ioType);
		$el.find('.opt-recycle').prop('checked', data.includeRecycle != 0);
		$el.find('.opt-history').prop('checked', data.includeHistory == 1);
		if (data.batch) $el.find('.opt-batch').val(data.batch);
		$el.find('.status-text').text(data.statusText || data.status || '');
		$el.find('.status-text').attr('class', 'status-tag status-text status-'+(data.status || 'idle'));
		var percent = data.percent || 0;
		var percentLabel = percent + '%';
		if (data.cleanStatus == 'running' || data.cleanStatus == 'paused' || data.cleanStatus == 'done') {
			percent = data.cleanPercent || 0;
			percentLabel = (data.cleanProcessed || 0) + ' / ' + (data.cleanTotal || data.missing || 0) + ' (' + percent + '%)';
		}
		$el.find('.percent-text').text(percentLabel);
		$el.find('.progress-bar span').css('width', percent + '%');
		$el.find('.v-scanned').text(data.scanned || 0);
		$el.find('.v-missing').text(data.missing || 0);
		$el.find('.v-total').text(data.total || 0);
		$el.find('.v-runid').text(data.runId || '-');
		$el.find('.v-csource').text(data.cleanDeletedSource || 0);
		$el.find('.v-cfile').text(data.cleanDeletedFile || 0);
		$el.find('.v-cskip').text(data.cleanSkipped || 0);
		$el.find('.v-cdone').text(data.cleanProcessed || 0);
		$el.find('.v-ctotal').text(data.cleanTotal || data.missing || 0);
		$el.find('.clean-status-text').text(data.cleanStatusText || LNG['fileMissingExport.clean.status.idle']);
		renderRecent(data.recent || []);
		var busy = data.status == 'running' || data.cleanStatus == 'running';
		$el.find('.opt-ioType,.opt-recycle,.opt-history,.opt-batch').prop('disabled', busy);
		$el.find('.act-clean').prop('disabled', !data.canClean);
		$el.find('.act-clean-continue').prop('disabled', !data.canCleanContinue);
	};

	var loopRun = function(){
		if (looping != 'scan') return;
		kodApi.requestSend('plugin/fileMissingExport/run', {}, function(result){
			if (!result || !result.code) {
				looping = false;
				return Tips.close(result);
			}
			applyState(result.data);
			if (_.get(result, 'data.status') == 'running' && looping == 'scan') {
				setTimeout(loopRun, 30);
				return;
			}
			looping = false;
			if (_.get(result, 'data.status') == 'done') Tips.tips(LNG['fileMissingExport.msg.done'], 'success');
		});
	};

	var loopClean = function(){
		if (looping != 'clean') return;
		kodApi.requestSend('plugin/fileMissingExport/clean', {resume: 2}, function(result){
			if (!result || !result.code) {
				looping = false;
				return Tips.close(result);
			}
			applyState(result.data);
			if (_.get(result, 'data.cleanStatus') == 'running' && looping == 'clean') {
				setTimeout(loopClean, 30);
				return;
			}
			looping = false;
			if (_.get(result, 'data.cleanStatus') == 'done') Tips.tips(LNG['fileMissingExport.clean.done'], 'success');
		});
	};

	var start = function(reset, isContinue){
		var data = options();
		data.reset = reset ? 1 : 0;
		if (isContinue) data.reset = 0;
		looping = 'scan';
		kodApi.requestSend('plugin/fileMissingExport/start', data, function(result){
			if (!result || !result.code) {
				looping = false;
				return Tips.close(result);
			}
			applyState(result.data);
			loopRun();
		});
	};

	var markCleaning = function(){
		looping = 'clean';
		var next = _.extend({}, lastState, {
			cleanStatus: 'running',
			cleanStatusText: LNG['fileMissingExport.clean.status.running'],
			canClean: false,
			canCleanContinue: false,
			cleanProcessed: lastState.cleanProcessed || 0,
			cleanTotal: lastState.cleanTotal || lastState.missing || 0,
			cleanPercent: lastState.cleanPercent || 0
		});
		applyState(next);
	};

	var startClean = function(resume){
		if (resume) {
			markCleaning();
			kodApi.requestSend('plugin/fileMissingExport/clean', {resume: 1}, function(result){
				if (!result || !result.code) {
					looping = false;
					return Tips.close(result);
				}
				applyState(result.data);
				loopClean();
			});
			return;
		}
		var missing = parseInt(_.get(lastState, 'missing', 0), 10) || 0;
		var runId = _.get(lastState, 'runId', '');
		var warn = (LNG['fileMissingExport.clean.warn'] || '').replace('[0]', missing);
		$.dialog.confirm(warn, function(){
			$.dialog.prompt(LNG['fileMissingExport.clean.prompt'], function(text){
				if ($.trim(text) !== 'DELETE') {
					Tips.tips(LNG['fileMissingExport.clean.confirmErr'], 'warning');
					return false;
				}
				markCleaning();
				kodApi.requestSend('plugin/fileMissingExport/clean', {
					resume: 0,
					runId: runId,
					confirm: 'DELETE',
					confirmCount: missing
				}, function(result){
					if (!result || !result.code) {
						looping = false;
						return Tips.close(result);
					}
					applyState(result.data);
					loopClean();
				});
			});
		});
	};

	var refreshStatus = function(){
		kodApi.requestSend('plugin/fileMissingExport/status', {}, function(result){
			if (!result || !result.code) return;
			applyState(result.data);
			if (_.get(result, 'data.status') == 'running') {
				looping = 'scan';
				loopRun();
			} else if (_.get(result, 'data.cleanStatus') == 'running') {
				looping = 'clean';
				loopClean();
			}
		});
	};

	var mount = function(){
		var $el = $box();
		if (!$el.length || $el.data('inited')) return;
		$el.data('inited', 1);
		$el.html(panelHtml());
		$el.closest('.dialog-form,.aui-dialog').find('.form-target-save,.aui-footer .aui-state-highlight').hide();
		refreshStatus();
	};

	$('body').delegate('.file-missing-export-page .act-start', 'click', function(e){
		e.preventDefault();
		start(0);
	});
	$('body').delegate('.file-missing-export-page .act-continue', 'click', function(e){
		e.preventDefault();
		start(0, true);
	});
	$('body').delegate('.file-missing-export-page .act-reset', 'click', function(e){
		e.preventDefault();
		$.dialog.confirm(LNG['fileMissingExport.msg.resetConfirm'], function(){ start(1); });
	});
	$('body').delegate('.file-missing-export-page .act-stop', 'click', function(e){
		e.preventDefault();
		looping = false;
		kodApi.requestSend('plugin/fileMissingExport/stop', {}, function(result){
			if (!result || !result.code) return Tips.close(result);
			applyState(result.data);
		});
	});
	$('body').delegate('.file-missing-export-page .act-csv', 'click', function(e){
		e.preventDefault();
		window.open(G.kod.appApi + 'plugin/fileMissingExport/download&type=csv');
	});
	$('body').delegate('.file-missing-export-page .act-txt', 'click', function(e){
		e.preventDefault();
		window.open(G.kod.appApi + 'plugin/fileMissingExport/download&type=txt');
	});
	$('body').delegate('.file-missing-export-page .act-clean', 'click', function(e){
		e.preventDefault();
		startClean(false);
	});
	$('body').delegate('.file-missing-export-page .act-clean-continue', 'click', function(e){
		e.preventDefault();
		startClean(true);
	});

	Events.bind('plugin.config.formAfter', function(_this){
		var form = _this && (_this.formfileMissingExport || _this.formfileMissingExportPlugin);
		if (form && form.$el) {
			form.$('.form-target-save').hide();
		}
		mount();
	});
	_.delay(mount, 80);
})();
