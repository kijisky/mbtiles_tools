#!/bin/sh
mainFile=$1

while [ "$2" ]
do

 removedFile=$2  
 echo from: $mainFile  remove: $removedFile
 time php mbtiles_tools/mbtiles-remove.php $mainFile $removedFile
 echo "DELETED, LETS RECALC PYRAMIDS"
 php mbtiles_tools/mbtiles-calc2.php 1 13 $mainFile $removedFile
 shift

done
