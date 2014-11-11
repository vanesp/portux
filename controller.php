#!/usr/bin/php
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
// <version>1.3</version>
// <email>vanesp@escurio.com</email>
// <date>2014-03-08</date>
// <summary>controller receives messages from pub/sub redis and acts upon them</summary>

// version 1.3

// Changed Forced handling so that a Forced switch becomes unforced after a while...

// version 1.2

// Add override methods for manual control (now that the Raspberry Pi works more reliably
// in controlling the nodo)

// Add method to retrieve new commands from the database on bali.local occasionally
// Add method to store status changes back to the database on bali.local occasionally


// version 1.1

// Add feedback mechanism on the 5th tick when the light level should have been read to
// be able to determine if the Soll state is the same as the Ist state (i.e. is the light
// on when it should be, or vice versa).
// Note that light values only get updated every 5 mins or so

// version 1.0

// This program needs to be run from inittab as a Daemon
//
// Currently it receives all data as Pub/Sub messages from the Redis instance stored
// and run on the portux.local machine (see redis.php) and it uses a second Redis connection
// to retrieve the current values of the lights etc.
//
// It uses the Predis library for this
//
// It controls the lights by sending a Redis PUB message with the Switch command, which
// is currently executed on the rpi1.local machine by the server running under socketstream
// under node.js (so the rpi1 machine needs to be up and running)
//
// When the Nodo control board for the KAKU switches is linked to the Portux serial port
// directly, it will read messages from the serial port (non-blocking) and write commands
// to the serial port directly instead, simplifying this program.
//
// The control logic will however, remain intact.

/**
 * System_Daemon Code
 *
 * If you run this code successfully, a daemon will be spawned
 * and stopped directly. You should find a log enty in
 * /var/log/simple.log
 *
 */

// Global data structure
$debug = false;
$showstatus = false;		// do we send status message via Redis ?

// Make it possible to test in source directory
// This is for PEAR developers only
ini_set('include_path', ini_get('include_path').':..');

// Include Class
error_reporting(E_ALL);
require_once "System/Daemon.php";

// No PEAR, run standalone
System_Daemon::setOption("usePEAR", false);

// Setup
$options = array(
	'appName' => 'controller',
	'appDir' => dirname(__FILE__),
	'appDescription' => 'Controls lights from Redis Events',
	'authorName' => 'Peter van Es',
	'authorEmail' => 'vanesp@escurio.com',
	'sysMaxExecutionTime' => '0',
	'sysMaxInputTime' => '0',
	'sysMemoryLimit' => '1024M',
	'appRunAsGID' => 20,			// dialout group for /dev/ttyS1
	'appRunAsUID' => 1000,
);

System_Daemon::setOptions($options);
System_Daemon::log(System_Daemon::LOG_INFO, "Daemon not yet started so this ".
	"will be written on-screen");

// Spawn Deamon!
System_Daemon::start();
System_Daemon::log(System_Daemon::LOG_INFO, "Daemon: '".
	System_Daemon::getOption("appName").
	"' spawned! This will be written to ".
	System_Daemon::getOption("logLocation"));

// access information
include('access.php');
$latitude = 52.2395602;			// details for Hilversum
$longitude = 5.1525346;
$sunrise = "08:00";				// will be overwritten every loop
$sunset = "17:30";
$in_the_dark = false;

// defines
define ('LIGHTLEVEL', 50);		// light level below which to switch (in %)
define ('LIGHTOFF', 70);		// note the off level is higher to prevent switching off due to own light
define ('DELAY', 500000);		// delay in microseconds after a send command (0.5s)

// states recognized for lights:
// ON
// OFF
// FORCEDON (switched on manually)
// FORCEDOFF (switched off manually)
// SCHED - state for random lights when a schedule is determined

// timestates from Nodo - Direction=Internal, Source=Clock, Event=(ClockDayLight x,0)
// where x is:
// 0 - midnight
// 1 - two hours before sunrise
// 2 - sunrise
// 3 - two hours before sunset
// 4 - sunset

$timestate = 0;

date_default_timezone_set('Europe/Amsterdam');

// don't timeout!
set_time_limit(0);

// include Redis pub sub functionality
include('redis.php');

// function to open the remote database
function open_remote_db () {
	global $RHOST, $RDBUSER, $RDBPASS, $RDATABASE;
	$remote = false;
	// Open the database
	// Open the database connection
	$remote = mysql_connect($RHOST, $RDBUSER, $RDBPASS);
	if (!$remote) {
		$message = date('Y-m-d H:i') . " Controller: Remote database connection failed " . mysql_error($remote);
		System_Daemon::notice($message);
	}

	// See if we can open the database
	$db_r = mysql_select_db ($RDATABASE, $remote);
	if (!$db_r) {
		$message = date('Y-m-d H:i') . " Controller: Failed to open $RDATABASE " . mysql_error($remote);
		System_Daemon::notice($message);
		$remote = false;
	}
	return $remote;
}

// function to send a command
function sendCommand ($key, $state) {
	global $debug, $showstatus, $publish, $switches;

	$sensortype = 'Switch';
	$location = $switches[$key]['description']; // or 2 or 3, or name of switch

	$value = false;	 // or false for off
	if ($state === 'On') $value = true;
	$quantity = $switches[$key]['command'];

	// set the count to 5 for the Ist-Soll function
	$switches[$key]['count'] = 5;

	if ($debug) System_Daemon::info("sendCommand ".$quantity.$state);

	// create the channel name for Pub/Sub
	$channel = 'portux.'.$sensortype.'.'.$location;

	// update Redis using socketstream message
	$msg = new PubMessage;
	$msg->setParams($sensortype, $location, $quantity, $value);
	try {
		$publish->publish('ss:event', json_encode($msg));
	}
	catch (Exception $e) {
			$message = date('Y-m-d H:i') . " Controller: Cannot publish to Redis " . $e->getMessage();
			System_Daemon::notice($message);
	}

	if ($showstatus) {
		$channel = 'portux.status.'.$location;
		$value = $switches[$key]['state'];
		$sensortype = 'Status';
		$quantity = $switches[$key]['strategy'].' '.date('Y-m-d H:i:s', $switches[$key]['nextevent']);
		// update Redis using socketstream message
		$msg = new PubMessage;
		$msg->setParams($sensortype, $location, $quantity, $value);
		$publish->publish('ss:event', json_encode($msg));
	}
	// wait for half a second or so
	usleep (DELAY);

}

// function to handle an incoming message and act accordingly
function handleIncoming($str) {
	global $switches, $debug, $sunrise, $sunset, $sunrise_t, $sunset_t, $timestate;

	// remove non-printable characters
	$str = preg_replace( '/[^[:print:]]/', '',$str);
	// deal with the incoming or outgoing message, replace comma's with \n
	$msg = str_replace (', ',"\n", $str);
	// and parse it to an associative array with fields Direction, Source, and Event
	$cmd = parse_ini_string ($msg);
	if ($debug) System_Daemon::info("handleIncoming ".$msg);

	// we only take action if it is an Input event
	// note - this will yield a Warning if there is no Direction field in the line
	if ($cmd['Direction'] === 'Input') {
		// parse the Event, first check for on or off
		$newstate = 'UNDEFINED';
		if (strripos ($cmd['Event'],',On') !== false) $newstate = 'FORCEON';
		if (strripos ($cmd['Event'],',Off') !== false) $newstate = 'FORCEOFF';
		if (($i = strripos ($cmd['Event'],'NewKAKU')) !== false) {
			// it's a new one... find the string before the comma
			$end = strripos ($cmd['Event'],',');
			$begin = $i + 8; // NewKaku is 7 long + 1 space
			$len = $end - $begin;	// length of the identifier
			$ident = substr ($cmd['Event'], $begin, $len);
		}
		if (($i = strripos ($cmd['Event'],'KAKU')) !== false) {
			// it's a new one... find the string before the comma
			$end = strripos ($cmd['Event'],',');
			$begin = $i + 5; // KAKU is 4 long + 1 space
			$len = $end - $begin;	// length of the identifier
			$ident = substr ($cmd['Event'], $begin, $len);
		}
		if ($debug) System_Daemon::info("handleIncoming identifier ".$ident);

		if (isset($ident)) {
			// loop through switches to find the one we have
			reset ($switches);
			foreach ($switches as $key => &$switch) {
				if ($switch['kaku'] === $ident) {
					if ($newstate === 'FORCEON') {
						// pressing 2x toggles the force status
						if ($switch['state'] === 'FORCEON') {
							$switch['state'] = 'ON';
						} else {
							$switch['state'] = 'FORCEON';
						}
						// if it is forced on, do not switch off till the normal off time
						$switch['time_off'] = '00:30';	// switch off at 30 past midnight
						if (time() > strtotime("today ".$switch['time_off'])) {
						    $switch['nextevent'] = strtotime("tomorrow ".$switch['time_off']);		// set the time
						} else {
						    $switch['nextevent'] = strtotime("today ".$switch['time_off']);		// set the time
						}
						sendCommand ($key,'On');		// make sure it is on
					}
					if ($newstate === 'FORCEOFF') {
						// pressing 2x toggles the force status
						if ($switch['state'] === 'FORCEOFF') {
							$switch['state'] = 'OFF';
						} else {
							$switch['state'] = 'FORCEOFF';
						}
						// if it is forced off, then we leave it off till the next event time
						$switch['time_on'] = $sunset;  // switch on at sunset tomorrow
						if (time() > $sunset_t) {
						    $switch['nextevent'] = strtotime("tomorrow ".$switch['time_on']);	   // set the time
						} else {
						    $switch['nextevent'] = $sunset_t;	   // set the time
						}
						sendCommand ($key,'Off');		 // make sure it is off
				   }
				   if ($debug) System_Daemon::info("handleIncoming changed ".print_r($switch, true));
				} // not the right switch
			}
		}
	} elseif ($cmd['Direction'] === 'Internal') {
		// handle clock events
		if (($i = strripos ($cmd['Event'],'ClockDayLight')) !== false) {
			// it's a new one... find the string
			$end = strripos ($cmd['Event'],',');
			$begin = $i + 13; // ClockDayLight is 13 long
			$len = $end - $begin;	// length of the identifier
			$timestate = intval(substr ($cmd['Event'], $begin, $len));
			if ($debug) System_Daemon::info("Timestate changed to ".$timestate);
			// To be added in future: and toggle FORCE off...
			reset ($switches);
			foreach ($switches as $key => &$switch) {
                if ($switch['state'] === 'FORCEON') {
                    $switch['state'] = 'ON';
                }
                if ($switch['state'] === 'FORCEOFF') {
                    $switch['state'] = 'OFF';
                }
			} // foreach
		} // if ClockDayLight
	} // elseif
}

// function to handle a motion event on a location
function handleMotion($location) {
	global $switches, $debug, $sunrise, $sunset, $in_the_dark, $publish;

	reset ($switches);
	foreach ($switches as $key => &$switch) {
		if ($switch['idroom'] == $location) {
			// we've got the switch that corresponds to this location, Motion was detected

			// only take action if the strategy is motion, and the light is not forced on or off
			if ($switch['strategy'] === 'motion') {
			   if ($debug) System_Daemon::info("handleMotion checking state for item ".print_r($switch, true));
			   // get the most recent light value
			   $light = intval($publish->get ($switch['location'].':Light'));
			   if ($debug) System_Daemon::info("handleMotion checking light ".$light);
			   switch ($switch['state']) {
					case 'ON':
						// if the light is on, keep it on, and extend the period
						$switch['nextevent'] = time() + $switch['duration']*60;
						// and make sure it is really on
						if ($switch['olddim'] == 0 ) {
							sendCommand ($key,'On');
						}
						break;
					case 'OFF':
						// is it after sunset, before sunrise ?
						if ($in_the_dark || $light <= LIGHTLEVEL) {
							// switch on that light
							$switch['state'] = 'ON';
							$switch['nextevent'] = time() + $switch['duration']*60;
							sendCommand ($key,'On');
						}
						break;
					case 'FORCEON':
					case 'FORCEOFF':
						// do nothing with motion
						break;
					default:
						// switch off that light and get to known state
						$switch['state'] = 'OFF';
						$switch['nextevent'] = time();
						sendCommand ($key,'Off');
				} // switch
			} // strategy

		} // not the right location
	}// foreach
}

// function to reset all switches at startup to known state
function resetAll() {
	global $switches, $debug, $sunset, $sunrise, $timestate;

	reset ($switches);
	foreach ($switches as $key => &$switch) {
		// we've got a switch that needs action, check the strategy
		$switch['state'] = 'OFF';
		$switch['count'] = 1;
		sendCommand ($key,'Off');

		switch ($switch['strategy']) {
			case 'sun':
				$switch['time_off'] = $sunrise;	 // switch off at sunrise
				$switch['time_on'] = $sunset;
				// all further actions get set in the handleTick function
				break;
			case 'evening':
				if (!isset($switch['time_off'])) {
					$switch['time_off'] = "00:00";	// this time is normally in the record, do not overwrite
				}
				$switch['time_on'] = $sunset;
			case 'time':
				// nothing to do, gets dealt with in the first handleTick program,
				// add two minutes before next action
				$switch['nextevent'] = time() + 2 * 60;
				break;
			// the following are self starting
			case 'simulate':
			case 'motion':
			case 'light':
			case 'event':
			default:
				$switch['nextevent'] = time();
		}

	}
}


// function to handle a timing event
function handleTick() {
	global $switches, $debug, $sunrise, $sunset, $in_the_dark, $publish, $showstatus;

	reset ($switches);
	foreach ($switches as $key => &$switch) {
		// we've got a switch that needs action, check the strategy
		$changed = false;
		$forced = false;
		switch ($switch['state']) {
			case 'FORCEON':
				$forced = true;
			case 'ON':
				$active = true;
				break;
			case 'FORCEOFF':
				$forced = true;
			case 'OFF':
			default:
				$active = false;
		}

		// For Ist-Soll comparison, deduct one from the counter, and verify that state
		// As a tick comes in every minute, this runs every 5 mins
		$switch['count']--;
		if ($switch['count'] == 0) {
			// reset the count
			$switch['count'] = 5;
			// get the most recent light value
			$light = intval($publish->get ($switch['location'].':Light'));
			// if the light is meant to be on, and the lightlevel is low, send the
			// On command again
			// but do not do this for old dimmable switches, because they'll keep cycling
			if ($active && $switch['olddim'] == 0 && $light <= LIGHTLEVEL) {
				sendCommand ($key,'On');
				$changed = true;
			}
			// if it is dark out or we're meant to be off or the level is too high,
			// try switching it off again
			// if (!$active && $in_the_dark && $light > LIGHTLEVEL) {
			// PvE: 5 jan
			// changed to: meant to be off... ensure it is off
			if (!$active) {
				sendCommand ($key,'Off');
				$changed = true;
			}
		}

		switch ($switch['strategy']) {
			case 'motion':
				// check if timed-out, and on, then go off
				 if (!$forced && $active && ($switch['nextevent'] <= time())) {
					$switch['state'] = 'OFF';
					$switch['nextevent'] = time();
					sendCommand ($key,'Off');
					$changed = true;
				} elseif (!isset($switch['state'])) {
					// switch off that light and get to known state
					$switch['state'] = 'OFF';
					$switch['nextevent'] = time();
					sendCommand ($key,'Off');
					$changed = true;
				}
				break;
			case 'sun':
				// don't do this if forced
				if (!$forced) {
					$switch['time_off'] = $sunrise;	 // switch off at sunrise
					$switch['time_on'] = $sunset;
				}
				// processing is the same for the next two sets...
			case 'evening':
				// evening only... not if forced
				if (!$forced) {
					// processing is identical to time, except that the time-on is set to sunset...
					// time off is in the record
					$switch['time_on'] = $sunset;
				}
				// so drop through to the next set, where also the forced items get reset
			case 'time':
				if (time() > $switch['nextevent']) {
					// time for action
					if (!$active) {
						$switch['state'] = 'ON';
						sendCommand ($key,'On');
						$changed = true;
					} else {
						// it is time to switch off
						$switch['state'] = 'OFF';
						sendCommand ($key,'Off');
						$changed = true;
					}
				}

				// deal with the case where the time_off time is tomorrow
				$start_t = strtotime("today ".$switch['time_on']);
				$finish_t = strtotime("today ".$switch['time_off']);
				if ($start_t >= $finish_t) $finish_t = strtotime("tomorrow ".$switch['time_off']);
				$t = time();

				// make sure the next time for action is set ok
				if ($t >= $start_t && $t < $finish_t) {
					// switch is meant to be on, set the first off time as the next action
					$switch['state'] = 'ON';
					$switch['nextevent'] = $finish_t;
				} else {
					// not in the time range, switch light back off and schedule on time
					$switch['state'] = 'OFF';
					$switch['nextevent'] = $start_t;
				}
				break;
			case 'simulate':
				// in the evening, random on periods... calculate a duration
				switch ($switch['state']) {
					case 'OFF':
						// schedule a new time in the evening interval
						$switch['duration'] = rand (15, 120);	// random between 15 minutes and two hours
						$start = rand (60,180);					// start time
						$on = strtotime("today ".$sunset." + ".$start." min");
						$off = strtotime("today ".$switch['time_on']." + ".$switch['duration']." min");
						$switch['time_on'] = date ("H:i", $on);
						$switch['time_off'] = date ("H:i", $off);
						$switch['nextevent'] = $on;	 // set the next event time
						$switch['state'] = 'SCHED';
						$changed = true;
						break;
					case 'ON':
						if ((time() >= $switch['nextevent']) && $active) {
							// it is time to switch off
							$switch['state'] = 'OFF';
							sendCommand ($key,'Off');
							$changed = true;
						}
						break;
					case 'SCHED':
						if ((time() >= $switch['nextevent']) && !$active) {
							// we've scheduled, and it's time to switch on
							$switch['state'] = 'ON';
							$switch['nextevent'] = strtotime($switch['time_off']);		// set the next event time
							sendCommand ($key,'On');
							$changed = true;
						}
					default:
						break;
				}
				break;

			case 'light':
				// get the most recent light value
				$light = intval($publish->get ($switch['location'].':Light'));
				// check if the light is forced, then don't do anything...
				if (($light <= LIGHTLEVEL) && !$active) {
					// we're in the dark, and it is earlier than the time to switch off
					$switch['state'] = 'ON';
					$switch['nextevent'] = time();		// set the time
					sendCommand ($key,'On');
					$changed = true;
				}
				if (($light > LIGHTOFF) && $active) {
					// it is time to switch off
					$switch['state'] = 'OFF';
					$switch['nextevent'] = time();		// set the time
					sendCommand ($key,'Off');
					$changed = true;
				}
			case 'event':
				// currently not yet used
				break;
		} // switch

		if ($debug && $changed) System_Daemon::info("handleTick changed ".print_r($switch, true));

	}// foreach
	// and then synchronize that database
	SyncDB();
}


// Initialize
function Initialize() {
	global $switches, $debug;
	// Open the database
	$remote = open_remote_db();
	if (!$remote) {
		$message = date('Y-m-d H:i') . " Controller: Cannot open remote database " . mysql_error($remote);
		System_Daemon::notice($message);
		System_Daemon::stop();
		exit (1);
	}

	// retrieve all switch definitions
	// add fake field count to the switches table... for ist-soll comparison
	$query = "SELECT Switch.id as sid, Switch.tstamp, nextevent, description, idroom, location, strategy, command, kaku, time_on, time_off, state, duration, duration as count, olddim FROM Switch,Sensor WHERE Sensor.id = Switch.sensor_id ORDER BY Switch.id";
	if (($remres = mysql_query ($query, $remote))===false) {
		$message = date('Y-m-d H:i') . " Controller: Could not read Contao database " . mysql_error($remote);
		System_Daemon::notice($message);
	}

	// and create the $switches array with these fields
	$numrows = mysql_num_rows($remres);
	while ($numrows > 0) {
		$numrows--;
		$switches[] = mysql_fetch_array($remres, MYSQL_ASSOC);
	}

	// database no longer needed
	mysql_close ($remote);
}

// Synchronize database
function SyncDB() {
	global $switches, $debug;

	if ($debug) System_Daemon::info("In SyncDB");

	// Open the database
	$remote = open_remote_db();
	if (!$remote) {
		$message = date('Y-m-d H:i') . " Controller: Cannot open remote database for sync " . mysql_error($remote);
		System_Daemon::notice($message);
		// but we do not stop the daemon, it's not critical right now
		return (1);
	}

	// retrieve only parts of the switch details
	// add fake field count to the switches table... for ist-soll comparison
	$query = "SELECT Switch.id as sid, Switch.tstamp, description, idroom, location, strategy, command, kaku, time_on, time_off, state, duration, duration as count, olddim FROM Switch,Sensor WHERE Sensor.id = Switch.sensor_id ORDER BY Switch.id";
	if (($remres = mysql_query ($query, $remote))===false) {
		$message = date('Y-m-d H:i') . " Controller: Could not read Contao database for sync " . mysql_error($remote);
		System_Daemon::notice($message);
	}

	// and create the $update array for fixing the $switches array with these fields
	$numrows = mysql_num_rows($remres);
	while ($numrows > 0) {
		$numrows--;
		$updates[] = mysql_fetch_array($remres, MYSQL_ASSOC);
	}

	// loop through $updates to update $switches but only for those records where the $updates.nextevent is bigger...
	reset ($updates);
	foreach ($updates as $updkey => &$upd) {
		reset($switches);
		$found = false;
		foreach ($switches as $key => &$switch) {
			// if the id's are the same
			if ($switch['sid'] == $upd['sid']) {
				$found = true;
				// keys are the same... so we have the record
				if ($upd['tstamp'] > $switch['tstamp']) {
					// update the switch values
    				if ($debug) System_Daemon::info("In SyncDB updating ".$switch['sid']);
      				$switch['tstamp'] = $upd['tstamp'];
					$switch['description'] = $upd['description'];
					$switch['location'] = $upd['location'];
					$switch['strategy'] = $upd['strategy'];
					$switch['command'] = $upd['command'];
					$switch['kaku'] = $upd['kaku'];
					$switch['time_on'] = $upd['time_on'];
					$switch['time_off'] = $upd['time_off'];
					$switch['duration'] = $upd['duration'];
					$switch['olddim'] = $upd['olddim'];
				}
			}
		} // foreach of $switches
        // if we haven't found the record, it is new... so we'll add it to the $switches in it's entirety
        if (!$found) {
            $switches[] = $updates[$updkey];
        }

	} // foreach of $updates

	// loop through the switches to update the database
	reset($switches);
	foreach ($switches as $key => &$switch) {
            // update the database record
            $query = "UPDATE Switch SET state='".$switch['state']."', nextevent='".$switch['nextevent']."',time_on='".$switch['time_on']."', time_off='".$switch['time_off']."' WHERE id=".$switch['sid'];
            if ($debug) System_Daemon::info("Updating {$query}");
            if (($remres = mysql_query ($query, $remote))===false) {
                $message = date('Y-m-d H:i') . " Controller: Could not update Contao database for sync " . mysql_error($remote);
                System_Daemon::notice($message);
            }
    }

	// database no longer needed
	mysql_close ($remote);
}



// MAIN Code

// connect to redis and quit if impossible, as we cannot do anything when that happens
if (!$redis->isConnected()) {
	try {
		$redis->connect();
		$pubredis = true;
	}
	catch (Exception $e) {
		$pubredis = false;
		$message = date('Y-m-d H:i') . " Cannot connect to Redis for subscribing " . $e->getMessage();
		System_Daemon::notice($message);
		// Just return to prevent the daemon from crashing
		// exit(1);
		return;
	}
}

try {
	$publish = new Predis\Client(array(
		'scheme' => 'tcp',
		'host'	 => '127.0.0.1',
		'port'	 => 6379,
		'database' => 1,
		// no timeouts on socket
		'read_write_timeout' => 0,
	));
}
catch (Exception $e) {
	$pubredis = false;
	$message = date('Y-m-d H:i') . " Cannot connect to Redis for publishing " . $e->getMessage();
	System_Daemon::notice($message);
	// Just return to prevent the daemon from crashing
	// exit(1);
	return;
}


// Initialize the system
Initialize();

// figureout the timestate, we only do this once, after that it is based on event msgs
$zenith = 90+50/60;
$offset = 1; // offset from UTC in NL
$sunrise_t = date_sunrise (time(), SUNFUNCS_RET_TIMESTAMP, $latitude, $longitude, $zenith, $offset);
$sunset_t = date_sunset (time(), SUNFUNCS_RET_TIMESTAMP, $latitude, $longitude, $zenith, $offset);
$sunrise = date_sunrise (time(), SUNFUNCS_RET_STRING, $latitude, $longitude, $zenith, $offset);
$sunset = date_sunset (time(), SUNFUNCS_RET_STRING, $latitude, $longitude, $zenith, $offset);

$timestate = 0;
if (time() > $sunrise_t - 2*60 ) $timestate = 1;
if (time() > $sunrise_t) $timestate = 2;
if (time() > $sunset_t - 2*60) $timestate = 3;
if (time() > $sunset_t) $timestate = 4;

// reset all switches and show what we have
resetAll();

if ($debug) System_Daemon::info("After initialization ".print_r($switches, true));


$count = 0;		// counter for number of messages

if ($pubredis) {
	// Initialize a new pubsub context
	$pubsub = $redis->pubSub();

	// Subscribe to your channels
	$pubsub->subscribe('ss:event');

	// Start processing the pubsub messages. Open a terminal and use redis-cli
	// to push messages to the channels. Examples:
	//	 ./redis-cli PUBLISH notifications "this is a test"
	//	 ./redis-cli PUBLISH control_channel quit_loop
	foreach ($pubsub as $message) {
		$count++;

		if ($count >= 60) {
			$count = 0;
			// calculate sunrise and sunset using php functions
			$sunrise = date_sunrise (time(), SUNFUNCS_RET_STRING, $latitude, $longitude, $zenith, $offset);
			$sunset = date_sunset (time(), SUNFUNCS_RET_STRING, $latitude, $longitude, $zenith, $offset);

			// set in_the_dark to tru between sunset and sunrise
			$sunrise_t = date_sunrise (time(), SUNFUNCS_RET_TIMESTAMP, $latitude, $longitude, $zenith, $offset);
			$sunset_t = date_sunset (time(), SUNFUNCS_RET_TIMESTAMP, $latitude, $longitude, $zenith, $offset);
		}

		$in_the_dark = true;
		if (time() > $sunrise_t) $in_the_dark = false;
		if (time() > $sunset_t) $in_the_dark = true;

		switch ($message->kind) {
			case 'subscribe':
				if ($debug) System_Daemon::info("Subscribed to {$message->channel}");
				break;

			case 'message':
				if ($debug) System_Daemon::info("Received {$message->payload}");
				// determine the kind of message
				$obj = json_decode ($message->payload);
				// if e is "newMessage" it is stuff from the Nodo
				// if e is portux, then we deal with it below
				if ($obj->e == 'newMessage') {
					handleIncoming ($obj->p);
				} elseif ($obj->e == 'portux') {
					// deal with the different kinds of message, take action on the
					// switches in the array switch
					switch ($obj->p->type) {
						case 'Tick':
							handleTick();
							break;
						case 'Motion':
							handleMotion($obj->p->location);
							break;
					} // switch on type
				} else {
					$message = date('Y-m-d H:i') . " Controller: unknown message type " . $obj->e;
					System_Daemon::notice($message);
				}
				break;
		} //switch on message kind
	} // foreach message

} // if $pubredis

// Always unset the pubsub context instance when you are done! The
// class destructor will take care of cleanups and prevent protocol
// desynchronizations between the client and the server.
unset($pubsub);

System_Daemon::stop();

?>
