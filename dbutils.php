<?php
include_once 'config.php';

function SqliteGetTables(&$dbh)
{
	$sql = "SELECT * FROM sqlite_master WHERE type='table';";
	$ret = $dbh->query($sql);
	if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	$out = array();
	foreach($ret as $row)
	{
		//print_r($row);
		array_push($out,$row['name']);
	}
	return $out;
}

function SqliteCheckTableExists(&$dbh,$name)
{
	//Check if table exists
	$sql = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=".$dbh->quote($name).";";
	//echo $sql."\n";
	$ret = $dbh->query($sql);
	if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	$tableExists = 0;
	foreach($ret as $row)
	{
		//print_r($row);
		$tableExists = ($row[0] > 0);
	}
	return $tableExists;
}

function SqliteCheckTableExistsOtherwiseCreate(&$dbh,$name,$createSql)
{
	//If node table doesn't exist, create it
	$exists = SqliteCheckTableExists($dbh,$name);
	//echo $name." ".$exists."\n";
	if($exists) return;
	//echo $createSql."\n";

	$ret = $dbh->exec($createSql);
	if($ret===false) {$err= $dbh->errorInfo();throw new Exception($createSql.",".$err[2]);}
}

function SqliteDropTableIfExists(&$dbh,$name)
{
	$eleExist = SqliteCheckTableExists($dbh,$name);
	if(!$eleExist) return;

	$sql = 'DROP TABLE ['.$name.'];';
	$ret = $dbh->exec($sql);
	if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
}

function TableToHtml($dbh,$table)
{
	$sql = "SELECT * FROM [?];";

	$sth = $dbh->prepare($sql);
	if($sth===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	$ret = $sth->execute(array($table));
	if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	foreach($ret as $row)
		print_r($row);
}

function CheckForRtree()
{
	chdir(dirname(realpath (__FILE__)));
	$dbh = new PDO('sqlite:rtreetest.db');
	SqliteDropTableIfExists($dbh, "test");
	$createSql="CREATE VIRTUAL TABLE position USING rtree(id,minLat,maxLat,minLon,maxLon);";
	$ret = $dbh->exec($createSql);
	if($ret===false) {$err= $dbh->errorInfo();return ($createSql.",".$err[2]);}
	return 1;
}

//***********************
//Generic sqlite table
//***********************

class GenericSqliteTable implements ArrayAccess
{
	var $keys=array('key'=>'STRING');

	var $dbname='generictable.db';
	var $tablename="table";
	var $dbh = null;
	var $transactionOpen = 0;
	var $useTransactions = 1;

	function __construct()
	{
		$fina = PATH_TO_SQLITE_DB.$this->dbname;
		$this->dbh = new PDO('sqlite:'.$fina);
		$this->InitialiseSchema();
	}

	function __destruct()
	{
		$this->EndTransaction();
	}

	function InitialiseSchema()
	{
		//Create table spec object
		$spec = new TableSpecSqlite($this->tablename);
		$count = 0;
		foreach($this->keys as $key=>$type)
		{
			$primaryKey = ($count == 0);
			$unique = ($count != 0);
			$spec->Add($key,$type,$primaryKey,$unique);
			$count ++;
		}
		$spec->Add("value","BLOB");

		//Action depends if table already exists
		if($spec->TableExists($this->dbh))
		{
			//Check existing table schema
			$match = $spec->SchemaMatches($this->dbh);
			if($match) return;

			//Experimental: migrate the schema
			//Back up your data before trying this!
			//$spec->MigrateSchema($dbh);
			//if($spec->SchemaMatches($dbh)) return;

			throw new Exception("Database schema does not match for table ".$this->tablename);
		}
		else
		{
			//Create table
			$spec->CreateTable($this->dbh);
		}
	}

	function BeginTransactionIfNotAlready()
	{
		if(!$this->transactionOpen)
		{
			$sql = "BEGIN;";
			$ret = $this->dbh->exec($sql);//Begin transaction
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
			$this->transactionOpen = 1;
		}
	}

	function EndTransaction()
	{
		if($this->transactionOpen)
		{
			$sql = "END;";
			$ret = $this->dbh->exec($sql);//End transaction	
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
			$this->transactionOpen = 0;
		}
	}

	function Purge()
	{
		SqliteDropTableIfExists($this->dbh, $this->tablename);
		$this->InitialiseSchema();
	}

	function UpdateRecord($key, $keyVal, $data)
	{

		//Get keys to specify separately in SQL
		$additionalKeys = array();
		foreach($this->keys as $keyExpected=>$type)
		{
			if(strcmp($keyExpected,$key)==0) continue;
			if(isset($data[$keyExpected]))
			{
				$additionalKeys[$keyExpected] = $data[$keyExpected];
				unset($data[$keyExpected]);
			}
			else $additionalKeys[$keyExpected] = null;
		}

		//Construct SQL
		$sql="UPDATE [".$this->dbh->quote($this->tablename)."] SET value=?";
		$sqlVals = array(serialize($data));
		foreach($additionalKeys as $adKey => $adVal)
		{
			$sql.= ", ".$adKey."=?";
			array_push($sqlVals, $adVal);
		}

		$sql.=" WHERE ".$key."=?;";
		array_push($sqlVals, $keyVal);

		//Execute SQL
		//echo $sql."\n";
		$sth = $this->dbh->prepare($sql);
		if($sth===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$ret = $sth->execute($sqlVals);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		return $sth->rowCount();
	}

	function CreateRecord($key, $keyVal = null, $data=null)
	{

		//Get keys to specify separately in SQL
		$additionalKeys = array();
		foreach($this->keys as $keyExpected=>$type)
		{
			if(isset($data[$keyExpected]))
			{
				$additionalKeys[$keyExpected] = $data[$keyExpected];
				unset($data[$keyExpected]);
			}
			else
				$additionalKeys[$keyExpected] = null;
		}
		$additionalKeys[$key] = $keyVal;

		//Construct SQL
		$sql="INSERT INTO [".$this->dbh->quote($this->tablename)."] (";
		$count = 0;
		$sqlVals = array();
		foreach($additionalKeys as $adKey => $adVal)
		{
			if($count != 0) $sql.=", ";
			$sql .= $adKey;
			$count += 1;
		}
		$sql .= ", value";
		$sql .= ") VALUES (";

		$count = 0;
		foreach($additionalKeys as $adKey => $adVal)
		{
			if($count != 0) $sql.= ", ";
			$sql .= "?";
			array_push($sqlVals, $adVal);
			$count += 1;
		}
		$sql.=", ?";
		//array_push($sqlVals, serialize($data));
		$sql.=");";

		//Execute SQL
		//echo $sql."\n";
		$sth = $this->dbh->prepare($sql);
		if($sth===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		for($i=0;$i<count($sqlVals);$i++)
			$sth->bindParam($i+1, $sqlVals[$i]);
		$blobData = serialize($data);
		$sth->bindParam(count($sqlVals)+1, $blobData, PDO::PARAM_LOB);
		$ret = $sth->execute();
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		if(is_null($keyVal)) return $this->dbh->lastInsertId();
		return $keyVal;
	}

	public function Set($key, $keyVal, $data)
	{
		if($this->useTransactions)
			$this->BeginTransactionIfNotAlready();

		//echo "Attempting update db\n";
		$ret = $this->UpdateRecord($key, $keyVal, $data);
		if($ret===0)
		{
			//echo "ret".$ret."\n";
			//echo "Attempting create record in db\n";
			$createId = $this->CreateRecord($key, $keyVal, $data);
			if(is_null($createId)) 
				throw new Exception ("Failed to create record in database");			
			return $createId;
		}

		if($ret!==1) throw new Exception ("Failed to update record in database");
		return $keyVal;
	}
	
	public function Get($key, $keyVal)
	{
		$query = "SELECT * FROM [".$this->dbh->quote($this->tablename)."] WHERE ".$key."=?;";
		//echo $query."\n";

		$sth = $this->dbh->prepare($query);
		if($sth===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$ret = $sth->execute(array($keyVal));
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		//$numCols = $stmt->columnCount();

		foreach($sth->fetchAll() as $row)
		{
			//print_r($row['value']);echo"\n";
			$record = unserialize($row['value']);
			foreach($this->keys as $exKey => $exType)
			{
				if(isset($row[$exKey]))
					$record[$exKey] = $row[$exKey];
				else
					$record[$exKey] = null;
			}
			//print_r($record);
			return $record;
		}
		return null;
	}

	public function IsRecordSet($key, $keyVal)
	{
		$query = "SELECT COUNT(value) FROM [".$this->dbh->quote($this->tablename)."] WHERE ".$key."=?;";
		//echo $query."\n";
		$sth = $this->dbh->prepare($query);
		if($sth===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$ret = $sth->execute(array($keyVal));
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach($sth->fetchAll() as $row) return($row[0]);
		return 0;
	}

	public function Count()
	{
		$query = "SELECT COUNT(value) FROM [".$this->dbh->quote($this->tablename)."];";
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach($ret as $row) return($row[0]);
		return 0;
	}

	public function Clear($key, $keyVal)
	{
		if($this->useTransactions)
			$this->BeginTransactionIfNotAlready();

		$sql = "DELETE FROM [".$this->dbh->quote($this->tablename)."] WHERE ".$key."=?;";

		//Execute SQL
		//echo $sql."\n";
		$sth = $this->dbh->prepare($sql);
		if($sth===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$ret = $sth->execute(array($key));
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		return $ret;
	}

	//*******************
	//Overload operators
	//*******************

	function offsetExists($name) {
		//echo 'offsetExists'.$name."\n";
		$getkeys = array_keys($this->keys);
		return $this->IsRecordSet($getkeys[0],$name);
	}
	function offsetGet($name) {
		//echo 'offsetGet'.$name."\n";	
		$getkeys = array_keys($this->keys);
		return $this->Get($getkeys[0],$name);
	}
	function offsetSet($name, $id) {
		//echo 'offsetSet'.$name.",".$id."\n";
		$getkeys = array_keys($this->keys);
		return $this->Set($getkeys[0],$name,$id);
	}
	function offsetUnset($name) {
		//echo 'offsetUnset'.$name."\n";
		$getkeys = array_keys($this->keys);
		return $this->Clear($getkeys[0],$name);
	}

	function GetKeys($keyName=null)
	{
		if(is_null($keyName))
		{
			$getkeys = array_keys($this->keys);
			$keyName = $getkeys[0];
		}

		$query = "SELECT ".$keyName." FROM [".$this->dbh->quote($this->tablename)."];";
		//echo $query."\n";
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$out = array();
		foreach($ret as $row)
		{
			array_push($out,$row[$keyName]);
			//print_r($row);
		}
		return $out;
	}
}


//**************************************
//Schema Checking and modification class
//**************************************

class TableSpecSqlite
{
	var $name = null;
	var $cols = array();

	function __construct($name)
	{
		$this->name = $name;
	}

	//Define a table column
	function Add($name, $type, $primaryKey=0, $unique = 0)
	{
		array_push($this->cols,array($name,$type,$primaryKey,$unique));
	}

	function GetColumnDef($col, &$dbh)
	{
		list($name,$type,$primaryKey,$unique) = $col;
		$sqlVals = array();
		$sql = $name." ".$type;
		if ($primaryKey) $sql .= " PRIMARY KEY";
		if ($unique) $sql .= " UNIQUE";
		return array($sql, $sqlVals);
	}

	function CreateTable(&$dbh,$ifNotExists=1)
	{
		if($ifNotExists and $this->TableExists($dbh)) return 0;

		$sql = 'CREATE TABLE "'.$dbh->quote($this->name).'" (';
		$sqlVals = array();
		$count = 0;
		foreach($this->cols as $col)
		{
			if($count > 0) $sql .= ", ";
			list($sqlCode, $sv) = $this->GetColumnDef($col, $dbh);
			$sql .= $sqlCode;
			$sqlVals = array_merge($sqlVals, $sv);
			$count ++;
		}

		$sql .= ');';
		//echo $sql;
		//print_r($sqlVals);
		$sth = $dbh->prepare($sql);
		if($sth===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$ret = $sth->execute($sqlVals);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	}
	
	function DropTable(&$dbh,$ifExists=1,$tableName=null)
	{
		if(is_null($tableName)) $tableName = $this->name;
		if($ifExists and !$this->TableExists($dbh,$tableName)) return 0;

		$sql = 'DROP TABLE ['.$dbh->quote($tableName).'];';
		$sth = $dbh->prepare($sql);
		if($sth===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$ret = $sth->exec(array());
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	}

	function TableExists(&$dbh, $tablename=null)
	{
		//Check if table exists
		if(is_null($tablename)) $tablename = $this->name;
		$sql = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=\"".$dbh->quote($tablename)."\";";
		$sth = $dbh->prepare($sql);
		if($sth===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$ret = $sth->execute(array());
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$tableExists = 0;
		foreach($sth->fetchAll() as $row)
			$tableExists = ($row[0] > 0);
		//echo $tablename."=".$tableExists;
		return $tableExists;
	}

	function SchemaMatches(&$dbh)
	{
		$sql = "PRAGMA table_info(\"".$dbh->quote($this->name)."\");";
		$ret = $dbh->query($sql);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$count =0;
		$differenceFound = 0;
		$existing = array();		

		foreach($ret as $row)
		{
			array_push($existing,$row);
			if((int)$row['cid'] >= count($this->cols)) $differenceFound = 1;
			list($name,$type,$primaryKey,$unique) = $this->cols[(int)$row['cid']];

			//print_r($row);
			if($row['name'] != $name) $differenceFound = 2;
			if($row['type'] != $type) $differenceFound = 3;
			//echo $row['pk']." ".$primaryKey.",";
			if((int)$row['pk'] != ($primaryKey)) $differenceFound = 4;

			$count ++;
		}
		if($count != count($this->cols)) $differenceFound = 5;
	
		if($differenceFound != 0)
		{
			//print_r($differenceFound);
			return 0;
		}
		return 1;
	}

	function MigrateSchemaByAlter(&$dbh)
	{
		//Check schema really is different
		if($this->SchemaMatches($dbh)) return 0;

		//Existing columns must match
		//Return value of -1 means ALTER cannot be used to migrate
		$sql = "PRAGMA table_info(".$dbh->quote($this->name).");";
		$ret = $dbh->query($sql);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$count =0;
		foreach($ret as $row)
		{
			if((int)$row['cid'] >= count($this->cols)) return -1;
			list($name,$type,$primaryKey,$unique) = $this->cols[(int)$row['cid']];
			if($row['name'] != $name) return -1;
			if($row['type'] != $type) return -1;
			if((int)$row['pk'] != $primaryKey) return -1;
			$count ++;
		}

		//Add additional columns
		for($i=$count;$i < count($this->cols);$i++)
		{
			$col = $this->cols[$i];
			$sqlVals = array($this->name);
			list($name,$type,$primaryKey,$unique) = $col;
			if($primaryKey or $unique) return -1; //Cannot add a new indexed column
			list($sqlCode, $sv) = $this->GetColumnDef($col, $dbh);
			$sqlVals = array_merge($sqlVals, $sv);
			$sql = "ALTER TABLE ? ADD COLUMN ".$sqlCode.";";
			//echo $sql;
			$sth = $dbh->prepare($sql);
			if($sth===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
			$ret = $sth->execute($sqlVals);
			if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		}
	
		//Check migration result
		if(!$this->SchemaMatches($dbh)) throw new Exception("Database migration failed");

		return 1;
	}

	function CountExistingRows(&$dbh)
	{
		//Get number of rows
		$query = "SELECT COUNT(*) FROM [".$dbh->quote($this->name)."];";
		$ret = $dbh->query($query);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$numRows = null;
		foreach($ret as $row)
		{
			$numRows = $row[0];
		}
		return $numRows;
	}

	function MigrateSchemaByRecreate(&$dbh)
	{
		//Check schema really is different
		if($this->SchemaMatches($dbh)) return 0;

		//Check temporary table doesn't already exist
		if($this->TableExists($dbh,"migrate_table")) 
			throw new Exception("Migrate table should not already exist");

		$numRowsBefore = $this->CountExistingRows($dbh);

		//Start transaction
		$sql = "BEGIN;";
		$ret = $dbh->exec($sql);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		//Rename table
		$sql = "ALTER TABLE [".$dbh->quote($this->name)."] RENAME TO migrate_table;";
		$ret = $dbh->exec($sql);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		//Recreate empty table
		$this->CreateTable($dbh,0);

		//Copy data from temp to new table
		$query = "SELECT * FROM migrate_table;";
		$ret = $dbh->query($query);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach($ret as $row)
		{
			$sql = "INSERT INTO [".$dbh->quote($this->name)."] (";
			$valueStr = "(";
			$count = 0;
			$sqlVals = array();
			foreach($this->cols as $col)
			{
				list($name,$type,$primaryKey,$unique) = $col;
				if(isset($row[$name]))
				{
					if($count>0) {$sql.=", "; $valueStr.=", ";}
					$sql .= $name;
					$valueStr .= "?";
					array_push($sqlVals, $row[$name]);
					$count ++;
				}
			}
			$sql .= ") VALUES ".$valueStr.");";
			//echo $sql;
			$sth = $dbh->prepare($sql);
			if($sth===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
			$ret = $sth->execute($sqlVals);
			if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		}

		//Drop the old table
		$this->DropTable($dbh,0,"migrate_table");

		//End transaction
		$sql = "END;";
		$ret = $dbh->exec($sql);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		//Check migration result
		if(!$this->SchemaMatches($dbh)) throw new Exception("Database migration failed");

		//Count rows to check migration
		$numRowsAfter = $this->CountExistingRows($dbh);
		if($numRowsBefore != $numRowsAfter)
			throw new Exception("Number of rows did not match ".$numRowsBefore." vs. ".$numRowsAfter);

		return 1;
	}

	function MigrateSchema(&$dbh)
	{
		$ret = $this->MigrateSchemaByAlter($dbh);
		if($ret != -1) return $ret; //Ret value of -1 means "unable"

		//Try recreating table
		return $this->MigrateSchemaByRecreate($dbh);
	}
}

?>
