<?php
/**
 * Better Reactions & Likes - core integration layer.
 *
 * @package BetterReactions
 * @version 0.1.0
 */

if (!defined('SMF'))
	die('No direct access...');

function BetterReactions_Bootstrap()
{
	global $modSettings, $context;

	if (empty($modSettings['better_reactions_enabled']))
		return;

	$context['better_reactions'] = array(
		'enabled' => true,
		'native_like_bridge' => !empty($modSettings['better_reactions_native_like_bridge']),
	);
}

function BetterReactions_LoadTheme()
{
	global $modSettings, $board, $txt;

	if (empty($modSettings['better_reactions_enabled']))
		return;

	BetterReactions_LoadLanguage();

	// Topic pages belong to one board. If Better Reactions is disabled for that
	// board, do not enhance the native Like control at all; SMF then falls back
	// to its own stock Like behavior.
	if (!empty($board) && !BetterReactions_GetBoardPolicy((int) $board)['enabled'])
		return;

	loadCSSFile('better_reactions.css', array('minimize' => true, 'order_pos' => 950), 'better_reactions');
	loadJavaScriptFile('better_reactions.js', array('minimize' => true, 'defer' => true), 'better_reactions');

	// Feed the admin-managed reaction registry into the front-end. This keeps
	// enabled state, labels and ordering out of hard-coded JavaScript.
	$types = !empty($board) ? BetterReactions_GetTypesForBoard((int) $board, true) : BetterReactions_GetTypes(true);
	$types = BetterReactions_FilterTypesForCurrentUser($types);
	$client_types = array();
	foreach ($types as $type)
	{
		$key = (string) $type['reaction_key'];
		$visual = BetterReactions_GetVisual($type);
		$client_types[] = array(
			'key' => $key,
			'label' => (string) $type['label'],
			'glyph' => $visual['glyph'],
			'image' => $visual['image'],
			'native' => !empty($type['is_native_like']),
			'category' => BetterReactions_GetReactionCategory($key),
		);
	}

	addJavaScriptVar('betterReactionsTypes', $client_types, true);
	addJavaScriptVar('betterReactionsConfig', array(
		'showCounts' => !isset($modSettings['better_reactions_show_counts']) || !empty($modSettings['better_reactions_show_counts']),
		'showWho' => !isset($modSettings['better_reactions_show_who']) || !empty($modSettings['better_reactions_show_who']),
		'profileLinks' => !isset($modSettings['better_reactions_profile_links']) || !empty($modSettings['better_reactions_profile_links']),
		'whoHover' => !isset($modSettings['better_reactions_who_hover']) || !empty($modSettings['better_reactions_who_hover']),
		'showLabels' => !isset($modSettings['better_reactions_show_labels']) || !empty($modSettings['better_reactions_show_labels']),
		'animateIcons' => !isset($modSettings['better_reactions_animate_icons']) || !empty($modSettings['better_reactions_animate_icons']),
		'iconSize' => isset($modSettings['better_reactions_icon_size']) && in_array($modSettings['better_reactions_icon_size'], array('compact', 'comfortable', 'large'), true) ? $modSettings['better_reactions_icon_size'] : 'comfortable',
		'pickerVisible' => isset($modSettings['better_reactions_picker_visible']) ? max(3, min(12, (int) $modSettings['better_reactions_picker_visible'])) : 7,
		'pickerTitle' => $txt['better_reactions_picker_title'],
		'pickerAll' => $txt['better_reactions_picker_all'],
		'pickerMore' => $txt['better_reactions_picker_more'],
		'pickerLess' => $txt['better_reactions_picker_less'],
		'reactionFailed' => $txt['better_reactions_reaction_failed'],
		'topicStateFallback' => $txt['better_reactions_topic_state_fallback'],
		'topicStateTitle' => $txt['better_reactions_topic_state_title'],
		'whoMore' => $txt['better_reactions_who_more'],
		'chooseReaction' => $txt['better_reactions_choose_reaction'],
		'switchLikeFailed' => $txt['better_reactions_switch_like_failed'],
		'activePreset' => !empty($modSettings['better_reactions_active_preset']) ? (string) $modSettings['better_reactions_active_preset'] : 'classic',
		'pickerCategories' => !isset($modSettings['better_reactions_picker_categories']) || !empty($modSettings['better_reactions_picker_categories']),
	), true);

	// Topic-state badges are calculated from live reaction data. Nothing is
	// persisted on the topic itself, so removing/changing a qualifying reaction
	// automatically changes the state.
	$topic_id = BetterReactions_CurrentTopicId();
	if ($topic_id > 0 && !empty($modSettings['better_reactions_topic_states_enabled']))
	{
		addJavaScriptVar('betterReactionsTopicState', array(
			'topic' => $topic_id,
			'states' => BetterReactions_GetTopicStates($topic_id),
		), true);
	}
}

function BetterReactions_Actions(&$actionArray)
{
	$actionArray['betterreactions'] = array('BetterReactions.php', 'BetterReactions_Action');
}

function BetterReactions_Action()
{
	global $smcFunc, $user_info, $modSettings;

	// This is an AJAX-only endpoint. Convert PHP Errors/Exceptions into a JSON
	// response so the client never receives an SMF HTML error page masquerading
	// as JSON. This also makes future test failures much easier to diagnose.
	try
	{
		BetterReactions_ActionDispatch();
	}
	catch (Throwable $e)
	{
		error_log('Better Reactions AJAX: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
		BetterReactions_Json(array('ok' => false, 'error' => 'server_error'), 500);
	}
}


/**
 * Check board visibility using SMF's native {query_see_board} filter.
 *
 * boardsAllowedTo() only accepts actual board-permission names. "view_board"
 * is not an SMF board permission, which caused ordinary members to receive
 * cannot_view for custom reactions while administrators (who short-circuit to
 * all boards) appeared to work normally.
 */
function BetterReactions_UserCanSeeBoard($id_board)
{
	global $smcFunc;

	$id_board = (int) $id_board;
	if ($id_board <= 0)
		return false;

	$request = $smcFunc['db_query']('', '
		SELECT b.id_board
		FROM {db_prefix}boards AS b
		WHERE b.id_board = {int:id_board}
			AND {query_see_board}
		LIMIT 1',
		array('id_board' => $id_board)
	);
	$can_see = $smcFunc['db_num_rows']($request) > 0;
	$smcFunc['db_free_result']($request);

	return $can_see;
}

function BetterReactions_ActionDispatch()
{
	global $smcFunc, $user_info, $modSettings;

	if (empty($modSettings['better_reactions_enabled']))
		BetterReactions_Json(array('ok' => false, 'error' => 'disabled'), 403);

	$sa = isset($_REQUEST['sa']) ? (string) $_REQUEST['sa'] : 'summary';

	if ($sa === 'summary')
	{
		$ids = isset($_REQUEST['ids']) ? preg_split('~\s*,\s*~', (string) $_REQUEST['ids'], -1, PREG_SPLIT_NO_EMPTY) : array();
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		$ids = array_slice($ids, 0, 100);
		BetterReactions_Json(array('ok' => true, 'summary' => BetterReactions_GetMessageSummary($ids)));
	}

	if ($sa === 'reactors')
	{
		if (isset($modSettings['better_reactions_show_who']) && empty($modSettings['better_reactions_show_who']))
			BetterReactions_Json(array('ok' => false, 'error' => 'reactor_names_disabled'), 403);
		$id_msg = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
		$reaction = isset($_REQUEST['reaction']) ? strtolower(trim((string) $_REQUEST['reaction'])) : '';

		if ($id_msg <= 0 || $reaction === '' || $reaction === 'like')
			BetterReactions_Json(array('ok' => false, 'error' => 'invalid_reaction'), 400);

		$types = BetterReactions_GetTypes(true);
		if (!isset($types[$reaction]) || !empty($types[$reaction]['is_native_like']))
			BetterReactions_Json(array('ok' => false, 'error' => 'invalid_reaction'), 400);

		BetterReactions_Json(array('ok' => true) + BetterReactions_GetMessageReactors($id_msg, $reaction));
	}

	if ($sa === 'topicstate')
	{
		$id_topic = isset($_REQUEST['topic']) ? (int) $_REQUEST['topic'] : 0;
		if ($id_topic <= 0)
			BetterReactions_Json(array('ok' => false, 'error' => 'invalid_topic'), 400);

		$request = $smcFunc['db_query']('', 'SELECT id_board FROM {db_prefix}topics WHERE id_topic = {int:topic} LIMIT 1', array('topic' => $id_topic));
		$topic = $smcFunc['db_fetch_assoc']($request);
		$smcFunc['db_free_result']($request);
		if (empty($topic))
			BetterReactions_Json(array('ok' => false, 'error' => 'invalid_topic'), 404);
		if (!BetterReactions_UserCanSeeBoard((int) $topic['id_board']))
			BetterReactions_Json(array('ok' => false, 'error' => 'cannot_view'), 403);

		BetterReactions_Json(array('ok' => true, 'topic' => $id_topic, 'states' => BetterReactions_GetTopicStates($id_topic)));
	}

	if ($sa === 'clear')
	{
		is_not_guest();
		checkSession('post');

		$id_msg = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
		if ($id_msg <= 0)
			BetterReactions_Json(array('ok' => false, 'error' => 'invalid_message'), 400);

		$request = $smcFunc['db_query']('', '
			SELECT m.id_msg, m.id_member, m.id_board, m.id_topic
			FROM {db_prefix}messages AS m
			WHERE m.id_msg = {int:id_msg}
			LIMIT 1',
			array('id_msg' => $id_msg)
		);
		$row = $smcFunc['db_fetch_assoc']($request);
		$smcFunc['db_free_result']($request);

		if (empty($row) || empty($user_info['id']))
			BetterReactions_Json(array('ok' => false, 'error' => 'cannot_react'), 403);

		if (!BetterReactions_UserCanSeeBoard((int) $row['id_board']))
			BetterReactions_Json(array('ok' => false, 'error' => 'cannot_view'), 403);

		$smcFunc['db_query']('', '
			DELETE FROM {db_prefix}better_reactions
			WHERE id_member = {int:id_member}
				AND content_type = {string:content_type}
				AND content_id = {int:content_id}',
			array(
				'id_member' => $user_info['id'],
				'content_type' => 'msg',
				'content_id' => $id_msg,
			)
		);

		$summary = BetterReactions_GetMessageSummary(array($id_msg));
		BetterReactions_Json(array(
			'ok' => true,
			'id' => $id_msg,
			'selected' => '',
			'summary' => isset($summary[$id_msg]) ? $summary[$id_msg] : array(),
			'topic_states' => !empty($row['id_topic']) ? BetterReactions_GetTopicStates((int) $row['id_topic']) : array(),
		));
	}

	if ($sa !== 'react')
		BetterReactions_Json(array('ok' => false, 'error' => 'bad_action'), 400);

	is_not_guest();
	checkSession('post');

	$id_msg = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
	$reaction = isset($_REQUEST['reaction']) ? strtolower(trim((string) $_REQUEST['reaction'])) : '';

	if ($id_msg <= 0 || $reaction === '' || $reaction === 'like')
		BetterReactions_Json(array('ok' => false, 'error' => 'invalid_reaction'), 400);

	$types = BetterReactions_GetTypes(true);
	if (!isset($types[$reaction]) || !empty($types[$reaction]['is_native_like']))
		BetterReactions_Json(array('ok' => false, 'error' => 'invalid_reaction'), 400);

	if (!BetterReactions_CurrentUserAllowsReaction($reaction))
		BetterReactions_Json(array('ok' => false, 'error' => 'cannot_react'), 403);

	$request = $smcFunc['db_query']('', '
		SELECT m.id_msg, m.id_member, m.id_board, m.id_topic
		FROM {db_prefix}messages AS m
		WHERE m.id_msg = {int:id_msg}
		LIMIT 1',
		array('id_msg' => $id_msg)
	);
	$row = $smcFunc['db_fetch_assoc']($request);
	$smcFunc['db_free_result']($request);

	if (empty($row) || empty($user_info['id']) || (int) $row['id_member'] === (int) $user_info['id'])
		BetterReactions_Json(array('ok' => false, 'error' => 'cannot_react'), 403);

	$board_policy = BetterReactions_GetBoardPolicy((int) $row['id_board']);
	if (empty($board_policy['enabled']) || !BetterReactions_BoardAllowsReaction((int) $row['id_board'], $reaction))
		BetterReactions_Json(array('ok' => false, 'error' => 'reaction_not_available_here'), 403);

	if (!BetterReactions_UserCanSeeBoard((int) $row['id_board']))
		BetterReactions_Json(array('ok' => false, 'error' => 'cannot_view'), 403);

	// Custom Better Reactions use their own membergroup + per-board policy matrix.
	// Do not gate them behind SMF's native likes_like permission; that permission
	// belongs only to the native 👍 Like path. Coupling the two made regular
	// members receive cannot_react even when Better Reactions explicitly allowed
	// the selected reaction.

	$request = $smcFunc['db_query']('', '
		SELECT reaction_key
		FROM {db_prefix}better_reactions
		WHERE id_member = {int:id_member}
			AND content_type = {string:content_type}
			AND content_id = {int:content_id}
		LIMIT 1',
		array(
			'id_member' => $user_info['id'],
			'content_type' => 'msg',
			'content_id' => $id_msg,
		)
	);
	$existing = $smcFunc['db_fetch_assoc']($request);
	$smcFunc['db_free_result']($request);

	if (!empty($existing) && $existing['reaction_key'] === $reaction)
	{
		$smcFunc['db_query']('', '
			DELETE FROM {db_prefix}better_reactions
			WHERE id_member = {int:id_member}
				AND content_type = {string:content_type}
				AND content_id = {int:content_id}',
			array(
				'id_member' => $user_info['id'],
				'content_type' => 'msg',
				'content_id' => $id_msg,
			)
		);
		$selected = '';
	}
	else
	{
		// Do not rely on engine-specific REPLACE semantics here. If this member
		// already has another reaction on the message, update it in place;
		// otherwise insert a new record. The unique key keeps this one-reaction
		// model race-safe.
		if (!empty($existing))
		{
			$smcFunc['db_query']('', '
				UPDATE {db_prefix}better_reactions
				SET reaction_key = {string:reaction_key},
					created_at = {int:created_at}
				WHERE id_member = {int:id_member}
					AND content_type = {string:content_type}
					AND content_id = {int:content_id}',
				array(
					'reaction_key' => $reaction,
					'created_at' => time(),
					'id_member' => $user_info['id'],
					'content_type' => 'msg',
					'content_id' => $id_msg,
				)
			);
		}
		else
		{
			$smcFunc['db_insert']('ignore',
				'{db_prefix}better_reactions',
				array(
					'id_member' => 'int',
					'content_type' => 'string-32',
					'content_id' => 'int',
					'reaction_key' => 'string-32',
					'created_at' => 'int',
				),
				array($user_info['id'], 'msg', $id_msg, $reaction, time()),
				array('id_member', 'content_type', 'content_id')
			);
		}

		$selected = $reaction;
	}

	$summary = BetterReactions_GetMessageSummary(array($id_msg));
	BetterReactions_Json(array(
		'ok' => true,
		'id' => $id_msg,
		'selected' => $selected,
		'summary' => isset($summary[$id_msg]) ? $summary[$id_msg] : array(),
		'topic_states' => !empty($row['id_topic']) ? BetterReactions_GetTopicStates((int) $row['id_topic']) : array(),
	));
}

function BetterReactions_GetMessageSummary($ids)
{
	global $smcFunc, $user_info;

	$summary = array();
	if (empty($ids))
		return $summary;

	// Never expose reaction metadata for messages the current user cannot see.
	// The summary endpoint accepts message IDs directly, so filter them through
	// SMF's native board-visibility query before reading reaction rows.
	$request = $smcFunc['db_query']('', '
		SELECT m.id_msg
		FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
		WHERE m.id_msg IN ({array_int:ids})
			AND {query_see_board}',
		array('ids' => $ids)
	);
	$visible_ids = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
		$visible_ids[] = (int) $row['id_msg'];
	$smcFunc['db_free_result']($request);

	if (empty($visible_ids))
		return $summary;

	$ids = $visible_ids;
	foreach ($ids as $id)
		$summary[(int) $id] = array('counts' => array(), 'mine' => '');

	$request = $smcFunc['db_query']('', '
		SELECT content_id, reaction_key, COUNT(*) AS total
		FROM {db_prefix}better_reactions
		WHERE content_type = {string:content_type}
			AND content_id IN ({array_int:ids})
		GROUP BY content_id, reaction_key',
		array('content_type' => 'msg', 'ids' => $ids)
	);
	while ($row = $smcFunc['db_fetch_assoc']($request))
		$summary[(int) $row['content_id']]['counts'][$row['reaction_key']] = (int) $row['total'];
	$smcFunc['db_free_result']($request);

	if (!empty($user_info['id']))
	{
		$request = $smcFunc['db_query']('', '
			SELECT content_id, reaction_key
			FROM {db_prefix}better_reactions
			WHERE content_type = {string:content_type}
				AND content_id IN ({array_int:ids})
				AND id_member = {int:id_member}',
			array('content_type' => 'msg', 'ids' => $ids, 'id_member' => $user_info['id'])
		);
		while ($row = $smcFunc['db_fetch_assoc']($request))
			$summary[(int) $row['content_id']]['mine'] = $row['reaction_key'];
		$smcFunc['db_free_result']($request);
	}

	// Respect board policy even for previously stored reactions. This lets an
	// administrator disable a reaction on a board without deleting history.
	$request = $smcFunc['db_query']('', '
		SELECT id_msg, id_board
		FROM {db_prefix}messages
		WHERE id_msg IN ({array_int:ids})',
		array('ids' => $ids)
	);
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$id_msg = (int) $row['id_msg'];
		$id_board = (int) $row['id_board'];
		$policy = BetterReactions_GetBoardPolicy($id_board);
		if (empty($policy['enabled']))
		{
			$summary[$id_msg] = array('counts' => array(), 'mine' => '');
			continue;
		}

		foreach (array_keys($summary[$id_msg]['counts']) as $key)
			if (!BetterReactions_BoardAllowsReaction($id_board, $key))
				unset($summary[$id_msg]['counts'][$key]);

		if ($summary[$id_msg]['mine'] !== '' && !BetterReactions_BoardAllowsReaction($id_board, $summary[$id_msg]['mine']))
			$summary[$id_msg]['mine'] = '';
	}
	$smcFunc['db_free_result']($request);

	return $summary;
}

function BetterReactions_GetMessageReactors($id_msg, $reaction)
{
	global $smcFunc, $scripturl, $modSettings;

	$request = $smcFunc['db_query']('', '
		SELECT m.id_board
		FROM {db_prefix}messages AS m
		WHERE m.id_msg = {int:id_msg}
		LIMIT 1',
		array('id_msg' => (int) $id_msg)
	);
	$message = $smcFunc['db_fetch_assoc']($request);
	$smcFunc['db_free_result']($request);

	if (empty($message))
		BetterReactions_Json(array('ok' => false, 'error' => 'not_found'), 404);

	if (!BetterReactions_BoardAllowsReaction((int) $message['id_board'], $reaction))
		BetterReactions_Json(array('ok' => false, 'error' => 'reaction_not_available_here'), 403);

	if (!BetterReactions_UserCanSeeBoard((int) $message['id_board']))
		BetterReactions_Json(array('ok' => false, 'error' => 'cannot_view'), 403);

	$names = array();
	$total = 0;
	$limit = 50;

	$request = $smcFunc['db_query']('', '
		SELECT br.id_member, COALESCE(NULLIF(mem.real_name, {string:empty}), NULLIF(mem.member_name, {string:empty}), {string:former_member}) AS reactor_name
		FROM {db_prefix}better_reactions AS br
		LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = br.id_member)
		WHERE br.content_type = {string:content_type}
			AND br.content_id = {int:content_id}
			AND br.reaction_key = {string:reaction_key}
		ORDER BY br.created_at ASC, br.id_reaction_record ASC
		LIMIT {int:limit}',
		array(
			'empty' => '',
			'former_member' => 'Former member',
			'content_type' => 'msg',
			'content_id' => (int) $id_msg,
			'reaction_key' => $reaction,
			'limit' => $limit,
		)
	);
	while ($row = $smcFunc['db_fetch_assoc']($request))
		$names[] = array(
			'id' => (int) $row['id_member'],
			'name' => (string) $row['reactor_name'],
			'url' => (!isset($modSettings['better_reactions_profile_links']) || !empty($modSettings['better_reactions_profile_links'])) && !empty($row['id_member']) ? $scripturl . '?action=profile;u=' . (int) $row['id_member'] : '',
		);
	$smcFunc['db_free_result']($request);

	$request = $smcFunc['db_query']('', '
		SELECT COUNT(*) AS total
		FROM {db_prefix}better_reactions
		WHERE content_type = {string:content_type}
			AND content_id = {int:content_id}
			AND reaction_key = {string:reaction_key}',
		array(
			'content_type' => 'msg',
			'content_id' => (int) $id_msg,
			'reaction_key' => $reaction,
		)
	);
	$row = $smcFunc['db_fetch_assoc']($request);
	$total = !empty($row['total']) ? (int) $row['total'] : 0;
	$smcFunc['db_free_result']($request);

	return array(
		'id' => (int) $id_msg,
		'reaction' => $reaction,
		'total' => $total,
		'names' => $names,
		'truncated' => $total > count($names),
	);
}

function BetterReactions_Json($data, $status = 200)
{
	// SMF 2.1 uses PHP's native json_encode(). There is no guaranteed
	// $smcFunc['json_encode'] callback in the database function table.
	// Discard any buffered template/debug output so this endpoint remains valid
	// JSON even when SMF has already started an output buffer.
	while (ob_get_level() > 0)
		@ob_end_clean();

	if (function_exists('http_response_code'))
		http_response_code((int) $status);

	header('Content-Type: application/json; charset=UTF-8');
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

	$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($json === false)
	{
		if (function_exists('http_response_code'))
			http_response_code(500);
		$json = '{"ok":false,"error":"json_encode_failed"}';
	}

	echo $json;
	exit;
}



/**
 * Generic reaction storage service for companion Better SMF modules.
 * The calling module remains responsible for object visibility/permission
 * checks and for any native-like bridge it owns.
 */
function BetterReactions_GetObjectSummary($content_type, $ids)
{
    global $smcFunc, $user_info;

    $content_type = preg_replace('~[^a-z0-9_\-]~i', '', (string) $content_type);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
    $summary = array();
    foreach ($ids as $id)
        $summary[$id] = array('counts' => array(), 'mine' => '');

    if ($content_type === '' || empty($ids))
        return $summary;

    $request = $smcFunc['db_query']('', '
        SELECT content_id, reaction_key, COUNT(*) AS total
        FROM {db_prefix}better_reactions
        WHERE content_type = {string:content_type}
            AND content_id IN ({array_int:ids})
        GROUP BY content_id, reaction_key',
        array('content_type' => $content_type, 'ids' => $ids)
    );
    while ($row = $smcFunc['db_fetch_assoc']($request))
        $summary[(int) $row['content_id']]['counts'][(string) $row['reaction_key']] = (int) $row['total'];
    $smcFunc['db_free_result']($request);

    if (!empty($user_info['id']))
    {
        $request = $smcFunc['db_query']('', '
            SELECT content_id, reaction_key
            FROM {db_prefix}better_reactions
            WHERE content_type = {string:content_type}
                AND content_id IN ({array_int:ids})
                AND id_member = {int:id_member}',
            array('content_type' => $content_type, 'ids' => $ids, 'id_member' => (int) $user_info['id'])
        );
        while ($row = $smcFunc['db_fetch_assoc']($request))
            $summary[(int) $row['content_id']]['mine'] = (string) $row['reaction_key'];
        $smcFunc['db_free_result']($request);
    }

    return $summary;
}

function BetterReactions_SetObjectReaction($content_type, $content_id, $reaction, $id_member = 0, $allow_native_key = false)
{
    global $smcFunc, $user_info;

    $content_type = preg_replace('~[^a-z0-9_\-]~i', '', (string) $content_type);
    $content_id = (int) $content_id;
    $reaction = strtolower(trim((string) $reaction));
    $id_member = $id_member ? (int) $id_member : (!empty($user_info['id']) ? (int) $user_info['id'] : 0);

    if ($content_type === '' || $content_id <= 0 || $id_member <= 0 || $reaction === '' || ($reaction === 'like' && !$allow_native_key))
        return array('ok' => false, 'error' => 'invalid_reaction');

    $types = BetterReactions_GetTypes();
    if (!isset($types[$reaction]) || empty($types[$reaction]['enabled']) || (!empty($types[$reaction]['is_native_like']) && !$allow_native_key))
        return array('ok' => false, 'error' => 'invalid_reaction');

    $request = $smcFunc['db_query']('', '
        SELECT reaction_key
        FROM {db_prefix}better_reactions
        WHERE id_member = {int:id_member}
            AND content_type = {string:content_type}
            AND content_id = {int:content_id}
        LIMIT 1',
        array('id_member' => $id_member, 'content_type' => $content_type, 'content_id' => $content_id)
    );
    $existing = $smcFunc['db_fetch_assoc']($request);
    $smcFunc['db_free_result']($request);

    if (!empty($existing) && (string) $existing['reaction_key'] === $reaction)
    {
        $smcFunc['db_query']('', '
            DELETE FROM {db_prefix}better_reactions
            WHERE id_member = {int:id_member}
                AND content_type = {string:content_type}
                AND content_id = {int:content_id}',
            array('id_member' => $id_member, 'content_type' => $content_type, 'content_id' => $content_id)
        );
        $selected = '';
    }
    elseif (!empty($existing))
    {
        $smcFunc['db_query']('', '
            UPDATE {db_prefix}better_reactions
            SET reaction_key = {string:reaction_key}, created_at = {int:created_at}
            WHERE id_member = {int:id_member}
                AND content_type = {string:content_type}
                AND content_id = {int:content_id}',
            array('reaction_key' => $reaction, 'created_at' => time(), 'id_member' => $id_member, 'content_type' => $content_type, 'content_id' => $content_id)
        );
        $selected = $reaction;
    }
    else
    {
        $smcFunc['db_insert']('insert', '{db_prefix}better_reactions',
            array('id_member' => 'int', 'content_type' => 'string-32', 'content_id' => 'int', 'reaction_key' => 'string-32', 'created_at' => 'int'),
            array($id_member, $content_type, $content_id, $reaction, time()),
            array('id_reaction_record')
        );
        $selected = $reaction;
    }

    $summary = BetterReactions_GetObjectSummary($content_type, array($content_id));
    return array('ok' => true, 'selected' => $selected, 'summary' => isset($summary[$content_id]) ? $summary[$content_id] : array('counts'=>array(),'mine'=>''));
}

function BetterReactions_ClearObjectReaction($content_type, $content_id, $id_member = 0)
{
    global $smcFunc, $user_info;
    $content_type = preg_replace('~[^a-z0-9_\-]~i', '', (string) $content_type);
    $content_id = (int) $content_id;
    $id_member = $id_member ? (int) $id_member : (!empty($user_info['id']) ? (int) $user_info['id'] : 0);
    if ($content_type === '' || $content_id <= 0 || $id_member <= 0)
        return false;
    $smcFunc['db_query']('', '
        DELETE FROM {db_prefix}better_reactions
        WHERE id_member = {int:id_member}
            AND content_type = {string:content_type}
            AND content_id = {int:content_id}',
        array('id_member'=>$id_member,'content_type'=>$content_type,'content_id'=>$content_id)
    );
    return true;
}

function BetterReactions_GetObjectReactors($content_type, $content_id, $reaction, $limit = 50)
{
    global $smcFunc, $scripturl, $modSettings;
    $content_type = preg_replace('~[^a-z0-9_\-]~i', '', (string) $content_type);
    $content_id = (int) $content_id;
    $reaction = strtolower(trim((string) $reaction));
    $limit = max(1, min(50, (int) $limit));
    $people = array();
    if ($content_type === '' || $content_id <= 0 || $reaction === '')
        return array('people'=>$people,'total'=>0);
    $request = $smcFunc['db_query']('', '
        SELECT br.id_member, COALESCE(NULLIF(mem.real_name, {string:empty}), NULLIF(mem.member_name, {string:empty}), {string:former}) AS reactor_name
        FROM {db_prefix}better_reactions AS br
            LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = br.id_member)
        WHERE br.content_type = {string:content_type}
            AND br.content_id = {int:content_id}
            AND br.reaction_key = {string:reaction_key}
        ORDER BY br.created_at ASC, br.id_reaction_record ASC
        LIMIT {int:limit}',
        array('empty'=>'','former'=>'Former member','content_type'=>$content_type,'content_id'=>$content_id,'reaction_key'=>$reaction,'limit'=>$limit)
    );
    while ($row = $smcFunc['db_fetch_assoc']($request))
        $people[] = array('id'=>(int)$row['id_member'],'name'=>(string)$row['reactor_name'],'url'=>(!isset($modSettings['better_reactions_profile_links']) || !empty($modSettings['better_reactions_profile_links'])) && !empty($row['id_member']) ? $scripturl . '?action=profile;u=' . (int)$row['id_member'] : '');
    $smcFunc['db_free_result']($request);
    $request = $smcFunc['db_query']('', '
        SELECT COUNT(*) AS total FROM {db_prefix}better_reactions
        WHERE content_type={string:content_type} AND content_id={int:content_id} AND reaction_key={string:reaction_key}',
        array('content_type'=>$content_type,'content_id'=>$content_id,'reaction_key'=>$reaction)
    );
    $row=$smcFunc['db_fetch_assoc']($request); $smcFunc['db_free_result']($request);
    return array('people'=>$people,'total'=>!empty($row['total'])?(int)$row['total']:0);
}

function BetterReactions_GetClientRegistry()
{
    global $modSettings;
    $out = array();
    foreach (BetterReactions_FilterTypesForCurrentUser(BetterReactions_GetTypes()) as $type)
    {
        if (empty($type['enabled'])) continue;
        $visual = BetterReactions_GetVisual($type);
        $out[] = array('key'=>(string)$type['reaction_key'],'label'=>(string)$type['label'],'glyph'=>(string)$visual['glyph'],'image'=>(string)$visual['image'],'native'=>!empty($type['is_native_like']));
    }
    return array(
        'types'=>$out,
        'display'=>array(
            'showCounts'=>!isset($modSettings['better_reactions_show_counts']) || !empty($modSettings['better_reactions_show_counts']),
            'showWho'=>!isset($modSettings['better_reactions_show_who']) || !empty($modSettings['better_reactions_show_who']),
            'profileLinks'=>!isset($modSettings['better_reactions_profile_links']) || !empty($modSettings['better_reactions_profile_links']),
            'whoHover'=>!isset($modSettings['better_reactions_who_hover']) || !empty($modSettings['better_reactions_who_hover']),
            'showLabels'=>!isset($modSettings['better_reactions_show_labels']) || !empty($modSettings['better_reactions_show_labels']),
            'animateIcons'=>!isset($modSettings['better_reactions_animate_icons']) || !empty($modSettings['better_reactions_animate_icons']),
            'iconSize'=>isset($modSettings['better_reactions_icon_size']) ? (string)$modSettings['better_reactions_icon_size'] : 'comfortable',
        ),
    );
}

function BetterReactions_Permissions(&$permissionGroups, &$permissionList, &$leftPermissionGroups, &$hiddenPermissions, &$relabelPermissions)
{
	$permissionList['membergroup']['better_react'] = array(false, 'general', 'view_basic_info');
}

/**
 * Load Better Reactions language strings while preserving custom-theme
 * translations and backfilling keys that only exist in the default theme.
 *
 * SMF stops at the first matching language file it finds. A custom theme can
 * therefore shadow a newer default-theme language file with an older copy.
 * Keep the custom strings, then merge in any missing defaults.
 */
function BetterReactions_LoadLanguage()
{
	global $txt, $settings;
	static $merged = false;

	loadLanguage('BetterReactions');
	if ($merged)
		return;

	// Always merge the package's current default English strings underneath the
	// active theme. This permanently solves stale generated/custom theme language
	// files shadowing new Better Reactions keys while preserving every existing
	// translated/custom string supplied by that theme.
	if (!empty($settings['default_theme_dir']))
	{
		$default_file = $settings['default_theme_dir'] . '/languages/BetterReactions.english.php';
		if (is_file($default_file))
		{
			$active_txt = $txt;
			template_include($default_file);
			$txt = $active_txt + $txt;
		}
	}

	$merged = true;
}

function BetterReactions_AdminAreas(&$admin_areas)
{
	global $txt;
	BetterReactions_LoadLanguage();

	$admin_areas['config']['areas']['betterreactions'] = array(
		'label' => $txt['better_reactions_admin_title'],
		'file' => 'BetterReactions.php',
		'function' => 'BetterReactions_Admin',
		'icon' => 'better_reactions.png',
		'permission' => array('admin_forum'),
		'subsections' => array(
			'general' => array($txt['better_reactions_admin_general']),
			'reactions' => array($txt['better_reactions_admin_manager']),
			'presets' => array($txt['better_reactions_admin_presets']),
			'categories' => array($txt['better_reactions_admin_categories']),
			'features' => array($txt['better_reactions_admin_features']),
			'boards' => array($txt['better_reactions_admin_boards']),
			'permissions' => array($txt['better_reactions_admin_permissions']),
			'analytics' => array($txt['better_reactions_admin_analytics']),
			'display' => array($txt['better_reactions_admin_display']),
			'cleanup' => array($txt['better_reactions_admin_cleanup']),
		),
	);
}

function BetterReactions_Admin()
{
	global $context, $txt;

	isAllowedTo('admin_forum');
	BetterReactions_LoadLanguage();
	$allowed_sa = array('general', 'reactions', 'presets', 'categories', 'features', 'boards', 'permissions', 'analytics', 'display', 'cleanup');
	$sa = isset($_REQUEST['sa']) && in_array($_REQUEST['sa'], $allowed_sa, true) ? $_REQUEST['sa'] : 'general';
	$context['page_title'] = $txt['better_reactions_admin_title'];

	if (!empty($context['admin_menu_name']))
	{
		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title' => $txt['better_reactions_admin_title'],
			'description' => $txt['better_reactions_admin_desc'],
			'tabs' => array(
				'general' => array('description' => $txt['better_reactions_admin_general_desc']),
				'reactions' => array('description' => $txt['better_reactions_admin_manager_desc']),
				'presets' => array('description' => $txt['better_reactions_admin_presets_desc']),
				'categories' => array('description' => $txt['better_reactions_admin_categories_desc']),
				'features' => array('description' => $txt['better_reactions_admin_features_desc']),
				'boards' => array('description' => $txt['better_reactions_admin_boards_desc']),
				'permissions' => array('description' => $txt['better_reactions_admin_permissions_desc']),
				'analytics' => array('description' => $txt['better_reactions_admin_analytics_desc']),
				'display' => array('description' => $txt['better_reactions_admin_display_desc']),
				'cleanup' => array('description' => $txt['better_reactions_admin_cleanup_desc']),
			),
		);
	}

	if ($sa === 'reactions')
		BetterReactions_AdminReactions();
	elseif ($sa === 'presets')
		BetterReactions_AdminPresets();
	elseif ($sa === 'categories')
		BetterReactions_AdminCategories();
	elseif ($sa === 'features')
		BetterReactions_AdminFeatures();
	elseif ($sa === 'boards')
		BetterReactions_AdminBoards();
	elseif ($sa === 'permissions')
		BetterReactions_AdminPermissions();
	elseif ($sa === 'analytics')
		BetterReactions_AdminAnalytics();
	elseif ($sa === 'display')
		BetterReactions_AdminDisplay();
	elseif ($sa === 'cleanup')
		BetterReactions_AdminCleanup();
	else
		BetterReactions_AdminGeneral();
}

function BetterReactions_AdminGeneral()
{
	global $context, $modSettings;
	loadTemplate('BetterReactionsGeneral');

	if (isset($_POST['save']))
	{
		checkSession('post');
		updateSettings(array(
			'better_reactions_enabled' => !empty($_POST['better_reactions_enabled']) ? 1 : 0,
			'better_reactions_native_like_bridge' => !empty($_POST['better_reactions_native_like_bridge']) ? 1 : 0,
		));
		redirectexit('action=admin;area=betterreactions;sa=general;saved');
	}

	$context['better_reactions_settings'] = array(
		'enabled' => !empty($modSettings['better_reactions_enabled']),
		'native_like_bridge' => !empty($modSettings['better_reactions_native_like_bridge']),
	);
	$context['better_reactions_saved'] = isset($_GET['saved']);
	$context['sub_template'] = 'better_reactions_general_page';
}

function BetterReactions_AdminDisplay()
{
	global $context, $modSettings;
	loadTemplate('BetterReactionsDisplay');

	if (isset($_POST['save']))
	{
		checkSession('post');
		$icon_size = isset($_POST['better_reactions_icon_size']) ? (string) $_POST['better_reactions_icon_size'] : 'comfortable';
		if (!in_array($icon_size, array('compact', 'comfortable', 'large'), true))
			$icon_size = 'comfortable';

		updateSettings(array(
			'better_reactions_show_counts' => !empty($_POST['better_reactions_show_counts']) ? 1 : 0,
			'better_reactions_show_who' => !empty($_POST['better_reactions_show_who']) ? 1 : 0,
			'better_reactions_profile_links' => !empty($_POST['better_reactions_profile_links']) ? 1 : 0,
			'better_reactions_who_hover' => !empty($_POST['better_reactions_who_hover']) ? 1 : 0,
			'better_reactions_show_labels' => !empty($_POST['better_reactions_show_labels']) ? 1 : 0,
			'better_reactions_animate_icons' => !empty($_POST['better_reactions_animate_icons']) ? 1 : 0,
			'better_reactions_icon_size' => $icon_size,
		));
		redirectexit('action=admin;area=betterreactions;sa=display;saved');
	}

	$context['better_reactions_display'] = array(
		'show_counts' => !isset($modSettings['better_reactions_show_counts']) || !empty($modSettings['better_reactions_show_counts']),
		'show_who' => !isset($modSettings['better_reactions_show_who']) || !empty($modSettings['better_reactions_show_who']),
		'profile_links' => !isset($modSettings['better_reactions_profile_links']) || !empty($modSettings['better_reactions_profile_links']),
		'who_hover' => !isset($modSettings['better_reactions_who_hover']) || !empty($modSettings['better_reactions_who_hover']),
		'show_labels' => !isset($modSettings['better_reactions_show_labels']) || !empty($modSettings['better_reactions_show_labels']),
		'animate_icons' => !isset($modSettings['better_reactions_animate_icons']) || !empty($modSettings['better_reactions_animate_icons']),
		'icon_size' => isset($modSettings['better_reactions_icon_size']) && in_array($modSettings['better_reactions_icon_size'], array('compact', 'comfortable', 'large'), true) ? $modSettings['better_reactions_icon_size'] : 'comfortable',
	);
	$context['better_reactions_saved'] = isset($_GET['saved']);
	$context['sub_template'] = 'better_reactions_display_page';
}



/**
 * Housekeeping for reaction records owned by Better Reactions.
 *
 * This pass intentionally targets only Better Chat's chat_message objects.
 * Normal SMF post reactions and every other companion content type are left
 * untouched. Better Chat 33e protects new deletions; this reconciles older
 * orphan rows created before lifecycle cleanup existed.
 */
function BetterReactions_AdminCleanup()
{
	global $context, $txt, $modSettings, $user_info, $sourcedir;

	// Cleanup UI lives in a dedicated template so stale copies of
	// BetterReactionsAdmin.template.php in custom/generated themes cannot
	// shadow newer Cleanup sub-templates. SMF will fall back to the default
	// theme for BetterReactionsCleanup.template.php when the active theme
	// does not provide it.
	loadTemplate('BetterReactionsCleanup');

	$context['better_reactions_cleanup_available'] = is_file($sourcedir . '/ChatHandler.php');
	$context['better_reactions_cleanup_result'] = null;
	$context['better_reactions_cleanup_error'] = '';

	if (!empty($_POST['clean_chat_orphans']))
	{
		checkSession('post');

		if (empty($context['better_reactions_cleanup_available']))
			$context['better_reactions_cleanup_error'] = $txt['better_reactions_cleanup_chat_missing'];
		else
		{
			$removed = BetterReactions_CleanupChatOrphans();
			updateSettings(array(
				'better_reactions_last_orphan_cleanup' => time(),
				'better_reactions_last_orphan_cleanup_rows' => (int) $removed,
				'better_reactions_last_orphan_cleanup_member' => !empty($user_info['id']) ? (int) $user_info['id'] : 0,
			));

			if (function_exists('logAction'))
				logAction('br_cleanup', array('rows' => (int) $removed), 'admin');

			redirectexit('action=admin;area=betterreactions;sa=cleanup;cleaned=' . (int) $removed);
		}
	}

	if (!empty($context['better_reactions_cleanup_available']))
		$context['better_reactions_cleanup_stats'] = BetterReactions_GetChatOrphanStats();
	else
		$context['better_reactions_cleanup_stats'] = array('total_rows' => 0, 'orphan_rows' => 0, 'orphan_objects' => 0);

	if (isset($_GET['cleaned']))
		$context['better_reactions_cleanup_result'] = max(0, (int) $_GET['cleaned']);

	$context['better_reactions_cleanup_last'] = array(
		'time' => !empty($modSettings['better_reactions_last_orphan_cleanup']) ? (int) $modSettings['better_reactions_last_orphan_cleanup'] : 0,
		'rows' => !empty($modSettings['better_reactions_last_orphan_cleanup_rows']) ? (int) $modSettings['better_reactions_last_orphan_cleanup_rows'] : 0,
		'member' => !empty($modSettings['better_reactions_last_orphan_cleanup_member']) ? (int) $modSettings['better_reactions_last_orphan_cleanup_member'] : 0,
	);
	$context['sub_template'] = 'better_reactions_cleanup_page';
}

function BetterReactions_GetChatOrphanStats()
{
	global $smcFunc;

	$stats = array('total_rows' => 0, 'orphan_rows' => 0, 'orphan_objects' => 0);

	$request = $smcFunc['db_query']('', '
		SELECT COUNT(*) AS total_rows
		FROM {db_prefix}better_reactions
		WHERE content_type = {string:content_type}',
		array('content_type' => 'chat_message')
	);
	$row = $smcFunc['db_fetch_assoc']($request);
	$smcFunc['db_free_result']($request);
	$stats['total_rows'] = !empty($row['total_rows']) ? (int) $row['total_rows'] : 0;

	$request = $smcFunc['db_query']('', '
		SELECT COUNT(*) AS orphan_rows, COUNT(DISTINCT br.content_id) AS orphan_objects
		FROM {db_prefix}better_reactions AS br
			LEFT JOIN {db_prefix}chat_messages AS cm ON (cm.id_chat = br.content_id)
		WHERE br.content_type = {string:content_type}
			AND cm.id_chat IS NULL',
		array('content_type' => 'chat_message')
	);
	$row = $smcFunc['db_fetch_assoc']($request);
	$smcFunc['db_free_result']($request);
	$stats['orphan_rows'] = !empty($row['orphan_rows']) ? (int) $row['orphan_rows'] : 0;
	$stats['orphan_objects'] = !empty($row['orphan_objects']) ? (int) $row['orphan_objects'] : 0;

	return $stats;
}

function BetterReactions_CleanupChatOrphans()
{
	global $smcFunc;

	$removed = 0;
	$batch_size = 500;

	while (true)
	{
		$ids = array();
		$request = $smcFunc['db_query']('', '
			SELECT br.id_reaction_record
			FROM {db_prefix}better_reactions AS br
				LEFT JOIN {db_prefix}chat_messages AS cm ON (cm.id_chat = br.content_id)
			WHERE br.content_type = {string:content_type}
				AND cm.id_chat IS NULL
			ORDER BY br.id_reaction_record ASC
			LIMIT {int:batch_size}',
			array('content_type' => 'chat_message', 'batch_size' => $batch_size)
		);
		while ($row = $smcFunc['db_fetch_assoc']($request))
			$ids[] = (int) $row['id_reaction_record'];
		$smcFunc['db_free_result']($request);

		if (empty($ids))
			break;

		$smcFunc['db_query']('', '
			DELETE FROM {db_prefix}better_reactions
			WHERE id_reaction_record IN ({array_int:ids})',
			array('ids' => $ids)
		);
		$removed += count($ids);

		if (count($ids) < $batch_size)
			break;
	}

	return $removed;
}

function BetterReactions_NormalizeAdminText($value)
{
	// SMF may entity-encode request values during input cleaning. Convert those
	// entities back to real UTF-8 before storing labels or emoji icons.
	$value = (string) $value;
	$decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

	return $decoded;
}

function BetterReactions_GetCategoryList()
{
	global $modSettings;

	$categories = array('General', 'Positive', 'Fun', 'Support', 'Gaming', 'Staff');
	if (!empty($modSettings['better_reactions_categories']))
	{
		$decoded = json_decode((string) $modSettings['better_reactions_categories'], true);
		if (is_array($decoded))
		{
			foreach ($decoded as $category)
			{
				$category = trim(BetterReactions_NormalizeAdminText($category));
				if ($category !== '')
					$categories[] = substr($category, 0, 40);
			}
		}
	}

	foreach (BetterReactions_GetCategoryMap() as $category)
		if ($category !== '')
			$categories[] = $category;

	$clean = array();
	foreach ($categories as $category)
	{
		$key = strtolower($category);
		if (!isset($clean[$key]))
			$clean[$key] = $category;
	}
	return array_values($clean);
}

function BetterReactions_SaveCategoryList($categories)
{
	$clean = array();
	foreach ((array) $categories as $category)
	{
		$category = trim(BetterReactions_NormalizeAdminText($category));
		if ($category === '')
			continue;
		$category = substr($category, 0, 40);
		$clean[strtolower($category)] = $category;
	}
	updateSettings(array('better_reactions_categories' => json_encode(array_values($clean), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
}

function BetterReactions_GetCategoryMap()
{
	global $modSettings;
	$map = array();
	if (!empty($modSettings['better_reactions_category_map']))
	{
		$decoded = json_decode((string) $modSettings['better_reactions_category_map'], true);
		if (is_array($decoded))
		{
			foreach ($decoded as $key => $category)
			{
				$key = BetterReactions_Slug($key);
				$category = trim(BetterReactions_NormalizeAdminText($category));
				if ($key !== '' && $category !== '')
					$map[$key] = substr($category, 0, 40);
			}
		}
	}
	return $map;
}

function BetterReactions_SaveCategoryMap($map)
{
	$clean = array();
	foreach ((array) $map as $key => $category)
	{
		$key = BetterReactions_Slug($key);
		$category = trim(BetterReactions_NormalizeAdminText($category));
		if ($key !== '' && $category !== '')
			$clean[$key] = substr($category, 0, 40);
	}
	updateSettings(array('better_reactions_category_map' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
}

function BetterReactions_DefaultCategoryForKey($key)
{
	$defaults = array(
		'like' => 'General', 'love' => 'Positive', 'care' => 'Positive', 'thanks' => 'Support',
		'helpful' => 'Support', 'solved' => 'Support', 'celebrate' => 'Positive', 'fire' => 'Fun',
		'laugh' => 'Fun', 'wow' => 'Fun', 'sad' => 'General', 'angry' => 'General',
		'gg' => 'Gaming', 'win' => 'Gaming', 'rip' => 'Gaming',
	);
	return isset($defaults[$key]) ? $defaults[$key] : 'General';
}

function BetterReactions_GetReactionCategory($key)
{
	$map = BetterReactions_GetCategoryMap();
	$key = BetterReactions_Slug($key);
	return isset($map[$key]) ? $map[$key] : BetterReactions_DefaultCategoryForKey($key);
}

function BetterReactions_GetPresets()
{
	return array(
		'minimal' => array(
			'name' => 'Minimal',
			'description' => 'A quiet two-choice set for forums that want reactions without visual noise.',
			'reactions' => array(
				array('like', 'Like', 'thumbs-up', 'General'),
				array('love', 'Love', 'emoji:❤️', 'Positive'),
			),
		),
		'classic' => array(
			'name' => 'Classic',
			'description' => 'The familiar Better Reactions set: expressive, compact, and general-purpose.',
			'reactions' => array(
				array('like', 'Like', 'thumbs-up', 'General'), array('love', 'Love', 'emoji:❤️', 'Positive'),
				array('laugh', 'Laugh', 'emoji:😂', 'Fun'), array('wow', 'Wow', 'emoji:😮', 'Fun'),
				array('sad', 'Sad', 'emoji:😢', 'General'), array('celebrate', 'Celebrate', 'emoji:🎉', 'Positive'),
				array('fire', 'Fire', 'emoji:🔥', 'Fun'),
			),
		),
		'social' => array(
			'name' => 'Social',
			'description' => 'A broad social set with empathy and stronger emotional responses.',
			'reactions' => array(
				array('like', 'Like', 'thumbs-up', 'General'), array('love', 'Love', 'emoji:❤️', 'Positive'),
				array('care', 'Care', 'emoji:🤗', 'Positive'), array('laugh', 'Laugh', 'emoji:😂', 'Fun'),
				array('wow', 'Wow', 'emoji:😮', 'Fun'), array('sad', 'Sad', 'emoji:😢', 'General'),
				array('angry', 'Angry', 'emoji:😡', 'General'),
			),
		),
		'support' => array(
			'name' => 'Support',
			'description' => 'Built for help desks and support boards: gratitude, usefulness, and resolution.',
			'reactions' => array(
				array('like', 'Like', 'thumbs-up', 'General'), array('thanks', 'Thanks', 'emoji:🙏', 'Support'),
				array('helpful', 'Helpful', 'emoji:💡', 'Support'), array('solved', 'Solved', 'emoji:✅', 'Support'),
				array('celebrate', 'Celebrate', 'emoji:🎉', 'Positive'),
			),
		),
		'gaming' => array(
			'name' => 'Gaming',
			'description' => 'A playful set for game communities, wins, clutch moments, and spectacular failures.',
			'reactions' => array(
				array('like', 'Like', 'thumbs-up', 'General'), array('gg', 'GG', 'emoji:🎮', 'Gaming'),
				array('fire', 'Fire', 'emoji:🔥', 'Fun'), array('win', 'Win', 'emoji:🏆', 'Gaming'),
				array('laugh', 'Laugh', 'emoji:😂', 'Fun'), array('wow', 'Wow', 'emoji:🤯', 'Fun'),
				array('rip', 'RIP', 'emoji:💀', 'Gaming'),
			),
		),
	);
}

function BetterReactions_AddReactionToAllGroups($key)
{
	global $smcFunc;
	$request = $smcFunc['db_query']('', 'SELECT id_group, allowed_reactions FROM {db_prefix}better_reactions_groups', array());
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$list = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $row['allowed_reactions'])))));
		if (!in_array($key, $list, true))
			$list[] = $key;
		$smcFunc['db_query']('', 'UPDATE {db_prefix}better_reactions_groups SET allowed_reactions = {string:list} WHERE id_group = {int:id_group}', array('list' => implode(',', $list), 'id_group' => (int) $row['id_group']));
	}
	$smcFunc['db_free_result']($request);
}

function BetterReactions_ApplyPreset($preset_key)
{
	global $smcFunc;
	$presets = BetterReactions_GetPresets();
	if (!isset($presets[$preset_key]))
		return false;

	$preset = $presets[$preset_key];
	$existing = BetterReactions_GetTypes(false);

	// Presets never delete history or definitions. They simply archive the
	// current non-native picker choices, then activate the selected set.
	$smcFunc['db_query']('', 'UPDATE {db_prefix}better_reactions_types SET enabled = 0 WHERE is_native_like = {int:not_native}', array('not_native' => 0));

	$order = 10;
	$category_map = BetterReactions_GetCategoryMap();
	foreach ($preset['reactions'] as $definition)
	{
		list($key, $label, $icon) = $definition;
		$category_map[$key] = isset($definition[3]) ? (string) $definition[3] : BetterReactions_DefaultCategoryForKey($key);
		$is_native = $key === 'like' ? 1 : 0;
		if (isset($existing[$key]))
		{
			$smcFunc['db_query']('', '
				UPDATE {db_prefix}better_reactions_types
				SET label = {string:label}, icon = {string:icon}, sort_order = {int:sort_order}, enabled = {int:enabled}
				WHERE reaction_key = {string:key}',
				array('label' => $label, 'icon' => $is_native ? (string) $existing[$key]['icon'] : $icon, 'sort_order' => $order, 'enabled' => 1, 'key' => $key)
			);
		}
		else
		{
			$smcFunc['db_insert']('insert', '{db_prefix}better_reactions_types',
				array('reaction_key' => 'string-32', 'label' => 'string-80', 'icon' => 'string-255', 'sort_order' => 'int', 'enabled' => 'int', 'is_native_like' => 'int'),
				array($key, $label, $icon, $order, 1, $is_native), array('id_reaction'));
		}
		BetterReactions_AddReactionToAllBoards($key);
		BetterReactions_AddReactionToAllGroups($key);
		$order += 10;
	}

	BetterReactions_SaveCategoryMap($category_map);
	updateSettings(array('better_reactions_active_preset' => $preset_key));
	return true;
}

function BetterReactions_AdminPresets()
{
	global $context, $modSettings;
	loadTemplate('BetterReactionsPresets');
	$presets = BetterReactions_GetPresets();

	if (!empty($_POST['apply_preset']))
	{
		checkSession('post');
		$key = BetterReactions_Slug((string) $_POST['apply_preset']);
		if (BetterReactions_ApplyPreset($key))
			redirectexit('action=admin;area=betterreactions;sa=presets;applied=' . urlencode($key));
	}
	elseif (isset($_POST['save_picker']))
	{
		checkSession('post');
		$visible = max(3, min(12, (int) ($_POST['picker_visible'] ?? 7)));
		$categories = !empty($_POST['picker_categories']) ? 1 : 0;
		updateSettings(array('better_reactions_picker_visible' => $visible, 'better_reactions_picker_categories' => $categories));
		redirectexit('action=admin;area=betterreactions;sa=presets;saved');
	}

	$context['better_reactions_presets'] = $presets;
	$context['better_reactions_active_preset'] = !empty($modSettings['better_reactions_active_preset']) ? (string) $modSettings['better_reactions_active_preset'] : 'classic';
	$context['better_reactions_picker_visible'] = isset($modSettings['better_reactions_picker_visible']) ? max(3, min(12, (int) $modSettings['better_reactions_picker_visible'])) : 7;
	$context['better_reactions_picker_categories'] = !isset($modSettings['better_reactions_picker_categories']) || !empty($modSettings['better_reactions_picker_categories']);
	$context['better_reactions_preset_applied'] = isset($_GET['applied']) ? BetterReactions_Slug((string) $_GET['applied']) : '';
	$context['better_reactions_saved'] = isset($_GET['saved']);
	$context['sub_template'] = 'better_reactions_presets_page';
}

function BetterReactions_AdminReactions()
{
	global $context, $smcFunc;

	// Reaction Manager has its own dedicated template. Load it explicitly so
	// stale custom-theme admin templates cannot shadow this page.
	loadTemplate('BetterReactionsManager');

	$context['better_reactions_error'] = '';

	if (isset($_POST['save']))
	{
		checkSession('post');
		$types = BetterReactions_GetTypes(false);
		$category_map = BetterReactions_GetCategoryMap();
		foreach ($types as $key => $type)
		{
			$is_native = !empty($type['is_native_like']);
			$label = isset($_POST['label'][$key]) ? trim(BetterReactions_NormalizeAdminText($_POST['label'][$key])) : (string) $type['label'];
			$label = $label !== '' ? substr($label, 0, 80) : (string) $type['label'];
			$order = $is_native ? 10 : (isset($_POST['sort'][$key]) ? max(20, min(999, (int) $_POST['sort'][$key])) : (int) $type['sort_order']);
			$enabled = $is_native ? 1 : (!empty($_POST['enabled'][$key]) ? 1 : 0);
			$category = isset($_POST['category'][$key]) ? trim(BetterReactions_NormalizeAdminText($_POST['category'][$key])) : BetterReactions_GetReactionCategory($key);
			$category_map[$key] = $category !== '' ? substr($category, 0, 40) : 'General';

			$icon = (string) $type['icon'];
			if (!$is_native && isset($_POST['emoji'][$key]))
			{
				$emoji = trim(BetterReactions_NormalizeAdminText($_POST['emoji'][$key]));
				if ($emoji !== '')
					$icon = 'emoji:' . substr($emoji, 0, 32);
			}

			if (!$is_native && !empty($_FILES['icon_upload']['name'][$key]))
			{
				$upload = BetterReactions_NormalizeUpload($_FILES['icon_upload'], $key);
				$result = BetterReactions_SaveUploadedIcon($upload, $key);
				if (!empty($result['error']))
					$context['better_reactions_error'] = $result['error'];
				else
					$icon = 'file:' . $result['file'];
			}

			$smcFunc['db_query']('', '
				UPDATE {db_prefix}better_reactions_types
				SET label = {string:label}, icon = {string:icon}, sort_order = {int:sort_order}, enabled = {int:enabled}
				WHERE id_reaction = {int:id_reaction}',
				array(
					'label' => $label,
					'icon' => $icon,
					'sort_order' => $order,
					'enabled' => $enabled,
					'id_reaction' => (int) $type['id_reaction'],
				)
			);
		}

		if (empty($context['better_reactions_error']))
		{
			BetterReactions_SaveCategoryMap($category_map);
			updateSettings(array('better_reactions_active_preset' => 'custom'));
			redirectexit('action=admin;area=betterreactions;sa=reactions;saved');
		}
	}
	elseif (isset($_POST['add_reaction']))
	{
		checkSession('post');
		$label = trim(BetterReactions_NormalizeAdminText($_POST['new_label'] ?? ''));
		$key_source = trim(BetterReactions_NormalizeAdminText($_POST['new_key'] ?? $label));
		$key = BetterReactions_Slug($key_source);
		$order = max(20, min(999, (int) ($_POST['new_sort'] ?? 100)));
		$emoji = trim(BetterReactions_NormalizeAdminText($_POST['new_emoji'] ?? ''));
		$category = trim(BetterReactions_NormalizeAdminText($_POST['new_category'] ?? 'General'));
		$category = $category !== '' ? substr($category, 0, 40) : 'General';

		$all = BetterReactions_GetTypes(false);
		if ($label === '' || $key === '')
			$context['better_reactions_error'] = 'A reaction name and valid key are required.';
		elseif (isset($all[$key]))
			$context['better_reactions_error'] = 'That reaction key is already in use.';
		elseif (count($all) >= 20)
			$context['better_reactions_error'] = 'Better Reactions currently supports up to 20 reaction types.';
		else
		{
			$icon = $emoji !== '' ? 'emoji:' . substr($emoji, 0, 32) : 'emoji:✨';
			if (!empty($_FILES['new_icon_upload']['name']))
			{
				$result = BetterReactions_SaveUploadedIcon($_FILES['new_icon_upload'], $key);
				if (!empty($result['error']))
					$context['better_reactions_error'] = $result['error'];
				else
					$icon = 'file:' . $result['file'];
			}

			if (empty($context['better_reactions_error']))
			{
				$smcFunc['db_insert']('insert',
					'{db_prefix}better_reactions_types',
					array('reaction_key' => 'string-32', 'label' => 'string-80', 'icon' => 'string-255', 'sort_order' => 'int', 'enabled' => 'int', 'is_native_like' => 'int'),
					array($key, substr($label, 0, 80), $icon, $order, 1, 0),
					array('id_reaction')
				);
				BetterReactions_AddReactionToAllBoards($key);
				BetterReactions_AddReactionToAllGroups($key);
				$category_map = BetterReactions_GetCategoryMap();
				$category_map[$key] = $category;
				BetterReactions_SaveCategoryMap($category_map);
				updateSettings(array('better_reactions_active_preset' => 'custom'));
				redirectexit('action=admin;area=betterreactions;sa=reactions;added');
			}
		}
	}
	elseif (!empty($_POST['remove_reaction']))
	{
		checkSession('post');
		$key = BetterReactions_Slug((string) $_POST['remove_reaction']);
		$types = BetterReactions_GetTypes(false);
		if (isset($types[$key]) && empty($types[$key]['is_native_like']))
		{
			$request = $smcFunc['db_query']('', '
				SELECT COUNT(*) AS total
				FROM {db_prefix}better_reactions
				WHERE reaction_key = {string:key}', array('key' => $key));
			$row = $smcFunc['db_fetch_assoc']($request);
			$smcFunc['db_free_result']($request);
			$total = !empty($row['total']) ? (int) $row['total'] : 0;
			if ($total > 0)
			{
				$smcFunc['db_query']('', 'UPDATE {db_prefix}better_reactions_types SET enabled = 0 WHERE reaction_key = {string:key}', array('key' => $key));
				updateSettings(array('better_reactions_active_preset' => 'custom'));
				redirectexit('action=admin;area=betterreactions;sa=reactions;archived');
			}
			$smcFunc['db_query']('', 'DELETE FROM {db_prefix}better_reactions_types WHERE reaction_key = {string:key}', array('key' => $key));
			BetterReactions_RemoveReactionFromBoards($key);
			updateSettings(array('better_reactions_active_preset' => 'custom'));
			redirectexit('action=admin;area=betterreactions;sa=reactions;removed');
		}
	}

	$context['better_reactions_types'] = BetterReactions_GetTypes(false);
	$context['better_reactions_glyphs'] = BetterReactions_GlyphMap();
	$context['better_reactions_category_map'] = BetterReactions_GetCategoryMap();
	$context['better_reactions_saved'] = isset($_GET['saved']);
	$context['better_reactions_added'] = isset($_GET['added']);
	$context['better_reactions_removed'] = isset($_GET['removed']);
	$context['better_reactions_archived'] = isset($_GET['archived']);
	$context['sub_template'] = 'better_reactions_manager_page';
}

function BetterReactions_Slug($value)
{
	$value = strtolower(trim((string) $value));
	$value = preg_replace('~[^a-z0-9_-]+~', '_', $value);
	$value = trim((string) $value, '_-');
	return substr($value, 0, 32);
}

function BetterReactions_NormalizeUpload($files, $key)
{
	return array(
		'name' => $files['name'][$key] ?? '',
		'type' => $files['type'][$key] ?? '',
		'tmp_name' => $files['tmp_name'][$key] ?? '',
		'error' => $files['error'][$key] ?? UPLOAD_ERR_NO_FILE,
		'size' => $files['size'][$key] ?? 0,
	);
}

function BetterReactions_SaveUploadedIcon($upload, $key)
{
	global $boarddir;

	if (empty($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
		return array('error' => 'The reaction image could not be uploaded.');
	if ((int) ($upload['size'] ?? 0) > 512 * 1024)
		return array('error' => 'Reaction images must be 512 KB or smaller.');

	$info = @getimagesize((string) $upload['tmp_name']);
	$allowed = array(IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif');
	if (defined('IMAGETYPE_WEBP'))
		$allowed[IMAGETYPE_WEBP] = 'webp';
	if (empty($info[2]) || !isset($allowed[$info[2]]))
		return array('error' => 'Reaction images must be PNG, JPG, GIF, or WebP files.');
	if ((int) $info[0] > 512 || (int) $info[1] > 512)
		return array('error' => 'Reaction images must be 512×512 pixels or smaller.');

	$dir = $boarddir . '/better_reactions_icons';
	if (!is_dir($dir) && !@mkdir($dir, 0755, true))
		return array('error' => 'Could not create the Better Reactions icon folder.');
	@file_put_contents($dir . '/.htaccess', "Options -Indexes\n<FilesMatch \\\"\\.(php|phtml|phar|cgi|pl|py|sh)$\\\">\nRequire all denied\n</FilesMatch>\n");

	$file = BetterReactions_Slug($key) . '-' . bin2hex(random_bytes(5)) . '.' . $allowed[$info[2]];
	if (!@move_uploaded_file((string) $upload['tmp_name'], $dir . '/' . $file))
		return array('error' => 'The reaction image could not be saved.');
	@chmod($dir . '/' . $file, 0644);
	return array('file' => $file);
}

function BetterReactions_AddReactionToAllBoards($key)
{
	global $smcFunc;
	$request = $smcFunc['db_query']('', 'SELECT id_board, allowed_reactions FROM {db_prefix}better_reactions_boards', array());
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$list = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $row['allowed_reactions'])))));
		if (!in_array($key, $list, true))
			$list[] = $key;
		$smcFunc['db_query']('', 'UPDATE {db_prefix}better_reactions_boards SET allowed_reactions = {string:list} WHERE id_board = {int:id_board}', array('list' => implode(',', $list), 'id_board' => (int) $row['id_board']));
	}
	$smcFunc['db_free_result']($request);
}

function BetterReactions_RemoveReactionFromBoards($key)
{
	global $smcFunc;
	$request = $smcFunc['db_query']('', 'SELECT id_board, allowed_reactions FROM {db_prefix}better_reactions_boards', array());
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$list = array_values(array_filter(array_map('trim', explode(',', (string) $row['allowed_reactions'])), function($v) use ($key) { return $v !== '' && $v !== $key; }));
		$smcFunc['db_query']('', 'UPDATE {db_prefix}better_reactions_boards SET allowed_reactions = {string:list} WHERE id_board = {int:id_board}', array('list' => implode(',', $list), 'id_board' => (int) $row['id_board']));
	}
	$smcFunc['db_free_result']($request);
}

function BetterReactions_AdminCategories()
{
	global $context;

	// Dedicated template prevents stale custom/generated theme admin templates
	// from hiding the Categories page. SMF falls back to the default theme.
	loadTemplate('BetterReactionsCategories');

	$categories = BetterReactions_GetCategoryList();
	$map = BetterReactions_GetCategoryMap();
	$types = BetterReactions_GetTypes(false);

	if (isset($_POST['add_category']))
	{
		checkSession('post');
		$name = trim(BetterReactions_NormalizeAdminText($_POST['new_category_name'] ?? ''));
		if ($name !== '')
		{
			$categories[] = substr($name, 0, 40);
			BetterReactions_SaveCategoryList($categories);
		}
		redirectexit('action=admin;area=betterreactions;sa=categories;saved');
	}

	if (isset($_POST['save_assignments']))
	{
		checkSession('post');
		foreach ($types as $key => $type)
		{
			$category = trim(BetterReactions_NormalizeAdminText($_POST['reaction_category'][$key] ?? BetterReactions_GetReactionCategory($key)));
			$map[$key] = $category !== '' ? substr($category, 0, 40) : 'General';
			$categories[] = $map[$key];
		}
		BetterReactions_SaveCategoryMap($map);
		BetterReactions_SaveCategoryList($categories);
		updateSettings(array('better_reactions_active_preset' => 'custom'));
		redirectexit('action=admin;area=betterreactions;sa=categories;saved');
	}

	if (!empty($_POST['delete_category']))
	{
		checkSession('post');
		$delete = trim(BetterReactions_NormalizeAdminText((string) $_POST['delete_category']));
		if ($delete !== '' && strcasecmp($delete, 'General') !== 0)
		{
			foreach ($map as $key => $category)
				if (strcasecmp($category, $delete) === 0)
					$map[$key] = 'General';
			$categories = array_values(array_filter($categories, function($category) use ($delete) { return strcasecmp($category, $delete) !== 0; }));
			BetterReactions_SaveCategoryMap($map);
			BetterReactions_SaveCategoryList($categories);
			updateSettings(array('better_reactions_active_preset' => 'custom'));
		}
		redirectexit('action=admin;area=betterreactions;sa=categories;saved');
	}

	$counts = array();
	foreach ($categories as $category)
		$counts[$category] = 0;
	foreach ($types as $key => $type)
	{
		$category = BetterReactions_GetReactionCategory($key);
		if (!isset($counts[$category]))
			$counts[$category] = 0;
		$counts[$category]++;
	}

	$context['better_reactions_categories'] = array_keys($counts);
	$context['better_reactions_category_counts'] = $counts;
	$context['better_reactions_category_types'] = $types;
	$context['better_reactions_categories_saved'] = isset($_GET['saved']);
	$context['sub_template'] = 'better_reactions_admin_categories';
}

function BetterReactions_AdminBoards()
{
	global $context, $smcFunc;

	$types = BetterReactions_GetTypes(true);
	$non_native_types = array_filter($types, function($type) {
		return empty($type['is_native_like']);
	});

	$request = $smcFunc['db_query']('', '
		SELECT b.id_board, b.name AS board_name, c.name AS category_name, c.cat_order, b.board_order
		FROM {db_prefix}boards AS b
		INNER JOIN {db_prefix}categories AS c ON (c.id_cat = b.id_cat)
		ORDER BY c.cat_order, b.board_order, b.id_board',
		array()
	);
	$boards = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$id_board = (int) $row['id_board'];
		$boards[$id_board] = array(
			'id_board' => $id_board,
			'board_name' => (string) $row['board_name'],
			'category_name' => (string) $row['category_name'],
		);
	}
	$smcFunc['db_free_result']($request);

	if (isset($_POST['save']))
	{
		checkSession('post');
		foreach ($boards as $id_board => $board_info)
		{
			$enabled = !empty($_POST['board_enabled'][$id_board]) ? 1 : 0;
			$allowed = array();
			if (!empty($_POST['board_reactions'][$id_board]) && is_array($_POST['board_reactions'][$id_board]))
			{
				foreach ($_POST['board_reactions'][$id_board] as $key)
				{
					$key = strtolower(trim((string) $key));
					if (isset($non_native_types[$key]))
						$allowed[] = $key;
				}
			}
			$allowed = array_values(array_unique($allowed));

			$smcFunc['db_insert']('replace',
				'{db_prefix}better_reactions_boards',
				array(
					'id_board' => 'int',
					'enabled' => 'int',
					'allowed_reactions' => 'string-255',
				),
				array($id_board, $enabled, implode(',', $allowed)),
				array('id_board')
			);
		}
		redirectexit('action=admin;area=betterreactions;sa=boards;saved');
	}

	foreach ($boards as $id_board => &$board_info)
		$board_info['policy'] = BetterReactions_GetBoardPolicy($id_board);
	unset($board_info);

	$context['better_reactions_boards'] = $boards;
	$context['better_reactions_board_types'] = $non_native_types;
	$context['better_reactions_glyphs'] = BetterReactions_GlyphMap();
	$context['better_reactions_category_map'] = BetterReactions_GetCategoryMap();
	$context['better_reactions_saved'] = isset($_GET['saved']);

	// Boards has its own uniquely named template so generated/custom themes with
	// an older BetterReactionsAdmin.template.php cannot shadow new reaction visuals.
	loadTemplate('BetterReactionsBoards');
	$context['sub_template'] = 'better_reactions_boards_page';
}

function BetterReactions_GetBoardPolicy($id_board)
{
	global $smcFunc;

	$id_board = (int) $id_board;
	$default = array('enabled' => true, 'allowed' => array_keys(array_filter(BetterReactions_GetTypes(true), function($type) {
		return empty($type['is_native_like']);
	})));
	if ($id_board <= 0)
		return $default;

	$request = $smcFunc['db_query']('', '
		SELECT enabled, allowed_reactions
		FROM {db_prefix}better_reactions_boards
		WHERE id_board = {int:id_board}
		LIMIT 1',
		array('id_board' => $id_board)
	);
	$row = $smcFunc['db_fetch_assoc']($request);
	$smcFunc['db_free_result']($request);

	if (empty($row))
		return $default;

	$allowed = array();
	if ((string) $row['allowed_reactions'] !== '')
		$allowed = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $row['allowed_reactions'])))));

	return array(
		'enabled' => !empty($row['enabled']),
		'allowed' => $allowed,
	);
}

function BetterReactions_BoardAllowsReaction($id_board, $reaction_key)
{
	$reaction_key = strtolower(trim((string) $reaction_key));
	if ($reaction_key === 'like')
		return true;

	$policy = BetterReactions_GetBoardPolicy((int) $id_board);
	return !empty($policy['enabled']) && in_array($reaction_key, $policy['allowed'], true);
}

function BetterReactions_GetTypesForBoard($id_board, $enabled_only = true)
{
	$types = BetterReactions_GetTypes($enabled_only);
	$policy = BetterReactions_GetBoardPolicy((int) $id_board);
	if (empty($policy['enabled']))
		return array();

	foreach ($types as $key => $type)
	{
		if (!empty($type['is_native_like']))
			continue;
		if (!in_array($key, $policy['allowed'], true))
			unset($types[$key]);
	}
	return $types;
}


/**
 * Add the Given / Received reaction history to member profiles.
 * Assignment is idempotent because SMF 2.1 releases may expose both the old
 * integrate_pre_profile_areas hook and the newer integrate_profile_areas hook.
 */
function BetterReactions_ProfileAreas(&$profile_areas)
{
	global $txt;
	BetterReactions_LoadLanguage();

	if (empty($profile_areas['info']['areas']))
		return;

	$profile_areas['info']['areas']['betterreactions'] = array(
		'label' => $txt['better_reactions_profile_title'],
		'file' => 'BetterReactions.php',
		'function' => 'BetterReactions_ProfileHistory',
		'icon' => 'stats',
		'subsections' => array(
			'given' => array($txt['better_reactions_profile_given'], array('is_not_guest', 'profile_view')),
			'received' => array($txt['better_reactions_profile_received'], array('is_not_guest', 'profile_view')),
		),
		'permission' => array(
			'own' => 'is_not_guest',
			'any' => 'profile_view',
		),
	);
}

function BetterReactions_GetStaffOnlyReactions()
{
	global $modSettings;
	$list = array();
	if (!empty($modSettings['better_reactions_staff_only']))
	{
		$decoded = json_decode((string) $modSettings['better_reactions_staff_only'], true);
		if (is_array($decoded))
			foreach ($decoded as $key)
			{
				$key = BetterReactions_Slug($key);
				if ($key !== '' && $key !== 'like')
					$list[] = $key;
			}
	}
	return array_values(array_unique($list));
}

function BetterReactions_IsStaffOnlyReaction($key)
{
	return in_array(BetterReactions_Slug($key), BetterReactions_GetStaffOnlyReactions(), true);
}

function BetterReactions_CurrentUserIsStaff()
{
	global $user_info;
	if (empty($user_info['id']))
		return false;
	if (!empty($user_info['is_admin']) || !empty($user_info['is_mod']))
		return true;
	$groups = BetterReactions_GetMemberGroupIds();
	if (in_array(1, $groups, true))
		return true;
	return allowedTo('admin_forum') || allowedTo('moderate_forum');
}

function BetterReactions_GetTopicStateRules()
{
	global $modSettings;
	$rules = array();
	if (!empty($modSettings['better_reactions_topic_state_rules']))
	{
		$decoded = json_decode((string) $modSettings['better_reactions_topic_state_rules'], true);
		if (is_array($decoded))
		{
			foreach ($decoded as $key => $rule)
			{
				$key = BetterReactions_Slug($key);
				if ($key === '' || !is_array($rule)) continue;
				$label = trim(BetterReactions_NormalizeAdminText(isset($rule['label']) ? $rule['label'] : ''));
				$threshold = max(1, min(100, (int) (isset($rule['threshold']) ? $rule['threshold'] : 1)));
				if ($label !== '')
					$rules[$key] = array('label' => substr($label, 0, 40), 'threshold' => $threshold);
			}
		}
	}
	return $rules;
}

function BetterReactions_CurrentTopicId()
{
	if (empty($_REQUEST['topic'])) return 0;
	$value = (string) $_REQUEST['topic'];
	if (preg_match('~^(\d+)~', $value, $m)) return (int) $m[1];
	return 0;
}

function BetterReactions_GetTopicStates($id_topic)
{
	global $smcFunc, $modSettings;
	$id_topic = (int) $id_topic;
	if ($id_topic <= 0 || empty($modSettings['better_reactions_topic_states_enabled']))
		return array();
	$rules = BetterReactions_GetTopicStateRules();
	if (empty($rules)) return array();

	$maxima = array();
	$trigger_message = array();
	$custom_keys = array_values(array_diff(array_keys($rules), array('like')));
	if (!empty($custom_keys))
	{
		$request = $smcFunc['db_query']('', '
			SELECT r.reaction_key, r.content_id, COUNT(*) AS total
			FROM {db_prefix}better_reactions AS r
				INNER JOIN {db_prefix}messages AS m ON (m.id_msg = r.content_id AND r.content_type = {string:type})
			WHERE m.id_topic = {int:topic} AND r.reaction_key IN ({array_string:keys})
			GROUP BY r.reaction_key, r.content_id', array('type' => 'msg', 'topic' => $id_topic, 'keys' => $custom_keys));
		while ($row = $smcFunc['db_fetch_assoc']($request))
		{
			$key = (string) $row['reaction_key']; $total = (int) $row['total'];
			if (!isset($maxima[$key]) || $total > $maxima[$key]) { $maxima[$key] = $total; $trigger_message[$key] = (int) $row['content_id']; }
		}
		$smcFunc['db_free_result']($request);
	}
	if (isset($rules['like']))
	{
		$request = $smcFunc['db_query']('', '
			SELECT l.content_id, COUNT(*) AS total
			FROM {db_prefix}user_likes AS l
				INNER JOIN {db_prefix}messages AS m ON (m.id_msg = l.content_id AND l.content_type = {string:type})
			WHERE m.id_topic = {int:topic}
			GROUP BY l.content_id', array('type' => 'msg', 'topic' => $id_topic));
		while ($row = $smcFunc['db_fetch_assoc']($request))
		{
			$total = (int) $row['total'];
			if (!isset($maxima['like']) || $total > $maxima['like']) { $maxima['like'] = $total; $trigger_message['like'] = (int) $row['content_id']; }
		}
		$smcFunc['db_free_result']($request);
	}

	$types = BetterReactions_GetTypes(false);
	$out = array();
	foreach ($rules as $key => $rule)
	{
		$count = isset($maxima[$key]) ? (int) $maxima[$key] : 0;
		if ($count < (int) $rule['threshold']) continue;
		$type = isset($types[$key]) ? $types[$key] : array('reaction_key'=>$key,'label'=>ucfirst($key),'icon'=>'emoji:✨','is_native_like'=>0);
		$visual = BetterReactions_GetVisual($type);
		$out[] = array('key'=>$key,'label'=>$rule['label'],'count'=>$count,'threshold'=>(int)$rule['threshold'],'message'=>isset($trigger_message[$key])?(int)$trigger_message[$key]:0,'glyph'=>$visual['glyph'],'image'=>$visual['image']);
	}
	return $out;
}

function BetterReactions_GetMilestones()
{
	global $modSettings;
	$defaults = array(
		array('threshold'=>10,'label'=>'Getting Noticed','icon'=>'✨'),
		array('threshold'=>50,'label'=>'Appreciated','icon'=>'💙'),
		array('threshold'=>100,'label'=>'Community Favorite','icon'=>'🏅'),
		array('threshold'=>500,'label'=>'Reaction Legend','icon'=>'🏆'),
	);
	if (empty($modSettings['better_reactions_milestones'])) return $defaults;
	$decoded = json_decode((string) $modSettings['better_reactions_milestones'], true);
	if (!is_array($decoded)) return $defaults;
	$out = array();
	foreach ($decoded as $row)
	{
		if (!is_array($row)) continue;
		$threshold = max(1, min(1000000, (int) (isset($row['threshold']) ? $row['threshold'] : 0)));
		$label = trim(BetterReactions_NormalizeAdminText(isset($row['label']) ? $row['label'] : ''));
		$icon = trim(BetterReactions_NormalizeAdminText(isset($row['icon']) ? $row['icon'] : ''));
		if ($label === '') continue;
		$out[] = array('threshold'=>$threshold,'label'=>substr($label,0,60),'icon'=>$icon !== '' ? substr($icon,0,16) : '🏅');
	}
	usort($out, function($a,$b){ return $a['threshold'] <=> $b['threshold']; });
	return $out;
}

function BetterReactions_GetMemberMilestoneStatus($memID)
{
	global $smcFunc, $modSettings;
	if (empty($modSettings['better_reactions_milestones_enabled'])) return array('total'=>0,'earned'=>array(),'next'=>null);
	$memID = (int) $memID; $total = 0;
	$request = $smcFunc['db_query']('', '
		SELECT COUNT(*) AS total FROM {db_prefix}better_reactions AS r
		INNER JOIN {db_prefix}messages AS m ON (r.content_type={string:type} AND r.content_id=m.id_msg)
		WHERE m.id_member={int:member} AND {query_see_message_board}', array('type'=>'msg','member'=>$memID));
	$row=$smcFunc['db_fetch_assoc']($request); $smcFunc['db_free_result']($request); $total += !empty($row['total'])?(int)$row['total']:0;
	$request = $smcFunc['db_query']('', '
		SELECT COUNT(*) AS total FROM {db_prefix}user_likes AS l
		INNER JOIN {db_prefix}messages AS m ON (l.content_type={string:type} AND l.content_id=m.id_msg)
		WHERE m.id_member={int:member} AND {query_see_message_board}', array('type'=>'msg','member'=>$memID));
	$row=$smcFunc['db_fetch_assoc']($request); $smcFunc['db_free_result']($request); $total += !empty($row['total'])?(int)$row['total']:0;
	$earned=array(); $next=null;
	foreach (BetterReactions_GetMilestones() as $milestone)
	{
		if ($total >= $milestone['threshold']) $earned[]=$milestone;
		elseif ($next === null) $next=$milestone;
	}
	return array('total'=>$total,'earned'=>$earned,'next'=>$next);
}

function BetterReactions_InstallStaffToolkit()
{
	global $smcFunc;
	$definitions = array(
		array('solved','Solved','emoji:✅','Support'),
		array('reviewed','Reviewed','emoji:🔎','Staff'),
		array('needs_attention','Needs Attention','emoji:⚠️','Staff'),
	);
	$types=BetterReactions_GetTypes(false); $map=BetterReactions_GetCategoryMap(); $staff=BetterReactions_GetStaffOnlyReactions(); $rules=BetterReactions_GetTopicStateRules();
	$order=200;
	foreach($definitions as $def)
	{
		list($key,$label,$icon,$category)=$def;
		if (!isset($types[$key]))
		{
			$smcFunc['db_insert']('insert','{db_prefix}better_reactions_types',
				array('reaction_key'=>'string-32','label'=>'string-80','icon'=>'string-255','sort_order'=>'int','enabled'=>'int','is_native_like'=>'int'),
				array($key,$label,$icon,$order,1,0),array('id_reaction'));
			BetterReactions_AddReactionToAllBoards($key); BetterReactions_AddReactionToAllGroups($key);
		}
		else
		{
			// Preserve administrator-customized label/icon/category, but make sure a
			// previously archived toolkit reaction is available again.
			$smcFunc['db_query']('', 'UPDATE {db_prefix}better_reactions_types SET enabled={int:enabled} WHERE reaction_key={string:key}', array('enabled'=>1,'key'=>$key));
		}
		if (!isset($map[$key])) $map[$key]=$category;
		if (!in_array($key,$staff,true)) $staff[]=$key;
		if (!isset($rules[$key])) $rules[$key]=array('label'=>$label,'threshold'=>1);
		$order+=10;
	}
	BetterReactions_SaveCategoryMap($map);
	updateSettings(array(
		'better_reactions_staff_only'=>json_encode(array_values(array_unique($staff))),
		'better_reactions_topic_states_enabled'=>1,
		'better_reactions_topic_state_rules'=>json_encode($rules,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
		'better_reactions_active_preset'=>'custom',
	));
}

function BetterReactions_IsStaffToolkitReady()
{
	$types = BetterReactions_GetTypes(false);
	$staff = array_flip(BetterReactions_GetStaffOnlyReactions());
	$rules = BetterReactions_GetTopicStateRules();
	$required = array('solved', 'reviewed', 'needs_attention');

	foreach ($required as $key)
	{
		if (empty($types[$key]) || empty($types[$key]['enabled']) || !isset($staff[$key]) || !isset($rules[$key]))
			return false;
	}

	return true;
}

function BetterReactions_AdminFeatures()
{
	global $context, $modSettings;
	BetterReactions_LoadLanguage();
	loadTemplate('BetterReactionsFeatures');
	$types = BetterReactions_GetTypes(false);

	if (isset($_POST['install_staff_toolkit']))
	{
		checkSession('post');
		BetterReactions_InstallStaffToolkit();
		redirectexit('action=admin;area=betterreactions;sa=features;toolkit');
	}

	if (isset($_POST['save_features']))
	{
		checkSession('post');
		$staff_only = array();
		if (!empty($_POST['staff_only']) && is_array($_POST['staff_only']))
			foreach ($_POST['staff_only'] as $key => $enabled)
			{
				$key=BetterReactions_Slug($key);
				if ($key !== 'like' && isset($types[$key]) && !empty($enabled)) $staff_only[]=$key;
			}

		$rules = array();
		if (!empty($_POST['state_enabled']) && is_array($_POST['state_enabled']))
			foreach ($_POST['state_enabled'] as $key => $enabled)
			{
				$key=BetterReactions_Slug($key); if (!isset($types[$key]) || empty($enabled)) continue;
				$label=trim(BetterReactions_NormalizeAdminText(isset($_POST['state_label'][$key])?$_POST['state_label'][$key]:$types[$key]['label']));
				$threshold=max(1,min(100,(int)(isset($_POST['state_threshold'][$key])?$_POST['state_threshold'][$key]:1)));
				if ($label !== '') $rules[$key]=array('label'=>substr($label,0,40),'threshold'=>$threshold);
			}

		$milestones = array();
		for ($i=0; $i<4; $i++)
		{
			$threshold=max(1,min(1000000,(int)(isset($_POST['milestone_threshold'][$i])?$_POST['milestone_threshold'][$i]:0)));
			$label=trim(BetterReactions_NormalizeAdminText(isset($_POST['milestone_label'][$i])?$_POST['milestone_label'][$i]:''));
			$icon=trim(BetterReactions_NormalizeAdminText(isset($_POST['milestone_icon'][$i])?$_POST['milestone_icon'][$i]:''));
			if ($label !== '') $milestones[]=array('threshold'=>$threshold,'label'=>substr($label,0,60),'icon'=>$icon!==''?substr($icon,0,16):'🏅');
		}
		usort($milestones,function($a,$b){return $a['threshold']<=>$b['threshold'];});
		updateSettings(array(
			'better_reactions_staff_only'=>json_encode(array_values(array_unique($staff_only))),
			'better_reactions_topic_states_enabled'=>!empty($_POST['topic_states_enabled'])?1:0,
			'better_reactions_topic_state_rules'=>json_encode($rules,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
			'better_reactions_milestones_enabled'=>!empty($_POST['milestones_enabled'])?1:0,
			'better_reactions_milestones'=>json_encode($milestones,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
		));
		redirectexit('action=admin;area=betterreactions;sa=features;saved');
	}

	$context['better_reactions_feature_types']=$types;
	$context['better_reactions_staff_only']=BetterReactions_GetStaffOnlyReactions();
	$context['better_reactions_topic_states_enabled']=!empty($modSettings['better_reactions_topic_states_enabled']);
	$context['better_reactions_topic_state_rules']=BetterReactions_GetTopicStateRules();
	$context['better_reactions_milestones_enabled']=!empty($modSettings['better_reactions_milestones_enabled']);
	$context['better_reactions_milestones']=BetterReactions_GetMilestones();
	$context['better_reactions_features_saved']=isset($_GET['saved']);
	$context['better_reactions_toolkit_installed']=isset($_GET['toolkit']);
	$context['better_reactions_staff_toolkit_ready']=BetterReactions_IsStaffToolkitReady();
	$context['sub_template']='better_reactions_features_page';
}

function BetterReactions_GetMemberGroupIds($user = null)
{
	global $user_info;
	if ($user === null)
		$user = $user_info;

	$groups = array();
	if (isset($user['id_group']))
		$groups[] = (int) $user['id_group'];
	if (!empty($user['additional_groups']))
	{
		$extra = is_array($user['additional_groups']) ? $user['additional_groups'] : explode(',', (string) $user['additional_groups']);
		foreach ($extra as $group)
			if ((int) $group >= 0)
				$groups[] = (int) $group;
	}
	if (empty($groups))
		$groups[] = 0;
	return array_values(array_unique($groups));
}

function BetterReactions_GetGroupPolicies($group_ids)
{
	global $smcFunc;
	$group_ids = array_values(array_unique(array_filter(array_map('intval', (array) $group_ids), function ($id) { return $id >= 0; })));
	if (empty($group_ids))
		return array();

	$request = $smcFunc['db_query']('', '
		SELECT id_group, enabled, allowed_reactions
		FROM {db_prefix}better_reactions_groups
		WHERE id_group IN ({array_int:groups})',
		array('groups' => $group_ids)
	);
	$policies = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$allowed = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $row['allowed_reactions'])))));
		$policies[(int) $row['id_group']] = array('enabled' => !empty($row['enabled']), 'allowed' => $allowed);
	}
	$smcFunc['db_free_result']($request);
	return $policies;
}

function BetterReactions_CurrentUserAllowsReaction($reaction_key)
{
	global $user_info;
	$reaction_key = strtolower(trim((string) $reaction_key));
	if ($reaction_key === 'like')
		return true;
	if (BetterReactions_IsStaffOnlyReaction($reaction_key) && !BetterReactions_CurrentUserIsStaff())
		return false;
	if (empty($user_info['id']))
		return false;

	$groups = BetterReactions_GetMemberGroupIds();
	if (in_array(1, $groups, true))
		return true;

	$types = BetterReactions_GetTypes(true);
	$all = array_keys(array_filter($types, function ($type) { return empty($type['is_native_like']); }));
	$policies = BetterReactions_GetGroupPolicies($groups);
	$allowed = array();
	foreach ($groups as $group)
	{
		// Backward-compatible default: groups without a 0.5.0 policy row retain
		// access to all enabled reactions until the administrator saves the matrix.
		if (!isset($policies[$group]))
		{
			$allowed = array_merge($allowed, $all);
			continue;
		}
		if (!empty($policies[$group]['enabled']))
			$allowed = array_merge($allowed, $policies[$group]['allowed']);
	}
	return in_array($reaction_key, array_unique($allowed), true);
}

function BetterReactions_FilterTypesForCurrentUser($types)
{
	global $user_info;
	foreach ($types as $key => $type)
	{
		if (!empty($type['is_native_like']))
			continue;
		if (empty($user_info['id']) || !BetterReactions_CurrentUserAllowsReaction($key))
			unset($types[$key]);
	}
	return $types;
}

function BetterReactions_AdminPermissions()
{
	global $context, $smcFunc, $txt;
	BetterReactions_LoadLanguage();
	loadTemplate('BetterReactionsPermissions');

	$types = array_filter(BetterReactions_GetTypes(true), function ($type) { return empty($type['is_native_like']); });
	$groups = array();
	$request = $smcFunc['db_query']('', '
		SELECT id_group, group_name
		FROM {db_prefix}membergroups
		WHERE id_group >= {int:min_group}
		ORDER BY id_group', array('min_group' => 0));
	while ($row = $smcFunc['db_fetch_assoc']($request))
		$groups[(int) $row['id_group']] = array('id_group' => (int) $row['id_group'], 'name' => (string) $row['group_name']);
	$smcFunc['db_free_result']($request);
	if (!isset($groups[0]))
		$groups = array(0 => array('id_group' => 0, 'name' => $txt['better_reactions_regular_members'])) + $groups;

	if (isset($_POST['save']))
	{
		checkSession('post');
		foreach ($groups as $id_group => $group)
		{
			$enabled = !empty($_POST['group_enabled'][$id_group]) ? 1 : 0;
			$allowed = array();
			if (!empty($_POST['group_reactions'][$id_group]) && is_array($_POST['group_reactions'][$id_group]))
			{
				foreach ($_POST['group_reactions'][$id_group] as $key)
				{
					$key = strtolower(trim((string) $key));
					if (isset($types[$key]))
						$allowed[] = $key;
				}
			}
			$smcFunc['db_insert']('replace', '{db_prefix}better_reactions_groups',
				array('id_group' => 'int', 'enabled' => 'int', 'allowed_reactions' => 'string-255'),
				array($id_group, $enabled, implode(',', array_values(array_unique($allowed)))), array('id_group'));
		}
		redirectexit('action=admin;area=betterreactions;sa=permissions;saved');
	}

	$policies = BetterReactions_GetGroupPolicies(array_keys($groups));
	$all_keys = array_keys($types);
	foreach ($groups as $id_group => &$group)
	{
		$group['policy'] = isset($policies[$id_group]) ? $policies[$id_group] : array('enabled' => true, 'allowed' => $all_keys);
		$group['is_admin'] = $id_group === 1;
	}
	unset($group);

	$context['better_reactions_permission_groups'] = $groups;
	$context['better_reactions_permission_types'] = $types;
	$context['better_reactions_permissions_saved'] = isset($_GET['saved']);
	$context['sub_template'] = 'better_reactions_permissions';
}

function BetterReactions_GetMemberNames($ids)
{
	global $smcFunc;
	$ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
	if (empty($ids))
		return array();
	$request = $smcFunc['db_query']('', 'SELECT id_member, real_name FROM {db_prefix}members WHERE id_member IN ({array_int:ids})', array('ids' => $ids));
	$names = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
		$names[(int) $row['id_member']] = (string) $row['real_name'];
	$smcFunc['db_free_result']($request);
	return $names;
}

function BetterReactions_AdminAnalytics()
{
	global $context, $smcFunc;
	BetterReactions_LoadLanguage();
	loadTemplate('BetterReactionsAnalytics');
	$since = time() - 30 * 86400;

	$metrics = array('custom_total' => 0, 'native_total' => 0, 'custom_30' => 0, 'native_30' => 0);
	$request = $smcFunc['db_query']('', 'SELECT COUNT(*), SUM(created_at >= {int:since}) FROM {db_prefix}better_reactions WHERE content_type = {string:type}', array('since' => $since, 'type' => 'msg'));
	list($metrics['custom_total'], $metrics['custom_30']) = $smcFunc['db_fetch_row']($request);
	$smcFunc['db_free_result']($request);
	$request = $smcFunc['db_query']('', 'SELECT COUNT(*), SUM(like_time >= {int:since}) FROM {db_prefix}user_likes WHERE content_type = {string:type}', array('since' => $since, 'type' => 'msg'));
	list($metrics['native_total'], $metrics['native_30']) = $smcFunc['db_fetch_row']($request);
	$smcFunc['db_free_result']($request);
	foreach ($metrics as &$v) $v = (int) $v; unset($v);
	$metrics['total'] = $metrics['custom_total'] + $metrics['native_total'];
	$metrics['last_30'] = $metrics['custom_30'] + $metrics['native_30'];

	$reaction_counts = array('like' => $metrics['native_total']);
	$request = $smcFunc['db_query']('', 'SELECT reaction_key, COUNT(*) AS total FROM {db_prefix}better_reactions WHERE content_type={string:type} GROUP BY reaction_key ORDER BY total DESC', array('type'=>'msg'));
	while ($row = $smcFunc['db_fetch_assoc']($request)) $reaction_counts[(string)$row['reaction_key']] = (int)$row['total'];
	$smcFunc['db_free_result']($request);
	arsort($reaction_counts);
	$types = BetterReactions_GetTypes(false);
	$reaction_mix = array();
	foreach ($reaction_counts as $key => $count)
	{
		$type = isset($types[$key]) ? $types[$key] : array('reaction_key'=>$key,'label'=>ucfirst($key),'icon'=>'emoji:✨','is_native_like'=>0);
		$reaction_mix[] = array('key'=>$key,'label'=>(string)$type['label'],'count'=>$count,'visual'=>BetterReactions_GetVisual($type));
	}

	$reactors = array();
	$request = $smcFunc['db_query']('', 'SELECT id_member, COUNT(*) AS total FROM {db_prefix}better_reactions WHERE content_type={string:type} AND id_member > 0 GROUP BY id_member ORDER BY total DESC LIMIT 50', array('type'=>'msg'));
	while ($row=$smcFunc['db_fetch_assoc']($request)) $reactors[(int)$row['id_member']] = ($reactors[(int)$row['id_member']] ?? 0) + (int)$row['total'];
	$smcFunc['db_free_result']($request);
	$request = $smcFunc['db_query']('', 'SELECT id_member, COUNT(*) AS total FROM {db_prefix}user_likes WHERE content_type={string:type} AND id_member > 0 GROUP BY id_member ORDER BY total DESC LIMIT 50', array('type'=>'msg'));
	while ($row=$smcFunc['db_fetch_assoc']($request)) $reactors[(int)$row['id_member']] = ($reactors[(int)$row['id_member']] ?? 0) + (int)$row['total'];
	$smcFunc['db_free_result']($request);
	arsort($reactors); $reactors = array_slice($reactors,0,10,true);
	$names = BetterReactions_GetMemberNames(array_keys($reactors));
	$top_reactors = array(); foreach ($reactors as $id=>$count) $top_reactors[] = array('id'=>$id,'name'=>$names[$id] ?? ('#'.$id),'count'=>$count);

	$received = array();
	$request=$smcFunc['db_query']('', 'SELECT m.id_member, COUNT(*) AS total FROM {db_prefix}better_reactions AS r INNER JOIN {db_prefix}messages AS m ON (r.content_type={string:type} AND r.content_id=m.id_msg) WHERE m.id_member > 0 GROUP BY m.id_member ORDER BY total DESC LIMIT 50', array('type'=>'msg'));
	while($row=$smcFunc['db_fetch_assoc']($request)) $received[(int)$row['id_member']] = ($received[(int)$row['id_member']] ?? 0)+(int)$row['total']; $smcFunc['db_free_result']($request);
	$request=$smcFunc['db_query']('', 'SELECT m.id_member, COUNT(*) AS total FROM {db_prefix}user_likes AS l INNER JOIN {db_prefix}messages AS m ON (l.content_type={string:type} AND l.content_id=m.id_msg) WHERE m.id_member > 0 GROUP BY m.id_member ORDER BY total DESC LIMIT 50', array('type'=>'msg'));
	while($row=$smcFunc['db_fetch_assoc']($request)) $received[(int)$row['id_member']] = ($received[(int)$row['id_member']] ?? 0)+(int)$row['total']; $smcFunc['db_free_result']($request);
	arsort($received); $received=array_slice($received,0,10,true); $rnames=BetterReactions_GetMemberNames(array_keys($received));
	$top_received=array(); foreach($received as $id=>$count) $top_received[]=array('id'=>$id,'name'=>$rnames[$id]??('#'.$id),'count'=>$count);

	$boards=array();
	$request=$smcFunc['db_query']('', 'SELECT m.id_board, COUNT(*) AS total FROM {db_prefix}better_reactions AS r INNER JOIN {db_prefix}messages AS m ON (r.content_type={string:type} AND r.content_id=m.id_msg) GROUP BY m.id_board', array('type'=>'msg'));
	while($row=$smcFunc['db_fetch_assoc']($request)) $boards[(int)$row['id_board']] = ($boards[(int)$row['id_board']]??0)+(int)$row['total']; $smcFunc['db_free_result']($request);
	$request=$smcFunc['db_query']('', 'SELECT m.id_board, COUNT(*) AS total FROM {db_prefix}user_likes AS l INNER JOIN {db_prefix}messages AS m ON (l.content_type={string:type} AND l.content_id=m.id_msg) GROUP BY m.id_board', array('type'=>'msg'));
	while($row=$smcFunc['db_fetch_assoc']($request)) $boards[(int)$row['id_board']] = ($boards[(int)$row['id_board']]??0)+(int)$row['total']; $smcFunc['db_free_result']($request);
	arsort($boards); $boards=array_slice($boards,0,10,true);
	$board_names=array(); if(!empty($boards)){ $request=$smcFunc['db_query']('', 'SELECT id_board, name FROM {db_prefix}boards WHERE id_board IN ({array_int:ids})', array('ids'=>array_keys($boards))); while($row=$smcFunc['db_fetch_assoc']($request)) $board_names[(int)$row['id_board']]=(string)$row['name']; $smcFunc['db_free_result']($request); }
	$top_boards=array(); foreach($boards as $id=>$count) $top_boards[]=array('id'=>$id,'name'=>$board_names[$id]??('#'.$id),'count'=>$count);

	$context['better_reactions_analytics'] = array('metrics'=>$metrics,'reaction_mix'=>$reaction_mix,'top_reactors'=>$top_reactors,'top_received'=>$top_received,'top_boards'=>$top_boards);
	$context['sub_template'] = 'better_reactions_analytics';
}

function BetterReactions_ProfileHistory($memID)
{
	global $context, $smcFunc, $scripturl;
	BetterReactions_LoadLanguage();
	loadTemplate('BetterReactionsProfile');
	$memID = (int) $memID;
	$mode = isset($_REQUEST['sa']) && $_REQUEST['sa'] === 'received' ? 'received' : 'given';
	$rows = array();
	$types = BetterReactions_GetTypes(false);
	$context['better_reactions_milestone_status'] = BetterReactions_GetMemberMilestoneStatus($memID);

	if ($mode === 'given')
	{
		$request=$smcFunc['db_query']('', '
			SELECT r.reaction_key, r.created_at, m.id_msg, m.id_topic, m.subject, m.id_member AS other_id, mem.real_name AS other_name
			FROM {db_prefix}better_reactions AS r
			INNER JOIN {db_prefix}messages AS m ON (r.content_type={string:type} AND r.content_id=m.id_msg)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member=m.id_member)
			WHERE r.id_member={int:member} AND {query_see_message_board}
			ORDER BY r.created_at DESC LIMIT 100', array('type'=>'msg','member'=>$memID));
	}
	else
	{
		$request=$smcFunc['db_query']('', '
			SELECT r.reaction_key, r.created_at, m.id_msg, m.id_topic, m.subject, r.id_member AS other_id, mem.real_name AS other_name
			FROM {db_prefix}better_reactions AS r
			INNER JOIN {db_prefix}messages AS m ON (r.content_type={string:type} AND r.content_id=m.id_msg)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member=r.id_member)
			WHERE m.id_member={int:member} AND {query_see_message_board}
			ORDER BY r.created_at DESC LIMIT 100', array('type'=>'msg','member'=>$memID));
	}
	while($row=$smcFunc['db_fetch_assoc']($request)) $rows[]=$row; $smcFunc['db_free_result']($request);

	if ($mode === 'given')
	{
		$request=$smcFunc['db_query']('', '
			SELECT {string:like} AS reaction_key, l.like_time AS created_at, m.id_msg, m.id_topic, m.subject, m.id_member AS other_id, mem.real_name AS other_name
			FROM {db_prefix}user_likes AS l
			INNER JOIN {db_prefix}messages AS m ON (l.content_type={string:type} AND l.content_id=m.id_msg)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member=m.id_member)
			WHERE l.id_member={int:member} AND {query_see_message_board}
			ORDER BY l.like_time DESC LIMIT 100', array('like'=>'like','type'=>'msg','member'=>$memID));
	}
	else
	{
		$request=$smcFunc['db_query']('', '
			SELECT {string:like} AS reaction_key, l.like_time AS created_at, m.id_msg, m.id_topic, m.subject, l.id_member AS other_id, mem.real_name AS other_name
			FROM {db_prefix}user_likes AS l
			INNER JOIN {db_prefix}messages AS m ON (l.content_type={string:type} AND l.content_id=m.id_msg)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member=l.id_member)
			WHERE m.id_member={int:member} AND {query_see_message_board}
			ORDER BY l.like_time DESC LIMIT 100', array('like'=>'like','type'=>'msg','member'=>$memID));
	}
	while($row=$smcFunc['db_fetch_assoc']($request)) $rows[]=$row; $smcFunc['db_free_result']($request);

	usort($rows, function($a,$b){ return (int)$b['created_at'] <=> (int)$a['created_at']; });
	$rows=array_slice($rows,0,50);
	foreach($rows as &$row)
	{
		$key=(string)$row['reaction_key'];
		$type=isset($types[$key])?$types[$key]:array('reaction_key'=>$key,'label'=>ucfirst($key),'icon'=>'emoji:✨','is_native_like'=>0);
		$row['label']=(string)$type['label']; $row['visual']=BetterReactions_GetVisual($type);
		$row['topic_url']=$scripturl.'?topic='.(int)$row['id_topic'].'.msg'.(int)$row['id_msg'].'#msg'.(int)$row['id_msg'];
		$row['member_url']=!empty($row['other_id'])?$scripturl.'?action=profile;u='.(int)$row['other_id']:'';
		$row['other_name']=!empty($row['other_name'])?(string)$row['other_name']:'#'.(int)$row['other_id'];
		$row['time']=timeformat((int)$row['created_at']);
	}
	unset($row);
	$context['better_reactions_profile_mode']=$mode;
	$context['better_reactions_profile_rows']=$rows;
	$context['page_title']=$context['member']['name'].' - '.($mode==='received' ? $context['member']['name'].' reactions received' : $context['member']['name'].' reactions given');
	$context['sub_template']='better_reactions_profile_history';
}

function BetterReactions_GlyphMap()
{
	return array(
		'like' => '👍', 'love' => '❤️', 'laugh' => '😂', 'wow' => '😮',
		'sad' => '😢', 'celebrate' => '🎉', 'fire' => '🔥',
	);
}

function BetterReactions_GetVisual($type)
{
	global $boardurl;
	$key = (string) $type['reaction_key'];
	$icon = (string) $type['icon'];
	if (strpos($icon, 'file:') === 0)
		return array('glyph' => '', 'image' => $boardurl . '/better_reactions_icons/' . rawurlencode(substr($icon, 5)));
	if (strpos($icon, 'emoji:') === 0)
		return array('glyph' => BetterReactions_NormalizeAdminText(substr($icon, 6)), 'image' => '');
	$glyphs = BetterReactions_GlyphMap();
	return array('glyph' => isset($glyphs[$key]) ? $glyphs[$key] : '✨', 'image' => '');
}

function BetterReactions_ValidLikes($type, $content, $sa, $js, $extra) {}
function BetterReactions_IssueLikeBefore(&$type, &$content, &$user, &$time) {}
function BetterReactions_IssueLike($like_object) {}

function BetterReactions_LikesJsonResponse(&$print)
{
	global $modSettings;
	if (!empty($modSettings['better_reactions_enabled']) && !empty($modSettings['better_reactions_native_like_bridge']) && is_array($print))
		$print['better_reactions_bridge'] = true;
}

function BetterReactions_GetTypes($enabled_only = true)
{
	global $smcFunc;
	$request = $smcFunc['db_query']('', '
		SELECT id_reaction, reaction_key, label, icon, sort_order, enabled, is_native_like
		FROM {db_prefix}better_reactions_types' . ($enabled_only ? '
		WHERE enabled = {int:enabled}' : '') . '
		ORDER BY sort_order, id_reaction',
		array('enabled' => 1)
	);
	$types = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
		$types[$row['reaction_key']] = $row;
	$smcFunc['db_free_result']($request);
	call_integration_hook('integrate_better_reactions_types', array(&$types));
	return $types;
}

function BetterReactions_IsAvailable($content_type)
{
	global $modSettings;
	$available = !empty($modSettings['better_reactions_enabled']);
	call_integration_hook('integrate_better_reactions_available', array($content_type, &$available));
	return $available;
}
