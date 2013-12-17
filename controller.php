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
// <date>2013-12-17</date>
// <summary>controller received messages from pub/sub redis and acts upon them</summary>


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
    'appRunAsGID' => 20,            // dialout group for /dev/ttyS1
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
$LOGFILE = "controller.log";		// log history of actions
$LEN = 128;						// records are max 128 bytes
$latitude = 52.2395602;         // details for Hilversum
$longitude = 5.1525346;
$sunrise = '08:00';             // will be overwritten every loop
$sunset = '17:30';
$in_the_dark = false;

// defines
define ('LIGHTLEVEL', 60);      // light level below which to switch (in %)
define ('DELAY', 500000);       // delay in microseconds after a send command (0.5s)

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

// Global data structure 
$debug = false;

// function to open the remote database
function open_remote_db () {
    global $RHOST, $RDBUSER, $RDBPASS, $RDATABASE, $LOGFILE;
    $remote = false;
    // Open the database
    // Open the database connection
    $remote = mysql_connect($RHOST, $RDBUSER, $RDBPASS);
    if (!$remote) {
 	    $message = date('Y-m-d H:i') . " Controller: Remote database connection failed " . mysql_error($remote) . "\n";
        System_Daemon::notice($message);
    }

    // See if we can open the database
    $db_r = mysql_select_db ($RDATABASE, $remote);
    if (!$db_r) {
    	$message = date('Y-m-d H:i') . " Controller: Failed to open $RDATABASE " . mysql_error($remote) . "\n";
        System_Daemon::notice($message);
    	$remote = false;
    }
    return $remote;
}

// function to send a command
function sendCommand ($key, $state) {
    global $debug, $publish, $switches, $LOGFILE;
    
    $sensortype = 'Switch';
    $location = $switches[$key]['description']; // or 2 or 3, or name of switch
    
    $value = false;  // or false for off
    if ($state == 'On') $value = true;
    $quantity = $switches[$key]['command'];

    if ($debug) System_Daemon::info("sendCommand ".$quantity."\n");
    
    // create the channel name for Pub/Sub
    $channel = 'portux.'.$sensortype.'.'.$location;

    // update Redis using socketstream message
    $msg = new PubMessage;
    $msg->setParams($sensortype, $location, $quantity, $value);
    try {
            $publish->publish('ss:event', json_encode($msg));
    }
    catch (Exception $e) {
            $message = date('Y-m-d H:i') . " Controller: Cannot publish to Redis " . $e->getMessage() . "\n";
            System_Daemon::notice($message);
    }
    // wait for half a second or so
    usleep (DELAY);
}

// function to handle an incoming message and act accordingly
function handleIncoming($str) {
    global $switches, $debug, $sunrise, $sunset, $timestate;
    
    // remove non-printable characters
    $str = preg_replace( '/[^[:print:]]/', '',$str);
    // deal with the incoming or outgoing message, replace comma's with \n
    $msg = str_replace (', ',"\n", $str);
    // and parse it to an associative array with fields Direction, Source, and Event
    $cmd = parse_ini_string ($msg);
    if ($debug) System_Daemon::info("handleIncoming ".$msg."\n");
    
    // we only take action if it is an Input event
    if ($cmd['Direction'] == 'Input') {
        // parse the Event, first check for on or off
        if ((strripos ($cmd['Event'],',On')) !== false) $newstate = 'FORCEON';
        if ((strripos ($cmd['Event'],',Off')) !== false) $newstate = 'FORCEOFF';
        if (($i = strripos ($cmd['Event'],'NewKAKU')) !== false) {
            // it's a new one... find the string
            $end = strripos ($cmd['Event'],',');
            $begin = $i + 7; // NewKaku is 7 long
            $len = $end - $begin;   // length of the identifier
            $ident = substr ($cmd['Event'], $begin, $len);
        }
        if (($i = strripos ($cmd['Event'],'KAKU')) !== false) {
            // it's a new one... find the string
            $end = strripos ($cmd['Event'],',');
            $begin = $i + 4; // KAKU is 7 long
            $len = $end - $begin;   // length of the identifier
            $ident = substr ($cmd['Event'], $begin, $len);
        }
        if (isset($ident)) {
            // loop through switches to find the one we have
            reset ($switches);
            foreach ($switches as &$switch) {
                if ($switch['kaku'] == $ident) {
                    // get the current state... cycle from 'FORCEON' -> 'ON' etc
                    if (($switch['state'] == 'FORCEON') || ($switch['state'] == 'FORCEOFF')) {
                        $forced = true;
                    } else {
                        $forced = false;
                    }

                    if ($newstate == 'FORCEON') {
                        if ($forced) {
                            $switch['state'] = 'ON';
                        } else {
                            $switch['state'] = 'FORCEON';
                        }
                        $switch['time_off'] = '00:30';  // switch off at 30 past midnight
                        $switch['tstamp'] = strtotime("tomorrow ".$switch['time_off']);     // set the time
                    }
                    if ($newstate == 'FORCEOFF') {
                        if ($forced) {
                            $switch['state'] = 'OFF';
                        } else {
                            $switch['state'] = 'FORCEOFF';
                        }
                        $switch['time_on'] = $sunset;  // switch on at sunset tomorrow
                        $switch['tstamp'] = strtotime("tomorrow ".$switch['time_on']);     // set the time
                    }
                    
                } // not the right switch
            }
        }
    } elseif ($cmd['Direction'] == 'Iternal') {
        // handle clock events
        if (($i = strripos ($cmd['Event'],'ClockDayLight')) !== false) {
            // it's a new one... find the string
            $end = strripos ($cmd['Event'],',');
            $begin = $i + 13; // ClockDayLight is 13 long
            $len = $end - $begin;   // length of the identifier
            $timestate = intval(substr ($cmd['Event'], $begin, $len));
        }
    }
 }

// function to handle a motion event on a location
function handleMotion($location) {
    global $switches, $debug, $sunrise, $sunset, $in_the_dark, $publish;

    reset ($switches);
    foreach ($switches as $key => &$switch) {
        if ($switch['idroom'] == $location) {
            // we've got the switch that corresponds to this location, Motion was detected
          
            // only take action if the strategy is motion, and the light is not forced on or off
            if ($switch['strategy'] == 'motion') {
               if ($debug) System_Daemon::info("handleMotion checking state for item ".$key."\n");
               // get the most recent light value
               $light = $publish->get ($switch['location'].':Light');
               if ($debug) echo System_Daemon::info("handleMotion checking light ".$light."\n");
               switch ($switch['state']) {
                    case 'ON':
                        // if the light is on, keep it on, and extend the period
                        $switch['tstamp'] = time() + $switch['duration']*60;
                        break;
                    case 'FORCEON':
                    case 'FORCEOFF':
                        // do nothing
                        break;
                    case 'OFF':
                        // is it after sunset, before sunrise ?
                        if ($in_the_dark) {
                            // switch on that light
                            $switch['state'] = 'ON';
                            $switch['tstamp'] = time() + $switch['duration']*60;
                            sendCommand ($key,'On');
                        }
                        break;
                    default:
                        // is it after sunset, before sunrise.... go to 'ON' or 'OFF' state
                        if ($in_the_dark || $light < LIGHTLEVEL) {
                            // switch on that light
                            $switch['state'] = 'ON';
                            $switch['tstamp'] = time() + $switch['duration']*60;
                            sendCommand ($key,'On');
                        } else {
                            // switch off that light and get to known state
                            $switch['state'] = 'OFF';
                            $switch['tstamp'] = time();
                            sendCommand ($key,'Off');
                        }
                        break;
                        
                } // switch
            } // strategy
           
        } // not the right location
    }// foreach
}

// function to reset all switches at startup to known state
function resetAll() {
    global $switches, $debug;

    reset ($switches);
    foreach ($switches as $key => &$switch) {
        // we've got a switch that needs action, check the strategy
        $switch['state'] = 'OFF';
        sendCommand ($key,'Off');
        $switch['tstamp'] = time();
    }
}
        

// function to handle a timing event
function handleTick() {
    global $switches, $debug, $sunrise, $sunset, $in_the_dark;

    reset ($switches);
    foreach ($switches as $key => &$switch) {
        // we've got a switch that needs action, check the strategy
        
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
        if ($debug) System_Daemon::info("handleTick active ".$active.",forced ".$forced."\n");
        
        switch ($switch['strategy']) {
            case 'motion':
                // check if timed-out, and on, then go off
                if (!$forced && $active && $switch['tstamp'] <= time()) {
                    $switch['state'] = 'OFF';
                    $switch['tstamp'] = time();
                    sendCommand ($key,'Off');
                } elseif (!isset($switch['state'])) {
                    // switch off that light and get to known state
                    $switch['state'] = 'OFF';
                    $switch['tstamp'] = time();
                    sendCommand ($key,'Off');
                }
                break;
            case 'sun':
                // check if it is dark, then on... even if forced
                if ($in_the_dark && !$active) {
                    // switch on that light
                    $switch['state'] = 'ON';
                    $switch['tstamp'] = strtotime ("tomorrow ".$sunrise); // next event at sunrise tomorrow
                    sendCommand ($key,'On');
                }
                if (!$in_the_dark && $active) {
                    // switch off that light
                    $switch['state'] = 'OFF';
                    $switch['tstamp'] = strtotime ("today ".$sunset); // next event at sunset today
                    sendCommand ($key,'Off');
                }
                break;    
            case 'evening':
                // evening only... even if forced, so automatic reset
                $switch['time_off'] = '00:30';  // switch off at 30 past midnight
                $switch['time_on'] = $sunset;
                $off = strtotime("tomorrow ".$switch['time_off']);
                $on = strtotime("today ".$switch['time_on']);
                if (($on <= time())  && !$active) {
                    // we're in the dark, and it is earlier than the time to switch off
                    $switch['state'] = 'ON';
                    $switch['tstamp'] = strtotime("tomorrow ".$switch['time_off']);     // set the time
                    sendCommand ($key,'On');
                }
                if (($off <= time()) && $active) {
                    // it is time to switch off
                    $switch['state'] = 'OFF';
                    $switch['tstamp'] = strtotime("tomorrow ".$switch['time_on']);     // set the time
                    sendCommand ($key,'Off');
                }
                break;    
            case 'simulate':
                // in the evening, random on periods... calculate a duration
                switch ($switch['state']) {
                    case 'OFF':
                        // schedule a new time in the evening interval
                        $switch['duration'] = rand (15, 120);   // random between 15 minutes and two hours
                        $start = rand (60,180);                 // start time
                        $on = strtotime("today ".$sunset." + ".$start." min");
                        $off = strtotime("today ".$switch['time_on']." + ".$switch['duration']." min");
                        $switch['time_on'] = date ("H:i", $on);
                        $switch['time_off'] = date ("H:i", $off);
                        $switch['tstamp'] = $on;     // set the next event time
                        $switch['state'] = 'SCHED';
                        break;
                    case 'ON':
                        if (time() >= $switch['tstamp'] && $active) {
                            // it is time to switch off
                            $switch['state'] = 'OFF';
                            sendCommand ($key,'Off');
                        }
                        break;    
                    case 'SCHED':
                        if (time() >= $switch['tstamp'] && !$active) {
                            // we've scheduled, and it's time to switch on
                            $switch['state'] = 'ON';
                            $switch['tstamp'] = strtotime($switch['time_off']);     // set the next event time
                            sendCommand ($key,'On');
                        }
                    default:
                        break;
                }
                break;
                
            case 'time':
                // within time interval time_on and time_off... except for when forced
                $off = strtotime("today ".$switch['time_off']);
                $on = strtotime("today ".$switch['time_on']);
                if ($on <= time() && !$active) {
                    // we're in the dark, and it is earlier than the time to switch off
                    $switch['state'] = 'ON';
                    $switch['tstamp'] = $off;     // set the time
                    sendCommand ($key,'On');
                }
                if ($off <= time() && $active) {
                    // it is time to switch off
                    $switch['state'] = 'OFF';
                    $switch['tstamp'] = strtotime("tomorrow ".$switch['time_on']);     // set the time
                    sendCommand ($key,'Off');
                }
                break;    
            case 'light':
               // get the most recent light value
               $light = $publish->get ($switch['location'].':Light');
                if ($light < LIGHTLEVEL && !$active) {
                    // we're in the dark, and it is earlier than the time to switch off
                    $switch['state'] = 'ON';
                    $switch['tstamp'] = time();     // set the time
                    sendCommand ($key,'On');
                }
                if ($light < LIGHTLEVEL && $active) {
                    // it is time to switch off
                    $switch['state'] = 'OFF';
                    $switch['tstamp'] = time();     // set the time
                    sendCommand ($key,'Off');
                }
            case 'event':
                // currently not yet used
                break;    
        } // switch
       
        if ($debug) System_Daemon::info("handleTick processed item ".$key."\n");
         
    }// foreach
}


// Initialize
function Initialize() {
    global $switches, $debug;
    // Open the database
    $remote = open_remote_db();
    if (!$remote) {
        $message = date('Y-m-d H:i') . " Controller: Cannot open remote database " . mysql_error($remote) . "\n";
        System_Daemon::notice($message);
        System_Daemon::stop();
        exit (1);
    }

    // retrieve all switch definitions
    $query = "SELECT Switch.tstamp, description, idroom, location, strategy, command, kaku, time_on, time_off, state, duration FROM Switch,Sensor WHERE Sensor.id = Switch.sensor_id";
    if (($remres = mysql_query ($query, $remote))===false) {
        $message = date('Y-m-d H:i') . " Controller: Could not read Contao database " . mysql_error($remote) . "\n";
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


// MAIN Code

// connect to redis and quit if impossible, as we cannot do anything when that happens
if (!$redis->isConnected()) {
    try {
        $redis->connect();
        $pubredis = true;
    }
    catch (Exception $e) {
        $pubredis = false;
        $message = date('Y-m-d H:i') . " Cannot connect to Redis for subscribing " . $e->getMessage() . "\n";
        System_Daemon::notice($message);
        // Just return to prevent the daemon from crashing
        // exit(1);
        return;
    }
}

try {
    $publish = new Predis\Client(array(
        'scheme' => 'tcp',
        'host'   => '127.0.0.1',
        'port'   => 6379,
        'database' => 1,
        // no timeouts on socket
        'read_write_timeout' => 0,
    ));
}
catch (Exception $e) {
    $pubredis = false;
    $message = date('Y-m-d H:i') . " Cannot connect to Redis for publishing " . $e->getMessage() . "\n";
    System_Daemon::notice($message);
    // Just return to prevent the daemon from crashing
    // exit(1);
    return;
}


// Initialize the system
Initialize();

// reset all switches and show what we have
resetAll();

// figureout the timestate, we only do this once, after that it is based on event msgs
$zenith = 90+50/60;
$offset = 1; // offset from UTC in NL
$sunrise_t = date_sunrise (time(), SUNFUNCS_RET_TIMESTAMP, $latitude, $longitude, $zenith, $offset);
$sunset_t = date_sunset (time(), SUNFUNCS_RET_TIMESTAMP, $latitude, $longitude, $zenith, $offset);

$timestate = 0;
if (time() > $sunrise_t - 2*60 ) $timestate = 1;
if (time() > $sunrise) $timestate = 2;
if (time() > $sunset_t - 2*60) $timestate = 3;
if (time() > $sunset_t) $timestate = 4;
        
if ($pubredis) {
    // Initialize a new pubsub context
    $pubsub = $redis->pubSub();

    // Subscribe to your channels
    $pubsub->subscribe('ss:event');

    // Start processing the pubsub messages. Open a terminal and use redis-cli
    // to push messages to the channels. Examples:
    //   ./redis-cli PUBLISH notifications "this is a test"
    //   ./redis-cli PUBLISH control_channel quit_loop
    foreach ($pubsub as $message) {
                        
        // calculate sunrise and sunset using php functions
        $sunrise = date_sunrise (time(), SUNFUNCS_RET_STRING, $latitude, $longitude, $zenith, $offset);
        $sunset = date_sunset (time(), SUNFUNCS_RET_STRING, $latitude, $longitude, $zenith, $offset);

        // set in_the_dark to tru between sunset and sunrise
        $sunrise_t = date_sunrise (time(), SUNFUNCS_RET_TIMESTAMP, $latitude, $longitude, $zenith, $offset);
        $sunset_t = date_sunset (time(), SUNFUNCS_RET_TIMESTAMP, $latitude, $longitude, $zenith, $offset);
        $in_the_dark = true;
        
        if (time() > $sunrise_t) $in_the_dark = false;
        if (time() > $sunset_t) $in_the_dark = true;

        switch ($message->kind) {
            case 'subscribe':
                if ($debug) System_Daemon::info("Subscribed to {$message->channel}\n"); 
                break;

            case 'message':
                if ($debug) System_Daemon::info("Received {$message->payload}\n"); 
                // determine the kind of message
                $obj = json_decode ($message->payload);
                // if e is "newMessage" it is stuff from the Nodo
                // if e is portux, then we deal with it below
                if ($obj->e == 'newMessage') {
                    handleIncoming ($obj->p[0]);
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
                    $message = date('Y-m-d H:i') . " Controller: unknown message type " . $obj->e . "\n";
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
