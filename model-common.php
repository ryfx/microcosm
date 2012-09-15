<?php


abstract class OsmDatabaseCommon
{

	//**********************
	//General main query
	//**********************

	public function GetNodesInBbox($bbox)
	{
		//Return node objects in array
		return array();
	}

	public function GetParentWaysOfNodes(&$nodes)
	{
		//Return way objects in array
		return array();
	}

	public function GetNodesToCompleteWays(&$nodes, &$ways)
	{
		//List node Ids
		$nids = array();
		foreach($nodes as $node)
		{
			array_push($nids,$node->attr['id']);
		}
		//print_r(count($nids));

		$additionalNodes = array();
		foreach($ways as $way)
		{
			//print_r($way);
			foreach($way->nodes as $data)
			{
				$id = $data[0];
				if(in_array($id,$nids)) continue;
				array_push($additionalNodes, $id);
			}
		}

		//print_r($additionalNodes);
		foreach($additionalNodes as $id)
		{
			$obj = $this->GetElementById("node",$id);
			if(!is_object($obj)) throw new Exception("Could not complete way, node ".$id." not found.");
			array_push($nodes,$obj);
		}
		
		return 1;
	}
	
	public function GetParentRelations(&$els)
	{
		//Return way objects in array
		return array();
	}

	public function MapQuery($bbox)
	{
		//Get nodes
		$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$out = '<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";

		//Specify bounds
		$out=$out.'<bounds minlat="'.$bbox[1].'" minlon="'.$bbox[0];
		$out=$out.'" maxlat="'.$bbox[3].'" maxlon="'.$bbox[2].'"/>'."\n";

		$timers = array();
		$startTimer = microtime(1);
		$nodes = $this->GetNodesInBbox($bbox);
		$timers['nodes']=(microtime(1) - $startTimer);

		foreach($nodes as $n) if(!is_object($n)) 
			throw new Exception("Retrieved database object type ".gettype($n)." incorrect");

		$startTimer = microtime(1);
		$ways = $this->GetParentWaysOfNodes($nodes); 
		$timers['ways']=(microtime(1) - $startTimer);

		$startTimer = microtime(1);
		$this->GetNodesToCompleteWays($nodes, $ways);
		$timers['ways2']=(microtime(1) - $startTimer);

		foreach($nodes as $n) if(!is_object($n)) 
			throw new Exception("Retrieved database object type ".gettype($n)." incorrect");

		$elsQuery = array_merge($nodes, $ways);
		$startTimer = microtime(1);
		$relations = $this->GetParentRelations($elsQuery);
		$timers['relations']=(microtime(1) - $startTimer);
		//print_r($timers); die();

		foreach($nodes as $obj)
		{
			//print_r($obj); echo "\n";
			if(!is_object($obj)) 
				throw new Exception("Retrieved database object type ".gettype($obj)." incorrect");
			$out=$out.$obj->ToXmlString();
		}

		foreach($ways as $obj)
		{
			if(!is_object($obj)) 
				throw new Exception("Retrieved database object type ".gettype($obj)." incorrect");
			$out=$out.$obj->ToXmlString();
		}

		foreach($relations as $obj)
		{
			if(!is_object($obj)) 
				throw new Exception("Retrieved database object type ".gettype($obj)." incorrect");
			$out=$out.$obj->ToXmlString();
		}

		$out = $out."</osm>";
		//return array();
		return $out;
	}

	//**********************************
	//Get specific info from database
	//**********************************
	public function GetCurentVerOfElement($type,$id)
	{
		$obj = $this->GetElementById($type,$id);
		if(!is_object($obj)) return $obj; //Not found or gone
		if(!isset($obj->attr['version'])) 
			throw new Exception("Internal database has missing version attribute.");
		return $obj->attr['version'];
	}

	public abstract function GetElementById($type,$id,$version=null);

	public function GetElementAsXmlString($type,$id)
	{
		$obj = $this->GetElementById($type,$id);
		if(!is_object($obj)) return $obj;
		return $obj->ToXmlString();
	}

	public function CheckElementExists($type,$id)
	{
		$obj = $this->GetElementById($type,$id);
		return is_object($obj);
	}

	public function GetCitingWaysOfNode($id)
	{
		$obj = new OsmNode();
		$obj->attr['id'] = $id;
		$objarr = array($obj);
		$ret = $this->GetParentWaysOfNodes($objarr);
		$out = array();
		foreach($ret as $obj) array_push($out,$obj->attr['id']);
		return $out;
	}

	public function GetCitingRelations($type,$id)
	{
		$obj = OsmElementFactory($type);
		$obj->attr['id'] = $id;
		$objarr = array($obj);
		$ret = $this->GetParentRelations($objarr);
		$out = array();
		foreach($ret as $obj) array_push($out,$obj->attr['id']);
		return $out;
	}

	public function GetBboxOfElement($type,$id,$depth = 0)
	{
		//Get the bounding box of an element
		//Return format: min_lon,min_lat,max_lon,max_lat
		$bbox= null;

		//Prevent infinite recursion
		$maxDepth = 10;
		if($depth>$maxDepth) return null;

		//Get parent element
		$el = $this->GetElementById($type,$id);
		if(!is_object($el)) return null;

		//Use own position attribute
		if(isset($el->attr['lon']) and isset($el->attr['lat']))
			UpdateBbox($bbox,
				array($el->attr['lon'],$el->attr['lat'],
				$el->attr['lon'],$el->attr['lat']));

		//Recursively get member elements
		foreach($el->nodes as $member)
			UpdateBbox($bbox,$this->GetBboxOfElement("node",$member[0],$depth+1));
		foreach($el->ways as $member)
			UpdateBbox($bbox,$this->GetBboxOfElement("way",$member[0],$depth+1));
		foreach($el->relations as $member)
			UpdateBbox($bbox,$this->GetBboxOfElement("relation",$member[0],$depth+1));

		return $bbox;
	}

	//***********************
	//Modification functions
	//***********************

}



?>
