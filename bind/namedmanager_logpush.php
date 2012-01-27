<?php
/*
	namedmanger_logpush

	Connects to NamedManager and uploads log messages from the server.

	Copyright (c) 2010 Amberdms Ltd
	Licensed under the GNU AGPL.
*/




/*
	CONFIGURATION
*/

require("include/config.php");
require("include/amberphplib/main.php");
require("include/application/main.php");



/*
	VERIFY LOG FILE ACCESS
*/

if (!is_readable($GLOBALS["config"]["log_file"]))
{
	log_write("error", "script", "Unable to read log file ". $GLOBALS["config"]["log_file"] ."");
	die("Fatal Error");
}



/*
	CHECK LOCK FILE

	We use exclusive file locks in non-blocking mode in order to check whether or not the script is already
	running to prevent any duplicate instances of it.

	The lock uses a file, but the file isn't actually the decider of the lock - so if the script is killed and
	doesn't properly clean up the lock file, it won't prevent the script starting again, as the actual lock
	determination is done using flock()
*/

if (empty($GLOBALS["config"]["lock_file"]))
{
	$GLOBALS["config"]["lock_file"] = "/var/lock/namedmanager_lock_logpush";
}
else
{
	$GLOBALS["config"]["lock_file"] = $GLOBALS["config"]["lock_file"] ."_logpush";
}

if (!file_exists($GLOBALS["config"]["lock_file"]))
{
	touch($GLOBALS["config"]["lock_file"]);
}

$fh_lock = fopen($GLOBALS["config"]["lock_file"], "r+");

if (flock($fh_lock, LOCK_EX | LOCK_NB))
{
	log_write("debug", "script", "Obtained filelock");
}
else
{
	log_write("warning", "script", "Unable to execute script due to active lock file ". $GLOBALS["config"]["lock_file"] .", is another instance running?");
	die("Lock Conflict ". $GLOBALS["config"]["lock_file"] ."\n");
}


// Establish lockfile deconstructor - this is purely for a tidy up process, the file's existance doesn't actually
// determine the lock.
function lockfile_remove()
{
	// delete lock file
	if (!unlink($GLOBALS["config"]["lock_file"]))
	{
		log_write("error", "script", "Unable to remove lock file ". $GLOBALS["config"]["lock_file"] ."");
	}
}

register_shutdown_function('lockfile_remove');



/*


	We have a class here for handling the actual logging, it's smart enough to re-authenticate if the session
	gets terminated without dropping log messages.

	(sessions could get terminated if remote API server reboots, connection times out, no logs get generated for long
	time periods, etc)
*/


class app_main extends soap_api
{

	/*
		log_watch

		Use tail to track the file and push any new log messages to NamedManager.

	*/
	function log_watch()
	{
		while (true)
		{
			// we have a while here to handle the unexpected termination of the tail command
			// by restarting a new connection

			$handle = popen("tail -f ". $GLOBALS["config"]["log_file"] ." 2>&1", 'r');

			while(!feof($handle))
			{
				// we now do a blocking read to the EOL. This solution isn't perfect, infact it does raise a number of issues,
				// for example the pcntl signal handler code won't interrupt the block, and when the script terminates, the tail
				// process won't always close until another log message is posted, upon when the process will end.

				$buffer = fgets($handle);

				// process the log input
				//
				// example format: May 30 15:53:35 localhost named[14286]: Message
				//
				if (preg_match("/^\S*\s*\S*\s\S*:\S*:\S*\s(\S*)\snamed\S*:\s([\S\s]*)$/", $buffer, $matches))
				{
					$this->log_push(time(), $matches[2]);
				
					log_write("debug", "script", "Log Recieved: $buffer");
				}
				else
				{
					log_write("debug", "script", "Unprocessable: $buffer");
				}
			}

			pclose($handle);
		}
	}


} // end of app_main


// call class
$obj_main		= New app_main;

$obj_main->authenticate();
$obj_main->log_watch();

log_write("notification", "script", "Terminating logging process for NamedManager");

?>
