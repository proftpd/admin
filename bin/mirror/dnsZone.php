#!/usr/local/bin/php -q
<?php
	/**
	 * Resolve all IP addresses for a given hostname, following any CNAMEs
	 * encountered.
	 *
	 * @param $hostname string The hostname to resolve to IP address(es).
	 * @return array sorted list of IP addresses for the given host
	 */
	function findAddresses($hostname) {
		$records = @dns_get_record($hostname);
		if ($records === false) {
			return PEAR::raiseError("Couldn't determine addresses for $hostname.");
		}

		$addrs = array();
		foreach ($records as $record) {
			/* ??? One of our mirrors (ftp.icm.edu.pl) has a record without
			 * a type?
			 */
			if (!isset($record['type'])) {
				continue;
			}

			switch ($record['type']) {
			case 'A':
				$addrs[] = $record['ip'];
				break;
			case 'CNAME':
				return findAddresses($record['target']);
				continue;
			}
		}
		if (!sort($addrs)) {
			return PEAR::raiseError("Couldn't sort address list for $hostname.");
		}
		return $addrs;
	}

	function usage() {
		// FIXME
		$basename = basename('dnsZone.php');
		print <<<EOM
Usage: $basename output-file

EOM;
	}

	require_once 'Console/Getopt.php';
	require_once 'Console/ProgressBar.php';
	require_once 'DB.php';

	$args = Console_Getopt::getopt(Console_Getopt::readPHPArgv(),
		'', array());
	if (empty($args[1][0])) {
		usage();
		exit;
	}

	$db = &DB::connect('mysql://SQL-USERSQL-PASSWORD@localhost/proftpd');
	if (PEAR::isError($db)) {
		die("Couldn't contact database server: " . $db->getMessage() . "\n");
	}

	$fp = fopen($args[1][0], 'w');

	foreach (array('www', 'ftp') as $type) {
		$query  = 'SELECT site, admin, admin_email, iso, sequence, round_robin ';
		$query .= "FROM ${type}mirrors LEFT JOIN countrycode ON ";
		$query .= "     ${type}mirrors.country_iso = countrycode.iso ";
		$query .= 'WHERE live = "true" ';
		$query .= 'ORDER BY iso, sequence';

		$queryResult = $db->query($query);
		if (PEAR::isError($queryResult)) {
			die("Couldn't query database server: " . $queryResult->getMessage() . "\n");
		}

		$bar = new Console_ProgressBar(
			"* $type %fraction% sites [%bar%] %percent%",
			'=>', '-', 80, $queryResult->numRows(),
			array('ansi_terminal' => false)
		);
		$siteNum = 0;
		$roundRobinSites = array();
		while (($row = $queryResult->fetchRow(DB_FETCHMODE_ASSOC))) {
			$bar->update(++$siteNum);

			if (!preg_match('#^(ftp|http)://([^/]+)($|/)#', $row['site'], $matches)) {
				die("Couldn't match URL '" . $row['site'] . "'\n");
			}
			$host = $matches[2];

			$addrs = findAddresses($host);
			if (PEAR::isError($addrs)) {
				continue;
			}

			if ($row['round_robin'] == 'true') {
				if (!isset($roundRobinSites[$row['iso']])) {
					$roundRobinSites[$row['iso']] = array();
				}
				foreach ($addrs as $addr) {
					$roundRobinSites[$row['iso']][] = $addr;
				}
			}

			$result = fputs($fp, '; ' . $row['site'] . "\n");
			if ($result === false) {
				die("Couldn't write to " . $args[1][0] . ".\n");
			}
			$result = fputs($fp, '; ' . $row['admin'] . ' ' .
			                '<' . $row['admin_email'] . '>' . "\n");
			if ($result === false) {
				die("Couldn't write to " . $args[1][0] . ".\n");
			}
			foreach ($addrs as $addr) {
				$result = fputs($fp, $type . $row['sequence'] . '.' . $row['iso'] . "		IN	A	$addr\n");
				if ($result === false) {
					die("Couldn't write to " . $args[1][0] . ".\n");
				}
			}
			$result = fputs($fp, "\n");
			if ($result === false) {
				die("Couldn't write to " . $args[1][0] . ".\n");
			}
		}

		foreach ($roundRobinSites as $iso => $addrs) {
			foreach ($addrs as $addr) {
				$result = fputs($fp, "$type.$iso		IN	A	$addr\n");
				if ($result === false) {
					die("Couldn't write to " . $args[1][0] . ".\n");
				}
			}
		}
	}

	fclose($fp);
