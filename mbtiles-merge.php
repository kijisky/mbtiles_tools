<?php

function SelectDups($conn){
	$sql_dups =
        ' select dm.zoom_level as zoom_level, dm.tile_row as tile_row, dm.tile_column as tile_column, '.
	' dm.tile_data as d_main, ds.tile_data as d_src'.
        ' from main.tiles as dm  '.
        ' join src.tiles as ds '.
        ' on(dm.zoom_level=ds.zoom_level and dm.tile_row=ds.tile_row and dm.tile_column=ds.tile_column)'.
	'';
	$stmt_dups = $conn->query($sql_dups);
	return $stmt_dups;
}

function MergeImages($blob_img_main, $blob_img_src){
	$img_main=imagecreatefromstring($blob_img_main);
	$img_src=imagecreatefromstring($blob_img_src);
	$imgW = imagesx($img_main);
        $imgH = imagesy($img_main);
	$ans = imagecreatetruecolor($imgW,  $imgH);

        imagealphablending($ans, true);
	imagealphablending($img_main, true);
	imagealphablending($img_src, true);
        imagesavealpha($ans, true);
	imagesavealpha($img_main, true);
	imagesavealpha($img_src, true);

        $r1 = imagecopy($ans, $img_main, 0,0, 0,0, $imgW, $imgH);
	$r2 = imagecopy($ans, $img_src, 0,0, 0,0, $imgW, $imgH);
	imagedestroy($img_main);
	imagedestroy($img_src);	
	return $ans;
}

function UpdateImage($conn, $id_zoom, $id_row, $id_col, $newImg){
	$sql_upd = "update main.tiles set tile_data = :d where tile_column = $id_col and tile_row = $id_row";
	$stmt = $conn->prepare($sql_upd);

	ob_start();
	imagepng($newImg);
	$imgBlob = ob_get_contents();
	ob_end_clean();
	//imagepng($newImg, "/home/kiji/test2/tmpfile");
	//$imgBlob = file_get_contents("/home/kiji/test2/tmpfile");
	$stmt->bindValue(":d", $imgBlob, PDO::PARAM_LOB);
	$stmt->execute();
}

function ProcessDuplicates($conn){
	$tiles_dups_stmt = SelectDups($conn);
	$dupCount = 0;
	if (!is_object($tiles_dups_stmt)) {
		echo "\n--no duplicates found";
		return;
	}
	while($tile = $tiles_dups_stmt->fetch()){
		$newImg = MergeImages($tile["d_main"], $tile["d_src"]);
		UpdateImage($conn, $tile["zoom_level"], $tile["tile_row"], $tile["tile_column"], $newImg);
		imagedestroy($newImg);
		$dupCount++;
	}
	echo "-- Duplicates mergeed: ".$dupCount."\n";
	$tiles_dups_stmt->closeCursor();
}

function ProcessUnique($conn){
	$sql = "insert or ignore into main.tiles(zoom_level, tile_row, tile_column, tile_data) ".
		"select zoom_level, tile_row, tile_column, tile_data from src.tiles ";
	$stmt = $conn->prepare($sql);
	$stmt->execute();	
}

function UpdateMetadataBounds($conn){
	$main_bounds = $conn->query("select value from main.metadata where name='bounds'")->fetch();
	$src_bounds = $conn->query("select value from src.metadata where name='bounds'")->fetch();
	$main_b = explode(',', $main_bounds[0]);
	$src_b = explode(',', $src_bounds[0]);
	// Calculate new bounds
	$final_b[0] = min($main_b[0] ?:  180, $src_b[0] ?:  180);
	$final_b[1] = min($main_b[1] ?:   90, $src_b[1] ?:   90);
        $final_b[2] = max($main_b[2] ?: -180, $src_b[2] ?: -180);
        $final_b[3] = max($main_b[3] ?:  -90, $src_b[3] ?:  -90);
	$final_bounds = implode(',', $final_b);
	$sql_upd = "update main.metadata set value = '$final_bounds' where name='bounds'";
	$conn->exec($sql_upd);
}

function DoMBTILESMerge($target_file, $src_file){
	$db = new PDO("sqlite:".$target_file);		// open main database
	$db->exec("attach '".$src_file."' as src");	// attach source database

	$db->beginTransaction();			// StartTransaction - speedUp process
	echo "\ndo merge duplicates:...";
	ProcessDuplicates($db);				// Select nd merge tiles exists in both mbtiles
	echo "\ndo merge uniquals:...";
	ProcessUnique($db);				// Add to target TILES, that is not there yet
	echo "\ndo update metadata bounds:...";
	UpdateMetadataBounds($db);			// recalculate map bounds 
	// CreateEmptyTiles($db);			// ?? create empty tiles instead of nonExisting
	$db->commit();
}

function CreateDB($fileName){
	$db = new PDO("sqlite:".$fileName);
	$db->exec('CREATE TABLE metadata (name TEXT, value TEXT)');
	$db->exec('CREATE TABLE tiles (zoom_level INTEGER NOT NULL,tile_column INTEGER NOT NULL,tile_row INTEGER NOT NULL,tile_data BLOB NOT NULL,UNIQUE (zoom_level, tile_column, tile_row))');

	// init metadata
	$db->exec("insert into metadata values('name','$fileName')");
        $db->exec("insert into metadata values('type','overlay')");
        $db->exec("insert into metadata values('description','$fileName')");
        $db->exec("insert into metadata values('version','1.1')");
        $db->exec("insert into metadata values('format','png')");
        $db->exec("insert into metadata values('bounds',',,,')");
        $db->exec("insert into metadata values('minzoom','13')");
        $db->exec("insert into metadata values('maxzoom','13')");
}

function LogMsg($logfile, $msg){
	date_default_timezone_set('Europe/Moscow');
	echo $msg;
        $logMsgFile = date("d-G:i:s")." ]] ".$msg;
        file_put_contents($logfile, $logMsgFile, FILE_APPEND);
}

function ShowUsage(){
	echo "Usage: ";
	echo "\n php mbtiles-merge.php target_mbtiles *source_mbtiles";
	echo "\n\n";
}


if ( !isset($argv) || count($argv) < 2) {
   	ShowUsage();
	die();
}

$filesCount = count($argv) - 2;
$targetDB = $argv[1];
$logfile = $targetDB.".log";

if (!file_exists($targetDB))
	CreateDB($targetDB);




for ($i=0; $i<$filesCount; $i++){
	$sourceDB = $argv[2+$i];
	
	LogMsg($logfile,  "!!! join to '".$targetDB."'  from: ".$sourceDB."\n");

	DoMBTILESMerge($targetDB, $sourceDB);
}

?>
