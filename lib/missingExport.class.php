<?php
/**
 * 失效文件导出：数据库有记录但存储中找不到物理文件
 * - 以 sourceID / historyID 游标分页，避免 OFFSET，适配百万级
 * - 状态落盘，支持暂停、断点续跑、重复执行（新一轮新目录）
 * - 结果追加写入 CSV/TXT，不把全部失效记录堆在内存
 */
class missingExport{
	private $plugin;
	private $workDir;
	private $stateFile;
	private $pageNum = 300;
	private $sliceSec = 8;
	private $cleanSliceSec = 8;
	private $cleanBatch = 80;
	private $storeMap = array();
	private $nameMap = array();
	private $userMap = array();
	private $groupMap = array();
	private $existCache = array();

	public function __construct($plugin){
		$this->plugin = $plugin;
		$logRoot = defined('LOG_PATH') ? LOG_PATH : TEMP_PATH.'log/';
		$this->workDir = $logRoot.'fileMissingExport/';
		$legacyDir = TEMP_PATH.'fileMissingExport/';
		if (!is_dir($this->workDir) && is_dir($legacyDir)) {
			@mk_dir($logRoot);
			@rename($legacyDir, rtrim($this->workDir, '/'));
		}
		$this->stateFile = $this->workDir.'state.json';
		@mk_dir($this->workDir);
	}

	public function state($public=true){
		$state = $this->loadState();
		if (!$state) {
			$state = $this->emptyState();
		}
		if ($public) {
			unset($state['lockPid']);
			$state['csvExists'] = !empty($state['csvFile']) && @is_file($state['csvFile']);
			$state['txtExists'] = !empty($state['txtFile']) && @is_file($state['txtFile']);
			$state['jsonlExists'] = !empty($state['jsonlFile']) && @is_file($state['jsonlFile']);
			$state['statusText'] = LNG('fileMissingExport.status.'.$state['status']);
			$cleanStatus = _get($state, 'cleanStatus', 'idle');
			$state['cleanStatus'] = $cleanStatus ? $cleanStatus : 'idle';
			$state['cleanStatusText'] = LNG('fileMissingExport.clean.status.'.$state['cleanStatus']);
			$state['canClean'] = ($state['status'] == 'done' && intval($state['missing']) > 0 && $state['jsonlExists'] && ($state['cleanStatus'] == 'idle' || $state['cleanStatus'] == ''));
			$state['canCleanContinue'] = ($state['cleanStatus'] == 'paused');
			$cleanTotal = intval(_get($state, 'cleanTotal', $state['missing']));
			$cleanDone = intval(_get($state, 'cleanProcessed', 0));
			$state['cleanPercent'] = $cleanTotal > 0 ? round($cleanDone / $cleanTotal * 100, 2) : 0;
			if ($state['cleanPercent'] > 100) $state['cleanPercent'] = 100;
			if ($state['total'] > 0) {
				$state['percent'] = round($state['scanned'] / $state['total'] * 100, 2);
				if ($state['percent'] > 100) $state['percent'] = 100;
			} else {
				$state['percent'] = $state['status'] == 'done' ? 100 : 0;
			}
		}
		$state['storageList'] = $this->storageOptions();
		return $state;
	}

	public function start($reset=false){
		$state = $this->loadState();
		if ($reset || !$state || $state['status'] == 'done' || $state['status'] == 'idle' || $state['status'] == 'error') {
			if ($state && $this->isAlive($state) && $state['status'] == 'running') {
				show_json(LNG('fileMissingExport.msg.running'), false);
			}
			$this->clearPauseFlag();
			$state = $this->newRun();
		} else {
			if ($this->isAlive($state) && $state['status'] == 'running') {
				show_json(LNG('fileMissingExport.msg.running'), false);
			}
			$this->clearPauseFlag();
			$state['status'] = 'running';
			$state['error'] = '';
			$state['heartbeat'] = time();
			$this->saveState($state);
		}
		return $this->runSlice();
	}

	public function stop(){
		$this->writePauseFlag();
		$state = $this->loadState();
		if (!$state) return $this->state();
		if (_get($state, 'cleanStatus') == 'running' || _get($state, 'cleanStatus') == 'paused') {
			$state['cleanStatus'] = 'paused';
			$state['heartbeat'] = time();
			$this->saveState($state);
			return $this->state();
		}
		if ($state['status'] == 'done' || $state['status'] == 'idle') {
			return $this->state();
		}
		$state['status'] = 'paused';
		$state['heartbeat'] = time();
		$this->saveState($state);
		return $this->state();
	}

	public function runSlice(){
		$state = $this->loadState();
		if (!$state) show_json(LNG('fileMissingExport.msg.noTask'), false);
		if ($state['status'] == 'paused') return $this->state();
		if ($state['status'] == 'done') return $this->state();
		if ($this->pauseRequested()) {
			$state['status'] = 'paused';
			$this->saveState($state);
			return $this->state();
		}

		$state['status'] = 'running';
		$state['heartbeat'] = time();
		$this->saveState($state);

		$this->pageNum = intval(_get($state, 'batch', 300));
		if ($this->pageNum < 50) $this->pageNum = 50;
		if ($this->pageNum > 2000) $this->pageNum = 2000;
		$this->storeMap = $this->loadStores();

		$begin = microtime(true);
		$deadline = $begin + $this->sliceSec;
		try {
			while (microtime(true) < $deadline) {
				if ($state['status'] != 'running') break;
				$fresh = $this->loadState();
				if ($fresh && $fresh['status'] == 'paused') {
					$state = $fresh;
					break;
				}
				$more = $this->processBatch($state, $deadline);
				$state['heartbeat'] = time();
				$state['updatedAt'] = time();
				$this->saveState($state);
				if (!$more) {
					$state['status'] = 'done';
					$state['finishedAt'] = time();
					$this->writeTxtFooter($state);
					$this->saveState($state);
					break;
				}
			}
		} catch (Exception $e) {
			$state['status'] = 'error';
			$state['error'] = $e->getMessage();
			$this->saveState($state);
			show_json($state['error'], false);
		}
		$this->nameMap = array();
		$this->existCache = array();
		return $this->state();
	}

	public function cleanStart(){
		$state = $this->loadState();
		if (!$state) show_json(LNG('fileMissingExport.msg.noTask'), false);
		if ($state['status'] == 'running' || $this->isAlive($state)) {
			show_json(LNG('fileMissingExport.msg.running'), false);
		}
		$resume = intval(_get($this->plugin->in, 'resume', 0));
		if ($resume) {
			if (_get($state, 'cleanStatus') != 'paused') {
				show_json(LNG('fileMissingExport.clean.needScan'), false);
			}
			$this->clearPauseFlag();
			if (isset($this->plugin->in['trustScan'])) {
				$state['cleanTrustScan'] = intval(_get($this->plugin->in, 'trustScan', 0)) ? 1 : 0;
			}
			$state['cleanStatus'] = 'running';
			$state['heartbeat'] = time();
			$this->saveState($state);
			return $this->state();
		}

		if ($state['status'] != 'done') {
			show_json(LNG('fileMissingExport.clean.needScan'), false);
		}
		if (intval($state['missing']) <= 0) {
			show_json(LNG('fileMissingExport.clean.empty'), false);
		}
		$jsonl = _get($state, 'jsonlFile');
		if (!$jsonl || !is_file($jsonl)) {
			show_json(LNG('fileMissingExport.clean.needScan'), false);
		}
		$runId = strval(_get($this->plugin->in, 'runId', ''));
		$confirm = strval(_get($this->plugin->in, 'confirm', ''));
		$confirmCount = intval(_get($this->plugin->in, 'confirmCount', 0));
		if ($runId === '' || $runId !== strval($state['runId'])) {
			show_json(LNG('fileMissingExport.clean.runIdErr'), false);
		}
		if ($confirm !== 'DELETE') {
			show_json(LNG('fileMissingExport.clean.confirmErr'), false);
		}
		if ($confirmCount !== intval($state['missing'])) {
			show_json(LNG('fileMissingExport.clean.countErr'), false);
		}
		if (_get($state, 'cleanStatus') == 'done') {
			show_json(LNG('fileMissingExport.clean.already'), false);
		}

		$this->clearPauseFlag();
		$state['cleanTrustScan'] = intval(_get($this->plugin->in, 'trustScan', 0)) ? 1 : 0;
		$state['cleanStatus'] = 'running';
		$state['cleanByte'] = 0;
		$state['cleanProcessed'] = 0;
		$state['cleanTotal'] = intval($state['missing']);
		$state['cleanDeletedSource'] = 0;
		$state['cleanDeletedFile'] = 0;
		$state['cleanDeletedHistory'] = 0;
		$state['cleanSkipped'] = 0;
		$state['cleanStartedAt'] = time();
		$state['heartbeat'] = time();
		$this->saveState($state);
		$this->cleanLog($state, 'START runId='.$state['runId'].' missing='.$state['missing'].' trustScan='.intval($state['cleanTrustScan']));
		return $this->state();
	}

	public function cleanSlice(){
		$state = $this->loadState();
		if (!$state) show_json(LNG('fileMissingExport.msg.noTask'), false);
		if (_get($state, 'cleanStatus') == 'paused') return $this->state();
		if (_get($state, 'cleanStatus') == 'done') return $this->state();
		if (_get($state, 'cleanStatus') != 'running') {
			show_json(LNG('fileMissingExport.clean.needScan'), false);
		}
		if ($this->pauseRequested()) {
			$state['cleanStatus'] = 'paused';
			$this->saveState($state);
			return $this->state();
		}

		$deadline = microtime(true) + $this->cleanSliceSec;
		$parents = array();
		$sliceFrom = intval(_get($state, 'cleanProcessed', 0));

		try {
			while (microtime(true) < $deadline) {
				if ($this->pauseRequested()) {
					$state['cleanStatus'] = 'paused';
					$this->saveState($state);
					break;
				}
				$fresh = $this->loadState();
				if ($fresh && _get($fresh, 'cleanStatus') == 'paused') {
					$state = $fresh;
					break;
				}
				$rows = $this->readJsonlBatch($state, $this->cleanBatch);
				if (!$rows) {
					$state['cleanStatus'] = 'done';
					$state['cleanFinishedAt'] = time();
					$this->folderSizeReset($parents);
					$this->cleanLog($state, 'DONE source='.intval($state['cleanDeletedSource']).' file='.intval($state['cleanDeletedFile']).' history='.intval($state['cleanDeletedHistory']).' skip='.intval($state['cleanSkipped']));
					$this->saveState($state);
					break;
				}
				$this->cleanRows($rows, $state, $parents);
				$state['heartbeat'] = time();
				$state['updatedAt'] = time();
				$this->saveState($state);
			}
			if ($parents && _get($state, 'cleanStatus') != 'done') {
				$this->folderSizeReset($parents);
			}
			if (_get($state, 'cleanStatus') == 'running') {
				$did = intval($state['cleanProcessed']) - $sliceFrom;
				if ($did > 0) {
					$this->cleanLog($state, 'PROG processed='.intval($state['cleanProcessed']).'/'.intval($state['cleanTotal']).' +'.$did.' source='.intval($state['cleanDeletedSource']).' file='.intval($state['cleanDeletedFile']).' skip='.intval($state['cleanSkipped']));
				}
			}
		} catch (Exception $e) {
			$state['cleanStatus'] = 'paused';
			$state['error'] = $e->getMessage();
			$this->saveState($state);
			show_json($e->getMessage(), false);
		}
		return $this->state();
	}

	public function download($type){
		$state = $this->loadState();
		if (!$state) show_json(LNG('fileMissingExport.msg.none'), false);
		$file = $type == 'txt' ? _get($state, 'txtFile') : _get($state, 'csvFile');
		if (!$file || !is_file($file) || !$this->inWorkDir($file)) {
			show_json(LNG('fileMissingExport.msg.none'), false);
		}
		$name = $type == 'txt' ? 'missing-files.txt' : 'missing-files.csv';
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="'.$name.'"');
		header('Content-Length: '.filesize($file));
		readfile($file);
		exit;
	}

	public function clearWork(){
		$path = $this->workDir;
		if (is_dir($path)) {
			del_dir($path);
		}
	}

	private function newRun(){
		$ioType = intval(_get($this->plugin->in, 'ioType', 0));
		$includeRecycle = intval(_get($this->plugin->in, 'includeRecycle', 1));
		$includeHistory = intval(_get($this->plugin->in, 'includeHistory', 0));
		$batch = intval(_get($this->plugin->in, 'batch', 300));
		if ($batch < 50) $batch = 50;
		if ($batch > 2000) $batch = 2000;

		$runId = date('Ymd_His').'_'.substr(md5(uniqid('', true)), 0, 6);
		$runDir = $this->workDir.$runId.'/';
		@mk_dir($runDir);
		$csvFile = $runDir.'missing-files.csv';
		$txtFile = $runDir.'missing-files.txt';
		$jsonlFile = $runDir.'missing-ids.jsonl';

		$total = $this->countTotal($includeRecycle, $includeHistory);
		$state = array(
			'runId'				=> $runId,
			'status'			=> 'running',
			'phase'				=> 'source',
			'lastSourceID'		=> 0,
			'lastHistoryID'		=> 0,
			'scanned'			=> 0,
			'missing'			=> 0,
			'total'				=> $total,
			'ioType'			=> $ioType,
			'includeRecycle'	=> $includeRecycle,
			'includeHistory'	=> $includeHistory,
			'batch'				=> $batch,
			'csvFile'			=> $csvFile,
			'txtFile'			=> $txtFile,
			'jsonlFile'			=> $jsonlFile,
			'recent'			=> array(),
			'error'				=> '',
			'startedAt'			=> time(),
			'updatedAt'			=> time(),
			'heartbeat'			=> time(),
			'finishedAt'		=> 0,
			'cleanStatus'		=> 'idle',
			'cleanByte'			=> 0,
			'cleanProcessed'	=> 0,
			'cleanTotal'		=> 0,
			'cleanDeletedSource'=> 0,
			'cleanDeletedFile'	=> 0,
			'cleanDeletedHistory'=> 0,
			'cleanSkipped'		=> 0,
		);
		$this->writeCsvHeader($csvFile);
		$this->writeTxtHeader($txtFile, $state);
		$this->saveState($state);
		$this->pruneOldRuns(8);
		return $state;
	}

	private function countTotal($includeRecycle, $includeHistory){
		$where = array('isFolder' => 0, 'fileID' => array('>', 0));
		if (!$includeRecycle) $where['isDelete'] = 0;
		$total = intval(Model('Source')->where($where)->count());
		if ($includeHistory) {
			$total += intval(Model('SourceHistory')->count());
		}
		return $total;
	}

	private function processBatch(&$state, $deadline){
		if ($state['phase'] == 'source') {
			$list = $this->nextSources($state);
			if (!$list) {
				if ($state['includeHistory']) {
					$state['phase'] = 'history';
					return true;
				}
				return false;
			}
			$this->handleSources($list, $state, 'file', $deadline);
			return true;
		}
		$list = $this->nextHistory($state);
		if (!$list) return false;
		$this->handleHistory($list, $state, $deadline);
		return true;
	}

	private function nextSources($state){
		$where = array(
			'isFolder' => 0,
			'fileID' => array('>', 0),
			'sourceID' => array('>', intval($state['lastSourceID'])),
		);
		if (!$state['includeRecycle']) $where['isDelete'] = 0;
		return Model('Source')
			->where($where)
			->field('sourceID,name,fileID,parentID,parentLevel,targetType,targetID,isDelete,size,createTime,modifyTime')
			->order('sourceID asc')
			->limit($this->pageNum)
			->select();
	}

	private function nextHistory($state){
		return Model('SourceHistory')
			->where(array('id' => array('>', intval($state['lastHistoryID']))))
			->field('id,sourceID,fileID,size,createTime,modifyTime')
			->order('id asc')
			->limit($this->pageNum)
			->select();
	}

	private function handleSources($list, &$state, $kind, $deadline){
		$lastID = intval($state['lastSourceID']);
		$fileMap = $this->loadFiles($list);
		$rows = array();
		foreach ($list as $item) {
			$lastID = intval($item['sourceID']);
			$state['scanned']++;
			$file = isset($fileMap[$item['fileID']]) ? $fileMap[$item['fileID']] : false;
			if ($state['ioType'] > 0) {
				if (!$file || intval($file['ioType']) != intval($state['ioType'])) {
					if (microtime(true) >= $deadline) break;
					continue;
				}
			}
			$reason = $this->missingReason($file);
			if ($reason) $rows[] = $this->buildRow($item, $file, $reason, $kind);
			if (microtime(true) >= $deadline) break;
		}
		$state['lastSourceID'] = $lastID;
		$this->appendRows($rows, $state);
	}

	private function handleHistory($list, &$state, $deadline){
		$lastID = intval($state['lastHistoryID']);
		$sourceIds = array();
		foreach ($list as $item) {
			$sourceIds[] = $item['sourceID'];
		}
		$sourceMap = array();
		if ($sourceIds) {
			$sourceIds = array_values(array_unique($sourceIds));
			$sources = Model('Source')->where(array('sourceID' => array('in', $sourceIds)))
				->field('sourceID,name,fileID,parentID,parentLevel,targetType,targetID,isDelete,size,createTime,modifyTime')
				->select();
			$sourceMap = array_to_keyvalue($sources, 'sourceID');
		}
		$fileMap = $this->loadFiles($list);
		$rows = array();
		foreach ($list as $item) {
			$lastID = intval($item['id']);
			$state['scanned']++;
			$file = isset($fileMap[$item['fileID']]) ? $fileMap[$item['fileID']] : false;
			if ($state['ioType'] > 0) {
				if (!$file || intval($file['ioType']) != intval($state['ioType'])) {
					if (microtime(true) >= $deadline) break;
					continue;
				}
			}
			$reason = $this->missingReason($file);
			if (!$reason) {
				if (microtime(true) >= $deadline) break;
				continue;
			}
			$source = isset($sourceMap[$item['sourceID']]) ? $sourceMap[$item['sourceID']] : array(
				'sourceID' => $item['sourceID'],
				'name' => '',
				'fileID' => $item['fileID'],
				'parentID' => 0,
				'parentLevel' => '',
				'targetType' => '',
				'targetID' => 0,
				'isDelete' => 0,
				'size' => $item['size'],
				'createTime' => $item['createTime'],
				'modifyTime' => $item['modifyTime'],
			);
			$source['fileID'] = $item['fileID'];
			$source['size'] = $item['size'];
			$source['modifyTime'] = $item['modifyTime'];
			$source['createTime'] = $item['createTime'];
			$source['historyID'] = $item['id'];
			$rows[] = $this->buildRow($source, $file, $reason, 'history');
			if (microtime(true) >= $deadline) break;
		}
		$state['lastHistoryID'] = $lastID;
		$this->appendRows($rows, $state);
	}

	private function loadFiles($list){
		$ids = array();
		foreach ($list as $item) {
			if (!empty($item['fileID'])) $ids[] = $item['fileID'];
		}
		$ids = array_values(array_unique($ids));
		if (!$ids) return array();
		$data = Model('File')->where(array('fileID' => array('in', $ids)))
			->field('fileID,name,size,ioType,path,hashMd5,linkCount,createTime,modifyTime')
			->select();
		return $data ? array_to_keyvalue($data, 'fileID') : array();
	}

	private function missingReason($file){
		if (!$file || empty($file['path'])) return 'fileMissing';
		$ioType = intval($file['ioType']);
		if (!isset($this->storeMap[$ioType])) return 'storageGone';
		$key = $ioType.':'.$file['path'];
		if (isset($this->existCache[$key])) {
			return $this->existCache[$key] ? false : 'physical';
		}
		try {
			$exists = IO::exist($file['path']);
		} catch (Exception $e) {
			return 'storageErr';
		}
		$this->existCache[$key] = $exists ? 1 : 0;
		if (count($this->existCache) > 20000) {
			$this->existCache = array_slice($this->existCache, -8000, null, true);
		}
		return $exists ? false : 'physical';
	}

	private function buildRow($source, $file, $reason, $kind){
		$pathDisplay = $this->sourceDisplayPath($source);
		$space = $this->spaceInfo($source);
		$ioType = $file ? intval($file['ioType']) : 0;
		$storeName = isset($this->storeMap[$ioType]) ? $this->storeMap[$ioType]['name'] : '';
		$reasonText = LNG('fileMissingExport.reason.'.($reason == 'storageErr' ? 'storageErr' : (
			$reason == 'storageGone' ? 'storageGone' : (
			$reason == 'fileMissing' ? 'fileMissing' : 'physical'))));
		$size = $file ? intval($file['size']) : intval(_get($source, 'size', 0));
		return array(
			'pathDisplay'	=> $pathDisplay,
			'sourcePath'	=> KodIO::make($source['sourceID']),
			'name'			=> $source['name'] ? $source['name'] : ($file ? $file['name'] : ''),
			'size'			=> $size,
			'sizeText'		=> size_format($size),
			'spaceType'		=> $space['typeText'],
			'spaceName'		=> $space['name'],
			'sourceID'		=> intval($source['sourceID']),
			'fileID'		=> intval($source['fileID']),
			'historyID'		=> intval(_get($source, 'historyID', 0)),
			'parentID'		=> intval(_get($source, 'parentID', 0)),
			'isDelete'		=> intval(_get($source, 'isDelete', 0)),
			'kind'			=> $kind,
			'kindText'		=> LNG($kind == 'history' ? 'fileMissingExport.kind.history' : 'fileMissingExport.kind.file'),
			'ioType'		=> $ioType,
			'storeName'		=> $storeName,
			'storePath'		=> $file ? $file['path'] : '',
			'reason'		=> $reason,
			'reasonText'	=> $reasonText,
			'createTime'	=> intval(_get($source, 'createTime', 0)),
			'modifyTime'	=> intval(_get($source, 'modifyTime', 0)),
		);
	}

	private function sourceDisplayPath($source){
		$ids = $this->parentIds($source['parentLevel']);
		$need = array();
		foreach ($ids as $id) {
			if (!isset($this->nameMap[$id])) $need[] = $id;
		}
		if ($need) {
			$list = Model('Source')->where(array('sourceID' => array('in', $need)))
				->field('sourceID,name')->select();
			if ($list) {
				foreach ($list as $row) {
					$this->nameMap[$row['sourceID']] = $row['name'];
				}
			}
			foreach ($need as $id) {
				if (!isset($this->nameMap[$id])) $this->nameMap[$id] = '#'.$id;
			}
		}
		$parts = array();
		foreach ($ids as $id) {
			$parts[] = $this->nameMap[$id];
		}
		if (!empty($source['name'])) $parts[] = $source['name'];
		$path = '/'.implode('/', array_filter($parts, array($this, 'notEmptyPath')));
		return $path ? $path : '/';
	}

	private function notEmptyPath($v){
		return $v !== '' && $v !== null;
	}

	private function parentIds($parentLevel){
		$arr = explode(',', strval($parentLevel));
		$ids = array();
		foreach ($arr as $id) {
			$id = intval($id);
			if ($id > 0) $ids[] = $id;
		}
		return $ids;
	}

	private function spaceInfo($source){
		$type = $source['targetType'];
		$isUser = ($type === 'user' || $type === '1' || intval($type) === 1);
		$isGroup = ($type === 'group' || $type === '2' || intval($type) === 2);
		$tid = intval($source['targetID']);
		$name = '';
		if ($isUser) {
			if (!isset($this->userMap[$tid])) {
				$info = Model('User')->field('userID,name,nickName')->where(array('userID' => $tid))->find();
				$this->userMap[$tid] = $info ? ($info['nickName'] ? $info['nickName'] : $info['name']) : ('user#'.$tid);
			}
			$name = $this->userMap[$tid];
			return array('typeText' => LNG('fileMissingExport.space.user'), 'name' => $name);
		}
		if ($isGroup) {
			if (!isset($this->groupMap[$tid])) {
				$info = Model('Group')->field('groupID,name')->where(array('groupID' => $tid))->find();
				$this->groupMap[$tid] = $info ? $info['name'] : ('group#'.$tid);
			}
			$name = $this->groupMap[$tid];
			return array('typeText' => LNG('fileMissingExport.space.group'), 'name' => $name);
		}
		return array('typeText' => strval($type), 'name' => strval($tid));
	}

	private function appendRows($rows, &$state){
		if (!$rows) return;
		$csv = fopen($state['csvFile'], 'ab');
		$txt = fopen($state['txtFile'], 'ab');
		if (!$csv || !$txt) {
			if ($csv) fclose($csv);
			if ($txt) fclose($txt);
			throw new Exception('cannot write result file');
		}
		$jsonl = false;
		if (!empty($state['jsonlFile'])) {
			$jsonl = fopen($state['jsonlFile'], 'ab');
			if (!$jsonl) {
				fclose($csv);
				fclose($txt);
				throw new Exception('cannot write result file');
			}
		}
		foreach ($rows as $row) {
			$state['missing']++;
			$idx = $state['missing'];
			fputcsv($csv, array(
				$row['pathDisplay'],
				$row['sourcePath'],
				$row['name'],
				$row['sizeText'],
				$row['size'],
				$row['spaceType'],
				$row['spaceName'],
				$row['sourceID'],
				$row['fileID'],
				$row['historyID'] ? $row['historyID'] : '',
				$row['isDelete'] ? LNG('fileMissingExport.yes') : LNG('fileMissingExport.no'),
				$row['kindText'],
				$row['storeName'],
				$row['ioType'],
				$row['storePath'],
				$row['reasonText'],
				$row['modifyTime'] ? date('Y-m-d H:i:s', $row['modifyTime']) : '',
				$row['createTime'] ? date('Y-m-d H:i:s', $row['createTime']) : '',
			));
			if ($jsonl) {
				$line = json_encode(array(
					'kind' => $row['kind'],
					'sourceID' => $row['sourceID'],
					'fileID' => $row['fileID'],
					'historyID' => $row['historyID'],
					'parentID' => intval(_get($row, 'parentID', 0)),
					'reason' => $row['reason'],
					'storePath' => $row['storePath'],
				), defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);
				fwrite($jsonl, $line."\n");
			}
			$block = '#'.$idx.'  '.$row['pathDisplay']."\n";
			$block .= '    '.$this->pad('文件名', $row['name']).$this->pad('大小', $row['sizeText'])."\n";
			$block .= '    '.$this->pad('空间', $row['spaceType'].' / '.$row['spaceName']).$this->pad('类型', $row['kindText'])."\n";
			$block .= '    '.$this->pad('sourceID', $row['sourceID']).$this->pad('fileID', $row['fileID']);
			if ($row['historyID']) $block .= $this->pad('historyID', $row['historyID']);
			$block .= $this->pad('回收站', $row['isDelete'] ? LNG('fileMissingExport.yes') : LNG('fileMissingExport.no'))."\n";
			$block .= '    '.$this->pad('存储', $row['storeName'].($row['ioType'] ? ' (io:'.$row['ioType'].')' : ''))."\n";
			$block .= '    '.$this->pad('存储路径', $row['storePath'])."\n";
			$block .= '    '.$this->pad('原因', $row['reasonText'])."\n";
			$block .= '    '.$this->pad('系统路径', $row['sourcePath'])."\n\n";
			fwrite($txt, $block);

			$state['recent'][] = array(
				'pathDisplay' => $row['pathDisplay'],
				'reasonText' => $row['reasonText'],
				'kindText' => $row['kindText'],
				'sizeText' => $row['sizeText'],
			);
		}
		fclose($csv);
		fclose($txt);
		if ($jsonl) fclose($jsonl);
		if (count($state['recent']) > 40) {
			$state['recent'] = array_slice($state['recent'], -40);
		}
	}

	private function pad($label, $value){
		return str_pad($label.': ', 10, ' ', STR_PAD_RIGHT).$value.'    ';
	}

	private function writeCsvHeader($file){
		$fp = fopen($file, 'wb');
		fwrite($fp, "\xEF\xBB\xBF");
		fputcsv($fp, array(
			LNG('fileMissingExport.col.path'),
			'系统路径',
			LNG('fileMissingExport.col.name'),
			LNG('fileMissingExport.col.size'),
			'字节',
			LNG('fileMissingExport.col.space'),
			'空间名称',
			LNG('fileMissingExport.col.sourceID'),
			LNG('fileMissingExport.col.fileID'),
			'historyID',
			LNG('fileMissingExport.col.recycle'),
			LNG('fileMissingExport.col.kind'),
			LNG('fileMissingExport.col.store'),
			'ioType',
			LNG('fileMissingExport.col.storePath'),
			LNG('fileMissingExport.col.reason'),
			LNG('fileMissingExport.col.time'),
			'创建时间',
		));
		fclose($fp);
	}

	private function writeTxtHeader($file, $state){
		$lines = array();
		$lines[] = str_repeat('=', 72);
		$lines[] = LNG('fileMissingExport.meta.title');
		$lines[] = 'runId: '.$state['runId'];
		$lines[] = 'start: '.date('Y-m-d H:i:s', $state['startedAt']);
		$lines[] = str_repeat('=', 72);
		$lines[] = '';
		file_put_contents($file, implode("\n", $lines));
	}

	private function writeTxtFooter($state){
		$lines = array();
		$lines[] = str_repeat('-', 72);
		$lines[] = LNG('fileMissingExport.msg.done');
		$lines[] = LNG('fileMissingExport.stat.scanned').': '.$state['scanned'];
		$lines[] = LNG('fileMissingExport.stat.missing').': '.$state['missing'];
		$lines[] = 'end: '.date('Y-m-d H:i:s');
		$lines[] = str_repeat('-', 72);
		file_put_contents($state['txtFile'], implode("\n", $lines)."\n", FILE_APPEND);
	}

	private function readJsonlBatch(&$state, $limit){
		$file = _get($state, 'jsonlFile');
		if (!$file || !is_file($file)) return array();
		$fp = fopen($file, 'rb');
		if (!$fp) return array();
		$byte = intval(_get($state, 'cleanByte', 0));
		if ($byte > 0) fseek($fp, $byte);
		$rows = array();
		while (count($rows) < $limit && ($line = fgets($fp)) !== false) {
			$line = trim($line);
			if ($line === '') continue;
			$item = json_decode($line, true);
			if (is_array($item)) $rows[] = $item;
		}
		$state['cleanByte'] = ftell($fp);
		$eof = feof($fp);
		fclose($fp);
		if (!$rows && $eof) return array();
		return $rows;
	}

	private function cleanRows($rows, &$state, &$parents){
		$fileIds = array();
		$sourceIds = array();
		$historyIds = array();
		$orphanFileIds = array();
		foreach ($rows as $row) {
			$state['cleanProcessed'] = intval(_get($state, 'cleanProcessed', 0)) + 1;
			$reason = _get($row, 'reason', '');
			if ($reason === 'storageErr') {
				$state['cleanSkipped']++;
				continue;
			}
			$fileID = intval(_get($row, 'fileID', 0));
			$sourceID = intval(_get($row, 'sourceID', 0));
			$historyID = intval(_get($row, 'historyID', 0));
			$kind = _get($row, 'kind', 'file');
			$storePath = _get($row, 'storePath', '');

			if ($reason === 'physical' && $storePath && !$this->trustScan($state) && $this->physicalStillExists($storePath)) {
				$state['cleanSkipped']++;
				continue;
			}

			if ($kind === 'history' && $historyID > 0) {
				$historyIds[] = $historyID;
				if ($fileID > 0) $orphanFileIds[] = $fileID;
				continue;
			}
			if ($fileID > 0 && $reason !== 'fileMissing') {
				$fileIds[] = $fileID;
				continue;
			}
			if ($sourceID > 0) {
				$sourceIds[] = $sourceID;
			} else {
				$state['cleanSkipped']++;
			}
		}
		$this->deleteByFileIDs($fileIds, $state, $parents);
		$this->deleteBySourceIDs($sourceIds, $state, $parents);
		$this->deleteHistories($historyIds, $state);
		$this->deleteOrphanFiles($orphanFileIds, $state);
	}

	private function trustScan($state){
		return intval(_get($state, 'cleanTrustScan', 0)) === 1;
	}

	private function pauseFlag(){
		return $this->workDir.'pause.flag';
	}

	private function pauseRequested(){
		return is_file($this->pauseFlag());
	}

	private function writePauseFlag(){
		@file_put_contents($this->pauseFlag(), strval(time()));
	}

	private function clearPauseFlag(){
		$flag = $this->pauseFlag();
		if (is_file($flag)) @unlink($flag);
	}

	private function physicalStillExists($storePath){
		$key = strval($storePath);
		if (isset($this->existCache[$key])) {
			return $this->existCache[$key] ? true : false;
		}
		try {
			$exists = IO::exist($storePath) ? 1 : 0;
		} catch (Exception $e) {
			$this->existCache[$key] = 1;
			return true;
		}
		$this->existCache[$key] = $exists;
		if (count($this->existCache) > 20000) {
			$this->existCache = array_slice($this->existCache, -8000, null, true);
		}
		return $exists ? true : false;
	}

	private function deleteByFileIDs($fileIds, &$state, &$parents){
		$fileIds = array_values(array_unique(array_filter($fileIds)));
		if (!$fileIds) return;
		foreach (array_chunk($fileIds, 200) as $chunk) {
			$where = array('fileID' => array('in', $chunk));
			$sourceList = Model('Source')->where($where)->select();
			$sourceList = $sourceList ? $sourceList : array();
			$sourceIds = array();
			foreach ($sourceList as $item) {
				$sourceIds[] = intval($item['sourceID']);
				$parents[] = intval($item['parentID']);
			}
			$hasFile = Model('File')->where($where)->find();
			$hasHistory = Model('SourceHistory')->where($where)->find();
			if (!$sourceList && !$hasFile && !$hasHistory) {
				$state['cleanSkipped'] += count($chunk);
				continue;
			}
			$this->deleteShares($sourceIds);
			if ($sourceIds) {
				Model('SourceRecycle')->where(array('sourceID' => array('in', $sourceIds)))->delete();
			}
			$cntSource = Model('Source')->where($where)->delete();
			$cntHistory = Model('SourceHistory')->where($where)->delete();
			Model('io_file_meta')->where($where)->delete();
			Model('io_file_contents')->where($where)->delete();
			Model('share_report')->where($where)->delete();
			$cntFile = Model('File')->where($where)->delete();
			$state['cleanDeletedSource'] += intval($cntSource);
			$state['cleanDeletedHistory'] += intval($cntHistory);
			$state['cleanDeletedFile'] += intval($cntFile);
		}
	}

	private function deleteBySourceIDs($sourceIds, &$state, &$parents){
		$sourceIds = array_values(array_unique(array_filter($sourceIds)));
		if (!$sourceIds) return;
		$orphanFileIds = array();
		foreach (array_chunk($sourceIds, 200) as $chunk) {
			$list = Model('Source')->where(array('sourceID' => array('in', $chunk)))->select();
			$list = $list ? $list : array();
			$found = array();
			foreach ($list as $info) {
				$sid = intval($info['sourceID']);
				$found[$sid] = 1;
				$parents[] = intval($info['parentID']);
				$fid = intval($info['fileID']);
				if ($fid > 0) $orphanFileIds[] = $fid;
			}
			foreach ($chunk as $sid) {
				if (empty($found[$sid])) $state['cleanSkipped']++;
			}
			if (!$list) continue;
			$ids = array_keys($found);
			$this->deleteShares($ids);
			Model('SourceRecycle')->where(array('sourceID' => array('in', $ids)))->delete();
			$cnt = Model('Source')->where(array('sourceID' => array('in', $ids)))->delete();
			$state['cleanDeletedSource'] += intval($cnt);
		}
		$this->deleteOrphanFiles($orphanFileIds, $state);
	}

	private function deleteHistories($historyIds, &$state){
		$historyIds = array_values(array_unique(array_filter($historyIds)));
		if (!$historyIds) return;
		foreach (array_chunk($historyIds, 200) as $chunk) {
			$cnt = Model('SourceHistory')->where(array('id' => array('in', $chunk)))->delete();
			$state['cleanDeletedHistory'] += intval($cnt);
		}
	}

	private function deleteOrphanFiles($fileIds, &$state){
		$fileIds = array_values(array_unique(array_filter($fileIds)));
		if (!$fileIds) return;
		foreach (array_chunk($fileIds, 200) as $chunk) {
			$where = array('fileID' => array('in', $chunk));
			$used = array();
			$sources = Model('Source')->where($where)->select();
			if ($sources) {
				foreach ($sources as $row) $used[intval($row['fileID'])] = 1;
			}
			$hist = Model('SourceHistory')->where($where)->select();
			if ($hist) {
				foreach ($hist as $row) $used[intval($row['fileID'])] = 1;
			}
			$orphans = array();
			foreach ($chunk as $fid) {
				if (empty($used[$fid])) $orphans[] = $fid;
			}
			if (!$orphans) continue;
			$ow = array('fileID' => array('in', $orphans));
			Model('io_file_meta')->where($ow)->delete();
			Model('io_file_contents')->where($ow)->delete();
			Model('share_report')->where($ow)->delete();
			$cnt = Model('File')->where($ow)->delete();
			$state['cleanDeletedFile'] += intval($cnt);
		}
	}

	private function deleteShares($sourceIds){
		$sourceIds = array_values(array_filter(array_unique($sourceIds)));
		if (!$sourceIds) return;
		$list = Model('Share')->where(array('sourceID' => array('in', $sourceIds)))->select();
		if ($list) {
			$shareIds = array_to_keyvalue($list, '', 'shareID');
			if ($shareIds) {
				Model('share_to')->where(array('shareID' => array('in', $shareIds)))->delete();
			}
		}
		Model('Share')->where(array('sourceID' => array('in', $sourceIds)))->delete();
	}

	private function folderSizeReset($parentSource){
		$model = Model('Source');
		$parentSource = array_filter(array_unique($parentSource));
		foreach ($parentSource as $sourceID) {
			if (intval($sourceID) > 0) $model->folderSizeReset($sourceID);
		}
	}

	private function cleanLog($state, $msg){
		$dir = '';
		if (!empty($state['csvFile'])) $dir = dirname($state['csvFile']);
		if (!$dir) return;
		$line = date('Y-m-d H:i:s').' '.$msg."\n";
		file_put_contents($dir.'/clean-log.txt', $line, FILE_APPEND);
	}

	private function loadStores(){
		$list = Model('Storage')->listData();
		$map = array();
		if (!$list) return $map;
		foreach ($list as $item) {
			$map[intval($item['id'])] = array(
				'id' => intval($item['id']),
				'name' => $item['name'],
				'driver' => $item['driver'],
			);
		}
		return $map;
	}

	private function storageOptions(){
		$opts = array(array('id' => 0, 'name' => LNG('fileMissingExport.opt.ioTypeAll')));
		foreach ($this->loadStores() as $item) {
			$opts[] = array('id' => $item['id'], 'name' => $item['name'].' ['.$item['driver'].']');
		}
		return $opts;
	}

	private function emptyState(){
		return array(
			'runId' => '',
			'status' => 'idle',
			'phase' => 'source',
			'lastSourceID' => 0,
			'lastHistoryID' => 0,
			'scanned' => 0,
			'missing' => 0,
			'total' => 0,
			'percent' => 0,
			'ioType' => 0,
			'includeRecycle' => 1,
			'includeHistory' => 0,
			'batch' => 300,
			'csvFile' => '',
			'txtFile' => '',
			'jsonlFile' => '',
			'recent' => array(),
			'error' => '',
			'startedAt' => 0,
			'updatedAt' => 0,
			'heartbeat' => 0,
			'finishedAt' => 0,
			'csvExists' => false,
			'txtExists' => false,
			'jsonlExists' => false,
			'statusText' => LNG('fileMissingExport.status.idle'),
			'cleanStatus' => 'idle',
			'cleanDeletedSource' => 0,
			'cleanDeletedFile' => 0,
			'cleanDeletedHistory' => 0,
			'cleanSkipped' => 0,
			'cleanProcessed' => 0,
			'cleanTrustScan' => 0,
			'canClean' => false,
		);
	}

	private function loadState(){
		if (!is_file($this->stateFile)) return false;
		$raw = @file_get_contents($this->stateFile);
		if (!$raw) return false;
		$data = json_decode($raw, true);
		return is_array($data) ? $data : false;
	}

	private function saveState($state){
		if ($this->pauseRequested()) {
			if (_get($state, 'cleanStatus') == 'running') $state['cleanStatus'] = 'paused';
			if (_get($state, 'status') == 'running') $state['status'] = 'paused';
		}
		$tmp = $this->stateFile.'.tmp';
		$json = json_encode($state);
		if (defined('JSON_UNESCAPED_UNICODE')) {
			$json = json_encode($state, JSON_UNESCAPED_UNICODE);
		}
		file_put_contents($tmp, $json);
		@rename($tmp, $this->stateFile);
	}

	private function isAlive($state){
		if (_get($state, 'status') != 'running') return false;
		$beat = intval(_get($state, 'heartbeat', 0));
		return $beat && (time() - $beat) < 90;
	}

	private function inWorkDir($file){
		$real = realpath($file);
		$root = realpath($this->workDir);
		if (!$real || !$root) return false;
		return strpos($real, $root) === 0;
	}

	private function pruneOldRuns($keep){
		$dirs = glob($this->workDir.'*', GLOB_ONLYDIR);
		if (!$dirs || count($dirs) <= $keep) return;
		usort($dirs, array($this, 'sortMtime'));
		$remove = array_slice($dirs, $keep);
		foreach ($remove as $dir) {
			del_dir($dir);
		}
	}

	private function sortMtime($a, $b){
		return filemtime($b) - filemtime($a);
	}
}
