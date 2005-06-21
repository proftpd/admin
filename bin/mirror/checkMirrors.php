#!/usr/local/bin/php -q
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

	/* FIXME: needs to handle:
	 *   - checking of www.isocode.proftpd.org properly (need to contact
	 *     that specific host and request www.isocode; currently we're
	 *     connecting to www.isocode, which could be any mirror for that
	 *     country).
	 *   - checking FTP sites. Currently only HTTP is supported.
	 */

	/**
	 * Check for an up-to-date mirror URL.
	 *
	 * @param $url string The URL to check. /MIRMON.PROBE is appended
	 *                    automatically.
	 * @return boolean Whether the mirror is accessible an up-to-date.
	 */
	function checkSite($connectTo, $httpHost, $path) {
		/* Timeout in seconds for socket operations. */
		$SOCKET_TIMEOUT = 30;
		/* Number of seconds after which a site is considered stale. */
		$STALE_THRESHOLD = 8 * 24 * 60 * 60;

		$fp = fsockopen($connectTo, 80, $errno, $errstr, $SOCKET_TIMEOUT);
		if ($fp === false) {
			return PEAR::raiseError("Couldn't open connection to $connectTo.");
		}

		$result = fputs($fp, "GET $path/MIRMON.PROBE HTTP/1.1\r\nHost: $httpHost\r\n\r\n");
		if ($result === false) {
			return PEAR::raiseError("Couldn't request $path/MIRMON.PROBE from $connectTo.");
		}

		/* Fast-forward past all the response headers to get to the content. */
		do {
			$line = fgets($fp);
			if ($line === false) {
				return PEAR::raiseError("Couldn't fetch contents of $path/MIRMON.PROBE from $connectHost.");
			}
		} while (!feof($fp) && $line != "\r\n");

		$tstamp = trim(fgets($fp));
		if ($tstamp === false) {
			return PEAR::raiseError("Couldn't fetch contents of $path/MIRMON.PROBE from $connectTo.");
		}

		fclose($fp);

		if ($tstamp + $STALE_THRESHOLD < time()) {
			return false;
		}
		return true;
	}

	function usage() {
		// FIXME
		$basename = basename('checkMirrors.php');
		print <<<EOM
Usage: $basename [option]...
    -t|--type (ftp|www)  Type of mirrors to check

EOM;
	}

	require_once 'Console/Getopt.php';
	require_once 'DB.php';
	require_once 'HTTP/Request.php';
	require_once 'Mail.php';
	require_once 'Net/URL.php';

	$args = Console_Getopt::getopt(Console_Getopt::readPHPArgv(),
		't:', array('type='));
	foreach ($args[0] as $arg) {
		switch ($arg[0]) {
		case 't':
		case '--type':
			if ($arg[1] != 'ftp' && $arg[1] != 'www') {
				usage();
				exit;
			}
			$TYPE = $arg[1];
			break;
		default:
			usage();
			exit;
		}
	}

	if (empty($TYPE)) {
		usage();
		exit;
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
	$query .= 'ORDER BY iso, sequence';

	$result = $db->query($query);
	if (PEAR::isError($result)) {
		die("Couldn't query database server: " . $queryResult->getMessage() . "\n");
	}

	while (($row = $result->fetchRow(DB_FETCHMODE_ASSOC))) {
		if (!preg_match('#^(ftp|http)://([^/]+)($|/.*)#', $row['site'], $matches)) {
			die("Couldn't match URL '" . $row['site'] . "'\n");
		}
		$urlsToCheck = array(
			$baseUrl . $matches[2] . $matches[3],
			$baseUrl . $hostnameBase . $row['sequence'] . '.' . $row['iso'] . '.proftpd.org',
			$urlsToCheck[] = $baseUrl . $hostnameBase . '.' . $row['iso'] . '.proftpd.org'
		);

		foreach ($urlsToCheck as $url) {
			$netUrl = new Net_URL($url);

			$checkResult = checkSite($matches[2], $netUrl->host, $netUrl->path);
			if (PEAR::isError($checkResult)) {
				print $checkResult->getMessage() . "\n";
				continue;
			}

			if ($checkResult !== true) {
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
				print "$url is out of date.\n";
				continue 2;
			}
		}
	}
?>

Done.
