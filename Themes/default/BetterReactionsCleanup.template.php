<?php
/**
 * Better Reactions & Likes - Cleanup admin template.
 * Kept separate from BetterReactionsAdmin.template.php so stale custom-theme
 * copies of the main admin template cannot hide newer Cleanup UI.
 *
 * @package BetterReactions
 * @version 0.1.0
 */

function template_better_reactions_cleanup_page()
{
	global $context, $txt, $scripturl;
	$stats = $context['better_reactions_cleanup_stats'];
	$last = $context['better_reactions_cleanup_last'];

	if ($context['better_reactions_cleanup_result'] !== null)
		echo '<div class="infobox">', sprintf($txt['better_reactions_cleanup_removed'], (int) $context['better_reactions_cleanup_result']), '</div>';
	if (!empty($context['better_reactions_cleanup_error']))
		echo '<div class="errorbox">', htmlspecialchars($context['better_reactions_cleanup_error'], ENT_QUOTES), '</div>';

	echo '
	<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_admin_cleanup'], '</h3></div>
	<div class="windowbg">
		<p class="description">', $txt['better_reactions_cleanup_intro'], '</p>';

	if (empty($context['better_reactions_cleanup_available']))
	{
		echo '<div class="information">', $txt['better_reactions_cleanup_chat_missing'], '</div></div>';
		return;
	}

	echo '
		<div class="information">', $txt['better_reactions_cleanup_scope_note'], '</div>
		<dl class="settings">
			<dt><strong>', $txt['better_reactions_cleanup_total_rows'], '</strong></dt><dd>', (int) $stats['total_rows'], '</dd>
			<dt><strong>', $txt['better_reactions_cleanup_orphan_rows'], '</strong><br><span class="smalltext">', $txt['better_reactions_cleanup_orphan_rows_desc'], '</span></dt><dd><strong>', (int) $stats['orphan_rows'], '</strong></dd>
			<dt><strong>', $txt['better_reactions_cleanup_orphan_objects'], '</strong><br><span class="smalltext">', $txt['better_reactions_cleanup_orphan_objects_desc'], '</span></dt><dd>', (int) $stats['orphan_objects'], '</dd>
		</dl>';

	if (!empty($last['time']))
		echo '<p class="smalltext">', sprintf($txt['better_reactions_cleanup_last_run'], timeformat((int) $last['time']), (int) $last['rows']), '</p>';
	else
		echo '<p class="smalltext">', $txt['better_reactions_cleanup_never_run'], '</p>';

	echo '
	</div>
	<form action="', $scripturl, '?action=admin;area=betterreactions;sa=cleanup" method="post" onsubmit="return confirm(', JavaScriptEscape($txt['better_reactions_cleanup_confirm']), ');">
		<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_cleanup_action'], '</h3></div>
		<div class="windowbg">
			<p>', $txt['better_reactions_cleanup_action_desc'], '</p>
			<div class="righttext"><button type="submit" name="clean_chat_orphans" value="1" class="button"', empty($stats['orphan_rows']) ? ' disabled' : '', '>', $txt['better_reactions_cleanup_button'], '</button></div>
		</div>
		<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
	</form>';
}
