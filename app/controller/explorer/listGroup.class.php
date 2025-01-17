<?php
/*
* @link http://kodcloud.com/
* @author warlee | e-mail:kodcloud@qq.com
* @copyright warlee 2014.(Shanghai)Co.,Ltd
* @license http://kodcloud.com/tools/license/license.txt
*/
class explorerListGroup extends Controller{
	public function __construct(){
		parent::__construct();
		$this->model = Model("Source");
		$this->modelGroup = Model('Group');
	}

	public function groupSelf($pathInfo){//获取组织架构的用户和子组织；
		$groupArray = Session::get("kodUser.groupInfo");
		$groupArray = array_sort_by($groupArray,'groupID');
		$groupArray = $this->groupSelfLimit($groupArray);
		$listData 	= $this->groupArray($groupArray);
		$this->groupSelfAppendAllow($listData);
		return $listData;
	}
	
	// 我所在的部门, 罗列自己有权限的部门(通路)
	public function groupSelfAppendAllow(&$listData){
		if(intval($this->in['page']) > 1) return;// 第一页才罗列;
		$groupArray = Action('filter.userGroup')->userGroupRootShow();
	    if(!$groupArray || empty($groupArray[0])) return false;

		$groupList = array();$groupListArr = array();
		foreach ($listData as $key => $item) {
			$item['sourceRootSelf'] = 'self';
			$groupList[] = $item;
			$groupListArr[$item['targetID']] = $item;
			unset($listData[$key]);
		}
				
		$groupChild = $this->modelGroup->where(array('parentID'=>$groupArray[0]))->select();
		$groupAdd 	= $this->groupArray($groupChild);
		$groupAddTo = array();// 去除重复已在该部门的部门;
		foreach ($groupAdd as $item){
			if(isset($groupListArr[$item['targetID']])){continue;}
			$groupAddTo[] = $item;
		}
		
		$listData['groupList'] = $groupList;
		$listData['folderList']= $groupAddTo;
		$desc = '('.LNG('explorer.toolbar.myGroupAllowDesc').')';
		$listData['groupShow'] = array(
			array('type'=>'childGroupSelf', 'title'=>LNG('explorer.toolbar.myGroup'),"filter"=>array('sourceRootSelf'=>'self')),
			array('type'=>'childGroupAllow','title'=>LNG('explorer.toolbar.myGroupAllow'),"desc"=>$desc,"filter"=>array('sourceRootSelf'=>'!=self')),
		);
		if(count($listData['folderList']) == 0){unset($listData['groupShow']);}
	}
	
	// 部门层级限制处理; 超过层级限制的部门,展示该部门在限制层级时的部门通路;
	private function groupSelfLimit($groupArray){
		$option = Model('SystemOption')->get();
		if($option['groupSpaceLimit'] != '1') return $groupArray;
		
		$levelMax = intval($option['groupSpaceLimitLevel']);
		$groupMap = array_to_keyvalue($groupArray,'groupID');
		$groupArray = array();
		foreach ($groupMap as $groupID => $item) {
			$level = explode(',',trim($item['parentLevel'],',')); // -1 + 1(去掉0;加上自己; 最后一层是自己)
			if(count($level) <= $levelMax){$groupArray[$groupID] = $item;continue;}

			$autoGroupID = $level[$levelMax];
			if(!isset($groupMap[$autoGroupID])){
				$info = $this->modelGroup->getInfoSimple($autoGroupID);
				$groupMap[$autoGroupID] = array(
					'groupID'		=> $info['groupID'],
					'groupName'		=> $info['name'],
					'parentLevel'	=> $info['parentLevel']
				);
			}
			if(!is_array($groupMap[$autoGroupID])){continue;}
			$groupArray[$autoGroupID] = $groupMap[$autoGroupID];
		}
		// pr($groupMap,$groupArray);exit;
		return $groupArray;
	}
	
	// 是否允许罗列部门的子部门;
	private function enableListGroup($groupID){
		$option = Model('SystemOption')->get();
		if($option['groupSpaceLimit'] == '1'){
			$groupInfo = $this->modelGroup->getInfoSimple($groupID);
			$level = explode(',',trim($groupInfo['parentLevel'],',')); // -1 + 1(去掉0;加上自己)
			if(count($level) >= intval($option['groupSpaceLimitLevel'])){return false;}
		}
		
		if( !isset($option['groupListChild']) ) return true;
		$listGroup = $option['groupListChild']=='1';
		if(!$listGroup) return false;
		if($groupID == '1'){
			return $option['groupRootListChild']=='1';
		}
		return true;
	}
	
	// 根据多个部门信息,构造部门item;
	private function groupArray($groupArray){
		$groupArray = array_sort_by($groupArray,'sort');// 排序处理;
		$groupArray	= array_to_keyvalue($groupArray,'groupID');//自己所在的组
		$this->_filterDisGroup($groupArray);	// 过滤已禁用部门
		$group = array_remove_value(array_keys($groupArray),1);
		if(!$group) return array();

		$groupSource = $this->model->sourceRootGroup($group);
		$groupSource = array_to_keyvalue($groupSource,'targetID');
		$result = array();
		foreach($groupArray as $group){ // 保持部门查询结构的顺序;
			$groupID = $group['groupID'];
			if($groupID == '1') continue; // 去除根部门
			if(!isset($groupSource[$groupID])) continue;
			
			$pathInfo = $groupSource[$groupID];			
			$pathInfo['sourceRoot'] = 'groupPath';
			$pathInfo['pathDisplay']= $pathInfo['groupPathDisplay'];
			if(!$pathInfo['auth']){
				$pathInfo['auth'] = Model("SourceAuth")->authDeepCheck($pathInfo['sourceID']);
			}
			
			$userRootShow = _get($GLOBALS,'isRoot') && $GLOBALS['config']["ADMIN_ALLOW_SOURCE"];
			if(!$userRootShow){
				if( !$pathInfo['auth'] || $pathInfo['auth']['authValue'] == 0){ // 放过-1; 打开通路;
					continue;// 没有权限;
				}
			}
			
			// 没有子文件; 则获取是否有子部门;
			if( !$pathInfo['hasFolder'] && !$pathInfo['hasFile'] ){
				$groupInfo = Model('Group')->getInfo($groupID);
				$pathInfo['hasFolder']  = $groupInfo['hasChildren'];
				$pathInfo['hasFile'] 	= $groupInfo['hasChildren'];
			}
			$result[] = $pathInfo;
		}
		// pr($result,$groupSource,$group,$groupArray);exit;
		return $result;
	}
	// 过滤已禁用部门
	private function _filterDisGroup(&$list){
		if(empty($list)) return array();
		$where = array(
			'groupID'	=> array('in', array_keys($list)),
			'key'		=> 'status', 
			'value'		=> 0
		);
		$data = Model('group_meta')->where($where)->field('groupID')->select();
		foreach($data as $value) {
			unset($list[$value['groupID']]);
		}
	}
	
	/**
	 * 部门根目录;罗列子部门;
	 */
	public function appendChildren(&$data){
		$pathInfo = $data['current'];
		if(!$pathInfo || _get($pathInfo,'targetType') != 'group') return false;
		if(isset($pathInfo['shareID'])) return false;
		if(intval($this->in['page']) > 1) return;// 第一页才罗列;

		//不是根目录
		$parents = $this->model->parentLevelArray($pathInfo['parentLevel']);
		if(count($parents) != 0) return false;
		if(!$this->enableListGroup($pathInfo['targetID'])) return;
		
		$groupList  = $this->modelGroup->where(array('parentID'=>$pathInfo['targetID']))->select();
		$groupListAdd  = $this->groupArray($groupList);
		$data['pageInfo']['totalNum'] += count($groupListAdd);

		$data['groupList'] = $groupListAdd;
		$data['groupShow'] = array(
			array('type'=>'childGroup','title'=>LNG('explorer.pathGroup.group'),"filter"=>array('sourceRoot'=>'groupPath')),
			array('type'=>'childContent','title'=>LNG('explorer.pathGroup.groupContent'),"filter"=>array('sourceRoot'=>'!=groupPath')),
		);
		if(count($data['groupList']) == 0){unset($data['groupShow']);}
		// show_json($data);exit;
	}

	public function pathGroupAuthMake($groupID,$userID=false){
		$groupRoot  = '1';
		$groupInfo  = $this->modelGroup->getInfoSimple($groupID);//101
		if(!$userID){
			$groupSelf 	= Session::get("kodUser.groupInfo");
		}else{
			$userInfo = Model('User')->getInfo($userID);
			$groupSelf = $userInfo['groupInfo'];
		}
		if(!$groupSelf) return false;
		
		// 部门文件夹或子文件夹没有针对自己设置权限,向上级部门回溯;
		$groupSelf 	= array_to_keyvalue($groupSelf,'groupID');//自己所在的组
		$parents	= $this->model->parentLevelArray($groupInfo['parentLevel']);
		$parents[]	= $groupID;
		$parents 	= array_reverse($parents);
		foreach ($parents as $id) {
			if($id == $groupRoot) return false;// 根部门;
			if(isset($groupSelf[$id])){
				return array(
					'authValue' => intval($groupSelf[$id]['auth']['auth']),
					'authInfo'  => $groupSelf[$id]['auth'],
				);
			}
		}
		return Model("SourceAuth")->authDeepCheck($groupInfo['sourceInfo']['sourceID'],$userID);
	}

	/**
	 * 用户根目录,部门根目录操作检测
	 * 不允许: 重命名,删除,复制,剪切,下载,分享
	 */
	public function pathRootCheck($action){
		$disable = array(
			'path' 	=> array(
				'explorer.index.pathRename',
				'explorer.userShare.add',
				'explorer.userShare.get',
				'explorer.userShare.edit',
			),
			'dataArr'=> array(
				'explorer.index.pathDelete',
				'explorer.index.pathCopy',
				'explorer.index.pathCute',
				'explorer.index.pathCopyTo',
				'explorer.index.pathCuteTo',
				'explorer.index.zipDownload'
			),
		);
		foreach ($disable as $type=>$medhods) {
			$disable[$type] = array();
			foreach ($medhods as $item) {
				$disable[$type][] = strtolower($item);
			}
		}
		
		$allAction = array_merge($disable['path'],$disable['dataArr']);
		if(!in_array($action,$allAction)) return;
		
		$isGroupRoot = false;
		$errorAdd = '';
		if(in_array($action,$disable['path'])){
			$isGroupRoot = $this->pathIsRoot($this->in['path']);
		}else{
			$data = json_decode($this->in['dataArr'],true);
			if(is_array($data)){
				foreach ($data as $item) {
					$isGroupRoot = $this->pathIsRoot($item['path']);
					if($isGroupRoot){
						$errorAdd = '['.$item['name'].'],';
						break;
					}
				}
			}
		}
		if(!$isGroupRoot) return;
		return show_json($errorAdd.LNG('explorer.pathNotSupport'),false);
	}
	// 检测目录是否为部门根目录;
	private function pathIsRoot($path){
		$parse = KodIO::parse($path);
		if($parse['type'] != KodIO::KOD_SOURCE) return false;
		
		$info = IO::infoSimple($path);
		if($info['targetType'] != SourceModel::TYPE_GROUP) return false;
		if($info['targetType'] != SourceModel::TYPE_USER)  return false;
		if($info['parentID'] =='0') return true;//部门根目录,用户根目录;
		// if(!$info || $info['targetType'] != 'group') return false;
		return false;
	}
	
}