<?php
/** Better Reactions & Likes - membergroup reaction permissions. @version 0.1.0 */
if (!defined('SMF'))
	die('No direct access...');

function template_better_reactions_permissions()
{
	global $context, $txt, $scripturl;
	$types = $context['better_reactions_permission_types'];

	if (!empty($context['better_reactions_permissions_saved']))
		echo '<div class="infobox">', $txt['better_reactions_permissions_saved'], '</div>';

	echo '
	<form action="', $scripturl, '?action=admin;area=betterreactions;sa=permissions" method="post">
		<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_admin_permissions'], '</h3></div>
		<div class="windowbg">
			<p class="description">', $txt['better_reactions_permissions_intro'], '</p>
			<div class="information">', $txt['better_reactions_permissions_note'], '</div>
		</div>
		<table class="table_grid"><thead><tr class="title_bar">
			<th>', $txt['better_reactions_col_membergroup'], '</th>
			<th class="centercol">', $txt['better_reactions_group_enabled'], '</th>
			<th>', $txt['better_reactions_group_allowed'], '</th>
		</tr></thead><tbody>';

	foreach ($context['better_reactions_permission_groups'] as $id_group => $group)
	{
		$policy = $group['policy'];
		echo '<tr class="windowbg"><td><strong>', htmlspecialchars($group['name'], ENT_QUOTES), '</strong>', !empty($group['is_admin']) ? '<br><span class="smalltext">' . $txt['better_reactions_admin_group_bypass'] . '</span>' : '', '</td>
			<td class="centercol"><input type="checkbox" name="group_enabled[', (int) $id_group, ']" value="1"', !empty($policy['enabled']) ? ' checked' : '', !empty($group['is_admin']) ? ' disabled' : '', '>', !empty($group['is_admin']) ? '<input type="hidden" name="group_enabled[' . (int)$id_group . ']" value="1">' : '', '</td>
			<td><div style="display:flex;flex-wrap:wrap;gap:6px 14px">';
		foreach ($types as $key => $type)
		{
			$visual = BetterReactions_GetVisual($type);
			$glyph = $visual['image'] !== '' ? '<img src="' . htmlspecialchars($visual['image'], ENT_QUOTES) . '" alt="" style="width:20px;height:20px;object-fit:contain;vertical-align:middle">' : htmlspecialchars($visual['glyph'], ENT_QUOTES, 'UTF-8');
			$checked = !empty($group['is_admin']) || in_array($key, $policy['allowed'], true);
			echo '<label style="white-space:nowrap"><input type="checkbox" name="group_reactions[', (int)$id_group, '][]" value="', htmlspecialchars($key, ENT_QUOTES), '"', $checked ? ' checked' : '', !empty($group['is_admin']) ? ' disabled' : '', '> ', $glyph, ' ', htmlspecialchars($type['label'], ENT_QUOTES), '</label>';
			if (!empty($group['is_admin']))
				echo '<input type="hidden" name="group_reactions[', (int)$id_group, '][]" value="', htmlspecialchars($key, ENT_QUOTES), '">';
		}
		echo '</div></td></tr>';
	}

	echo '</tbody></table>
		<div class="windowbg righttext"><input type="submit" name="save" value="', $txt['save'], '" class="button"></div>
		<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
	</form>';
}
