<?php
namespace Opencart\System\Library\Ccfeeds;

/**
 * A copy of the module's settings that outlives uninstall, shared by the
 * OpenCart 4 / 3 / 2.3 builds.
 *
 * Why: OpenCart deletes the whole setting group of an extension the moment it
 * is uninstalled — the feed token with it — and only THEN calls the module's
 * own uninstall(). On OpenCart 4 the installer refuses an archive over an
 * installed extension, so an update through the admin is uninstall + install,
 * and every feed URL the shop has handed to Rozetka / Prom / Google / Meta
 * changed. The copy lives in its own table, which uninstall never drops:
 *
 *   save()      mirrors the setting rows exactly as the core wrote them;
 *   uninstall() refreshes the copy from the request config (the core has
 *               already deleted the rows, the config of this request still
 *               holds them) — covers a shop that got the new files by copying
 *               them and never pressed Save;
 *   install()   puts the rows back when the group is empty;
 *   reset()     deletes the copy and leaves a marker, so the next uninstall
 *               keeps nothing and the next install starts clean. The next
 *               Save starts keeping a copy again.
 *
 * PHP 5.6+ syntax.
 */
class SavedSettings {

	/** Marker row written by reset(): "do not keep a copy until the next Save". */
	const RESET_KEY = '__reset__';

	private static function table() {
		return DB_PREFIX . 'cc_feeds_saved_setting';
	}

	/**
	 * ⚠ No unique index over (code, store_id, key): utf8mb4 makes it longer
	 * than the 767 bytes old InnoDB row formats allow. Rows of a code are
	 * always replaced as a whole, so a plain index is enough.
	 */
	public static function ensureTable($db) {
		$sql = "CREATE TABLE IF NOT EXISTS `" . self::table() . "` (
			`saved_setting_id` int(11) NOT NULL AUTO_INCREMENT,
			`code` varchar(64) NOT NULL,
			`store_id` int(11) NOT NULL DEFAULT '0',
			`key` varchar(128) NOT NULL,
			`value` mediumtext NOT NULL,
			`serialized` tinyint(1) NOT NULL DEFAULT '0',
			`date_saved` datetime NOT NULL,
			PRIMARY KEY (`saved_setting_id`),
			KEY `code` (`code`)
		) ENGINE=InnoDB DEFAULT CHARSET=";

		try {
			$db->query($sql . "utf8mb4 COLLATE=utf8mb4_general_ci");
		} catch (\Exception $e) {
			$db->query($sql . "utf8 COLLATE=utf8_general_ci");
		}
	}

	/** Mirror the setting rows of $code as they are in the database now. */
	public static function capture($db, $code) {
		self::ensureTable($db);

		$rows = $db->query("SELECT `store_id`, `key`, `value`, `serialized` FROM `" . DB_PREFIX . "setting` WHERE `code` = '" . $db->escape($code) . "' ORDER BY `store_id`, `key`")->rows;

		if (!$rows) {
			return 0;
		}

		self::replace($db, $code, $rows);

		return count($rows);
	}

	/**
	 * Called from the module's uninstall(). The core has already deleted the
	 * rows; the config of this request was loaded before that and still holds
	 * them (store 0 — the only store the module saves to). Arrays go back in
	 * the core's own encoding: json_encode, serialized = 1.
	 *
	 * After reset() nothing is kept, and an empty config never overwrites a
	 * good copy.
	 *
	 * @param object $config the request config
	 * @param array  $keys   full setting keys of the group
	 */
	public static function captureOnUninstall($db, $code, $config, $keys) {
		self::ensureTable($db);

		if (self::isReset($db, $code)) {
			return 0;
		}

		// Some core builds may call the module before deleting the rows.
		$kept = self::capture($db, $code);
		if ($kept) {
			return $kept;
		}

		$rows = array();
		foreach ($keys as $key) {
			// ⚠ has(), not get() !== null: OpenCart 4 get() answers '' for a key
			// it never loaded, which would invent rows the shop never had.
			if (!$config->has($key)) {
				continue;
			}
			$value = $config->get($key);

			if (is_array($value)) {
				$rows[] = array('store_id' => 0, 'key' => $key, 'value' => json_encode($value), 'serialized' => 1);
			} else {
				$rows[] = array('store_id' => 0, 'key' => $key, 'value' => (string)$value, 'serialized' => 0);
			}
		}

		if (!$rows) {
			return 0;
		}

		self::replace($db, $code, $rows);

		return count($rows);
	}

	/**
	 * Put the saved rows back, byte for byte, if the group is empty. Called
	 * from install(). The copy stays: the next Save refreshes it anyway.
	 *
	 * @return int rows restored
	 */
	public static function restore($db, $code) {
		self::ensureTable($db);

		$present = (int)$db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "setting` WHERE `code` = '" . $db->escape($code) . "'")->row['total'];
		if ($present > 0) {
			return 0;
		}

		$rows = $db->query("SELECT `store_id`, `key`, `value`, `serialized` FROM `" . self::table() . "` WHERE `code` = '" . $db->escape($code) . "' AND `key` <> '" . self::RESET_KEY . "' ORDER BY `saved_setting_id`")->rows;

		foreach ($rows as $row) {
			$db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '" . (int)$row['store_id'] . "', `code` = '" . $db->escape($code) . "', `key` = '" . $db->escape($row['key']) . "', `value` = '" . $db->escape($row['value']) . "', `serialized` = '" . (int)$row['serialized'] . "'");
		}

		return count($rows);
	}

	/** "Reset saved settings": drop the copy, keep nothing on the next uninstall. */
	public static function reset($db, $code) {
		self::ensureTable($db);

		$db->query("DELETE FROM `" . self::table() . "` WHERE `code` = '" . $db->escape($code) . "'");
		$db->query("INSERT INTO `" . self::table() . "` SET `code` = '" . $db->escape($code) . "', `store_id` = '0', `key` = '" . self::RESET_KEY . "', `value` = '', `serialized` = '0', `date_saved` = NOW()");
	}

	/**
	 * For the settings screen.
	 *
	 * @return array count (settings kept), date (Y-m-d H:i or ''), reset (bool)
	 */
	public static function info($db, $code) {
		self::ensureTable($db);

		$row = $db->query("SELECT COUNT(*) AS `total`, MAX(`date_saved`) AS `saved` FROM `" . self::table() . "` WHERE `code` = '" . $db->escape($code) . "' AND `key` <> '" . self::RESET_KEY . "'")->row;

		$count = (int)$row['total'];

		return array(
			'count' => $count,
			'date'  => ($count > 0 && !empty($row['saved'])) ? substr((string)$row['saved'], 0, 16) : '',
			'reset' => self::isReset($db, $code),
		);
	}

	private static function isReset($db, $code) {
		return (int)$db->query("SELECT COUNT(*) AS `total` FROM `" . self::table() . "` WHERE `code` = '" . $db->escape($code) . "' AND `key` = '" . self::RESET_KEY . "'")->row['total'] > 0;
	}

	private static function replace($db, $code, $rows) {
		$db->query("DELETE FROM `" . self::table() . "` WHERE `code` = '" . $db->escape($code) . "'");

		foreach ($rows as $row) {
			$db->query("INSERT INTO `" . self::table() . "` SET `code` = '" . $db->escape($code) . "', `store_id` = '" . (int)$row['store_id'] . "', `key` = '" . $db->escape($row['key']) . "', `value` = '" . $db->escape($row['value']) . "', `serialized` = '" . (int)$row['serialized'] . "', `date_saved` = NOW()");
		}
	}
}
