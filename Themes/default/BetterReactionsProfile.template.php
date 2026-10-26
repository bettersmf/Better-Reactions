<?php
/** Better Reactions & Likes - profile history. @version 0.1.0 */
if (!defined('SMF'))
	die('No direct access...');

function template_better_reactions_profile_history()
{
	global $context, $txt;
	$mode = $context['better_reactions_profile_mode'];
	$rows = $context['better_reactions_profile_rows'];
	$status = isset($context['better_reactions_milestone_status']) ? $context['better_reactions_milestone_status'] : array('total'=>0,'earned'=>array(),'next'=>null);

	if (!empty($status['earned']) || !empty($status['next']))
	{
		echo '<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_profile_milestones'], '</h3></div><div class="windowbg br-milestone-panel"><div class="br-milestone-total">', sprintf($txt['better_reactions_profile_received_total'], (int) $status['total']), '</div><div class="br-milestone-list">';
		foreach ($status['earned'] as $badge)
			echo '<span class="br-milestone-badge"><span class="br-milestone-icon">', htmlspecialchars($badge['icon'], ENT_QUOTES, 'UTF-8'), '</span><strong>', htmlspecialchars($badge['label'], ENT_QUOTES), '</strong><small>', (int) $badge['threshold'], '+</small></span>';
		echo '</div>';
		if (!empty($status['next']))
		{
			$need=max(0,(int)$status['next']['threshold']-(int)$status['total']);
			echo '<p class="smalltext br-milestone-next">', sprintf($txt['better_reactions_profile_next_milestone'], $need, htmlspecialchars($status['next']['label'], ENT_QUOTES)), '</p>';
		}
		echo '</div>';
	}

	echo '<div class="cat_bar"><h3 class="catbg">', $mode === 'received' ? $txt['better_reactions_profile_received'] : $txt['better_reactions_profile_given'], '</h3></div>
	<div class="windowbg"><p class="description">', $mode === 'received' ? $txt['better_reactions_profile_received_desc'] : $txt['better_reactions_profile_given_desc'], '</p></div>
	<table class="table_grid"><thead><tr class="title_bar">
		<th>', $txt['better_reactions_col_reaction'], '</th>
		<th>', $mode === 'received' ? $txt['better_reactions_profile_from'] : $txt['better_reactions_profile_to'], '</th>
		<th>', $txt['better_reactions_profile_post'], '</th>
		<th>', $txt['better_reactions_profile_date'], '</th>
	</tr></thead><tbody>';
	if (empty($rows))
		echo '<tr class="windowbg"><td colspan="4">', $txt['better_reactions_profile_empty'], '</td></tr>';
	foreach ($rows as $row)
	{
		$icon = $row['visual']['image'] !== '' ? '<img src="' . htmlspecialchars($row['visual']['image'], ENT_QUOTES) . '" alt="" style="width:24px;height:24px;object-fit:contain;vertical-align:middle">' : htmlspecialchars($row['visual']['glyph'], ENT_QUOTES, 'UTF-8');
		echo '<tr class="windowbg"><td>', $icon, ' ', htmlspecialchars($row['label'], ENT_QUOTES), '</td><td>';
		if ($row['member_url'] !== '')
			echo '<a href="', $row['member_url'], '">', htmlspecialchars($row['other_name'], ENT_QUOTES), '</a>';
		else
			echo htmlspecialchars($row['other_name'], ENT_QUOTES);
		echo '</td><td><a href="', $row['topic_url'], '">', htmlspecialchars($row['subject'], ENT_QUOTES), '</a></td><td>', htmlspecialchars($row['time'], ENT_QUOTES), '</td></tr>';
	}
	echo '</tbody></table><p class="smalltext">', $txt['better_reactions_profile_scope_note'], '</p>';
}
