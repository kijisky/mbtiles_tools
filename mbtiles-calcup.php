<?php

$logfile ="./mbtiles-recalcup.log";

function LogProgress($cnt){
	$quant=100;
	if ( $cnt % $quant == 0){
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
	$stmt = $db->query("select max(zoom_level) from tiles");
	$ans = $stmt->fetch();
	return $ans[0];
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

function  UpdateMetadata($db, $targetLevel){
	$sql = "update metadata set value='$targetLevel' where name='maxzoom'";
	$db->exec($sql);
}

function DoMBTILESCalcUp($fileName, $targetLevel){
	LogMsg("do recalcl: $fileName");
        $db = new PDO("sqlite:".$fileName);          // open mbtiles database
        $db->beginTransaction();                        // StartTransaction - speedUp process

	$currentLevel = GetCurrentLevel($db);
	for($level = $currentLevel; $level<$targetLevel; $level++){
		RiseUpLevel($db, $level);
	}

        UpdateMetadata($db, $targetLevel);              // recalculate map maxZoom
        $db->commit();
}


if (empty($argv)){
	die("use: php  mbtiles-calcup.php targetZoom mbtilesFilename\n");
}
$filesCount = count($argv) - 1;
if ($filesCount < 1){
	die("use: php  mbtiles-calcup.php targetZoom mbtilesFilename\n");
}
$targetLevel = $argv[1];

for ($i=2; $i< $filesCount; $i++){
	$fileName = $argv[$i];
	$logfile = $fileName.".log";

	if (file_exists($fileName)){
		DoMBTILESCalcUp($fileName, $targetLevel);
	} else {
		LogMsg("Can't fond file: $fileName");
	}
}
?>

