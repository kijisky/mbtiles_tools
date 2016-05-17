<?php

$logfile ="./mbtiles-recalcup.log";
$logProgressQuant= 100;

function LogProgress($cnt){
	global $logProgressQuant;
	if ( $cnt % $logProgressQuant == 0){
		echo "..".$cnt;
	} 
}

function LogMsg($msg){
	global $logfile;
        date_default_timezone_set('Europe/Moscow');
        $logMsgFile = date("d-G:i:s")." ]] ".$msg."\n";
	echo $logMsgFile;
        file_put_contents($logfile, $logMsgFile, FILE_APPEND);
}

function GetCurrentLevel($db){
	$stmt = $db->query("select min(zoom_level) as zmin, max(zoom_level) as zmax from tiles");
	$ans = $stmt->fetch();
	return $ans;
}

function Get4DetailedTiles($blobData){
        $img_src=imagecreatefromstring($blobData);
	$imgW = imagesx($img_src);
        $imgH = imagesy($img_src);

	$imgW1 = $imgW / 2;
	$imgH1 = $imgH / 2;

	ImageColorTransparent($img_src, imagecolorallocate($img_src, 0, 0, 0)  );
	imagealphablending($img_src, true);

	$img = Array();
	for ($i=0;$i<4;$i++){
		$img[$i]= imagecreatetruecolor($imgW,  $imgH);
		ImageColorTransparent($img[$i], imagecolorallocatealpha($img[$i], 0, 0, 0, 127)  );
		imagealphablending($img[$i], false);
        	imagesavealpha($img[$i], true);
	}

/* no difference
        imagecopyresampled ($img[2], $img_src, 0, 0,      0 ,      0 , $imgW , $imgH , $imgW1 , $imgH1 );
        imagecopyresampled ($img[3], $img_src, 0, 0, $imgW1 ,      0 , $imgW , $imgH , $imgW1 , $imgH1 );
        imagecopyresampled ($img[0], $img_src, 0, 0,      0 , $imgH1 , $imgW , $imgH , $imgW1 , $imgH1 );
        imagecopyresampled ($img[1], $img_src, 0, 0, $imgW1 , $imgH1 , $imgW , $imgH , $imgW1 , $imgH1 );
//*/
	imagecopyresized ($img[2], $img_src, 0, 0,      0 ,      0 , $imgW , $imgH , $imgW1 , $imgH1 );
        imagecopyresized ($img[3], $img_src, 0, 0, $imgW1 ,      0 , $imgW , $imgH , $imgW1 , $imgH1 );
        imagecopyresized ($img[0], $img_src, 0, 0,      0 , $imgH1 , $imgW , $imgH , $imgW1 , $imgH1 );
        imagecopyresized ($img[1], $img_src, 0, 0, $imgW1 , $imgH1 , $imgW , $imgH , $imgW1 , $imgH1 );

	imagedestroy($img_src);	
	return $img;
}

function GetTilesCount($db, $zoomLevel){
	$sql= "select count(1) from tiles where zoom_level=$zoomLevel";
	$stmt= $db->query($sql);
	$ans = $stmt->fetch();
	return $ans[0];
}

function InsertNewTile($db, $zoomLevel, $rowNum, $colNum, $tileData){
	//LogMsg("debug: InsertNewTile$zoomLevel, $rowNum, $colNum");
        $sql_upd = "insert or replace into tiles (zoom_level, tile_row, tile_column, tile_data) values(:z, :r, :c, :d)";
        $stmt = $db->prepare($sql_upd);

        ob_start();
        imagepng($tileData);
        $imgBlob = ob_get_contents();
        ob_end_clean();
        //imagepng($newImg, "/home/kiji/test2/tmpfile");
        //$imgBlob = file_get_contents("/home/kiji/test2/tmpfile");
	$stmt->bindValue(":z", $zoomLevel);
        $stmt->bindValue(":r", $rowNum);
        $stmt->bindValue(":c", $colNum);
        $stmt->bindValue(":d", $imgBlob, PDO::PARAM_LOB);
        $stmt->execute();
	$rowsount = $stmt->rowCount();
	if ($rowsount==0) LogMsg("can't insert:  $zoomLevel, $rowNum, $colNum");
}


function RiseUpLevel($db, $level){
        $tiles_count = GetTilesCount($db, $level);
        LogMsg("Found tiles: $tiles_count");

	$tiles_stmt = $db->query("select tile_row, tile_column, tile_data from tiles where zoom_level=$level");

        $tilesCount = 0;
        if (!is_object($tiles_stmt)) {
                echo "\n--no tiles found on level $level\n";
                return;
        }

	$newZoomLevel = $level+1;

	LogMsg("rizeUp:  $level => $newZoomLevel");

        while($tile = $tiles_stmt->fetch()){
		$col = $tile["tile_column"];
		LogProgress($tilesCount);
		$row = $tile["tile_row"];

                $newImg = Get4DetailedTiles($tile["tile_data"]);
		InsertNewTile($db, $newZoomLevel, $row*2 + 0, $col*2 + 0, $newImg[0]);
                InsertNewTile($db, $newZoomLevel, $row*2 + 0, $col*2 + 1, $newImg[1]);
                InsertNewTile($db, $newZoomLevel, $row*2 + 1, $col*2 + 0, $newImg[2]);
                InsertNewTile($db, $newZoomLevel, $row*2 + 1, $col*2 + 1, $newImg[3]);

                imagedestroy($newImg[0]);
                imagedestroy($newImg[1]);
                imagedestroy($newImg[2]);
                imagedestroy($newImg[3]);

                $tilesCount++;
        }
	LogMsg("--- ok: $tilesCount tiles");
}

function  GetTilesForLowerLevel($db, $level){
	//$sql = "select tile_row/2 as tile_row, tile_column/2 as tile_column from tiles ".
	//	" where zoom_level=$level and tile_row % 2 = 0 and tile_column % 2  = 0";
	//$stmt = $db->query($sql);
	//$ans = $stmt->fetchAll();

	$sql=" select min(tile_row) as rmin, max(tile_row) as rmax, min(tile_column) as cmin, max(tile_column) as cmax ".
		" from tiles where zoom_level=$level";
	$stmt = $db->query($sql);
	$minmax = $stmt->fetch();
	$stmt->closeCursor();

	$rmin = intval($minmax["rmin"]/2-0.5 );
	$rmax = intval($minmax["rmax"]/2+1 );
        $cmin = intval($minmax["cmin"]/2-0,5 );
        $cmax = intval($minmax["cmax"]/2+1 );
	LogMsg("debug: $rmin - $rmax ; $cmin - $cmax");
	
	$ans = Array();
	$ans["rmin"] = $rmin;
        $ans["rmax"] = $rmax;
        $ans["cmin"] = $cmin;
        $ans["cmax"] = $cmax;
	return $ans;

	for($r = $rmin; $r <$rmax; $r++){
           for($c = $cmin; $c <$cmax; $c++){
		$obj["tile_row"] = $r;
		$obj["tile_column"] = $c;	   
		$ans[]= $obj;
	  }
	}
	return $ans;	
}

function GetTile($db, $level, $rowNum, $colNum){
	//LogMsg("GetTile $level, $rowNum, $colNum");
	$sql = "select tile_data from tiles where zoom_level = :z and tile_column = :c and tile_row = :r";
	$stmt = $db->prepare($sql);
	$stmt->bindValue(":z", $level);
	$stmt->bindValue(":c", $colNum);
	$stmt->bindValue(":r", $rowNum);
	$stmt->execute();
	$ansImg = $stmt->fetch();
	if ($ansImg == null){
	   return null;
	   $ans= imagecreatetruecolor(256,256);
	   imagefill($ans, 1,1, imagecolorallocatealpha($ans, 0, 0, 0, 127));
	   $stmt->closeCursor();
	   return $ans;
	}

	$ans = imagecreatefromstring($ansImg[0]);
	$stmt->closeCursor();
	return $ans;
}

function  GetTilesImg($db, $zoomLevel, $rowNum, $colNum){
	$q_level = $zoomLevel+1;
	$q_rowNum = $rowNum*2;
	$q_colNum = $colNum*2;

	//LogMsg("--- getTiles: $zoomLevel, $rowNum, $colNum -> $q_rowNum, $q_colNum, ");
	$img[0] = GetTile($db, $q_level, $q_rowNum+0, $q_colNum+0);
        $img[1] = GetTile($db, $q_level, $q_rowNum+0, $q_colNum+1);
        $img[2] = GetTile($db, $q_level, $q_rowNum+1, $q_colNum+0);
        $img[3] = GetTile($db, $q_level, $q_rowNum+1, $q_colNum+1);
	
	return $img;
}

function Merge4Tiles($img){
	$tmpltImg = null;
        if ($img[0] != null) $tmpltImg = $img[0];
	else if ($img[1] != null) $tmpltImg = $img[1];
        else if ($img[2] != null) $tmpltImg = $img[2];
        else if ($img[3] != null) $tmpltImg = $img[3];
	
	if ($tmpltImg == null){
		$ans= imagecreatetruecolor(256,256);
		ImageColorTransparent($ans, imagecolorallocatealpha($ans, 0, 0, 0, 127)  );
        	imagealphablending($ans, false);
        	imagesavealpha($ans, true);
        	imagefill($ans, 0,0, imagecolorallocatealpha($ans, 0, 0, 0, 127)  );
		return $ans;
	}	

	$imgW = imagesx($tmpltImg);
	$imgH = imagesy($tmpltImg);

        $imgW1 = $imgW / 2;
        $imgH1 = $imgH / 2;

        $ans= imagecreatetruecolor($imgW,  $imgH);
        ImageColorTransparent($ans, imagecolorallocatealpha($ans, 0, 0, 0, 127)  );
        imagealphablending($ans, false);
        imagesavealpha($ans, true);

	imagefill($ans, 0,0, imagecolorallocatealpha($ans, 0, 0, 0, 127)  );

        if ($img[2] != null)
	  imagecopyresized ($ans, $img[2],      0 ,      0 , 0 , 0 , $imgW1 , $imgH1 , $imgW , $imgH );
        if ($img[3] != null)
          imagecopyresized ($ans, $img[3], $imgW1 ,      0 , 0 , 0 , $imgW1 , $imgH1 , $imgW , $imgH );
        if ($img[0] != null)
          imagecopyresized ($ans, $img[0],      0 , $imgH1 , 0 , 0 , $imgW1 , $imgH1 , $imgW , $imgH );
        if ($img[1] != null)
          imagecopyresized ($ans, $img[1], $imgW1 , $imgH1 , 0 , 0 , $imgW1 , $imgH1 , $imgW , $imgH );
	return $ans;
}

function DestroyImages($srcTiles){
       if ($srcTiles[0] != null) imagedestroy($srcTiles[0]);
       if ($srcTiles[1] != null) imagedestroy($srcTiles[1]);
       if ($srcTiles[2] != null) imagedestroy($srcTiles[2]);
       if ($srcTiles[3] != null) imagedestroy($srcTiles[3]);
}

function CalcDownLevel($db, $level){
	global $logProgressQuant;
	$tgtLevel = $level-1;
	$tminmax = GetTilesForLowerLevel($db, $level);
	$tilesTotal = ($tminmax["rmax"]- $tminmax["rmin"]) * ($tminmax["cmax"]- $tminmax["cmin"]);
	if ($tilesTotal > 100000 ) $logProgressQuant = 1000;
        if ($tilesTotal > 1000000) $logProgressQuant = 10000;

	LogMsg("calc: $level -> $tgtLevel, tiles: ".$tilesTotal."  progressReport every: ".$logProgressQuant  );
	$cntTiles = 0;
	for ($r = $tminmax["rmin"]; $r <=  $tminmax["rmax"];  $r++)
            for ($c = $tminmax["cmin"]; $c <=  $tminmax["cmax"];  $c++)
	    {
		$rowNum = $r;
		$colNum = $c;
		$srcTiles = GetTilesImg($db, $tgtLevel, $rowNum, $colNum);
		$tileData = Merge4Tiles($srcTiles);
		DestroyImages($srcTiles);

		InsertNewTile($db, $tgtLevel, $rowNum, $colNum, $tileData);
		LogProgress($cntTiles);		
		$cntTiles++;
	   }	
}

function  UpdateMetadata($db, $maxmin, $targetLevel){
	$sql = "update metadata set value='$targetLevel' where name='$maxmin'";
	$db->exec($sql);
}

function DoMBTILESCalcUp($db, $currentLevel, $targetLevel){
	for($level = $currentLevel; $level<$targetLevel; $level++){
		RiseUpLevel($db, $level);
	}
        UpdateMetadata($db, 'maxzoom', $targetLevel);              // recalculate map maxZoom
}

function DoMBTILESCalcDown($db, $currentLevel, $targetLevel){
        for($level = $currentLevel; $level>$targetLevel; $level--){
                CalcDownLevel($db, $level);
        }
        UpdateMetadata($db, 'minzoom', $targetLevel);              // recalculate map maxZoom
}

function DoMBTILESCalc($fileName, $targetLevel, $baseLevel = null){
        LogMsg("do recalcl: $fileName");
        $db = new PDO("sqlite:".$fileName);          // open mbtiles database
        $db->beginTransaction();                        // StartTransaction - speedUp process

	if ($baseLevel == null){
		$currentLevels = GetCurrentLevel($db);
		$zmin = $currentLevels["zmin"];
        	$zmax = $currentLevels["zmax"];
	} else {
		$zmin = $baseLevel;
		$zmax = $baseLevel;
	}
	LogMsg("levels: $zmin - $zmax, target:$targetLevel");
	if ($targetLevel > $zmax){
		DoMBTILESCalcUp($db, $zmax, $targetLevel);
	}
        if ($targetLevel < $zmin){
                DoMBTILESCalcDown($db, $zmin, $targetLevel);
        }


        $db->commit();
}


if (empty($argv)){
        die("use: php  mbtiles-calcup.php targetZoom sourceZoomLevel mbtilesFilename\n");
}
$filesCount = count($argv) - 1;
if ($filesCount < 1){
        die("use: php  mbtiles-calcup.php targetZoom sourceZoomLevel mbtilesFilename\n");
}
$targetLevel = $argv[1];
$baseLevel = $argv[2];

for ($i=3; $i<= $filesCount; $i++){
	$fileName = $argv[$i];
	$logfile = $fileName.".log";

	if (file_exists($fileName)){
		DoMBTILESCalc($fileName, $targetLevel, $baseLevel);
	} else {
		LogMsg("Can't fond file: $fileName");
	}
}
?>

