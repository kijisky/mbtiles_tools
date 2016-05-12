<?php

$filesCount = count($argv);
for ($i=1; $i<$filesCount; $i++){
	$fileName = $argv[$i];
 	$db = new PDO("sqlite:".$fileName);
	$stmt = $db->query('select max(zoom_level) from tiles');
	$row = $stmt->fetch();
	$level = $row[0];
	if ($level < 13) {
		echo "\n file:".$fileName." ]] level: ".$level;
	}
//	if ($i % 100 == 0) 		echo "\n --- ".$i;
}

echo "\n";
?>
