<?php

require_once('dbutils.php');

class ChangesetDatabaseSqlite extends GenericSqliteTable
{
	var $keys=array('cid'=>'INTEGER');
	var $dbname='sqlite/changesets.db';
	var $tablename="changesets";

	public function Open($cid,$data,$displayName,$userId,$createTime)
	{
		$c = array();
		$data->attr['user'] = $displayName;
		$data->attr['uid'] = $userId;
		$data->attr['created_at'] = date('c',$createTime);
		$data->attr['open'] = "true";
		$c['data'] = $data;
		$c['change'] = new OsmChange();
		//$c['open'] = 1;
		//$c['createTime'] = $createTime;
		//$c['closeTime'] = $createTime;
		//$c['displayName'] = $displayName;
		//$c['userId'] = $userId;
		$this[(int)$cid] = $c;
		//print_r($this[(int)$cid]);
		return $cid;
	}

	function Update($cid,$data,$displayName,$userId)
	{
		$c = $this[(int)$cid];
		$c['data']->tags = $data->tags;
		$this[(int)$cid] = $c;
	}

	public function IsOpen($cid)
	{
		$c = $this[(int)$cid];
		if(is_null($c)) return -1;
		return (strcmp($c['data']->attr['open'],"true")==0);
	}

	public function GetContentObject($cid)
	{
		$c = $this[(int)$cid];
		return $c['change'];
	}

	function GetSize($cid)
	{
		$changeset = $this[(int)$cid];
		if(is_null($changeset)) return null;
		//print_r($changeset);
		$count = 0;
		foreach($changeset['change']->data as $data)
		{
			$action = $data[0];
			$els = $data[1];
			$count = $count + count($els);
		}
		return $count;
	}

	function AppendElement($cid, $action, $el)
	{
		$changeset = $this[(int)$cid];
		array_push($changeset['change']->data,array($action,array($el)));
		$this[(int)$cid] = $changeset;
	}

	function GetClosedTime($cid)
	{
		$changeset = $this[(int)$cid];
		return strtotime($changeset['data']->attr['closed_at']);
	}

	function GetUid($cid)
	{
		$c = $this[(int)$cid];
		if(is_null($c)) return null;
		//print_r($c['data']);
		return $c['data']->attr['uid'];
	}

	function GetMetadata($cid)
	{
		$c = $this[(int)$cid];
		return $c['data'];
	}

	function Close($cid)
	{
		$changeset = $this[(int)$cid];
		$changeset['data']->attr['open'] = "false";
		$changeset['data']->attr['closed_at'] = date('c',time());
		$this[(int)$cid] = $changeset;
	}

	function Query($user,$open,$timerange)
	{
		$ids = $this->GetKeys();
		$out = array();
		//TODO This steps through the entire database, which is very slow for big databases
		foreach($ids as $cid)
		{
			$c = $this[$cid];
			if(!is_null($user) and $user != $c['data']->attr['uid']) continue; 
			if(!is_null($open) and $open==1 and strcmp($c['data']->attr['open'],"true")!=0) continue;
			if(!is_null($open) and $open==0 and strcmp($c['data']->attr['open'],"false")!=0) continue;
			array_push($out,(int)$cid);
		}
		return $out;
	}

}//End of class
