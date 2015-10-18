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
// <version>1.5</version>
// <email>vanesp@escurio.com</email>
// <date>2013-12-17</date>
// <summary>consume reads records from the local redis queue and stores them remotely</summary>

// Everytime consume runs, it reads all the records from the local database,
// interprets them, and stores them in the remote database
// when done, it deletes the records from the local database
// in a two step process

// version 1.0

// version 1.1 - publication to a Redis database of up-to-date information is performed.

// version 1.2 - much more resilience if redis is not there, and does not publish data
//				 if its playing catch-up

// version 1.3 - stop filling the sensor log if not fatal

// version 1.4 - use Redis Set mechanism instead of pub/sub for the measurement values

// version 1.5 - retrieve queued values from local redis store instead of mysql

// version 1.6 - GNR with node id 18 is a p1scanner message with electricity and gas usage values
// @dir p1scanner
// Parse P1 data from smart meter and send as compressed packet over RF12.
// @see http://jeelabs.org/2013/01/02/encoding-p1-data/
// 2012-12-31 <jc@wippler.nl> http://opensource.org/licenses/mit-license.php
// Note: 
// Node=18


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
$publish = false;	   // publish data? (Pachube only)
$pubredis = true;	   // publish redis? 

$P1 = 18;		   // define the P1 node number. If 0, do not include P1 code
                           // if it is defined, stop processing electricity pulses 
$ELEC = 8;		   // id of ELEC sensor in database, needed only when P1 in use

// do timeout if not done within 60 seconds (as the next process will be invoked)
set_time_limit(59);

// function to open the database
function open_remote_db () {
	global $RHOST, $RDBUSER, $RDBPASS, $RDATABASE, $LOGFILE;
	$remote = false;
	// Open the database
	// Open the database connection
	$remote = mysql_connect($RHOST, $RDBUSER, $RDBPASS);
	if (!$remote) {
		$message = date('Y-m-d H:i') . " Consume: Remote database connection failed " . mysql_error() . "\n";
		error_log($message, 3, $LOGFILE);
		return false;
	}

	// See if we can open the database
	$db_r = mysql_select_db ($RDATABASE, $remote);
	if (!$db_r) {
		$message = date('Y-m-d H:i') . " Consume: Failed to open $RDATABASE " . mysql_error() . "\n";
		error_log($message, 3, $LOGFILE);
		$remote = false;
	}
	return $remote;
}


// Open the database
$remote = open_remote_db();
if (!$remote) {
	$message = date('Y-m-d H:i') . " Consume: Cannot open remote database " . mysql_error() . "\n";
	error_log($message, 3, $LOGFILE);
	exit (1);
}

// open the redis store... it is crucial now
if (!$redis->isConnected()) {
	try {
		$redis->connect();
	}
	catch (Exception $e) {
		$pubredis = false;
		$message = date('Y-m-d H:i') . " Cannot connect to Redis " . $e->getMessage() . "\n";
		error_log($message, 3, $LOGFILE);
		exit(1);	// as there is no point in continuing
	}
}

// how many items are there in our queue
$numrows = $redis->llen('queue');

if ($numrows > 20) {				// if this is the case, we are in recovery
	$publish = false;
}
while ($msg = $redis->lpop('queue')) {	   // while there is stuff in the queue

	$value = json_decode($msg);
	if ($debug) print_r ($value);
	$buf = $value->buf;
	$ts = $value->tstamp;
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
			$insertq = "INSERT INTO Sensorlog SET pid='".$id."', tstamp='".$ts."', value='".$value."'";
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
			$updateq = "UPDATE Sensor SET tstamp='".$ts."', lobatt=0 WHERE id='".$id."'";
			if ($debug) echo $updateq, "\n";
			if (($res = mysql_query ($updateq, $remote))===false) {
			    $message = date('Y-m-d H:i') . " Consume: Could not update Sensor " . mysql_error($remote) . "\n";
			    error_log($message, 3, $LOGFILE);
			}
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
			if ($publish) { // and update Pachube / Cosm
			    $data = '"' . $value . '"';
			    if ($datastream != '') {
			        $result = $pachube->updateDataStream("csv", $feed, $datastream, $data);
                            }
                        }
                }
	} // if TMP

	// changed so that P1 needs to be 0 too, otherwise use the P1 sender
	if (strpos($buf, "ELEC") === 0 && $P1 == 0) {
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
			$insertq = "INSERT INTO Sensorlog SET pid='".$id."', tstamp='".$ts."', value='".$power."', count='".$pulse."'";
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
			$updateq = "UPDATE Sensor SET tstamp='".$ts."', lobatt=0, cum_elec_pulse=cum_elec_pulse+".$pulse." WHERE id='".$id."'";
			if ($debug) echo $updateq, "\n";
			if (($res = mysql_query ($updateq, $remote))===false) {
				$message = date('Y-m-d H:i') . " Consume: Could not update Sensor " . mysql_error($remote) . "\n";
				error_log($message, 3, $LOGFILE);
			}
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
			if ($publish) {
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

                // from version 1.6, nodeid = $P1 means p1scanner
                if ($roomid != $P1) {
                	// normal node id
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
						$insertq = "INSERT INTO Motionlog SET pid='".$id."', tstamp='".$ts."', movement='1'";
					} else {
						// regular data update
						$insertq = "INSERT INTO Roomlog SET pid='".$id."', tstamp='".$ts."', light='".$light."', humidity='".$humid."', temp='".$temp."'";

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
						if (!$pir && $pubredis) {
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
					$updateq = "UPDATE Sensor SET tstamp='".$ts."', lobatt='".$lobat."' WHERE id='".$id."'";
					if ($debug) echo $updateq, "\n";
					if (($res = mysql_query ($updateq, $remote))===false) {
						$message = date('Y-m-d H:i') . " Consume: Could not update Sensor " . mysql_error($remote) . "\n";
						error_log($message, 3, $LOGFILE);
					}
				} // if RNR
			} // if numrows
                } else {  // version 1.6, if roomid != $P1

    		    // we have a p1scanner packet
    		    // $field[2..x] contain decimal representations of packed p1 data
    		    //  Decode JeeLabs compressed longs format
    		    //
    		    // for more details - http://jeelabs.org/2013/01/03/processing-p1-data/
    		    // forum thread - http://jeelabs.net/boards/6/topics/3446
		
    		    $ints = [];	// array to receive the values
    		    $v = 0;
		
    		    if ($debug) echo count($field), " values in field\n";
		
    		    for  ($i = 2; $i<count($field); $i++) {
			$b = intval($field[$i]);
			$v = ($v << 7) + ($b & 0x7F);
			if ($b & 0x80) {
		            // top bit set, store this and get next value
			    $ints[] = $v;
			    $v = 0;
			}
                    }
		                        
                    if ($ints[0] == 1) {
			$use1 = $ints[1];	// electricity usage in watts
		        $use2 = $ints[2];	
		        $gen1 = $ints[3];	// electricity generated
		        $gen2 = $ints[4];
		        $mode = $ints[5];	
		        $usew = $ints[6];	// actual usage
		        $genw = $ints[7];
		        $gas =  $ints[9];	// gas usage in m3
                    }
		                                        
                    if ($debug) print_r ($ints);
		                         
                    // create a query, update values
                    $insertq = "INSERT INTO P1log SET pid='".$roomid."', tstamp='".$ts."', use1='".$use1."', use2='".$use2."', gen1='".$gen1."'";
                    $insertq .= ", gen2='".$gen2."', mode='".$mode."', usew='".$usew."', genw='".$genw."', gas='".$gas."'";

                    if ($debug) echo $insertq, "\n";
                    if (($res = mysql_query ($insertq, $remote))===false) {
			$message = date('Y-m-d H:i') . " Consume: Could not insert P1log " . mysql_error($remote) . "\n";
			error_log($message, 3, $LOGFILE);
			if (mysql_errno($remote) === 1062) {
				// it is a Duplicate Key message... delete the record anyway by setting $upd_done to true
				$upd_done = true;
			}
                    }
                    // now update the sensor timestamp and battery status
                    $elec = $use1 + $use2;
                    $updateq = "UPDATE Sensor SET tstamp='".$ts."', lobatt='0', cum_gas_pulse='".$gas."', cum_elec_pulse='".$elec."' WHERE id='".$roomid."'";
                    if ($debug) echo $updateq, "\n";
                    if (($res = mysql_query ($updateq, $remote))===false) {
			$message = date('Y-m-d H:i') . " Consume: Could not update Sensor " . mysql_error($remote) . "\n";
			error_log($message, 3, $LOGFILE);
                    }

                    // and, if P1 set, also insert the value in the electricity sensor
                    $insertq = "INSERT INTO Sensorlog SET pid='".$ELEC."', tstamp='".$ts."', value='".$usew."', count='".$usew."'";
                    if ($debug) echo $insertq, "\n";
                    if (($res = mysql_query ($insertq, $remote))===false) {
			$message = date('Y-m-d H:i') . " Consume: Could not insert Sensorlog " . mysql_error($remote) . "\n";
			error_log($message, 3, $LOGFILE);
                    }
	
		} // else
        } // if GNR	
} // while
mysql_close ($remote);

?>