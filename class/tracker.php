<?php

class trackerResource extends XoopsObject {
	function __construct(){
		$this->XoopsObject();
		$this->initVar("lid", XOBJ_DTYPE_INT);
		$this->initVar("seeds", XOBJ_DTYPE_INT);
		$this->initVar("leechers", XOBJ_DTYPE_INT);
		$this->initVar("tracker", XOBJ_DTYPE_INT);	
	}
}

class XtorrentTrackerHandler extends XoopsObjectHandler {
	var $db;
	var $db_table;
	var $perm_name = 'xtorrent_xtorrent_';
	var $obj_class = 'trackerResource';

	function __construct($db){
		if (!isset($db)&&!empty($db))
		{
			$this->db = $db;
		} else {
			global $xoopsDB;
			$this->db = $xoopsDB;
		}
		$this->db_table = $this->db->prefix('xtorrent_tracker');
		$this->perm_handler = xoops_gethandler('groupperm');
	}
	
	function getInstance($db){
		static $instance;
		if( !isset($instance) ){
			$instance = new xtorrenttrackerHandler($db);
		}
		return $instance;
	}

	function poll($torrent, $timeout = '5'){

		$xthdlr_downloads = xoops_load('downloads', 'xtorrent');

		if (is_object($torrent))
			$download = $xthdlr_downloads->get($torrent->getVar('lid'));
		elseif (is_numeric($torrent))
			$download = $xthdlr_downloads->get($torrent);
		else
			return false;

		// Create and Decompile Benc Object
		$xthdlr_benc  = xoops_load('benc', 'xtorrent');
		$benc_torrent = $xthdlr_benc->create();				
		$benc_torrent->setVar('filename',$download->getVar('filename'));
		$benc_torrent = $xthdlr_benc->decompile($benc_torrent);

		$torrent = $benc_torrent->getVar('object');
	
		if(!$torrent->error) {
			$scrape_result = @tracker_scrape_all($torrent, $timeout);
			$summary       = tracker_scrape_summarise($scrape_result);
		
			$xthdlr_tracker = xoops_load('tracker', 'xtorrent');
			$criteria_y     = new CriteriaCompo(new Criteria('lid', $download->getVar('lid')));
			$xthdlr_tracker->delete($criteria_y);

			foreach($scrape_result as $tracker => $result){
				$traxr = $xthdlr_tracker->create();		
				if (isset($result)){
					$traxr->setVar('lid', $download->getVar('lid'));
					$traxr->setVar('seeds', $result['seeds']);
					$traxr->setVar('leechers', $result['leeches']);
					$traxr->setVar('tracker', $result['tracker']);
					$xthdlr_tracker->insert($traxr);
				} else {
					$traxr->setVar('lid', $download->getVar('lid'));
					$traxr->setVar('seeds', 0);
					$traxr->setVar('leechers', 0);
					$traxr->setVar('tracker', $torrent->announce());
					$xthdlr_tracker->insert($traxr);
				}
			}
		}
	}

	private function xtorrent_scrape_summarise($scrape_results) {
		if(!is_array($scrape_results)) {
			trigger_error("Scrape Summarise error: Expected array as first parameter.");
			return false;
		}

		$summary = ['seeds' => 0, 'leeches' => 0, 'downloads' => 0];
		foreach($scrape_results as $result) {
			if(is_array($result)) {
				$summary['seeds'] += $result['seeds'];
				$summary['leeches'] += $result['leeches'];
				$summary['downloads'] += $result['downloads'];
			}
		}
		
		return $summary;
	}

	function xtorrent_scrape_all($torrent, $timeout = 5) {	
		if(!count($torrent->getvar('announceList'))) {
			return array($this->xtorrent_scrape($torrent));
		}

		$scrape_results = [];

		foreach($torrent->getvar('announceList') as $tier)
			foreach($tier as $tracker)
				$scrape_results[$tracker] = $this->xtorrent_scrape($torrent, $tracker, $timeout);

		return $scrape_results;
	}

	function xtorrent_scrape($torrent, $tracker = null, $timeout = 5) {	
		if(is_null($tracker))
			$tracker = $torrent->getVar('announce');

		$scrape_address = $this->xtorrent_get_scrape_address($tracker);

		if($scrape_address === false) {
			trigger_error("Failed to scrape tracker {$tracker}", E_USER_WARNING);
			return false;
		}

		if(strpos($scrape_address, "?") !== false)
			$scrape_address .= "&info_hash=" . urlencode($torrent->infoHash);
		else
			$scrape_address .= "?info_hash=" . urlencode($torrent->infoHash);

		// Set the timeout before proceeding and reset it when done
		$old_timeout = ini_get('default_socket_timeout');
		ini_set('default_socket_timeout', $timeout);

		$data = @file_get_contents($scrape_address);
		ini_set('default_socket_timeout', $old_timeout);

		if($data === false) {
			trigger_error("Scrape error: Failed to scrape torrent details from the tracker", E_USER_WARNING);
			return false;
		}

		$xthdlr_benc  = xoops_load('benc', 'xtorrent');
		$benc_tracker = $xthdlr_benc->create();				
		$benc_tracker->setVar('benc',$data);
		$benc_tracker = $xthdlr_benc->decompile($benc_tracker);
		$trackerInfo  = $benc_tracker->getVar('object');

		if($trackerInfo === false) {
			trigger_error("Scrape error: Tracker returned invalid response to scrape request", E_USER_WARNING);
			return false;
		}

		if(isset($trackerInfo['failure reason'])) {
			trigger_error("Scrape error: Scrape failed. Tracker gave the following reason: ".ucfirst($trackerInfo['failure reason']), E_USER_WARNING);
			return false;
		}

		$result = [];
		$result['seeds'] = $trackerInfo['files'][$torrent->getVar('infoHash')]['complete'];
		$result['downloads'] = $trackerInfo['files'][$torrent->getVar('infoHash')]['downloaded'];
		$result['leeches'] = $trackerInfo['files'][$torrent->getVar('infoHash')]['incomplete'];
		$result['tracker'] = $tracker;
		
		return $result;
	}

	private function xtorrent_get_scrape_address($announce) {

		$last_slash = strrpos($announce, "/");

		if($last_slash === false) {
			trigger_error("Tracker address ({$announce}) is invalid", E_USER_WARNING);
			return false;
		}

		$last_part = substr($announce, $last_slash);
		if(strpos($last_part, "announce") === false) {
			trigger_error("Tracker ({$announce}) does not appear to support scrape", E_USER_WARNING);
			return false;
		}

		return substr($announce, 0, $last_slash) . "/" . str_replace($last_part, "announce", "scrape");
	}

	function create(){
		return new $this->obj_class();
	}

	function get($id, $fields='*'){
		$id = intval($id);
		if( $id > 0 ){
			$sql = 'SELECT '.$fields.' FROM '.$this->db_table.' WHERE id='.$id;
		} else {
			return false;
		}
		if( !$result = $this->db->query($sql) ){
			return false;
		}
		$numrows = $this->db->getRowsNum($result);
		if( $numrows == 1 ){
			$tracker = new $this->obj_class();
			$tracker->assignVars($this->db->fetchArray($result));
			return $tracker;
		}
		return false;
	}

	function insert($tracker, $force = false){
        if( strtolower(get_class($tracker)) != strtolower($this->obj_class)){
            return false;
        }
        if( !$tracker->isDirty() ){
            return true;
        }
        if( !$tracker->cleanVars() ){
            return false;
        }
		foreach( $tracker->cleanVars as $k=>$v ){
			${$k} = $v;
		}
		$myts = MyTextSanitizer::getInstance();
		if( $tracker->isNew() || empty($id) ){
			$id = $this->db->genId($this->db_table."_xt_xtorrent_id_seq");
			$sql = sprintf("INSERT INTO %s (
				`lid`, `seeds`, `leechers`, `tracker`
				) VALUES (
				%u, %s, %s, %s,
				)",
				$this->db_table,
				$this->db->quoteString($lid),
				$this->db->quoteString($seeds),
				$this->db->quoteString($leechers),
				$this->db->quoteString($tracker)
			);
		}else{
			$sql = sprintf("UPDATE %s SET
				`seeds` = %s,
				`leechers` = %s,
				`tracker` = %s WHERE lid = %s",
				$this->db_table,
				$this->db->quoteString($seeds),
				$this->db->quoteString($leechers),
				$this->db->quoteString($tracker),
				$this->db->quoteString($lid)
			);
		}

		if( false != $force ){
            $result = $this->db->queryF($sql);
        }else{
            $result = $this->db->query($sql);
        }
		if( !$result ){
			$tracker->setErrors("Could not store data in the database.<br>".$this->db->error().' ('.$this->db->errno().')<br>'.$sql);
			return false;
		}
		if( empty($id) ){
			$id = $this->db->getInsertId();
		}
        $tracker->assignVar('id', $id);
		return $id;
	}

	function delete($criteria = null, $force = false){
		if( strtolower(get_class($tracker)) != strtolower($this->obj_class) ){
			return false;
		}
		if( isset($criteria) && is_subclass_of($criteria, 'criteriaelement') ){
			$sql = "DELETE FROM ".$this->db_table." ".$criteria->renderWhere()."";
		}
        if( false != $force ){
            $result = $this->db->queryF($sql);
        }else{
            $result = $this->db->query($sql);
        }
		return true;
	}

	function getObjects($criteria = null, $fields='*', $id_as_key = false){
		$ret   = [];
		$limit = $start = 0;
		$sql   = 'SELECT '.$fields.' FROM '.$this->db_table;
		if( isset($criteria) && is_subclass_of($criteria, 'criteriaelement') ){
			$sql .= ' '.$criteria->renderWhere();
			if( $criteria->getSort() != '' ){
				$sql .= ' ORDER BY '.$criteria->getSort().' '.$criteria->getOrder();
			}
			$limit = $criteria->getLimit();
			$start = $criteria->getStart();
		}
		$result = $this->db->query($sql, $limit, $start);
		if( !$result )
			return false;
		while( $myrow = $this->db->fetchArray($result) ){
			$tracker = new $this->obj_class();
			$tracker->assignVars($myrow);
			if( !$id_as_key ){
				$ret[] = $tracker;
			}else{
				$ret[$myrow['id']] = $tracker;
			}
			unset($tracker);
		}
		return count($ret) > 0 ? $ret : false;
	}

    function getCount($criteria = null){
		$sql = 'SELECT COUNT(*) FROM '.$this->db_table;
		if( isset($criteria) && is_subclass_of($criteria, 'criteriaelement') ){
			$sql .= ' '.$criteria->renderWhere();
		}
		$result = $this->db->query($sql);
		if( !$result ){
			return 0;
		}
		list($count) = $this->db->fetchRow($result);
		return $count;
	}

    function deleteAll($criteria = null){
		$sql = 'DELETE FROM '.$this->db_table;
		if( isset($criteria) && is_subclass_of($criteria, 'criteriaelement') ){
			$sql .= ' '.$criteria->renderWhere();
		}
		if( !$result = $this->db->query($sql) ){
			return false;
		}
		return true;
	}

	function deleteTorrentPermissions($id, $mode = "view"){
		global $xoopsModule;
		$criteria = new CriteriaCompo();
		$criteria->add(new Criteria('gperm_itemid', $id)); 
		$criteria->add(new Criteria('gperm_modid', $xoopsModule->getVar('mid')));
		$criteria->add(new Criteria('gperm_name', $this->perm_name.$mode)); 
		if( $old_perms = $this->perm_handler->getObjects($criteria) ){
			foreach( $old_perms as $p ){
				$this->perm_handler->delete($p);
			}
		}
		return true;
	}

	function insertTorrentPermissions($id, $group_ids, $mode = "view"){
		global $xoopsModule;
		foreach( $group_ids as $id ){
			$perm = $this->perm_handler->create();
			$perm->setVar('gperm_name', $this->perm_name.$mode);
			$perm->setVar('gperm_itemid', $id);
			$perm->setVar('gperm_groupid', $id);
			$perm->setVar('gperm_modid', $xoopsModule->getVar('mid'));
			$this->perm_handler->insert($perm);
			$ii++;
		}
		return "Permission ".$this->perm_name.$mode." set $ii times for "._C_ADMINTITLE." Record ID ".$id;
	}

	function getPermittedTorrents($tracker, $mode = "view"){
		global $xoopsUser, $xoopsModule;
		$ret=false;
		if (isset($tracker))
		{
			$ret      = [];
			$criteria = new CriteriaCompo();
			$criteria->add(new Criteria('gperm_itemid', $tracker->getVar('lid'), '='), 'AND');
			$criteria->add(new Criteria('gperm_modid', $xoopsModule->getVar('mid'), '='), 'AND');
			$criteria->add(new Criteria('gperm_name', $this->perm_name.$mode, '='), 'AND');						

			$gtObjperm = $this->perm_handler->getObjects($criteria);
			$groups    = [];
			
			foreach ($gtObjperm as $v)
			{
				$ret[] = $v->getVar('gperm_groupid');
			}	
			return $ret;

		} else {
			$ret    = [];
			$groups = is_object($xoopsUser) ? $xoopsUser->getGroups() : 3;
			$criteria = new CriteriaCompo();
			$criteria->add(new Criteria('Torrent_order', 1, '>='), 'OR');
			$criteria->setSort('Torrent_order');
			$criteria->setOrder('ASC');
			if( $tracker = $this->getObjects($criteria, 'home_list') ){
				$ret = [];
				foreach( $tracker as $f ){
					if( false != $this->perm_handler->checkRight($this->perm_name.$mode, $f->getVar('lid'), $groups, $xoopsModule->getVar('mid')) ){
						$ret[] = $f;
						unset($f);
					}
				}
			}
		}
		return ret;
	}

	function getSingleTorrentPermission($id, $mode = "view"){
		global $xoopsUser, $xoopsModule;
		$groups = is_object($xoopsUser) ? $xoopsUser->getGroups() : 3;
		if( false != $this->perm_handler->checkRight($this->perm_name.$mode, $id, $groups, $xoopsModule->getVar('mid')) ){
			return true;
		}
		return false;
	}
}