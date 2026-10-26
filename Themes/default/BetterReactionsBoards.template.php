<?php
/**
 * Better Reactions & Likes - board policy admin template.
 * Dedicated template to prevent stale generated-theme admin templates from
 * shadowing current reaction visuals.
 *
 * @package BetterReactions
 * @version 0.1.0
 */

function template_better_reactions_boards_page()
{
	global $context, $txt, $scripturl;
	$types = $context['better_reactions_board_types'];

	if (!empty($context['better_reactions_saved']))
		echo '<div class="infobox">', $txt['better_reactions_boards_saved'], '</div>';

	echo '
	<form action="', $scripturl, '?action=admin;area=betterreactions;sa=boards" method="post">
		<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_admin_boards'], '</h3></div>
		<div class="windowbg">
			<p class="description">', $txt['better_reactions_boards_intro'], '</p>
			<div class="information">', $txt['better_reactions_boards_native_note'], '</div>
		</div>
		<table class="table_grid">
			<thead><tr class="title_bar">
				<th>', $txt['better_reactions_col_board'], '</th>
				<th class="centercol">', $txt['better_reactions_col_board_enabled'], '</th>
				<th>', $txt['better_reactions_col_board_reactions'], '</th>
			</tr></thead><tbody>';

	$last_category = null;
	foreach ($context['better_reactions_boards'] as $id_board => $board)
	{
		if ($last_category !== $board['category_name'])
		{
			$last_category = $board['category_name'];
			echo '<tr class="title_bar"><td colspan="3"><strong>', htmlspecialchars($last_category, ENT_QUOTES), '</strong></td></tr>';
		}

		$policy = $board['policy'];
		echo '<tr class="windowbg">
			<td><strong><span class="smalltext">#', (int) $id_board, '</span> ', htmlspecialchars($board['board_name'], ENT_QUOTES), '</strong></td>
			<td class="centercol"><input type="checkbox" name="board_enabled[', (int) $id_board, ']" value="1"', !empty($policy['enabled']) ? ' checked' : '', '></td>
			<td><div style="display:flex;flex-wrap:wrap;gap:6px 14px">';

		foreach ($types as $key => $type)
		{
			$visual = BetterReactions_GetVisual($type);
			$icon = $visual['image'] !== '' ? '<img src="' . htmlspecialchars($visual['image'], ENT_QUOTES) . '" alt="" style="width:18px;height:18px;object-fit:contain;vertical-align:middle">' : '<span style="font-size:1.05em;vertical-align:middle">' . htmlspecialchars($visual['glyph'], ENT_QUOTES) . '</span>';
			$checked = in_array($key, $policy['allowed'], true);
			echo '<label style="white-space:nowrap"><input type="checkbox" name="board_reactions[', (int) $id_board, '][]" value="', htmlspecialchars($key, ENT_QUOTES), '"', $checked ? ' checked' : '', '> ', $icon, ' ', htmlspecialchars($type['label'], ENT_QUOTES), '</label>';
		}

		echo '</div></td></tr>';
	}

	echo '</tbody></table>
		<div class="windowbg righttext"><input type="submit" name="save" value="', $txt['save'], '" class="button"></div>
		<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
	</form>';
}

