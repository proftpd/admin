#!/usr/bin/php -q
<?php

/* ProFTPD Mirror Network Maintenance System
 * Copyright (c) 2005, 2006, 2008, John Morrissey <jwm@horde.net>
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

require_once 'DB.php';

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
		switch ($record['type']) {
		case 'A':
			$addrs[] = 'A:' . $record['ip'];
			break;
		case 'AAAA':
			$addrs[] = 'AAAA:' . $record['ipv6'];
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

$db = &DB::connect('mysql://SQL-USER:SQL-PASSWORD@localhost/proftpd');
if (PEAR::isError($db)) {
	print "Couldn't contact database server: " . $db->getMessage() . "\n";
	exit(1);
}

foreach (array('www', 'ftp') as $hostType) {
	$query  = 'SELECT site, admin, admin_email, iso, sequence ';
	$query .= "FROM ${hostType}mirrors LEFT JOIN countrycode ON ";
	$query .= "     ${hostType}mirrors.country_iso = countrycode.iso ";
	$query .= 'WHERE live = "true" ';
	$query .= 'ORDER BY iso, sequence';

	$queryResult = $db->query($query);
	if (PEAR::isError($queryResult)) {
		print "Couldn't query database server: " . $queryResult->getMessage() . "\n";
		exit(1);
	}

	$roundRobinHosts = array();
	while (($row = $queryResult->fetchRow(DB_FETCHMODE_ASSOC))) {
		if (!preg_match('#^(ftp|http)://([^/]+)($|/)#', $row['site'], $matches)) {
			print "Couldn't match URL '" . $row['site'] . "'\n";
			exit(1);
		}
		$host = $matches[2];

		if (!isset($roundRobinHosts[$row['iso']])) {
			$roundRobinHosts[$row['iso']] = array();
		}
		$roundRobinHosts[$row['iso']][] = $host;

		print '; ' . $row['site'] . "\n";
		print '; ' . $row['admin'] . ' ' .
			'<' . $row['admin_email'] . '>' . "\n";
		print $hostType . $row['sequence'] . '.' . $row['iso'] .
			"		IN	CNAME	$host\n\n";
	}

	foreach ($roundRobinHosts as $iso => $hosts) {
		if (count($hosts) == 1) {
			print "$hostType.$iso		IN	CNAME	" .
				$hosts[0] . "\n";
			continue;
		}

		foreach ($hosts as $host) {
			$addrs = findAddresses($host);
			if (PEAR::isError($addrs)) {
				continue;
			}
			foreach ($addrs as $addr) {
				list($type, $ip) = explode(':', $addr, 2);
				print "$hostType.$iso		IN	$type	$ip\n";
			}
		}
	}

	print "\n";
}
