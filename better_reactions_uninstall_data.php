<?php
/**
 * Better Reactions & Likes
 * Destructive uninstall data cleanup for SMF 2.1.x.
 *
 * This file is invoked through package-info.xml's <database> uninstall action,
 * so SMF runs it only when the administrator checks the native
 * "Remove all data associated with this modification" option.
 *
 * Hooks are intentionally NOT handled here. They are detached by the always-run
 * <code> uninstall action in better_reactions_install.php.
 *
 * @package BetterReactions
 * @version 0.1.0
 */

if (!defined('SMF'))
	die('No direct access...');

global $smcFunc, $boarddir, $sourcedir;

require_once($sourcedir . '/Subs.php');

// Drop Better Reactions-owned tables. DROP TABLE IF EXISTS keeps this safe for
// partial/older installs where one or more tables may not exist.
foreach (array(
	'better_reactions',
	'better_reactions_groups',
	'better_reactions_boards',
	'better_reactions_types',
) as $table)
{
	$smcFunc['db_query']('', 'DROP TABLE IF EXISTS {db_prefix}' . $table, array());
}

// Remove all Better Reactions settings, including settings introduced by newer
// versions that an older uninstall script would not know by name.
$smcFunc['db_query']('', '
	DELETE FROM {db_prefix}settings
	WHERE variable LIKE {string:prefix}',
	array('prefix' => 'better\\_reactions\\_%')
);

// Remove any persisted SMF permission assignments for Better Reactions.
// These tables are core SMF 2.1 tables and may legitimately contain no rows.
$smcFunc['db_query']('', '
	DELETE FROM {db_prefix}permissions
	WHERE permission = {string:permission}',
	array('permission' => 'better_react')
);
$smcFunc['db_query']('', '
	DELETE FROM {db_prefix}board_permissions
	WHERE permission = {string:permission}',
	array('permission' => 'better_react')
);

// Remove Better Reactions' own admin-log event records.
$smcFunc['db_query']('', '
	DELETE FROM {db_prefix}log_actions
	WHERE action = {string:action}',
	array('action' => 'br_cleanup')
);

// Uploaded custom reaction icons are Better Reactions-owned storage. Remove the
// contents only on destructive uninstall; preserved-data uninstall leaves this
// directory untouched so restored/reinstalled reaction definitions still work.
$icon_dir = !empty($boarddir) ? $boarddir . '/better_reactions_icons' : '';
if ($icon_dir !== '' && is_dir($icon_dir))
{
	$items = @scandir($icon_dir);
	if (is_array($items))
	{
		foreach ($items as $item)
		{
			if ($item === '.' || $item === '..')
				continue;

			$path = $icon_dir . '/' . $item;
			if (is_file($path) || is_link($path))
				@unlink($path);
		}
	}
	@rmdir($icon_dir);
}
