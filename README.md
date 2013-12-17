# Portux

Code on Portux platform to process Arduino messages

The code essentially consists of two processes:
- rcvsend (a daemon) which is started from inittab, opens the serial port and
  stores all received data in a local MySQL database
  Additionally it send a PubSub message the moment it receives a Motion alert from
  the Arduino / Jeenode
- consume which runs every minute, retrieves records from the local
  database, and interprets them, and stores them in the remote database on the server.
  It also pushes the latest values to a Redis store for retrieval of current values by
  Controller.
  Additionally it sends the values to Pachube for updating
  
To control the lights, two additional processes are defined:

- timer.php (another daemon started from inittab) which sends, using PubSub messages, a
  timer Tick every 60 seconds

- controller.php (yet another deamon to be started from inittab). Upon startup it
  retrieves configuration details from the remote contao database on available switches,
  the links to sensors, and the criteria for switching
  
  controller is a finite state machine acting on PubSub messages to/from a Nodo controller
  connected to rpi1.local (in future it may be to a serial port on the Portux)
  
  depending on the actions it publishes Switch type messages on the channel, which the 
  Nodo software on rpi1.local sends to the Nodo via a serial port to take action on.

## PubSub Messages

The channel is 'ss:event' for socketstream event.

The subscribe messages are of the form:

    {
        "t" : "all",
        "e" : "portux",
        "p" : [ 'type', 'location', 'quantity','value']
    }

Where RNR sensors are split into <sensortype> Temperature / Humidity / Light / Motion
and <value> needs no more calculation. The order is changed from the previous version
because this allows easier subscription to e.g. all motion events, or all temperature events.

Additional kinds of messages are:

* Motion messages (type = Motion, location is 2 (Studeerkamer) or 3 (Woonkamer) by rcvsend)
* Switch messages (type = Switch, location is 1..4, quantity is the command string, 
  value is true (On) or false (Off))
* Tick messages (type is Tick, location is blank, as is quantity, value has a timestamp)


### Redis interface

A php Redis interface is added to be able to Publish data values.

The interface selected is Predis (https://github.com/nrk/predis) and is installed
using Composer (http://getcomposer.org).

A composer.json file needs to be created, and then the command

    composer install

needs to be run in the directory of the project. That leads to the rerquired packages
being installed in the subdirectory vendor/. They can then be included in the PHP project

### Redis usage

The Redis datastore resides on machine portux.local and is accessible via
the standard port.

It is also installed as a service and started as a daemon using redis_6379

    sudo update-rc.d redis_6379 defaults 20 40


### Transforming to a Daemon

Using System_Daemon, a PHP class that allows developers to create their own daemon 
applications on Linux systems. The class is focussed entirely on creating & 
spawning standalone daemons

More info at:

- [Blog Article: Create daemons in PHP][1]

  [1]: http://kevin.vanzonneveld.net/techblog/article/create_daemons_in_php/
  
Note that files called rcvsend, timer and controller need to be copied to /etc/init.d
to start it up automatically, and it needs to be initialised by root as:

    sudo update-rc.d rcvsend start 40 2 3 4 5 . stop 40 0 1 6 .
    sudo update-rc.d timer start 45 2 3 4 5 . stop 45 0 1 6 .
    sudo update-rc.d controller start 50 2 3 4 5 . stop 40 0 1 6 .

To remove, execute

    sudo update-rc.d rcvsend remove
    sudo update-rc.d timer remove
    sudo update-rc.d controller remove
   
To start them manually, go to directory /etc/init.d and execute:

    sudo ./rcvsend start
    
etc, etc. 
