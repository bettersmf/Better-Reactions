<?php
/** Better Reactions & Likes - analytics dashboard. @version 0.1.0 */
if (!defined('SMF'))
	die('No direct access...');

function template_better_reactions_analytics()
{
	global $context, $txt, $scripturl;
	$a = $context['better_reactions_analytics'];
	$m = $a['metrics'];

	echo '<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_admin_analytics'], '</h3></div>
	<div class="windowbg"><p class="description">', $txt['better_reactions_analytics_intro'], '</p></div>
	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:12px 0">';
	$cards = array(
		array($txt['better_reactions_metric_total'], $m['total']),
		array($txt['better_reactions_metric_30'], $m['last_30']),
		array($txt['better_reactions_metric_custom'], $m['custom_total']),
		array($txt['better_reactions_metric_native'], $m['native_total']),
	);
	foreach ($cards as $card)
		echo '<div class="windowbg" style="text-align:center"><div class="smalltext">', $card[0], '</div><div style="font-size:2em;font-weight:700">', comma_format((int)$card[1]), '</div></div>';
	echo '</div>';

	echo '<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_analytics_mix'], '</h3></div><table class="table_grid"><thead><tr class="title_bar"><th>', $txt['better_reactions_col_reaction'], '</th><th class="centercol">', $txt['better_reactions_col_total'], '</th></tr></thead><tbody>';
	if (empty($a['reaction_mix']))
		echo '<tr class="windowbg"><td colspan="2">', $txt['better_reactions_analytics_empty'], '</td></tr>';
	foreach ($a['reaction_mix'] as $row)
	{
		$icon = $row['visual']['image'] !== '' ? '<img src="' . htmlspecialchars($row['visual']['image'], ENT_QUOTES) . '" alt="" style="width:24px;height:24px;object-fit:contain;vertical-align:middle">' : htmlspecialchars($row['visual']['glyph'], ENT_QUOTES, 'UTF-8');
		echo '<tr class="windowbg"><td>', $icon, ' ', htmlspecialchars($row['label'], ENT_QUOTES), '</td><td class="centercol">', comma_format((int)$row['count']), '</td></tr>';
	}
	echo '</tbody></table>';

	$tables = array(
		array($txt['better_reactions_top_reactors'], $a['top_reactors'], true),
		array($txt['better_reactions_top_received'], $a['top_received'], true),
		array($txt['better_reactions_top_boards'], $a['top_boards'], false),
	);
	foreach ($tables as $def)
	{
		echo '<div class="cat_bar" style="margin-top:14px"><h3 class="catbg">', $def[0], '</h3></div><table class="table_grid"><thead><tr class="title_bar"><th>', $def[2] ? $txt['better_reactions_col_member'] : $txt['better_reactions_col_board'], '</th><th class="centercol">', $txt['better_reactions_col_total'], '</th></tr></thead><tbody>';
		if (empty($def[1]))
			echo '<tr class="windowbg"><td colspan="2">', $txt['better_reactions_analytics_empty'], '</td></tr>';
		foreach ($def[1] as $row)
		{
			$url = $def[2] ? $scripturl . '?action=profile;u=' . (int)$row['id'] : $scripturl . '?board=' . (int)$row['id'] . '.0';
			echo '<tr class="windowbg"><td><a href="', $url, '">', htmlspecialchars($row['name'], ENT_QUOTES), '</a></td><td class="centercol">', comma_format((int)$row['count']), '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
