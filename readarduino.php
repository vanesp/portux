<?php

// <copyright> Copyright (c) 2012 All Rights Reserved,
// Escurio
// http://www.escurio.com/
//
// THIS CODE AND INFORMATION ARE PROVIDED "AS IS" WITHOUT WARRANTY OF ANY 
// KIND, EITHER EXPRESSED OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
// IMPLIED WARRANTIES OF MERCHANTABILITY AND/OR FITNESS FOR A
// PARTICULAR PURPOSE.
//
// </copyright>
// <author>Peter van Es</author>
// <version>1.0</version>
// <email>vanesp@escurio.com</email>
// <date>2012-7-11</date>
// <summary>readarduino receives text from an Arduino and stores records in the database</summary>

// version 1.0

// set some variables
$HOST = "192.168.1.1";
$DATABASE = "mydb";
$DBUSER = "esuser";
$DBPASS = "Vecht18Watch!";
$LOGFILE = "sensor.log";		// log history of actions

$device = "/dev/ttyS1";
$LEN = 128;						// records are max 128 bytes

date_default_timezone_set('Europe/Amsterdam');

// DEBUG
$debug = true;

// don't timeout!
set_time_limit(0);

// function to open the database
function opendb () {
    global $HOST, $DBUSER, $DBPASS, $DATABASE, $LOGFILE;
    $link = false;
    // Open the database
    // Open the database connection
    $link = mysql_connect($HOST, $DBUSER, $DBPASS);
    if (!$link) {
 	    $message = date('Y-m-d H:i') . " Database connection failed " . mysql_error($link) . "\n";
	    error_log($message, 3, $LOGFILE);
    }

    // See if we can open the database
    $db = mysql_select_db ($DATABASE, $link);
    if (!$db) {
    	$message = date('Y-m-d H:i') . " Failed to open $DATABASE " . mysql_error($link) . "\n";
    	error_log($message, 3, $LOGFILE);
    	$link = false;
    }
    return $link;
}



// set to the correct serial parameters
exec('stty -F '.$device.' 57600');

// open the serial port (later need to set settings)
$handle = fopen ($device, "r");
if($handle === FALSE) {
	$message = date('Y-m-d H:i') . " Failed to open device " .$device. "\n";
	error_log($message, 3, $LOGFILE);
}

if ($debug) echo "Ready to receive...\n";
while (($buf = fgets($handle, $LEN)) !== false) {
	// process another line
	if ($debug) echo $buf;
	if (strstr($buf, "[")) {
		// skip this line, it tells us the program version
	};
	if (strstr($buf, " A")) {
		// skip this line, it indicates startup of communication
	};
	if (strstr($buf, "RNR")) {
		// ok, this is a data line... now parse the content
		// it will be:
		// 0 - header (RNR)
		// 1 - idroom
		// 2 - lightlevel, if 0, then we had a PIR move
		// 3 - PIR status
		// 4 - humidity
		// 5 - temp
		// 6 - lobat
		$field = explode (" ", $buf);
		if ($debug) print_r ($field);
		
		// Open the database
		$link = opendb();
		if (!$link) {
			$message = date('Y-m-d H:i') . " Cannot open database " . mysql_error($link) . "\n";
			error_log($message, 3, $LOGFILE);
		} else {
			$query = "SELECT id FROM Sensor WHERE idroom='" . $field[1] . "'";
			if ($debug) echo "Query ", $query, "\n";
			if (($result = mysql_query ($query, $link))===false) {
				$message = date('Y-m-d H:i') . " Could not read Sensor " . mysql_error($link) . "\n";
				error_log($message, 3, $LOGFILE);
			}
	
			$numrows = mysql_num_rows($result);
			if ($numrows < 1) {
				$message = date('Y-m-d H:i') . " idroom not found " . $fields[1] . "\n";
				error_log($message, 3, $LOGFILE);
			} else {
				// note - use explicit variable names to protect against database changes
				$Record = mysql_fetch_array($result, MYSQL_ASSOC);
				$id = $Record['id'];
				$field[5] = $field[5]/10;	// divide temperature by 10
				$field[2] = round($field[2]/2.55, 0);	// scale light on range 0..100
				if ($field[3] != '0') {
					// it is a PIR alert
					$insertq = "INSERT INTO Motionlog SET pid='".$id."', tstamp=UNIX_TIMESTAMP(NOW()), movement='1'";
				} else {
					// regular data update 	
					$insertq = "INSERT INTO Roomlog SET pid='".$id."', tstamp=UNIX_TIMESTAMP(NOW()), light='".$field[2]."', humidity='".$field[4]."', temp='".$field[5]."'";
				}
				if ($debug) echo $insertq, "\n";
				if (($res = mysql_query ($insertq, $link))===false) {
					$message = date('Y-m-d H:i') . " Could not insert event " . mysql_error($link) . "\n";
					error_log($message, 3, $LOGFILE);
				}
				// now update the sensor timestamp and battery status
				$updateq = "UPDATE Sensor SET tstamp=UNIX_TIMESTAMP(NOW()), lobatt='".$field[6] ."' WHERE id='".$id."'";
				if ($debug) echo $updateq, "\n";
				if (($res = mysql_query ($updateq, $link))===false) {
					$message = date('Y-m-d H:i') . " Could not update Sensor " . mysql_error($link) . "\n";
					error_log($message, 3, $LOGFILE);
				}
				mysql_free_result ($result);	// result
				mysql_close ($link);
			} // open db
		} // if RNR
	};
}
if (!feof($handle)) {
	$message = date('Y-m-d H:i') . " fgets failed\n";
	error_log($message, 3, $LOGFILE);
}

fclose($handle);
?>


