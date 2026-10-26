<?php
/**
 * Better Reactions Categories admin template.
 * Dedicated file prevents stale custom/generated theme copies of
 * BetterReactionsAdmin.template.php from shadowing newer category UI.
 */

function template_better_reactions_admin_categories()
{
	global $context, $txt, $scripturl;

	if (!empty($context['better_reactions_categories_saved']))
		echo '<div class="infobox">', $txt['better_reactions_categories_saved'], '</div>';

	echo '
	<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_admin_categories'], '</h3></div>
	<div class="windowbg"><p class="description">', $txt['better_reactions_categories_intro'], '</p></div>

	<form action="', $scripturl, '?action=admin;area=betterreactions;sa=categories" method="post">
		<div class="title_bar"><h3 class="titlebg">', $txt['better_reactions_categories_existing'], '</h3></div>
		<table class="table_grid"><thead><tr class="title_bar"><th>', $txt['better_reactions_col_category'], '</th><th class="centercol">', $txt['better_reactions_category_reactions'], '</th><th class="centercol">', $txt['better_reactions_col_action'], '</th></tr></thead><tbody>';
	foreach ($context['better_reactions_categories'] as $category)
	{
		$count = isset($context['better_reactions_category_counts'][$category]) ? (int) $context['better_reactions_category_counts'][$category] : 0;
		echo '<tr class="windowbg"><td><strong>', htmlspecialchars($category, ENT_QUOTES), '</strong></td><td class="centercol">', $count, '</td><td class="centercol">';
		if (strcasecmp($category, 'General') !== 0)
			echo '<button type="submit" name="delete_category" value="', htmlspecialchars($category, ENT_QUOTES), '" class="button" onclick="return confirm(\'', addslashes($txt['better_reactions_category_delete_confirm']), '\');">', $txt['better_reactions_remove'], '</button>';
		else
			echo '—';
		echo '</td></tr>';
	}
	echo '</tbody></table><input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '"></form>

	<form action="', $scripturl, '?action=admin;area=betterreactions;sa=categories" method="post" style="margin-top:16px">
		<div class="title_bar"><h3 class="titlebg">', $txt['better_reactions_category_add'], '</h3></div>
		<div class="windowbg"><input type="text" name="new_category_name" maxlength="40" required> <button type="submit" name="add_category" value="1" class="button">', $txt['better_reactions_category_add'], '</button></div>
		<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
	</form>

	<form action="', $scripturl, '?action=admin;area=betterreactions;sa=categories" method="post" style="margin-top:16px">
		<div class="title_bar"><h3 class="titlebg">', $txt['better_reactions_category_assignments'], '</h3></div>
		<table class="table_grid"><thead><tr class="title_bar"><th>', $txt['better_reactions_col_reaction'], '</th><th>', $txt['better_reactions_col_category'], '</th></tr></thead><tbody>';
	foreach ($context['better_reactions_category_types'] as $key => $type)
	{
		$visual = BetterReactions_GetVisual($type);
		$icon = $visual['image'] !== '' ? '<img src="' . htmlspecialchars($visual['image'], ENT_QUOTES) . '" alt="" style="width:22px;height:22px;object-fit:contain;vertical-align:middle">' : '<span style="font-size:1.25em">' . htmlspecialchars($visual['glyph'], ENT_QUOTES) . '</span>';
		echo '<tr class="windowbg"><td>', $icon, ' <strong>', htmlspecialchars($type['label'], ENT_QUOTES), '</strong></td><td><select name="reaction_category[', htmlspecialchars($key, ENT_QUOTES), ']">';
		$current = BetterReactions_GetReactionCategory($key);
		foreach ($context['better_reactions_categories'] as $category)
			echo '<option value="', htmlspecialchars($category, ENT_QUOTES), '"', strcasecmp($current, $category) === 0 ? ' selected' : '', '>', htmlspecialchars($category, ENT_QUOTES), '</option>';
		echo '</select></td></tr>';
	}
	echo '</tbody></table><div class="windowbg righttext"><button type="submit" name="save_assignments" value="1" class="button">', $txt['save'], '</button></div>
		<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
	</form>';
}
