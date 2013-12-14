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
// <version>1.4</version>
// <email>vanesp@escurio.com</email>
// <date>2013-12-08</date>
// <summary>consume reads records from the local database and stores them remotely</summary>

// Everytime consume runs, it reads all the records from the local database,
// interprets them, and stores them in the remote database
// when done, it deletes the records from the local database
// in a two step process

// version 1.0

// version 1.1 - publication to a Redis database of up-to-date information is performed.

// version 1.2 - much more resilience if redis is not there, and does not publish data
//               if its playing catch-up

// version 1.3 - stop filling the sensor log if not fatal

// version 1.4 - use Redis Set mechanism instead of pub/sub for the measurement values

// data for Cosm updates
include('PachubeAPI.php');
include('pachubeaccess.php');

// access information
include('access.php');
$LOGFILE = "sensor.log";		// log history of actions
$LEN = 128;						// records are max 128 bytes


// include Redis pub sub functionality
include('redis.php');

// mapping of details to feed names for simple, one value sensors
$map = array(
	'TMP' => 'Temperature',
	'HUMI' => 'Humidity',
	'LIGHT' => 'Light',
	'MOVE' => 'Motion',
	'BAR' => 'Pressure',
	'RAIN' => 'Rainfall',
	'WSPD' => 'Windspeed',
	);

date_default_timezone_set('Europe/Amsterdam');

// DEBUG and other flags
$debug = false;
$publish = true;       // publish data? (Pachube/Redis)
$pubredis = true;      // publish redis?

// don't timeout!
set_time_limit(0);

// function to open the database
function open_remote_db () {
    global $RHOST, $RDBUSER, $RDBPASS, $RDATABASE, $LOGFILE;
    $remote = false;
    // Open the database
    // Open the database connection
    $remote = mysql_connect($RHOST, $RDBUSER, $RDBPASS);
    if (!$remote) {
 	    $message = date('Y-m-d H:i') . " Consume: Remote database connection failed " . mysql_error($remote) . "\n";
	    error_log($message, 3, $LOGFILE);
    }

    // See if we can open the database
    $db_r = mysql_select_db ($RDATABASE, $remote);
    if (!$db_r) {
    	$message = date('Y-m-d H:i') . " Consume: Failed to open $RDATABASE " . mysql_error($remote) . "\n";
    	error_log($message, 3, $LOGFILE);
    	$remote = false;
    }
    return $remote;
}

function open_local_db () {
    global $LHOST, $LDBUSER, $LDBPASS, $LDATABASE, $LOGFILE;
    $local = false;
    // Open the database
    // Open the database connection
    $local = mysql_connect($LHOST, $LDBUSER, $LDBPASS);
    if (!$local) {
 	    $message = date('Y-m-d H:i') . " Consume: Local database connection failed " . mysql_error($local) . "\n";
	    error_log($message, 3, $LOGFILE);
    }

    // See if we can open the database
    $db_l = mysql_select_db ($LDATABASE, $local);
    if (!$db_l) {
    	$message = date('Y-m-d H:i') . " Consume: Failed to open $LDATABASE " . mysql_error($local) . "\n";
    	error_log($message, 3, $LOGFILE);
    	$local = false;
    }
    return $local;
}


// Open the database
$remote = open_remote_db();
if (!$remote) {
	$message = date('Y-m-d H:i') . " Consume: Cannot open remote database " . mysql_error($remote) . "\n";
	error_log($message, 3, $LOGFILE);
	exit (1);
}

$local = open_local_db();
if (!$local) {
	$message = date('Y-m-d H:i') . " Consume: Cannot open local database " . mysql_error($local) . "\n";
	error_log($message, 3, $LOGFILE);
	exit (1);
}

// retrieve new, unprocessed records
$lq = "SELECT * FROM rcvlog WHERE bP='0' ORDER BY ts";
if ($debug) echo "Query ", $lq, "\n";
if (($locres = mysql_query ($lq, $local))===false) {
	$message = date('Y-m-d H:i') . " Consume: Could not read rcvlog " . mysql_error($local) . "\n";
	error_log($message, 3, $LOGFILE);
}

if (!$redis->isConnected()) {
    try {
        $redis->connect();
    }
    catch (Exception $e) {
        $pubredis = false;
        // don't bother to log it...
        // $message = date('Y-m-d H:i') . " Cannot connect to Redis " . $e->getMessage() . "\n";
        // error_log($message, 3, $LOGFILE);
    }
}

$numrows = mysql_num_rows($locres);
if ($numrows > 20) {                // if this is the case, we are in recovery
    $publish = false;
}
while ($numrows > 0) {
	$numrows--;
	$localrec = mysql_fetch_array($locres, MYSQL_ASSOC);
	$localid = $localrec['id'];
	$buf = $localrec['s'];
	$ts = $localrec['ts'];

	// create the local update query
	$lupdq = "UPDATE rcvlog SET bP=1 WHERE id='".$localid."'";
	// flag to see if we need to handle processed
	$upd_done = false;	

	// process another line
	if ($debug) echo $buf;
	
	$field = explode(" ",$buf);
	if ($debug) print_r ($field);
	// see if field[0] is in our map of simple sensors
	if (array_key_exists($field[0], $map)) {
		// it is a simple array, the sensor type is $map[$field[0])
		// generic sensor  sender
		// get the sensor id and the type to select the sensor from the database
		// field 1 = sensor id
		// field 2 = value (integer)
		$id = $field[1];		// id
		$sensortype = $map[$field[0]];
		$query = "SELECT id, sensortype, sensorscale, datastream, sensorquantity, location FROM Sensor WHERE idsensor='" . $id . "' AND sensortype='".$sensortype."'";
		if ($debug) echo "Query ", $query, "\n";
		if (($result = mysql_query ($query, $remote))===false) {
			$message = date('Y-m-d H:i') . " Could not read Sensor " . mysql_error($remote) . "\n";
			error_log($message, 3, $LOGFILE);
		}
		$nr = mysql_num_rows($result);
		if ($nr < 1) {
			$message = date('Y-m-d H:i') . " sensor id not found " . $id . "\n";
			if ($debug) error_log($message, 3, $LOGFILE);
		} else {
			// decode message depending on sensortype
			$Record = mysql_fetch_array($result, MYSQL_ASSOC);
			$id = $Record['id'];
			$scale = $Record['sensorscale'];
			// get Pachube value
			$datastream = $Record['datastream'];
			$location = $Record['location'];
			$quantity = $Record['sensorquantity'];
			$value = $field[2] * $scale;
			// if it's temperature then we have special processing for IT+ sensors
			if (strstr($sensortype, "Temperature")) {
				// if $field[2] > 1280 then negative temperature... but only for IT+ sensors
				// the scale factor ensures this
				if ($field[2] > 128/$scale) {
					$value = (128/$scale - $field[2]) * $scale;
				} else {
					$value = $field[2] * $scale;
				}
				// somehow quantity does not come out of database correctly for temperature, so overrride
				$quantity = '°C';
			}
			$insertq = "INSERT INTO Sensorlog SET pid='".$id."', tstamp=UNIX_TIMESTAMP('".$ts."'), value='".$value."'";
			if ($debug) echo $insertq, "\n";
			if (($res = mysql_query ($insertq, $remote))===false) {
				$message = date('Y-m-d H:i') . " Consume: Could not insert event " . mysql_error($remote) . "\n";
				error_log($message, 3, $LOGFILE);
                                if (mysql_errno($remote) === 1062) {
                                        // it is a Duplicate Key message... delete the record anyway by setting $upd_done to true
                                        $upd_done = true;
                                } 
                                } else {
				        $upd_done = true;	
				}
				// now update the sensor timestamp and battery status
				$updateq = "UPDATE Sensor SET tstamp=UNIX_TIMESTAMP('".$ts."'), lobatt=0 WHERE id='".$id."'";
				if ($debug) echo $updateq, "\n";
				if (($res = mysql_query ($updateq, $remote))===false) {
				        $message = date('Y-m-d H:i') . " Consume: Could not update Sensor " . mysql_error($remote) . "\n";
				        error_log($message, 3, $LOGFILE);
                                }
                                if ($publish) {
                                        if ($pubredis) {
                                                // update Redis using socketstream message
                                                $msg = new PubMessage;
                                                $msg->setParams($sensortype, $location, $quantity, $value);
                                                if ($debug) echo "Redis publishing ".$sensortype." ".$location.": ".$value."\n";
                                                try {
                                                        // update Redis using Set
                                        			    $key = $location.":".$sensortype;
                                                        $redis->set ($key, $value); 
                                                        // $redis->publish('ss:event', json_encode($msg));
                                                }
                                                catch (Exception $e) {
                                                        $message = date('Y-m-d H:i') . " Consume: Cannot publish to Redis " . $e->getMessage() . "\n";
                                                        if ($debug) error_log($message, 3, $LOGFILE);
                                                }
                                        }
                                        // and update Pachube / Cosm
                                        $data = '"' . $value . '"';
                                        if ($datastream != '') {
                                                $result = $pachube->updateDataStream("csv", $feed, $datastream, $data);
                                        }
                                }
                        }
	} // if TMP

	if (strpos($buf, "ELEC") === 0) {
		// generic electricity sender
		// field 0 = ELEC
		// field 1 = instantaneous power
		// field 2 = pulse count since last time
		$query = "SELECT id, sensortype, sensorscale, datastream, location FROM Sensor WHERE sensortype='Electricity'";
		// there should be only one...
		if ($debug) echo "Query ", $query, "\n";
		if (($result = mysql_query ($query, $remote))===false) {
			$message = date('Y-m-d H:i') . " Consume: Could not read Sensor " . mysql_error($remote) . "\n";
			error_log($message, 3, $LOGFILE);
		}
		$nr = mysql_num_rows($result);
		if ($nr < 1) {
			$message = date('Y-m-d H:i') . " Consume: Electricity sensor not found " . $id . "\n";
			error_log($message, 3, $LOGFILE);
		} else {
			// decode message depending on sensortype
			$Record = mysql_fetch_array($result, MYSQL_ASSOC);
			$id = $Record['id'];
			$scale = $Record['sensorscale'];
			$location = $Record['location'];
			$sensortype = $Record['sensortype'];
			// create the channel name for Pub/Sub
			$channel = 'portux.'.$sensortype.'.'.$location;
			// get Pachube value
			$datastream = $Record['datastream'];
			$power = $field[1] * $scale;
			$pulse = $field[2];
			$insertq = "INSERT INTO Sensorlog SET pid='".$id."', tstamp=UNIX_TIMESTAMP('".$ts."'), value='".$power."', count='".$pulse."'";
			if ($debug) echo $insertq, "\n";
			if (($res = mysql_query ($insertq, $remote))===false) {
				$message = date('Y-m-d H:i') . " Consume: Could not insert event " . mysql_error($remote) . "\n";
				error_log($message, 3, $LOGFILE);
				if (mysql_errno($remote) === 1062) {
				        // it is a Duplicate Key message... delete the record anyway by setting $upd_done to true
				        $upd_done = true;
                                } 
			} else {
				$upd_done = true;	
			}
			// now update the sensor timestamp and battery status
			$updateq = "UPDATE Sensor SET tstamp=UNIX_TIMESTAMP('".$ts."'), lobatt=0, cum_elec_pulse=cum_elec_pulse+".$pulse." WHERE id='".$id."'";
			if ($debug) echo $updateq, "\n";
			if (($res = mysql_query ($updateq, $remote))===false) {
				$message = date('Y-m-d H:i') . " Consume: Could not update Sensor " . mysql_error($remote) . "\n";
				error_log($message, 3, $LOGFILE);
			}
			if ($publish) {
			    if ($pubredis) {
			            // update Redis using socketstream message
			            $msg = new PubMessage;
			            $msg->setParams($sensortype, $location, 'W', $power);
			            if ($debug) echo "Redis publishing ".$sensortype." ".$location.": ".$power."\n";
			            try {
			                        // update Redis using Set
                    			    $key = $location.":".$sensortype;
                                    $redis->set ($key, $power); 
                                    // $redis->publish('ss:event', json_encode($msg));
                                    }
                                    catch (Exception $e) {
                                            $message = date('Y-m-d H:i') . " Consume: Cannot publish to Redis " . $e->getMessage() . "\n";
                                            if ($debug) error_log($message, 3, $LOGFILE);
                                    }
                            }
                            // and update Pachube / Cosm
                            $data = '"' . $power . '"';
                            if ($datastream != '') {
                                    $result = $pachube->updateDataStream("csv", $feed, $datastream, $data);
                            }
                         }
		}
	} // if ELEC

	if (strpos($buf, "GNR") === 0) {
		// generic node receiver
		// get the room id to select the sensor type from the database
		// field 0 = GNR
		// field 1 = header
		$roomid = $field[1] & 0x1F;		// node from the header
		
		$query = "SELECT id, sensortype, datastream, location FROM Sensor WHERE idroom='" . $roomid . "'";
		if ($debug) echo "Query ", $query, "\n";
		if (($result = mysql_query ($query, $remote))===false) {
			$message = date('Y-m-d H:i') . " Could not read Sensor " . mysql_error($remote) . "\n";
			error_log($message, 3, $LOGFILE);
		}

		$nr = mysql_num_rows($result);
		if ($nr < 1) {
			$message = date('Y-m-d H:i') . " Consume: idroom not found " . $roomid . "\n";
			error_log($message, 3, $LOGFILE);
		} else {
			// decode message depending on sensortype
			$Record = mysql_fetch_array($result, MYSQL_ASSOC);
			$id = $Record['id'];
			$type = $Record['sensortype'];
			$location = $Record['location'];
			// get Pachube value
			$datastream = $Record['datastream'];
			
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
					$insertq = "INSERT INTO Motionlog SET pid='".$id."', tstamp=UNIX_TIMESTAMP('".$ts."'), movement='1'";
				} else {
					// regular data update 	
					$insertq = "INSERT INTO Roomlog SET pid='".$id."', tstamp=UNIX_TIMESTAMP('".$ts."'), light='".$light."', humidity='".$humid."', temp='".$temp."'";

				}
				if ($debug) echo $insertq, "\n";
				if (($res = mysql_query ($insertq, $remote))===false) {
					$message = date('Y-m-d H:i') . " Consume: Could not insert event " . mysql_error($remote) . "\n";
					error_log($message, 3, $LOGFILE);
					if (mysql_errno($remote) === 1062) {
					    // it is a Duplicate Key message... delete the record anyway by setting $upd_done to true
					    $upd_done = true;
					} 
				} else {
					$upd_done = true;	

					// update Redis
    			        	// Motion records should be sent immediately, so
					// they are sent by rcvsend.php
					if (!$pir && $publish && $pubredis) {
					        // update Redis using socketstream message
                                                $msg = new PubMessage;
                                                if ($debug) echo "Redis publishing RNR ".$location."\n";
                                                $msg->setParams('Light', $location, '%', $light);
                                                try {
                       			                        // update Redis using Set
                       			                        $key = $location.":Light";
                                                        $redis->set ($key, $light); 
                                                        // $redis->set ($location.":Light", $light); 
                                                        // $redis->publish('ss:event', json_encode($msg));
                                                }
                                                catch (Exception $e) {
                                                        $message = date('Y-m-d H:i') . " Consume: Cannot publish to Redis " . $e->getMessage() . "\n";
                                                        error_log($message, 3, $LOGFILE);
                                                }
                                                $msg->setParams('Humidity', $location, '%', $humid);
                                                try {
                       			                        // update Redis using Set
                       			                        $key = $location.":Humidity";
                                                        $redis->set ($key, $humid); 
                                                        // $redis->set ($location.":Humidity", $humid); 
                                                        // $redis->publish('ss:event', json_encode($msg));
                                                }
                                                catch (Exception $e) {
                                                        $message = date('Y-m-d H:i') . " Consume: Cannot publish to Redis " . $e->getMessage() . "\n";
                                                        error_log($message, 3, $LOGFILE);
                                                }
                                                $msg->setParams('Temperature', $location, '°C', $temp);
                                                try {
                       			                        // update Redis using Set
                       			                        $key = $location.":Temperature";
                                                        $redis->set ($key, $temp); 
                                                        // $redis->set ($location.":Temperature", $temp); 
                                                        // $redis->publish('ss:event', json_encode($msg));
                                                }
                                                catch (Exception $e) {
                                                        $message = date('Y-m-d H:i') . " Consume: Cannot publish to Redis " . $e->getMessage() . "\n";
                                                        error_log($message, 3, $LOGFILE);
                                                }
 					}

					// and update Pachube / Cosm
					if (!$pir && $publish && ($datastream != '')) {
						// update Pachube/Cosm
						$data = '"' . $light . '"';
						$result = $pachube->updateDataStream("csv", $feed, $datastream.'_1' , $data);
						$data = '"' . $humid . '"';
						$result = $pachube->updateDataStream("csv", $feed, $datastream.'_2' , $data);
						$data = '"' . $temp . '"';
						$result = $pachube->updateDataStream("csv", $feed, $datastream.'_3' , $data);
						
					}
					
				}
				// now update the sensor timestamp and battery status
				$updateq = "UPDATE Sensor SET tstamp=UNIX_TIMESTAMP('".$ts."'), lobatt='".$lobat."' WHERE id='".$id."'";
				if ($debug) echo $updateq, "\n";
				if (($res = mysql_query ($updateq, $remote))===false) {
					$message = date('Y-m-d H:i') . " Consume: Could not update Sensor " . mysql_error($remote) . "\n";
					error_log($message, 3, $LOGFILE);
				}
			} // if RNR
		} // if numrows
	} // if GNR
	
	if ($upd_done) {
		// updated on remote database, now fix local database
		if ($debug) echo $lupdq, "\n";
		if (($res = mysql_query ($lupdq, $local))===false) {
			$message = date('Y-m-d H:i') . " Consume: Could not update local rcvlog " . mysql_error($local) . "\n";
			error_log($message, 3, $LOGFILE);
		}
	}
	
} // while

// delete processed records
$lq = "DELETE FROM rcvlog WHERE bP='1'";
if ($debug) echo "Query ", $lq, "\n";
if (($result = mysql_query ($lq, $local))===false) {
	$message = date('Y-m-d H:i') . " Consume: Could not delete local rcvlog " . mysql_error($local) . "\n";
	error_log($message, 3, $LOGFILE);
}

mysql_close ($remote);
mysql_close ($local);

?>