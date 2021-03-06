#! /bin/bash
#
# crond          Start/Stop the centreontrapd daemon.
#
# chkconfig: 2345 71 31
# description: centreontrapd is a Centreon program that manage traps
# processname: centreontrapd
# config: @CENTREON_ETC@
# pidfile: ${RunDir}/centreontrapd.pid

# Source function library.
. /lib/lsb/init-functions
  
binary=@CENTREONTRAPD_BINDIR@/centreontrapd
servicename=$(basename "$0")
user=@CENTREON_USER@
timeout=60
start_timeout=5

pidfile=@CENTREON_RUNDIR@/centreontrapd.pid

# Check if we can find the binary.
if [ ! -x $binary ]; then
    echo -n $"Starting $servicename.";
    log_failure_msg $"Executable file $binary not found. Exiting."
    echo
    exit 2
fi

start() {
	echo -n $"Starting $servicename: "
	if [ -e "$pidfile" ] && [ -n "$(cat $pidfile)" ] && [ -e "/proc/`cat $pidfile`" ]; then
		echo -n $"cannot start $servicename: $servicename is already running.";
		log_failure_msg $"cannot start $servicename: $servicename already running.";
		echo
		return 1
	fi
	if [ ! -e "$pidfile" ] ; then
			pid=$(pidofproc $binary)
			if [ -n "$pid" ] ; then
				echo -n $"cannot start $servicename: $servicename is already running.";
				log_failure_msg $"cannot start $servicename: $servicename already running.";
				echo
				return 1
			fi
	fi

	if [ "$(id -u -n)" = "$user" ] ; then
		startproc $binary
	else
		startproc -u $user $binary
	fi
	
	i=0
	while : ; do
		if [ "$i" -gt $start_timeout ] ; then
			log_failure_msg $"service not launched"
			echo
			return 1
		fi
		pid=$(pidofproc $binary)
		if [ -n "$pid" ] ; then
			echo $pid > $pidfile
			break
		fi
		sleep 1
		i=$(($i + 1))
	done
	log_success_msg $"service launched"
	echo
	return 0
}

stop() {
	echo -n $"Stopping $servicename: "
	if [ ! -e "$pidfile" ] || [ -z "$(cat $pidfile)" ] ; then
		killproc -t$timeout "$binary"
	else
		killproc -p "$pidfile" -t$timeout "$binary" 
	fi
	RETVAL=$?
	echo
	return $RETVAL
}	

rhstatus() {
	if [ ! -e "$pidfile" ] || [ -z "$(cat $pidfile)" ] ; then
		echo -n "$servicename is " 
        checkproc "$binary"
        rc_status -v
	else
		echo -n "$servicename is " 
        checkproc -p "$pidfile" "$binary"
        rc_status -v
	fi
}	

restart() {
  	stop
	start
}	

reload() {
	echo -n $"Reloading $servicename daemon configuration: "
	if [ ! -e "$pidfile" ] || [ -z "$(cat $pidfile)" ] ; then
		killproc "$binary" -HUP
	else
		killproc -p "$pidfile" "$binary" -HUP
	fi
    RETVAL=$?
    echo
    return $RETVAL
}	

case "$1" in
  start)
  	start
	;;
  stop)
  	stop
	;;
  restart)
  	restart
	;;
  reload)
  	reload
	;;
  status)
  	rhstatus
	;;
  condrestart)
  	[ -f /var/lock/subsys/centcore ] && restart || :
	;;
  *)
	echo $"Usage: $0 {start|stop|status|reload|restart|condrestart}"
	exit 1
esac

