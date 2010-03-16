#!/usr/bin/php -q
<?php

/* ProFTPD Mirror Network Maintenance System
 * Copyright (c) 2005, John Morrissey <jwm@horde.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307, USA.
 */


/* Timeout in seconds for socket operations. */
$SOCKET_TIMEOUT = 90;
/* Number of seconds after which a site is considered stale. */
$STALE_THRESHOLD = 2 * 24 * 60 * 60;


/**
 * Check for an up-to-date mirror URL.
 *
 * @param $url string The URL to check. /MIRMON.PROBE is appended
 *                    automatically.
 * @return boolean Whether the mirror is accessible an up-to-date.
 */
function checkSite($netUrl, $connectTo = null) {
	/* To test round-robin hostnames, we need to connect to a specific
	 * node to test and send a Host: header with the round-robin
	 * hostname to make sure the node handles it. I couldn't find a good
	 * way to do this any other way, so we'll send the HTTP commands/
	 * headers ourselves.
	 */
	if ($netUrl->protocol == 'http') {
		$toHost = (!empty($connectTo) ? $connectTo : $netUrl->host);

		$fp = @fsockopen($toHost, $netUrl->port, $errno, $errstr, $GLOBALS['SOCKET_TIMEOUT']);
		if ($fp === false) {
			return PEAR::raiseError("Couldn't open connection to $toHost: ($errno) $errstr.");
		}

		$result = fputs($fp, "GET $netUrl->path/MIRMON.PROBE HTTP/1.1\r\nHost: $netUrl->host\r\nAccept: */*\r\nUser-Agent: proftpd.org mirror monitoring system\r\n\r\n");
		if ($result === false) {
			return PEAR::raiseError("Couldn't request $netUrl->path/MIRMON.PROBE from $toHost.");
		}

		/* Fast-forward past all the response headers to get to the content. */
		do {
			$line = fgets($fp);
			if ($line === false) {
				return PEAR::raiseError("Couldn't fetch contents of $netUrl->path/MIRMON.PROBE from $toHost.");
			}
		} while (!feof($fp) && $line != "\r\n");
	} else {
		$fp = fopen($netUrl->getURL() . '/MIRMON.PROBE', 'r');
		if ($fp === false) {
			return PEAR::raiseError("Couldn't retrieve " . $netUrl->getURL() . '/MIRMON.PROBE');
		}
	}

	$tstamp = trim(fgets($fp));
	if ($tstamp === false) {
		return PEAR::raiseError("Couldn't fetch contents of $path/MIRMON.PROBE from $connectTo.");
	}

	fclose($fp);

	if (strspn($tstamp, "0123456789") != strlen($tstamp)) {
		return PEAR::raiseError("Got bad timestamp from $netUrl->host" . ((isset($toHost) && $toHost != $netUrl->host) ? " ($toHost)" : null) . ": $tstamp\n");
	}

	if ($GLOBALS['VERBOSE']) {
		print "Got '$tstamp' (" . date('Y-m-d H:i:s', $tstamp) . ") for $netUrl->host" . ((isset($toHost) && $toHost != $netUrl->host) ? " ($toHost)" : null) . "\n";
	}
	if ($tstamp + $GLOBALS['STALE_THRESHOLD'] < time()) {
		return false;
	}
	return true;
}

function usage() {
	// FIXME
	$basename = basename($GLOBALS['argv'][0]);
	print <<<EOM
Usage: $basename [option]...
    -t, --type (ftp|www)  Type of mirrors to check
    -v, --verbose         Emit status output for each mirror, regardless of
                          whether it's up to date
    -m, --mirror=URL      Check whether mirrors matching URL are up to date

EOM;
}

require_once 'Console/Getopt.php';
require_once 'DB.php';
require_once 'HTTP/Request.php';
require_once 'Mail.php';
require_once 'Net/URL.php';

$VERBOSE = false;

$args = Console_Getopt::getopt(Console_Getopt::readPHPArgv(),
	'm:t:v', array('mirror=', 'type=', 'verbose'));
if (PEAR::isError($args)) {
	print $args->getMessage() . "\n";
	usage();
	exit(1);
}
foreach ($args[0] as $arg) {
	switch ($arg[0]) {
	case 't':
	case '--type':
		if ($arg[1] != 'ftp' && $arg[1] != 'www') {
			usage();
			exit(1);
		}
		$TYPE = $arg[1];
		break;

	case 'v':
	case '--verbose':
		$VERBOSE = true;
		break;
		
	case 'm':
	case '--mirror':
		$MATCH_SUBSET = $arg[1];
		break;
		
	default:
		usage();
		exit(1);
	}
}

if (empty($TYPE)) {
	usage();
	exit(1);
}

$db = &DB::connect('mysql://SQL-USER:SQL-PASSWORD@localhost/proftpd');
if (PEAR::isError($db)) {
	die("Couldn't contact database server: " . $db->getMessage() . "\n");
}

if ($TYPE == 'ftp') {
	$table = 'ftpmirrors';
	$hostnameBase = 'ftp';
	$baseUrl = 'ftp://';
} elseif ($TYPE == 'www') {
	$table = 'wwwmirrors';
	$hostnameBase = 'www';
	$baseUrl = 'http://';
}

$query  = 'SELECT * ';
$query .= "FROM $table LEFT JOIN countrycode ON ";
$query .= "     $table.country_iso = countrycode.iso ";
$query .= "WHERE live = 'true' ";
if (isset($MATCH_SUBSET)) {
	$query .= "AND site LIKE '%$MATCH_SUBSET%' ";
}
$query .= 'ORDER BY iso, sequence';

$result = $db->query($query);
if (PEAR::isError($result)) {
	die("Couldn't query database server: " . $queryResult->getMessage() . "\n");
}

while (($row = $result->fetchRow(DB_FETCHMODE_ASSOC))) {
	$siteUrl = new Net_URL($row['site']);
	$urlsToCheck = array($siteUrl);

	if ($siteUrl->protocol == 'http') {
		$sequenceUrl = new Net_URL($row['site']);
		$sequenceUrl->host = $hostnameBase . $row['sequence'] . '.' . $row['iso'] . '.proftpd.org';
		$sequenceUrl->path = '/';
		$urlsToCheck[] = $sequenceUrl;

		$roundRobinUrl = new Net_URL($row['site']);
		$roundRobinUrl->host = $hostnameBase . '.' . $row['iso'] . '.proftpd.org';
		$roundRobinUrl->path = '/';
		$urlsToCheck[] = $roundRobinUrl;
	}

	foreach ($urlsToCheck as $url) {
		$checkResult = checkSite($url,
			($TYPE == 'www' ? $urlsToCheck[0]->host : null));
		if ($checkResult !== true || PEAR::isError($checkResult)) {
			if (PEAR::isError($checkResult)) {
				print $checkResult->getMessage() . "\n";
			}

			$headers = array(
				'To' => $row['admin'] . '<' . $row['admin_email'] . '>, ' .
				        'core@proftpd.org',
				'From' => 'core@proftpd.org',
				'Subject' => 'proftpd.org mirror update'
			);

			$body  = $row['admin'] . ",\n";
			$body .= "\n";
			$body .= "This is a semi-automatic email to inform you that your mirror of\n";
			$body .= "\n";
			$body .= "	$baseUrl$hostnameBase.proftpd.org/\n";
			$body .= "\n";
			$body .= "does not appear to be functioning properly.\n";
			$body .= "\n";
			$body .= "The details we have on record for your mirror are:\n";
			$body .= "\n";
			$body .= "	Site:        " . $row['site'] . "\n";
			$body .= "	Admin:       " . $row['admin'] . "\n";
			$body .= "	Admin_email: " . $row['admin_email'] . "\n";
			$body .= "	Site Info:   " . $row['other_details'] . "\n";
			$body .= "	Updated:     " . $row['updated'] . "\n";
			$body .= "	Location:    " . $row['city'] . '/' . $row['country_iso'] . "\n";
			$body .= "\n";
			$body .= "Your mirror should be accepting connections for the following sites\n";
			$body .= "\n";
			$body .= "	$baseUrl$hostnameBase." . $row['country_iso'] . ".proftpd.org/\n";
			$body .= "	$baseUrl$hostnameBase" . $row['sequence'] . '.' . $row['country_iso'] . ".proftpd.org/\n";
			$body .= "\n";
			$body .= "We have temporarily removed your site from the proftpd.org DNS. Please let\n";
			$body .= "us know when things are back to normal or if you wish to cease being a\n";
			$body .= "mirror site, and we will update our records accordingly.\n";
			$body .= "\n";
			$body .= "Thanks,\n";
			$body .= "The ProFTPD Core Team\n";

$headers['To'] = 'jwm@horde.net';
			$mailer = &Mail::factory('sendmail',
				array('sendmail_path' => '/usr/lib/sendmail'));
			$mailResult = $mailer->send($headers['To'], $headers, $body);
			if (PEAR::isError($mailResult)) {
				die("Couldn't send message to " . $headers['To'] . ': ' . $mailResult->getMessage() . "\n");
			}
			print $url->getURL() .
				' (' . ($TYPE == 'www' ? $urlsToCheck[0]->host : null) . ') ' .
				"is out of date.\n";
			continue 2;
		}
	}
}
