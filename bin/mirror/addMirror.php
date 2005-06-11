<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "DTD/xhtml1-transitional.dtd">
<html>
<head>
	<title>Add Your Mirror to the ProFTPD Mirror Network</title>
</head>

<body bgcolor="#ffffff">

<img src="http://www.proftpd.org/proftpd.png" width="215" height="92" alt="[ProFTPD Logo]" />
<h1>Add Your Mirror to the ProFTPD Mirror Network</h1>

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

	if (isset($_REQUEST['submit'])) {
		$result = addMirror();
	}
?>

<?php if (!isset($result) || PEAR::isError($result)): ?>
	<?php if (isset($result)): ?>
		<div style="background: yellow">
			<?php echo $result->getMessage() ?>
		</div>
		<br />
	<?php endif; ?>

	<form method="post" action="<?php echo $_SERVER['PHP_SELF'] ?>">
	<table>
	<tr>
		<td>Mirror type:</td>
		<td>
			<select name="type">
				<option value="web">Web site</option>
				<option value="ftp">FTP site</option>
			</select>
		</td>
	</tr>
	<tr>
		<td>URL:</td>
		<td><input size="50" name="url" value="<?php echo @$_REQUEST['url'] ?>" /></td>
	</tr>
	<tr>
		<td>Admin name:</td>
		<td><input size="50" name="adminName" value="<?php echo @$_REQUEST['adminName'] ?>" /></td>
	</tr>
	<tr>
		<td>Admin e-mail address:</td>
		<td><input size="50" name="adminEmail" value="<?php echo @$_REQUEST['adminEmail'] ?>" /></td>
	</tr>
	<tr>
		<td>Location of mirror:</td>
		<td><input size="50" name="location" value="<?php echo @$_REQUEST['location'] ?>" /></td>
	</tr>
	<tr>
		<td>ISO country code (e.g., us, de, etc.):</td>
		<td><input size="2" maxlength="2" name="countrycode" value="<?php echo @$_REQUEST['countrycode'] ?>" /></td>
	</tr>
	<tr>
		<td>Mirror update frequency:</td>
		<td><input size="50" name="updated" value="<?php echo @$_REQUEST['updated'] ?>" /></td>
	</tr>
	<tr>
		<td>Other information:</td>
		<td><input size="50" name="details" value="<?php echo @$_REQUEST['details'] ?>" /></td>
	</tr>
	<tr>
		<td></td>
		<td><input type="submit" name="submit" value="Submit" /></td>
	</tr>
	</table>
	</form>
<?php endif; ?>

</body>
</html>

<?php
	function addMirror() {
		require_once 'DB.php';

		$db = &DB::connect('mysql://SQL-USER:SQL-PASSWORD@localhost/proftpd');
		if (PEAR::isError($db)) {
			return $db;
		}

		if ($_REQUEST['type'] == 'ftp') {
			$table = 'ftpmirrors';
		} elseif ($_REQUEST['type'] == 'web') {
			$table = 'wwwmirrors';
		} else {
			return PEAR::raiseError('Please select a mirror type.');
		}

		$query  = 'SELECT * FROM countrycode ';
		$query .= 'WHERE iso = ' . $db->quote($_REQUEST['countrycode']);
		$result = $db->query($query);
		if (PEAR::isError($result)) {
			return $result;
		}
?>

<?php if ($result->numRows() == 0): ?>
	<?php return PEAR::raiseError("Sorry, that country code isn't listed in my database. Please try again."); ?>
<?php endif; ?>

<?php
		$query  = "SELECT * from $table ";
		$query .= 'WHERE country_iso = ' . $db->quote($_REQUEST['countrycode']);
		$query .= 'ORDER BY sequence DESC ';
		$query .= 'LIMIT 1';
		$result = $db->query($query);
		if (PEAR::isError($result)) {
			return $result;
		}

		if ($result->numRows() != 0) {
			$row = $result->fetchRow(DB_FETCHMODE_ASSOC);
			$sequence = $row['sequence'] + 1;
		} else {
			$sequence = 1;
		}

		$info  = 'Type of Mirror: ' . $_REQUEST['type'] . "\n";
		$info .= 'URLs: ' . $_REQUEST['url'] . ",\n" .
		         '      www.' . $_REQUEST['countrycode'] . ".proftpd.org,\n" .
		         "      www$sequence." . $_REQUEST['countrycode'] . ".proftpd.org\n";
		$info .= 'Admin Name: ' . $_REQUEST['adminName'] . "\n";
		$info .= 'Admin Email address: ' . $_REQUEST['adminEmail'] . "\n";
		$info .= 'Physical Location: ' . $_REQUEST['location'] . "\n";
		$info .= 'Country Code: ' . $_REQUEST['countrycode'] . "\n";
		$info .= 'Updated: ' . $_REQUEST['updated'] . "\n";
		$info .= 'Other site details: ' . $_REQUEST['details'] . "\n";
?>

	<p>Thank you for submitting your mirror. Once your details have been
	approved, they will be added to the mirror page and round-robin DNS for
	your geographical region.</p>

	<?php if ($_REQUEST['type'] == 'web'): ?>
		<p><strong>Please note:</strong> if your mirror site does not have
		a dedicated IP address, you need to add a <tt>ServerAlias</tt>
		directive for each of the following hostnames <strong>before your
		mirror can be approved:</strong></p>

		<ul>
			<li>www.<?php echo $_REQUEST['countrycode'] ?>.proftpd.org</li>
			<li>www<?php echo $sequence ?>.<?php echo $_REQUEST['countrycode'] ?>.proftpd.org</li>
		</ul>
	<?php endif; ?>

<hr />
<h2>Submitted Details</h2>
<p><tt><pre><?php echo $info ?></pre></tt></p>

<?php
		$query  = "INSERT INTO $table (site, admin, admin_email, country_iso, ";
		$query .= '                    city, other_details, updated, ';
		$query .= '                    live, sequence, round_robin) ';
		$query .= 'VALUES (' . $db->quote($_REQUEST['url']) . ', ';
		$query .=              $db->quote($_REQUEST['adminName']) . ', ';
		$query .=              $db->quote($_REQUEST['adminEmail']) . ', ';
		$query .=              $db->quote($_REQUEST['countrycode']) . ', ';
		$query .=              $db->quote($_REQUEST['location']) . ', ';
		$query .=              $db->quote($_REQUEST['details']) . ', ';
		$query .=              $db->quote($_REQUEST['updated']) . ', ';
		$query .=              "true, $sequence, true)";
		$result = $db->query($query);
		if (PEAR::isError($result)) {
			return $result;
		}

		$headers = array(
			'To' => 'core@proftpd.org',
			'From' => 'core@proftpd.org',
			'Subject' => 'ProFTPD Mirror Site Submission'
		);

		require_once 'Mail.php';
		$mailer = &Mail::factory('sendmail',
			array('sendmail_path' => '/usr/lib/sendmail'));
		$result = $mailer->send($headers['To'], $headers, $info);
		if (PEAR::isError($result)) {
			return $result;
		}

		return true;
	}
?>
