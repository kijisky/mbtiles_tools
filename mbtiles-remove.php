<?php

function DoMBTILESRemoveAll($target_file, $src_file){
        $db = new PDO("sqlite:".$target_file); 
        $db->exec("attach '".$src_file."' as src"); 
        $db->beginTransaction();    
	$sql = "delete from main.tiles where exists (".
	    "select 1 from src.tiles ".
	    "where zoom_level  =main.tiles.zoom_level".
            " and  tile_row    =main.tiles.tile_row".  
            " and  tile_column =main.tiles.tile_column".  
            ")";
	$isExecOk = $db->exec($sql);
	if (!$isExecOk)
	   print_r($db->errorInfo());
        $db->commit();
}

function DoMBTILESRemoveInner($target_file, $src_file){
	$db_src = new PDO("sqlite:".$src_file);
	$zoomLevel = 13;
	$sqlBounds = "select min(tile_row) as rmin, max(tile_row) as rmax, min(tile_column) as cmin, max(tile_column) as cmax ".
		" from tiles where zoom_level = :zoom ";
	$minmax_stmt = $db_src->prepare($sqlBounds );
	$minmax_stmt->execute(Array(":zoom" => $zoomLevel) );
	$minmax = $minmax_stmt->fetchAll();
	 
	print_r($minmax);

	$db = new PDO("sqlite:".$target_file);
	$sqlRemove = "delete from tiles where zoom_level = :zoom ".
	  " and :rmin < tile_row and tile_row < :rmax ".
          " and :cmin < tile_column and tile_column < :cmax ";
	$binds = Array(
		":zoom" => $zoomLevel,
		":cmin" => $minmax[0]["cmin"],
		":cmax" => $minmax[0]["cmax"],
		":rmin" => $minmax[0]["rmin"],
		":rmax" => $minmax[0]["rmax"]
	);
	$isOk = $db->exec($sqlRemove, $binds);
        if (!$isOk)
           print_r($db->errorInfo());
}

$filesCount = count($argv) - 2;
$targetDB = $argv[1];
$logfile = $targetDB.".log";
if (!file_exists($targetDB))
{
	echo "file not found: $targetDB, file must exist!";
	die("\r\n");
}

if ( $argv[2] == "inner")
{
  for ($i=3; $i<$filesCount+2; $i++){
        $sourceDB = $argv[$i];
        DoMBTILESRemoveInner($targetDB, $sourceDB);
  }
}
else
{
  for ($i=2; $i<$filesCount+2; $i++){ 
       $sourceDB = $argv[$i];  
       DoMBTILESRemoveAll($targetDB, $sourceDB);
  }
}

?>
