<?php
// Git version

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

// Open the database
$link = opendb();
if (!$link) {
	$message = date('Y-m-d H:i') . " Cannot open database " . mysql_error($link) . "\n";
	error_log($message, 3, $LOGFILE);
	exit (1);
}

if ($debug) echo "Ready to receive...\n";
while (($buf = fgets($handle, $LEN)) !== false) {
	// process another line
	if ($debug) echo $buf;
	if (strpos($buf, "[")) {
		// skip this line, it tells us the program version
		continue;
	};
	if (strpos($buf, " A")) {
		// skip this line, it indicates startup of communication
		continue;
	};
	if (strpos($buf, "ELEC") === 0) {
		// generic electricity sender
		$field = explode (" ", $buf);
		if ($debug) print_r ($field);
		// field 0 = ELEC
		// field 1 = instantaneous power
		// field 2 = pulse count since last time
		$query = "SELECT id, sensorscale FROM Sensor WHERE sensortype='Electricity'";
		// there should be only one...
		if ($debug) echo "Query ", $query, "\n";
		if (($result = mysql_query ($query, $link))===false) {
			$message = date('Y-m-d H:i') . " Could not read Sensor " . mysql_error($link) . "\n";
			error_log($message, 3, $LOGFILE);
		}
		$numrows = mysql_num_rows($result);
		if ($numrows < 1) {
			$message = date('Y-m-d H:i') . " Electricity sensor not found " . $id . "\n";
			error_log($message, 3, $LOGFILE);
		} else {
			// decode message depending on sensortype
			$Record = mysql_fetch_array($result, MYSQL_ASSOC);
			$id = $Record['id'];
			$scale = $Record['sensorscale'];
			$power = $field[1] * $scale;
			$pulse = $field[2];
			$insertq = "INSERT INTO Sensorlog SET pid='".$id."', tstamp=UNIX_TIMESTAMP(NOW()), value='".$power."', count='".$pulse."'";
			if ($debug) echo $insertq, "\n";
			if (($res = mysql_query ($insertq, $link))===false) {
				$message = date('Y-m-d H:i') . " Could not insert event " . mysql_error($link) . "\n";
				error_log($message, 3, $LOGFILE);
			}
			// now update the sensor timestamp and battery status
			$updateq = "UPDATE Sensor SET tstamp=UNIX_TIMESTAMP(NOW()), lobatt=0, cum_elec_pulse=cum_elec_pulse+".$pulse." WHERE id='".$id."'";
			if ($debug) echo $updateq, "\n";
			if (($res = mysql_query ($updateq, $link))===false) {
				$message = date('Y-m-d H:i') . " Could not update Sensor " . mysql_error($link) . "\n";
				error_log($message, 3, $LOGFILE);
			}
		}
	} // if ELEC
	if (strpos($buf, "TMP") === 0) {
		// generic temperature sender
		$field = explode (" ", $buf);
		if ($debug) print_r ($field);
		// get the room id to select the sensor type from the database
		// field 0 = TMP
		// field 1 = sensor id
		// field 2 = temperature (integer)
		$id = $field[1];		// id
		$query = "SELECT id, sensortype, sensorscale FROM Sensor WHERE idsensor='" . $id . "'";
		if ($debug) echo "Query ", $query, "\n";
		if (($result = mysql_query ($query, $link))===false) {
			$message = date('Y-m-d H:i') . " Could not read Sensor " . mysql_error($link) . "\n";
			error_log($message, 3, $LOGFILE);
		}
		$numrows = mysql_num_rows($result);
		if ($numrows < 1) {
			$message = date('Y-m-d H:i') . " sensor id not found " . $id . "\n";
			error_log($message, 3, $LOGFILE);
		} else {
			// decode message depending on sensortype
			$Record = mysql_fetch_array($result, MYSQL_ASSOC);
			$id = $Record['id'];
			$type = $Record['sensortype'];
			$scale = $Record['sensorscale'];
			if (strstr($type, "Temperature")) {
				// TMP content
				// 1 - sensor id (from database)
				// 2 - temperature as integer
				$temp = $field[2] * $scale;
				$insertq = "INSERT INTO Sensorlog SET pid='".$id."', tstamp=UNIX_TIMESTAMP(NOW()), value='".$temp."'";
				if ($debug) echo $insertq, "\n";
				if (($res = mysql_query ($insertq, $link))===false) {
					$message = date('Y-m-d H:i') . " Could not insert event " . mysql_error($link) . "\n";
					error_log($message, 3, $LOGFILE);
				}
				// now update the sensor timestamp and battery status
				$updateq = "UPDATE Sensor SET tstamp=UNIX_TIMESTAMP(NOW()), lobatt=0 WHERE id='".$id."'";
				if ($debug) echo $updateq, "\n";
				if (($res = mysql_query ($updateq, $link))===false) {
					$message = date('Y-m-d H:i') . " Could not update Sensor " . mysql_error($link) . "\n";
					error_log($message, 3, $LOGFILE);
				}
			} // if Temperature
		}
	} // if TMP

	if (strpos($buf, "GNR") === 0) {
		// generic node receiver
		$field = explode (" ", $buf);
		if ($debug) print_r ($field);
		// get the room id to select the sensor type from the database
		// field 0 = GNR
		// field 1 = header
		$roomid = $field[1] & 0x1F;		// node from the header
		
		$query = "SELECT id, sensortype FROM Sensor WHERE idroom='" . $roomid . "'";
		if ($debug) echo "Query ", $query, "\n";
		if (($result = mysql_query ($query, $link))===false) {
			$message = date('Y-m-d H:i') . " Could not read Sensor " . mysql_error($link) . "\n";
			error_log($message, 3, $LOGFILE);
		}

		$numrows = mysql_num_rows($result);
		if ($numrows < 1) {
			$message = date('Y-m-d H:i') . " idroom not found " . $roomid . "\n";
			error_log($message, 3, $LOGFILE);
		} else {
			// decode message depending on sensortype
			$Record = mysql_fetch_array($result, MYSQL_ASSOC);
			$id = $Record['id'];
			$type = $Record['sensortype'];
			if (strstr($type, "RNR")) {
				// RNR content
				// it will be:
				// 1 - header (with idroom in it - if ack is set, then we have a PIR trigger
				// 2 - lightlevel
				// 3 - moved
				// 4 - humidity
				// 5 & 6 - signed integer value, temp
				// 7 - lobat
				// Room node. PIR is on if ACK is set
				$pir = (($field[1] & 0x20) == 0x20); 							
				$light = round ($field[2] / 2.55, 0);		// lightness 0..100%
				$moved = $field[3] & 0x01;
				$humid = $field[4] & 0x7F;					// bottom 7 bits only
				// temperature is two values, little endian, so least significant byte first
				// do this to preserve signs
				$binarydata = pack("C2", $field[5], $field[6]);
				$out = unpack("sshort/", $binarydata);
				$t1 = $out['short'];
				// $t1 = $field[5] + $field[6] * 256;
				$temp = $t1 / 10;		// divide by 10 
				$lobat = $field[7] & 0x01;
				if ($pir) {
					// it is a PIR alert
					$insertq = "INSERT INTO Motionlog SET pid='".$id."', tstamp=UNIX_TIMESTAMP(NOW()), movement='1'";
				} else {
					// regular data update 	
					$insertq = "INSERT INTO Roomlog SET pid='".$id."', tstamp=UNIX_TIMESTAMP(NOW()), light='".$light."', humidity='".$humid."', temp='".$temp."'";
				}
				if ($debug) echo $insertq, "\n";
				if (($res = mysql_query ($insertq, $link))===false) {
					$message = date('Y-m-d H:i') . " Could not insert event " . mysql_error($link) . "\n";
					error_log($message, 3, $LOGFILE);
				}
				// now update the sensor timestamp and battery status
				$updateq = "UPDATE Sensor SET tstamp=UNIX_TIMESTAMP(NOW()), lobatt='".$lobat."' WHERE id='".$id."'";
				if ($debug) echo $updateq, "\n";
				if (($res = mysql_query ($updateq, $link))===false) {
					$message = date('Y-m-d H:i') . " Could not update Sensor " . mysql_error($link) . "\n";
					error_log($message, 3, $LOGFILE);
				}
			} // if RNR
		} // if numrows
	} // if GNR
} // while

mysql_free_result ($result);	// result
mysql_close ($link);

if (!feof($handle)) {
	$message = date('Y-m-d H:i') . " fgets failed\n";
	error_log($message, 3, $LOGFILE);
}

fclose($handle);
?>
