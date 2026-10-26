<?php
/**
 * Better Reactions & Likes
 * Foundation installer/uninstaller for SMF 2.1.x.
 *
 * @package BetterReactions
 * @version 0.1.0
 */

if (!defined('SMF'))
	die('No direct access...');

global $smcFunc, $context, $sourcedir, $boarddir, $modSettings, $settings;

require_once($sourcedir . '/Subs.php');

// Package database helpers (db_create_table, etc.) are not part of the base
// database API in SMF 2.1. Load them explicitly before using them.
if (!isset($smcFunc['db_create_table']))
{
	if (function_exists('db_extend'))
		db_extend('packages');
}

$hooks = array(
	'integrate_pre_load' => 'BetterReactions_Bootstrap',
	'integrate_load_theme' => 'BetterReactions_LoadTheme',
	'integrate_actions' => 'BetterReactions_Actions',
	'integrate_load_permissions' => 'BetterReactions_Permissions',
	'integrate_admin_areas' => 'BetterReactions_AdminAreas',
	'integrate_profile_areas' => 'BetterReactions_ProfileAreas',
	'integrate_pre_profile_areas' => 'BetterReactions_ProfileAreas',
	'integrate_valid_likes' => 'BetterReactions_ValidLikes',
	'integrate_issue_like_before' => 'BetterReactions_IssueLikeBefore',
	'integrate_issue_like' => 'BetterReactions_IssueLike',
	'integrate_likes_json_response' => 'BetterReactions_LikesJsonResponse',
);

$hook_file = '$sourcedir/BetterReactions.php';

// v0.9.0b: The old shared admin template is obsolete. Remove it only when
// it actually exists. SMF's declarative <remove-file> package action treats a
// missing file as a test-install error, which breaks clean installs/upgrades.
$legacy_admin_template = !empty($settings['default_theme_dir'])
	? $settings['default_theme_dir'] . '/BetterReactionsAdmin.template.php'
	: (!empty($boarddir) ? $boarddir . '/Themes/default/BetterReactionsAdmin.template.php' : '');

if ($legacy_admin_template !== '' && is_file($legacy_admin_template))
	@unlink($legacy_admin_template);

// v0.3.0 moves settings out of SMF's generic modification settings page.
remove_integration_function('integrate_general_mod_settings', 'BetterReactions_Settings', true, $hook_file);
remove_integration_function('integrate_general_mod_settings', 'BetterReactions_Settings', true);

if (!empty($context['uninstalling']))
{
	foreach ($hooks as $hook => $function)
	{
		// Remove both the corrected v0.1.1 form and the malformed v0.1.0 form.
		remove_integration_function($hook, $function, true, $hook_file);
		remove_integration_function($hook, $function, true);
	}

	// Intentionally preserve reaction data on uninstall.
	return;
}

// Repair any malformed v0.1.0 hook registrations first, then register the
// proper source-file-aware hooks. SMF 2.1 signature is:
// add_integration_function($hook, $function, $permanent, $file, $object).
foreach ($hooks as $hook => $function)
{
	remove_integration_function($hook, $function, true);
	add_integration_function($hook, $function, true, $hook_file);
}

// Remove the stray installer copied to the board root by failed v0.1.0 installs.
if (!empty($boarddir) && is_file($boarddir . '/better_reactions_install.php'))
	@unlink($boarddir . '/better_reactions_install.php');

if (!isset($smcFunc['db_create_table']) || !is_callable($smcFunc['db_create_table']))
	fatal_error('Better Reactions could not initialize SMF package database functions.', false);

$smcFunc['db_create_table']('{db_prefix}better_reactions_types',
	array(
		array('name' => 'id_reaction', 'type' => 'smallint', 'size' => 5, 'unsigned' => true, 'auto' => true),
		array('name' => 'reaction_key', 'type' => 'varchar', 'size' => 32, 'default' => ''),
		array('name' => 'label', 'type' => 'varchar', 'size' => 80, 'default' => ''),
		array('name' => 'icon', 'type' => 'varchar', 'size' => 255, 'default' => ''),
		array('name' => 'sort_order', 'type' => 'smallint', 'size' => 5, 'unsigned' => true, 'default' => 0),
		array('name' => 'enabled', 'type' => 'tinyint', 'size' => 1, 'unsigned' => true, 'default' => 1),
		array('name' => 'is_native_like', 'type' => 'tinyint', 'size' => 1, 'unsigned' => true, 'default' => 0),
	),
	array(
		array('type' => 'primary', 'columns' => array('id_reaction')),
		array('type' => 'unique', 'columns' => array('reaction_key')),
	),
	array(),
	'ignore'
);

$smcFunc['db_create_table']('{db_prefix}better_reactions_boards',
	array(
		array('name' => 'id_board', 'type' => 'smallint', 'size' => 5, 'unsigned' => true, 'default' => 0),
		array('name' => 'enabled', 'type' => 'tinyint', 'size' => 1, 'unsigned' => true, 'default' => 1),
		array('name' => 'allowed_reactions', 'type' => 'varchar', 'size' => 255, 'default' => ''),
	),
	array(
		array('type' => 'primary', 'columns' => array('id_board')),
	),
	array(),
	'ignore'
);

$smcFunc['db_create_table']('{db_prefix}better_reactions_groups',
	array(
		array('name' => 'id_group', 'type' => 'smallint', 'size' => 5, 'default' => 0),
		array('name' => 'enabled', 'type' => 'tinyint', 'size' => 1, 'unsigned' => true, 'default' => 1),
		array('name' => 'allowed_reactions', 'type' => 'varchar', 'size' => 255, 'default' => ''),
	),
	array(
		array('type' => 'primary', 'columns' => array('id_group')),
	),
	array(),
	'ignore'
);

$smcFunc['db_create_table']('{db_prefix}better_reactions',
	array(
		array('name' => 'id_reaction_record', 'type' => 'bigint', 'size' => 20, 'unsigned' => true, 'auto' => true),
		array('name' => 'id_member', 'type' => 'mediumint', 'size' => 8, 'unsigned' => true, 'default' => 0),
		array('name' => 'content_type', 'type' => 'varchar', 'size' => 32, 'default' => ''),
		array('name' => 'content_id', 'type' => 'bigint', 'size' => 20, 'unsigned' => true, 'default' => 0),
		array('name' => 'reaction_key', 'type' => 'varchar', 'size' => 32, 'default' => ''),
		array('name' => 'created_at', 'type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0),
	),
	array(
		array('type' => 'primary', 'columns' => array('id_reaction_record')),
		array('type' => 'unique', 'columns' => array('id_member', 'content_type', 'content_id')),
		array('type' => 'index', 'columns' => array('content_type', 'content_id')),
		array('type' => 'index', 'columns' => array('reaction_key')),
	),
	array(),
	'ignore'
);

$defaults = array(
	array('like', 'Like', 'thumbs-up', 10, 1, 1),
	array('love', 'Love', 'emoji:❤️', 20, 1, 0),
	array('laugh', 'Laugh', 'emoji:😂', 30, 1, 0),
	array('wow', 'Wow', 'emoji:😮', 40, 1, 0),
	array('sad', 'Sad', 'emoji:😢', 50, 1, 0),
	array('celebrate', 'Celebrate', 'emoji:🎉', 60, 1, 0),
	array('fire', 'Fire', 'emoji:🔥', 70, 1, 0),
);

foreach ($defaults as $reaction)
{
	$smcFunc['db_insert']('ignore',
		'{db_prefix}better_reactions_types',
		array(
			'reaction_key' => 'string-32',
			'label' => 'string-80',
			'icon' => 'string-255',
			'sort_order' => 'int',
			'enabled' => 'int',
			'is_native_like' => 'int',
		),
		array($reaction),
		array('reaction_key')
	);
}


// v0.3.6: migrate only untouched legacy built-in icon tokens to their real
// Unicode emoji values. Any administrator-customized icon is deliberately left
// alone.
$legacy_icon_migrations = array(
	'love' => array('heart', 'emoji:❤️'),
	'laugh' => array('laugh', 'emoji:😂'),
	'wow' => array('wow', 'emoji:😮'),
	'sad' => array('sad', 'emoji:😢'),
	'celebrate' => array('celebrate', 'emoji:🎉'),
	'fire' => array('fire', 'emoji:🔥'),
);
foreach ($legacy_icon_migrations as $reaction_key => $migration)
{
	$smcFunc['db_query']('', '
		UPDATE {db_prefix}better_reactions_types
		SET icon = {string:new_icon}
		WHERE reaction_key = {string:reaction_key}
			AND icon = {string:legacy_icon}',
		array(
			'new_icon' => $migration[1],
			'reaction_key' => $reaction_key,
			'legacy_icon' => $migration[0],
		)
	);
}

$setting_defaults = array(
	'better_reactions_enabled' => 1,
	'better_reactions_multiple' => 0,
	'better_reactions_native_like_bridge' => 1,
	'better_reactions_show_who' => 1,
	'better_reactions_profile_links' => 1,
	'better_reactions_show_counts' => 1,
	'better_reactions_who_hover' => 1,
	'better_reactions_show_labels' => 1,
	'better_reactions_animate_icons' => 1,
	'better_reactions_icon_size' => 'comfortable',
	'better_reactions_active_preset' => 'classic',
	'better_reactions_picker_visible' => 7,
	'better_reactions_picker_categories' => 1,
	'better_reactions_category_map' => '{}',
	'better_reactions_staff_only' => '[]',
	'better_reactions_topic_states_enabled' => 1,
	'better_reactions_topic_state_rules' => '{"solved":{"label":"Solved","threshold":1}}',
	'better_reactions_milestones_enabled' => 1,
	'better_reactions_milestones' => '[{"threshold":10,"label":"Getting Noticed","icon":"✨"},{"threshold":50,"label":"Appreciated","icon":"💙"},{"threshold":100,"label":"Community Favorite","icon":"🏅"},{"threshold":500,"label":"Reaction Legend","icon":"🏆"}]',
);
foreach ($setting_defaults as $setting => $default)
{
	if (!isset($modSettings[$setting]))
		updateSettings(array($setting => $default));
}
