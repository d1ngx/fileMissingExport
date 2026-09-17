<?php

/**
 * 失效文件导出：网盘有记录、存储找不到物理文件
 */
class fileMissingExportPlugin extends PluginBase{
	function __construct(){
		parent::__construct();
	}
	public function regist(){
		$this->hookRegist(array(
			'user.commonJs.insert' => 'fileMissingExportPlugin.echoJs',
		));
	}
	public function echoJs(){
		if (_get($GLOBALS, 'isRoot') != 1) return;
		$this->echoFile('static/main.js');
	}
	public function onUninstall(){
		$this->api()->clearWork();
	}

	private function api(){
		static $api = null;
		if (!$api) {
			include_once($this->pluginPath.'lib/missingExport.class.php');
			$api = new missingExport($this);
		}
		return $api;
	}
	private function allow(){
		KodUser::checkRoot();
		if (!KodUser::isRoot()) {
			show_json(LNG('fileMissingExport.msg.needRoot'), false);
		}
	}

	public function status(){
		$this->allow();
		show_json($this->api()->state());
	}

	public function start(){
		$this->allow();
		ignore_timeout();
		$reset = intval(_get($this->in, 'reset', 0));
		$data = $this->api()->start($reset ? true : false);
		show_json($data);
	}

	public function run(){
		$this->allow();
		ignore_timeout();
		show_json($this->api()->runSlice());
	}

	public function stop(){
		$this->allow();
		show_json($this->api()->stop());
	}

	public function clean(){
		$this->allow();
		ignore_timeout();
		$resume = intval(_get($this->in, 'resume', 0));
		if ($resume == 2) {
			show_json($this->api()->cleanSlice());
			return;
		}
		show_json($this->api()->cleanStart());
	}

	public function download(){
		$this->allow();
		$type = Input::get('type', 'in', 'csv', array('csv', 'txt'));
		$this->api()->download($type);
	}
}
